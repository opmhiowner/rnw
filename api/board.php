<?php
// ============================================================
// Renewal Center - the one API. JSON in, JSON out, always HTTP 200
// with an ok flag (SEV pattern). Every action is scoped to the
// signed-in user's office from the Hub session.
//
// v0.2: everything is per CYCLE = the increase month ("2026-12").
// October's run for the December 1 increase is cycle 2026-12; the
// decisions made then are the rows with cycle = '2026-12' and stay
// retrievable for November's upload and forever after as history.
// ============================================================
declare(strict_types=1);
require __DIR__ . '/../lib/core.php';
require __DIR__ . '/../lib/sync.php';
require __DIR__ . '/../lib/rentvine.php';
require __DIR__ . '/../lib/craigslist.php';
require __DIR__ . '/../lib/fmp.php';
schema_ensure();
$me = require_login();

$action = $_GET['a'] ?? '';
$in = body_json();

// ---------- helpers
function cyc(array $in): string {
    $c = trim((string)($in['cycle'] ?? ''));
    return cycle_valid($c) ? $c : cycle_default();
}

function q_row(string $leaseId, string $cycle): ?array {
    $st = db()->prepare("SELECT * FROM renewal_decisions WHERE office_id = ? AND lease_id = ? AND cycle = ?");
    $st->execute([oid(), $leaseId, $cycle]);
    $r = $st->fetch();
    return $r ?: null;
}

// the most recent row for a lease in any cycle (history on the record)
function q_history(string $leaseId, int $limit = 6): array {
    $st = db()->prepare("SELECT cycle, status, current_rent, new_rent, pct_inc, sdr_delta, increase_date, posted_at, pau_at, decided_by
                         FROM renewal_decisions WHERE office_id = ? AND lease_id = ? ORDER BY cycle DESC LIMIT $limit");
    $st->execute([oid(), $leaseId]);
    return $st->fetchAll();
}

function new_deposit_for(?float $newRent, ?float $curDeposit): ?float {
    if ($newRent === null) { return null; }
    $rule = (string)knob('deposit_rule');
    if ($rule === 'match_rent') { return $newRent; }
    return $curDeposit;
}

// open (or reuse) the DECISION row for a lease in a cycle, snapshotting the mirror.
// New rent starts BLANK (FileMaker "unfilled"); the increase date is the cycle's 1st.
// This never adds the lease to the set - see cycle_add for that.
function q_open(array $L, string $cycle, bool $addon = false): array {
    $me = current_user();
    $q = q_row($L['lease_id'], $cycle);
    if ($q) { return $q; }
    $ci = cycle_info($cycle);
    db()->prepare(
        "INSERT INTO renewal_decisions
           (company_id, office_id, lease_id, cycle, status, addon,
            lease_tenant, lease_property, lease_unit, lease_pcode, lease_zip, lease_rent, lease_deposit,
            lease_start, lease_end, lease_mtm,
            current_rent, new_rent, pct_inc, step_pct, increase_date, current_deposit, new_deposit, sdr_delta, decided_by)
         VALUES (?, ?, ?, ?, 'open', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NULL, NULL, NULL, ?, ?, NULL, NULL, ?)")
        ->execute([cid(), oid(), $L['lease_id'], $cycle, $addon ? 1 : 0,
                   $L['tenant'], $L['property'], $L['unit'], $L['pcode'], $L['zip'], $L['rent'], $L['deposit'],
                   $L['start'], $L['end'], $L['mtm'] ? 1 : 0,
                   $L['rent'], $ci['increase'], $L['deposit'], $me['key'] ?? 'system']);
    $q = q_row($L['lease_id'], $cycle);
    log_event((int)$q['id'], 'opened', ['lease_id' => $L['lease_id'], 'detail' => ['cycle' => $cycle]]);
    return $q;
}

function in_set(string $leaseId, string $cycle): bool {
    foreach (queue_build($cycle) as $r) { if ($r['lease']['lease_id'] === $leaseId) { return true; } }
    return false;
}

// current rent missing from the mirror -> ask Rentvine for the rent charge
// once, and remember charge id + due day on the row (the post's find step)
function rent_backfill(array &$q, array $L): void {
    if ($q['current_rent'] !== null || $q['status'] !== 'open') { return; }
    if (setting('rent_backfill_off', '0') === '1') { return; }
    $pick = rv_live_rent_charge($L['lease_id']);
    if (!$pick || $pick['amount'] === null) { return; }
    $rent = (float)$pick['amount'];
    $pct = ($q['new_rent'] !== null && $rent > 0) ? round((((float)$q['new_rent'] - $rent) / $rent) * 100, 2) : null;
    db()->prepare("UPDATE renewal_decisions SET current_rent = ?, lease_rent = COALESCE(lease_rent, ?), pct_inc = ?,
                     rv_old_charge_id = COALESCE(rv_old_charge_id, ?), rv_day_due = COALESCE(rv_day_due, ?)
                   WHERE id = ?")
        ->execute([$rent, $rent, $pct, $pick['id'] !== '' ? $pick['id'] : null, $pick['day_due'], $q['id']]);
    log_event((int)$q['id'], 'rent_from_rentvine', ['lease_id' => $L['lease_id'], 'detail' => ['charge' => $pick['id'], 'amount' => $rent, 'desc' => $pick['desc'], 'day_due' => $pick['day_due']]]);
    $q = q_row($L['lease_id'], $q['cycle']);
}

function ranges_for(array $q, array $L): array {
    $top = $q['range_top'] !== null ? (float)$q['range_top'] : null;
    $bot = $q['range_bottom'] !== null ? (float)$q['range_bottom'] : null;
    $median = $q['comp_median'] !== null ? (float)$q['comp_median'] : null;
    $base = $median ?? ($q['current_rent'] !== null ? (float)$q['current_rent'] : ($L['rent'] ?? 0.0));
    if ($top === null) { $top = round($base * ($median ? 1.10 : 1.15)); }
    if ($bot === null) { $bot = round($base * ($median ? 0.85 : 0.90)); }
    $pos = fn(float $p) => round($bot + ($top - $bot) * $p);
    return ['top' => $top, 'bottom' => $bot, 'baseline' => $pos(0.60), 'rentstart' => $pos(0.80), 'rentdrop' => $pos(0.92),
            'auto' => $q['range_top'] === null, 'basis' => $median ? 'comp median' : 'current rent'];
}

function media_row(string $pcode): array {
    $st = db()->prepare("SELECT * FROM renewal_media WHERE office_id = ? AND pcode = ?");
    $st->execute([oid(), $pcode]);
    $r = $st->fetch();
    $m = $r ? $r + ['slot_order' => json_decode((string)$r['slot_order'], true) ?: []]
            : ['pcode' => $pcode, 'cover' => null, 'slot_order' => [], 'description' => ''];
    if (trim((string)$m['description']) === '') {                              // FileMaker's ad copy until one is saved here
        $fm = fmp_marketing($pcode);
        $txt = $fm ? trim((string)($fm['adcopy'] ?: $fm['adcopy_plain'] ?: '')) : '';
        if ($txt !== '') { $m['description'] = $txt; $m['description_source'] = 'filemaker'; }
    }
    return $m;
}

function property_row(string $pcode): array {
    $st = db()->prepare("SELECT * FROM renewal_property WHERE office_id = ? AND pcode = ?");
    $st->execute([oid(), $pcode]);
    $r = $st->fetch() ?: ['pcode' => $pcode, 'special' => null, 'vaoao' => null, 'color' => null];
    if (($r['vaoao'] ?? null) === null || $r['vaoao'] === '') {           // FileMaker's building name until one is typed here
        $fp = fmp_property($pcode);
        if ($fp && !empty($fp['aoao'])) { $r['vaoao'] = $fp['aoao']; $r['vaoao_source'] = 'filemaker'; }
    }
    return $r;
}
function property_map(): array {
    $st = db()->prepare("SELECT pcode, special, vaoao, color FROM renewal_property WHERE office_id = ?");
    $st->execute([oid()]);
    $out = [];
    foreach ($st as $r) { $out[$r['pcode']] = $r; }
    return $out;
}

function lease_out(array $L): array { unset($L['has_record']); return $L; }

function record_payload(string $leaseId, string $cycle, bool $create = true): array {
    $L = lease_one($leaseId);
    if (!$L) { json_out(['ok' => false, 'error' => 'Lease ' . $leaseId . ' is not in Sync Center for this office.']); }
    $q = q_row($leaseId, $cycle);
    if (!$q && $create) { $q = q_open($L, $cycle); }   // a decision row only; membership is separate
    if (!$q) { json_out(['ok' => false, 'error' => 'Lease ' . $leaseId . ' is not in cycle ' . $cycle . '.']); }
    rent_backfill($q, $L);
    if ($L['rent'] === null && $q['current_rent'] !== null) { $L['rent'] = (float)$q['current_rent']; $L['rent_source'] = 'rentvine charge ' . ($q['rv_old_charge_id'] ?? ''); }
    $q['pinned_comps'] = json_decode((string)($q['pinned_comps'] ?? ''), true) ?: [];
    $hist = array_map(fn($x) => ['lease_id' => $x['lease_id'], 'unit' => $x['unit'] ?: $x['pcode'], 'bed' => $x['bed'], 'bath' => $x['bath'],
                                'parking' => $x['parking'], 'rent' => $x['rent'], 'last_increase' => $x['last_renewal'] ?? $x['last_increase'],
                                'move_in' => $x['move_in'], 'tenant' => $x['tenant']], building_history($L));
    $LP = last_posted_map();
    $AD = addons_for($cycle);
    $rule = queue_rule($L, $q, $cycle, $LP[$leaseId] ?? null, $AD[$leaseId] ?? null);
    [$anchor, $anchorSrc] = increase_anchor($L, $LP[$leaseId] ?? null);
    return ['lease' => lease_out($L), 'q' => $q, 'ranges' => ranges_for($q, $L), 'history' => $hist,
            'media' => media_row($L['pcode'] ?: $L['property_id']), 'property' => property_row($L['pcode']),
            'fmp' => ['ready' => fmp_ready(), 'fields' => fmp_renewal_fields(), 'row' => fmp_renewal((string)$L['pcode']),
                      'property' => fmp_property((string)$L['pcode']), 'marketing' => fmp_marketing((string)$L['pcode'])],
            'past' => q_history($leaseId),
            'cycle' => cycle_info($cycle), 'finalized' => cycle_finalized($cycle),
            'anchor' => ['date' => $anchor, 'source' => $anchorSrc],
            'in_set' => $rule !== null, 'addon' => isset($AD[$leaseId]),
            'cat' => $rule ? $rule[0] : null, 'cat_label' => $rule ? cat_label($rule[0]) : 'not in this set', 'reason' => $rule[1] ?? 'decision only - Add to set to pull it',
            'steps' => array_map('floatval', explode(',', (string)knob('steps'))),
            'step_dollars' => (float)knob('rent_step_dollars')];
}

// one list row for the queue / prep table
function set_row(array $r, array $PM, string $cycle = ''): array {
    $L = $r['lease']; $q = $r['q']; $r['cycle'] = $cycle ?: cycle_default();
    $P = $PM[$L['pcode']] ?? [];
    $newRent = $q && $q['new_rent'] !== null ? (float)$q['new_rent'] : null;
    $cur = $q && $q['current_rent'] !== null ? (float)$q['current_rent'] : $L['rent'];
    $dep = $q && $q['current_deposit'] !== null ? (float)$q['current_deposit'] : $L['deposit'];
    return ['lease_id' => $L['lease_id'], 'tenant' => $L['tenant'], 'property' => $L['property'], 'unit' => $L['unit'],
            'pcode' => $L['pcode'], 'zip' => $L['zip'], 'address' => $L['address'], 'owner' => $L['owner'], 'ptype' => $L['ptype'],
            'cat' => $r['cat'], 'cat_label' => $r['cat_label'], 'reason' => $r['reason'],
            'move_in' => $L['move_in'], 'end' => $L['end'], 'mtm' => $L['mtm'], 'last_increase' => $L['last_renewal'] ?? $L['last_increase'],
            'move_out' => $L['move_out'] ?? $L['notice'], 'vacating' => !empty($L['vacating']),
            'rent' => $cur, 'deposit' => $dep,
            'new_rent' => $newRent, 'change' => ($newRent !== null && $cur !== null) ? $newRent - $cur : null,
            'pct' => $q ? $q['pct_inc'] : null, 'asd' => $q ? $q['sdr_delta'] : null, 'day_due' => $q ? $q['rv_day_due'] : null,
            'increase_date' => $q && $q['increase_date'] ? $q['increase_date'] : cycle_info($r['cycle'] ?? cycle_default())['increase'],
            'status' => $q ? $q['status'] : null, 'addon' => !empty($r['addon']), 'revisit' => $q ? (bool)$q['revisit'] : false,
            'remarks' => $q ? $q['remarks'] : null, 'special' => $P['special'] ?? null, 'vaoao' => $P['vaoao'] ?? null, 'color' => $P['color'] ?? null,
            'deposit_mismatch' => ($cur !== null && $dep !== null && abs($cur - $dep) > 0.5),
            'unfilled' => $newRent === null,
            // Post screen: step progress, verification, readiness
            'rv' => $q ? ['find' => !empty($q['rv_old_charge_id']), 'expire' => !empty($q['rv_old_charge_expired_at']), 'create' => !empty($q['rv_new_charge_id']),
                          'sdr' => (float)($q['sdr_delta'] ?? 0) <= 0 || !empty($q['rv_sdr_charge_id']), 'custom' => !empty($q['rv_custom_field_at'])] : null,
            'partial' => $q && $q['status'] !== 'posted' && (!empty($q['rv_old_charge_id']) || !empty($q['rv_old_charge_expired_at']) || !empty($q['rv_new_charge_id'])),
            'posted_at' => $q ? ($q['posted_at'] ?? null) : null, 'posted_by' => $q ? ($q['posted_by'] ?? null) : null,
            'verified_at' => $q ? ($q['rv_verified_at'] ?? null) : null, 'verify_ok' => $q && isset($q['rv_verify_ok']) && $q['rv_verify_ok'] !== null ? (int)$q['rv_verify_ok'] : null,
            'verify_note' => $q ? ($q['rv_verify_note'] ?? null) : null,
            'ready' => $newRent !== null && $q && !in_array($q['status'], ['pau', 'posted'], true)];
}

function set_totals(array $rows): array {
    $t = ['count' => count($rows), 'filled' => 0, 'increase' => 0.0, 'asd' => 0.0, 'posted' => 0, 'pau' => 0, 'mtm' => 0, 'fixed' => 0, 'addon' => 0, 'exceptions' => 0];
    foreach ($rows as $r) {
        if (!$r['unfilled']) { $t['filled']++; $t['increase'] += (float)$r['change']; $t['asd'] += (float)($r['asd'] ?? 0); }
        if ($r['status'] === 'posted') { $t['posted']++; }
        if ($r['status'] === 'pau') { $t['pau']++; }
        if ($r['addon']) { $t['addon']++; } elseif ($r['mtm']) { $t['mtm']++; } else { $t['fixed']++; }
        if ($r['deposit_mismatch'] || $r['move_out'] || $r['vacating'] || $r['special']) { $t['exceptions']++; }
    }
    return $t;
}

// ------------------------------------------------------------
switch ($action) {

// ---- the set for a cycle (Main's queue and the Prep table share this)
case 'board':
case 'prep': {
    if (!empty($in['office'])) { switch_office_code((string)$in['office'], $me); }
    $cycle = cyc($in);
    $rows = queue_build($cycle);
    $PM = property_map();
    $out = array_map(fn($r) => set_row($r, $PM, $cycle), $rows);
    $counts = [];
    foreach ($rows as $r) { $counts[$r['cat']] = ($counts[$r['cat']] ?? 0) + 1; }
    $setIds = [];
    foreach ($rows as $r) { $setIds[$r['lease']['lease_id']] = true; }
    $overdue = queue_overdue($cycle, $setIds, last_posted_map());
    $unpulled = array_map(fn($r) => set_row($r, $PM, $cycle), queue_unpulled($cycle, $setIds));
    json_out(['ok' => true, 'cycle' => cycle_info($cycle) + ['row' => cycle_row($cycle), 'finalized' => cycle_finalized($cycle), 'default' => cycle_default()],
              'queue' => $out, 'counts' => $counts, 'cats' => RNW_CATS, 'totals' => set_totals($out), 'unpulled' => $unpulled,
              'overdue' => array_map(fn($o) => ['lease_id' => $o['lease']['lease_id'], 'tenant' => $o['lease']['tenant'], 'pcode' => $o['lease']['pcode'],
                                                'property' => $o['lease']['property'], 'rent' => $o['lease']['rent'], 'months' => $o['months'], 'anchor' => $o['anchor'], 'source' => $o['source']], $overdue),
              'office' => ['id' => oid(), 'code' => office_code(), 'label' => rnw_office_defaults(oid())['region_label']],
              'offices' => $me['offices'], 'me' => ['key' => $me['key'], 'name' => $me['name']],
              'sync' => ['ready' => sync_ready(), 'last' => sync_last_run(), 'leases' => count(leases_all())],
              'version' => rnw_version(),
              'display' => ['on' => (string)knob('display_mode') === '1', 'scale' => (float)knob('display_scale'), 'main_scale' => (float)knob('main_scale')]]);
}

case 'record': {
    $id = trim((string)($in['lease_id'] ?? ''));
    if ($id === '') { json_out(['ok' => false, 'error' => 'lease_id required']); }
    json_out(['ok' => true] + record_payload($id, cyc($in), !empty($in['create'])));
}

// Save: every decision field on the row. Enter on Main = this.
case 'save': {
    $id = trim((string)($in['lease_id'] ?? ''));
    $cycle = cyc($in);
    $L = lease_one($id);
    if (!$L) { json_out(['ok' => false, 'error' => 'Unknown lease.']); }
    if (cycle_finalized($cycle)) { json_out(['ok' => false, 'error' => 'Cycle ' . $cycle . ' is finalized (permanent). Reopen it on the Prep screen to change it.']); }
    $q = q_open($L, $cycle);
    if ($q['status'] === 'posted') { json_out(['ok' => false, 'error' => 'Already posted to Rentvine - reopen first.']); }
    $num = fn($k) => array_key_exists($k, $in) ? ($in[$k] === '' || $in[$k] === null ? null : (float)$in[$k]) : ($q[$k] !== null ? (float)$q[$k] : null);
    $str = fn($k) => array_key_exists($k, $in) ? (trim((string)$in[$k]) === '' ? null : trim((string)$in[$k])) : $q[$k];
    $flag = fn($k) => array_key_exists($k, $in) ? (int)!empty($in[$k]) : (int)$q[$k];
    $cur = $num('current_rent') ?? $L['rent'];
    $new = $num('new_rent');
    $pct = ($new !== null && $cur) ? round((($new - $cur) / $cur) * 100, 2) : null;
    $curDep = $num('current_deposit') ?? $L['deposit'];
    $newDep = array_key_exists('new_deposit', $in) && $in['new_deposit'] !== '' ? $num('new_deposit') : new_deposit_for($new, $curDep);
    $sdr = ($newDep !== null && $curDep !== null) ? max(0.0, $newDep - $curDep) : null;
    $incDate = $str('increase_date');
    if ($incDate !== null && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $incDate)) { $ts = strtotime($incDate); $incDate = $ts ? date('Y-m-d', $ts) : null; }
    if ($incDate === null) { $incDate = cycle_info($cycle)['increase']; }
    $catOv = array_key_exists('category_override', $in) ? ($in['category_override'] === '' || $in['category_override'] === null ? null : (int)$in['category_override']) : $q['category_override'];
    $pinned = array_key_exists('pinned_comps', $in) ? json_encode((array)$in['pinned_comps'], JSON_UNESCAPED_SLASHES) : $q['pinned_comps'];
    $median = array_key_exists('pinned_comps', $in) ? cl_median(array_column((array)$in['pinned_comps'], 'price')) : ($q['comp_median'] !== null ? (float)$q['comp_median'] : null);
    db()->prepare(
        "UPDATE renewal_decisions SET current_rent = ?, new_rent = ?, pct_inc = ?, step_pct = ?, increase_date = ?,
            current_deposit = ?, new_deposit = ?, sdr_delta = ?, range_top = ?, range_bottom = ?,
            eval_top = ?, eval_recom = ?, eval_bottom = ?, notes = ?, remarks = ?,
            revisit = ?, special = ?, oa = ?, no_increase = ?, category_override = ?,
            pinned_comps = ?, comp_median = ?, decided_by = ?, decided_at = NOW()
         WHERE id = ?")
        ->execute([$cur, $new, $pct, $num('step_pct'), $incDate,
                   $curDep, $newDep, $sdr, $num('range_top'), $num('range_bottom'),
                   $str('eval_top'), $str('eval_recom'), $str('eval_bottom'), $str('notes'), $str('remarks'),
                   $flag('revisit'), $flag('special'), $flag('oa'), $flag('no_increase'), $catOv,
                   $pinned, $median, $me['key'], $q['id']]);
    // property-level notes travel with the pcode, not the cycle
    if (array_key_exists('prop_special', $in) || array_key_exists('prop_vaoao', $in) || array_key_exists('prop_color', $in)) {
        $P = property_row($L['pcode']);
        db()->prepare("INSERT INTO renewal_property (company_id, office_id, pcode, special, vaoao, color, updated_by) VALUES (?, ?, ?, ?, ?, ?, ?)
                       ON DUPLICATE KEY UPDATE special = VALUES(special), vaoao = VALUES(vaoao), color = VALUES(color), updated_by = VALUES(updated_by)")
            ->execute([cid(), oid(), $L['pcode'],
                       array_key_exists('prop_special', $in) ? (trim((string)$in['prop_special']) ?: null) : $P['special'],
                       array_key_exists('prop_vaoao', $in) ? (trim((string)$in['prop_vaoao']) ?: null) : $P['vaoao'],
                       array_key_exists('prop_color', $in) ? (trim((string)$in['prop_color']) ?: null) : $P['color'], $me['key']]);
        // the same building name lives in FileMaker's property file (fmp_properties.aoao): keep it in step
        if (array_key_exists('prop_vaoao', $in)) { fmp_column_save('fmp_properties', (string)$L['pcode'], 'aoao', trim((string)$in['prop_vaoao'])); }
    }
    log_event((int)$q['id'], 'saved', ['lease_id' => $id, 'detail' => ['cycle' => $cycle, 'new_rent' => $new, 'pct' => $pct, 'sdr' => $sdr, 'increase_date' => $incDate]]);
    json_out(['ok' => true] + record_payload($id, $cycle));
}

// Pau renewal: decided (FileMaker "pau"); stays in the set for the upload
case 'pau': case 'reopen': case 'prepped': {
    $id = trim((string)($in['lease_id'] ?? ''));
    $cycle = cyc($in);
    $L = lease_one($id);
    if (!$L) { json_out(['ok' => false, 'error' => 'Unknown lease.']); }
    if (cycle_finalized($cycle) && $action !== 'reopen') { json_out(['ok' => false, 'error' => 'Cycle is finalized.']); }
    $q = q_open($L, $cycle);
    if ($action === 'pau') {
        db()->prepare("UPDATE renewal_decisions SET status = 'pau', pau_at = NOW(), pau_by = ? WHERE id = ? AND status = 'open'")->execute([$me['key'], $q['id']]);
    } elseif ($action === 'reopen') {
        db()->prepare("UPDATE renewal_decisions SET status = 'open', pau_at = NULL, pau_by = NULL WHERE id = ? AND status <> 'posted'")->execute([$q['id']]);
    } else {
        db()->prepare("UPDATE renewal_decisions SET printed_at = NOW(), printed_by = ? WHERE id = ?")->execute([$me['key'], $q['id']]);
    }
    log_event((int)$q['id'], $action, ['lease_id' => $id, 'detail' => ['cycle' => $cycle]]);
    json_out(['ok' => true] + record_payload($id, $cycle));
}

// ---- the set, by hand
case 'cycle_add': {
    $id = trim((string)($in['lease_id'] ?? ''));
    $cycle = cyc($in);
    $L = lease_one($id);
    if (!$L) { json_out(['ok' => false, 'error' => 'Unknown lease.']); }
    if (cycle_finalized($cycle)) { json_out(['ok' => false, 'error' => 'Cycle is finalized.']); }
    db()->prepare("INSERT IGNORE INTO renewal_addons (company_id, office_id, cycle, lease_id, note, added_by) VALUES (?, ?, ?, ?, ?, ?)")
        ->execute([cid(), oid(), $cycle, $id, trim((string)($in['note'] ?? '')) ?: null, $me['key']]);
    log_event(null, 'added_by_hand', ['lease_id' => $id, 'detail' => ['cycle' => $cycle]]);
    json_out(['ok' => true, 'lease_id' => $id, 'cycle' => $cycle]);
}
// remove an addon from the set. The decision row, if any, is kept (shows under "Not pulled").
case 'cycle_remove': {
    $id = trim((string)($in['lease_id'] ?? ''));
    $cycle = cyc($in);
    if (cycle_finalized($cycle)) { json_out(['ok' => false, 'error' => 'Cycle is finalized.']); }
    $q = q_row($id, $cycle);
    if ($q && $q['status'] === 'posted') { json_out(['ok' => false, 'error' => 'Posted rows cannot be removed from the set.']); }
    $st = db()->prepare("DELETE FROM renewal_addons WHERE office_id = ? AND cycle = ? AND lease_id = ?");
    $st->execute([oid(), $cycle, $id]);
    if ($st->rowCount() === 0) { json_out(['ok' => false, 'error' => 'That lease is in the set by the rule, not by hand - it cannot be removed. Use Category / flags instead.']); }
    log_event($q ? (int)$q['id'] : null, 'removed_by_hand', ['lease_id' => $id, 'detail' => ['cycle' => $cycle, 'decision_kept' => $q !== null]]);
    json_out(['ok' => true]);
}
case 'leases_search': {
    $q = strtolower(trim((string)($in['q'] ?? '')));
    $out = [];
    if ($q !== '') {
        foreach (leases_all() as $L) {
            $hay = strtolower($L['tenant'] . ' ' . $L['pcode'] . ' ' . $L['code'] . ' ' . $L['property'] . ' ' . $L['address'] . ' ' . $L['lease_id']);
            if (str_contains($hay, $q)) {
                $out[] = ['lease_id' => $L['lease_id'], 'tenant' => $L['tenant'], 'pcode' => $L['pcode'], 'property' => $L['property'], 'unit' => $L['unit'],
                          'rent' => $L['rent'], 'end' => $L['end'], 'mtm' => $L['mtm'], 'last_increase' => $L['last_renewal'] ?? $L['last_increase']];
                if (count($out) >= 15) { break; }
            }
        }
    }
    json_out(['ok' => true, 'rows' => $out]);
}

// ---- the whole cycle: letters stamp, batch post, finalize
case 'cycle_letters': {
    $cycle = cyc($in);
    db()->prepare("INSERT INTO renewal_cycles (company_id, office_id, cycle, letters_at, letters_by) VALUES (?, ?, ?, NOW(), ?)
                   ON DUPLICATE KEY UPDATE letters_at = NOW(), letters_by = VALUES(letters_by)")->execute([cid(), oid(), $cycle, $me['key']]);
    log_event(null, 'cycle_letters', ['detail' => ['cycle' => $cycle]]);
    json_out(['ok' => true, 'row' => cycle_row($cycle)]);
}
case 'cycle_post': {
    $cycle = cyc($in);
    if (empty($in['confirm'])) { json_out(['ok' => false, 'error' => 'Confirm required.']); }
    $rows = queue_build($cycle);
    $log = []; $done = 0; $failed = 0; $skipped = 0;
    foreach ($rows as $r) {
        $q = $r['q']; $L = $r['lease'];
        if (!$q || $q['new_rent'] === null || $q['status'] === 'posted') { $skipped++; continue; }
        if (!empty($in['only']) && !in_array($L['lease_id'], (array)$in['only'], true)) { $skipped++; continue; }
        $res = rv_post($q, $L, null);
        $log[] = ['lease_id' => $L['lease_id'], 'pcode' => $L['pcode'], 'tenant' => $L['tenant'], 'ok' => $res['ok'], 'done' => $res['done'] ?? false,
                  'steps' => array_map(fn($l) => $l['step'] . ($l['ok'] ? ' ok' : ' FAILED: ' . ($l['error'] ?? '')), $res['log'])];
        if ($res['ok'] && !empty($res['done'])) { $done++; } elseif (!$res['ok']) { $failed++; }
    }
    log_event(null, 'cycle_post', ['detail' => ['cycle' => $cycle, 'done' => $done, 'failed' => $failed, 'skipped' => $skipped]]);
    json_out(['ok' => $failed === 0, 'done' => $done, 'failed' => $failed, 'skipped' => $skipped, 'log' => $log]);
}
case 'cycle_finalize': {
    $cycle = cyc($in);
    if (empty($in['confirm'])) { json_out(['ok' => false, 'error' => 'Confirm required.']); }
    db()->prepare("INSERT INTO renewal_cycles (company_id, office_id, cycle, finalized_at, finalized_by) VALUES (?, ?, ?, NOW(), ?)
                   ON DUPLICATE KEY UPDATE finalized_at = NOW(), finalized_by = VALUES(finalized_by)")->execute([cid(), oid(), $cycle, $me['key']]);
    log_event(null, 'cycle_finalized', ['detail' => ['cycle' => $cycle]]);
    json_out(['ok' => true, 'row' => cycle_row($cycle)]);
}
case 'cycle_unfinalize': {
    $cycle = cyc($in);
    db()->prepare("UPDATE renewal_cycles SET finalized_at = NULL, finalized_by = NULL WHERE office_id = ? AND cycle = ?")->execute([oid(), $cycle]);
    log_event(null, 'cycle_reopened', ['detail' => ['cycle' => $cycle]]);
    json_out(['ok' => true, 'row' => cycle_row($cycle)]);
}
case 'cycles': {
    $st = db()->prepare("SELECT q.cycle, COUNT(*) n, SUM(q.status = 'posted') posted, SUM(q.new_rent IS NOT NULL) filled, c.finalized_at, c.letters_at
                         FROM renewal_decisions q LEFT JOIN renewal_cycles c ON c.office_id = q.office_id AND c.cycle = q.cycle
                         WHERE q.office_id = ? GROUP BY q.cycle ORDER BY q.cycle DESC LIMIT 36");
    $st->execute([oid()]);
    json_out(['ok' => true, 'cycles' => $st->fetchAll(), 'default' => cycle_default()]);
}

case 'events': {
    $id = trim((string)($in['lease_id'] ?? ''));
    $st = db()->prepare("SELECT event, actor, detail, created_at FROM renewal_events WHERE office_id = ? AND lease_id = ? ORDER BY id DESC LIMIT 100");
    $st->execute([oid(), $id]);
    json_out(['ok' => true, 'events' => $st->fetchAll()]);
}

// ---- KPI: the cycle at a glance
case 'kpi': {
    $cycle = cyc($in);
    $PM = property_map(); $rows = array_map(fn($r) => set_row($r, $PM, $cycle), queue_build($cycle));
    $cats = [];
    foreach ($rows as $r) { $cats[$r['cat_label']] = ($cats[$r['cat_label']] ?? 0) + 1; }
    $filled = array_filter($rows, fn($r) => !$r['unfilled']);
    $avg = $filled ? array_sum(array_map(fn($r) => (float)$r['pct'], $filled)) / count($filled) : null;
    json_out(['ok' => true, 'cycle' => cycle_info($cycle), 'totals' => set_totals($rows), 'by_cat' => $cats, 'avg_pct' => $avg]);
}

// ---- comps
case 'comps_search': {
    $p = ['keywords' => (string)($in['keywords'] ?? ''), 'bed' => (string)($in['bed'] ?? ''), 'bath' => (string)($in['bath'] ?? ''),
          'zip' => (string)($in['zip'] ?? ''), 'miles' => (string)($in['miles'] ?? knob('cl_miles')),
          'has_image' => !empty($in['has_image']), 'week' => !empty($in['week'])];
    json_out(['ok' => true, 'result' => cl_search($p, !empty($in['force']))]);
}
case 'comps_pin': {
    $id = trim((string)($in['lease_id'] ?? ''));
    $cycle = cyc($in);
    $L = lease_one($id);
    if (!$L) { json_out(['ok' => false, 'error' => 'Unknown lease.']); }
    $q = q_open($L, $cycle);
    $pinned = array_values(array_filter((array)($in['pinned'] ?? []), 'is_array'));
    $median = cl_median(array_column($pinned, 'price'));
    db()->prepare("UPDATE renewal_decisions SET pinned_comps = ?, comp_median = ? WHERE id = ?")
        ->execute([json_encode($pinned, JSON_UNESCAPED_SLASHES), $median, $q['id']]);
    log_event((int)$q['id'], 'comps_pinned', ['lease_id' => $id, 'detail' => ['n' => count($pinned), 'median' => $median]]);
    json_out(['ok' => true, 'median' => $median, 'pinned' => $pinned]);
}

// ---- media (files live on the office drive; this is cover / order / description)
case 'media_get': {
    $pcode = trim((string)($in['pcode'] ?? ''));
    json_out(['ok' => true, 'media' => media_row($pcode)]);
}
case 'media_set': {
    $pcode = trim((string)($in['pcode'] ?? ''));
    if ($pcode === '') { json_out(['ok' => false, 'error' => 'pcode required']); }
    $cur = media_row($pcode);
    $cover = array_key_exists('cover', $in) ? ($in['cover'] === '' ? null : (string)$in['cover']) : $cur['cover'];
    $order = array_key_exists('slot_order', $in) ? json_encode(array_values((array)$in['slot_order'])) : json_encode($cur['slot_order']);
    $desc = array_key_exists('description', $in) ? (string)$in['description'] : (string)$cur['description'];
    db()->prepare("INSERT INTO renewal_media (company_id, office_id, pcode, cover, slot_order, description, updated_by)
                   VALUES (?, ?, ?, ?, ?, ?, ?)
                   ON DUPLICATE KEY UPDATE cover = VALUES(cover), slot_order = VALUES(slot_order), description = VALUES(description), updated_by = VALUES(updated_by)")
        ->execute([cid(), oid(), $pcode, $cover, $order, $desc, $me['key']]);
    if (array_key_exists('description', $in)) { fmp_column_save('fmp_marketing', $pcode, 'f_12_adcopy1_rent_util_online', (string)$in['description']); }   // FileMaker's ad copy follows
    log_event(null, 'media_saved', ['detail' => ['pcode' => $pcode, 'cover' => $cover]]);
    json_out(['ok' => true, 'media' => media_row($pcode)]);
}

// ---- Rentvine write-back
case 'rv_plan': {
    $id = trim((string)($in['lease_id'] ?? ''));
    $cycle = cyc($in);
    $L = lease_one($id);
    if (!$L) { json_out(['ok' => false, 'error' => 'Unknown lease.']); }
    $q = q_open($L, $cycle);
    json_out(['ok' => true, 'plan' => rv_plan($q, $L), 'q' => $q]);
}
case 'rv_post': {
    $id = trim((string)($in['lease_id'] ?? ''));
    $cycle = cyc($in);
    $L = lease_one($id);
    if (!$L) { json_out(['ok' => false, 'error' => 'Unknown lease.']); }
    $q = q_open($L, $cycle);
    if ($q['new_rent'] === null) { json_out(['ok' => false, 'error' => 'Set a new rent first.']); }
    if (empty($in['confirm'])) { json_out(['ok' => false, 'error' => 'Confirm required.']); }
    $only = isset($in['only']) ? (string)$in['only'] : null;
    $r = rv_post($q, $L, $only);
    json_out(['ok' => $r['ok'], 'log' => $r['log'], 'done' => $r['done'] ?? false, 'error' => $r['ok'] ? null : ('Step failed: ' . end($r['log'])['error'])] + record_payload($id, $cycle));
}
// ---- the FileMaker renewal record (fmp_renewals) for the property, edited from Main
case 'fmp_save': {
    $id = trim((string)($in['lease_id'] ?? ''));
    $cycle = cyc($in);
    $L = lease_one($id);
    if (!$L) { json_out(['ok' => false, 'error' => 'Unknown lease.']); }
    $r = fmp_renewal_save((string)$L['pcode'], (array)($in['fields'] ?? []));
    if (!$r['ok']) { json_out($r); }
    log_event(null, 'fmp_save', ['lease_id' => $id, 'detail' => ['pcode' => $L['pcode'], 'fields' => array_keys((array)($in['fields'] ?? []))]]);
    json_out(['ok' => true, 'changed' => $r['changed']] + record_payload($id, $cycle, false));
}
case 'rv_verify': {
    $id = trim((string)($in['lease_id'] ?? ''));
    $cycle = cyc($in);
    $L = lease_one($id);
    if (!$L) { json_out(['ok' => false, 'error' => 'Unknown lease.']); }
    $q = q_row($id, $cycle);
    if (!$q) { json_out(['ok' => false, 'error' => 'No decision row for this lease in ' . $cycle . '.']); }
    json_out(rv_verify($q, $L));
}
case 'rv_status': {
    $c = rv_creds();
    json_out(['ok' => true, 'creds' => ['source' => $c['source'], 'base' => $c['base'], 'key_tail' => $c['key'] !== '' ? '…' . substr($c['key'], -4) : '', 'have' => $c['key'] !== '' && $c['base'] !== ''],
              'deposit_account' => rv_tpl('rv_deposit_account_id') !== '', 'rent_account' => rv_tpl('rv_rent_account_id') !== '', 'custom_field' => rv_tpl('rv_custom_field_id') !== '']);
}
case 'rv_test': {
    $id = trim((string)($in['lease_id'] ?? ''));
    json_out(['ok' => true, 'test' => rv_test($id)]);
}

// ---- settings
case 'settings_get': {
    $keys = array_merge(array_keys(rnw_defaults()), array_keys(rv_templates_default()), ['timezone', 'region_label', 'rv_base', 'rv_auth_style', 'rv_auth_header']);
    $out = [];
    foreach ($keys as $k) { $out[$k] = setting($k, rnw_defaults()[$k] ?? rv_templates_default()[$k] ?? ''); }
    $out['rv_api_key_tail'] = ($k = (string)setting('rv_api_key', '')) !== '' ? '…' . substr($k, -4) : '';
    $out['fm_push_key_set'] = (string)setting('fm_push_key', '') !== '';
    $out['fm_login_user'] = (string)setting('fm_login_user', '');
    $c = rv_creds();
    json_out(['ok' => true, 'settings' => $out, 'rv_source' => $c['source'], 'rv_base_effective' => $c['base'], 'rv_key_tail' => $c['key'] !== '' ? '…' . substr($c['key'], -4) : '',
              'defaults' => rnw_defaults() + rv_templates_default(), 'verified' => rv_verified()]);
}
case 'settings_set': {
    $allowed = array_merge(array_keys(rnw_defaults()), array_keys(rv_templates_default()), ['timezone', 'region_label', 'rv_base', 'rv_api_key', 'rv_api_secret', 'rv_auth_style', 'rv_auth_header', 'fm_push_key', 'fm_login_user']);
    $changed = [];
    foreach ((array)($in['settings'] ?? []) as $k => $v) {
        if (!in_array($k, $allowed, true)) { continue; }
        if (in_array($k, ['rv_api_key', 'rv_api_secret', 'fm_push_key'], true) && (string)$v === '') { continue; }   // blank keeps the secret
        if ($k === 'fm_push_key' && strlen((string)$v) < 16) { json_out(['ok' => false, 'error' => 'FileMaker push key: at least 16 characters (use Generate).']); }
        if ($k === 'fm_login_user') { $v = trim((string)$v); }
        setting_put((string)$k, $v === null ? null : (string)$v);
        $changed[] = $k;
    }
    log_event(null, 'settings', ['detail' => ['changed' => $changed]]);
    json_out(['ok' => true, 'changed' => $changed]);
}

// ---- linked windows through the server (per signed-in user)
case 'current_set': {
    $cur = (array)($in['current'] ?? []);
    $cur['at'] = date('c');
    setting_put('current:' . $me['key'], json_encode($cur, JSON_UNESCAPED_SLASHES));
    json_out(['ok' => true]);
}
case 'current_get': {
    $cur = json_decode((string)setting('current:' . $me['key'], ''), true) ?: null;
    $rowAt = null; $median = null; $pinned = null;
    if ($cur && !empty($cur['record_id'])) {
        $q = q_row((string)$cur['record_id'], cycle_valid((string)($cur['cycle'] ?? '')) ? (string)$cur['cycle'] : cycle_default());
        if ($q) { $rowAt = $q['updated_at']; $median = $q['comp_median']; $pinned = json_decode((string)$q['pinned_comps'], true) ?: []; }
    }
    json_out(['ok' => true, 'current' => $cur, 'row_updated_at' => $rowAt, 'comp_median' => $median, 'pinned' => $pinned]);
}

case 'switch_office': {
    $ok = switch_office_code((string)($in['code'] ?? ''), $me);
    json_out(['ok' => $ok, 'office' => ['id' => oid(), 'code' => office_code()]]);
}

default:
    json_out(['ok' => false, 'error' => 'Unknown action: ' . $action]);
}
