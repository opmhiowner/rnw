<?php
// ============================================================
// Renewal Center - the one API. JSON in, JSON out, always HTTP 200
// with an ok flag (SEV pattern). Every action is scoped to the
// signed-in user's office from the Hub session.
// ============================================================
declare(strict_types=1);
require __DIR__ . '/../lib/core.php';
require __DIR__ . '/../lib/sync.php';
require __DIR__ . '/../lib/rentvine.php';
require __DIR__ . '/../lib/craigslist.php';
schema_ensure();
$me = require_login();

$action = $_GET['a'] ?? '';
$in = body_json();

// ---------- helpers
function q_row(string $leaseId): ?array {
    $st = db()->prepare("SELECT * FROM renewal_queue WHERE office_id = ? AND lease_id = ?
                         ORDER BY (status = 'open') DESC, cycle DESC, id DESC LIMIT 1");
    $st->execute([oid(), $leaseId]);
    $r = $st->fetch();
    return $r ?: null;
}

function default_increase_date(array $L): string {
    if ($L['end'] && $L['end'] >= date('Y-m-d')) { return date('Y-m-d', strtotime($L['end'] . ' +1 day')); }
    return date('Y-m-01', strtotime('+1 month'));
}

function new_deposit_for(?float $newRent, ?float $curDeposit): ?float {
    $rule = (string)knob('deposit_rule');
    if ($rule === 'match_rent') { return $newRent; }
    return $curDeposit;
}

// open (or reuse) the decision row for a lease, snapshotting Sync Center
function q_open(array $L): array {
    $me = current_user();
    $q = q_row($L['lease_id']);
    if ($q && $q['status'] === 'open') { return $q; }
    if ($q && $q['status'] !== 'open' && $q['cycle'] === cycle_now()) { return $q; }   // done this month: show it
    $rent = $L['rent'];
    $dep = $L['deposit'];
    db()->prepare(
        "INSERT INTO renewal_queue
           (company_id, office_id, lease_id, cycle, status,
            lease_tenant, lease_property, lease_unit, lease_pcode, lease_zip, lease_rent, lease_deposit,
            lease_start, lease_end, lease_mtm,
            current_rent, new_rent, pct_inc, step_pct, increase_date, current_deposit, new_deposit, sdr_delta, decided_by)
         VALUES (?, ?, ?, ?, 'open', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, 0, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)")
        ->execute([cid(), oid(), $L['lease_id'], cycle_now(),
                   $L['tenant'], $L['property'], $L['unit'], $L['pcode'], $L['zip'], $rent, $dep,
                   $L['start'], $L['end'], $L['mtm'] ? 1 : 0,
                   $rent, $rent, default_increase_date($L), $dep, new_deposit_for($rent, $dep),
                   $dep !== null && $rent !== null ? max(0, (float)new_deposit_for($rent, $dep) - $dep) : null,
                   $me['key'] ?? 'system']);
    $q = q_row($L['lease_id']);
    log_event((int)$q['id'], 'opened', ['lease_id' => $L['lease_id']]);
    return $q;
}

function ranges_for(array $q, array $L): array {
    $top = $q['range_top'] !== null ? (float)$q['range_top'] : null;
    $bot = $q['range_bottom'] !== null ? (float)$q['range_bottom'] : null;
    $median = $q['comp_median'] !== null ? (float)$q['comp_median'] : null;
    $base = $median ?? $L['rent'] ?? 0.0;
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
    return $r ? $r + ['slot_order' => json_decode((string)$r['slot_order'], true) ?: []]
              : ['pcode' => $pcode, 'cover' => null, 'slot_order' => [], 'description' => ''];
}

function lease_out(array $L): array {
    unset($L['has_record']);
    return $L;
}

function record_payload(string $leaseId): array {
    $L = lease_one($leaseId);
    if (!$L) { json_out(['ok' => false, 'error' => 'Lease ' . $leaseId . ' is not in Sync Center for this office.']); }
    $q = q_open($L);
    $q['pinned_comps'] = json_decode((string)($q['pinned_comps'] ?? ''), true) ?: [];
    $hist = array_map(fn($x) => ['lease_id' => $x['lease_id'], 'unit' => $x['unit'], 'bed' => $x['bed'], 'bath' => $x['bath'],
                                'parking' => $x['parking'], 'rent' => $x['rent'], 'last_increase' => $x['last_renewal'] ?? $x['last_increase'],
                                'move_in' => $x['move_in'], 'tenant' => $x['tenant']], building_history($L));
    $rule = queue_rule($L, $q, date('Y-m-d'));
    return ['lease' => lease_out($L), 'q' => $q, 'ranges' => ranges_for($q, $L), 'history' => $hist,
            'media' => media_row($L['pcode'] ?: $L['property_id']),
            'cat' => $rule ? $rule[0] : null, 'cat_label' => $rule ? cat_label($rule[0]) : '', 'reason' => $rule[1] ?? '',
            'steps' => array_map('floatval', explode(',', (string)knob('steps'))),
            'step_dollars' => (float)knob('rent_step_dollars')];
}

// ------------------------------------------------------------
switch ($action) {

case 'board': {
    if (!empty($in['office'])) { switch_office_code((string)$in['office'], $me); }
    $rows = queue_build();
    $counts = [];
    foreach ($rows as $r) { $counts[$r['cat']] = ($counts[$r['cat']] ?? 0) + 1; }
    $out = [];
    foreach ($rows as $r) {
        $L = $r['lease'];
        $days = $L['end'] ? (int)((strtotime($L['end']) - strtotime(date('Y-m-d'))) / 86400) : null;
        $out[] = ['lease_id' => $L['lease_id'], 'tenant' => $L['tenant'], 'property' => $L['property'], 'unit' => $L['unit'],
                  'pcode' => $L['pcode'], 'zip' => $L['zip'], 'address' => $L['address'],
                  'cat' => $r['cat'], 'cat_label' => $r['cat_label'], 'reason' => $r['reason'],
                  'end' => $L['end'], 'mtm' => $L['mtm'], 'days' => $days, 'rent' => $L['rent'],
                  'status' => $r['q']['status'] ?? null, 'new_rent' => $r['q']['new_rent'] ?? null, 'pct' => $r['q']['pct_inc'] ?? null];
    }
    json_out(['ok' => true, 'queue' => $out, 'counts' => $counts, 'cats' => RNW_CATS,
              'office' => ['id' => oid(), 'code' => office_code(), 'label' => rnw_office_defaults(oid())['region_label']],
              'offices' => $me['offices'], 'me' => ['key' => $me['key'], 'name' => $me['name']],
              'sync' => ['ready' => sync_ready(), 'last' => sync_last_run(), 'leases' => count(leases_all())],
              'version' => rnw_version(),
              'display' => ['on' => (string)knob('display_mode') === '1', 'scale' => (float)knob('display_scale')]]);
}

case 'record': {
    $id = trim((string)($in['lease_id'] ?? ''));
    if ($id === '') { json_out(['ok' => false, 'error' => 'lease_id required']); }
    json_out(['ok' => true] + record_payload($id));
}

// Save: every decision field on the row. Enter on Main = this.
case 'save': {
    $id = trim((string)($in['lease_id'] ?? ''));
    $L = lease_one($id);
    if (!$L) { json_out(['ok' => false, 'error' => 'Unknown lease.']); }
    $q = q_open($L);
    if ($q['status'] === 'posted') { json_out(['ok' => false, 'error' => 'Already posted to Rentvine - reopen first.']); }
    $num = fn($k) => array_key_exists($k, $in) ? ($in[$k] === '' || $in[$k] === null ? null : (float)$in[$k]) : ($q[$k] !== null ? (float)$q[$k] : null);
    $str = fn($k) => array_key_exists($k, $in) ? (trim((string)$in[$k]) === '' ? null : trim((string)$in[$k])) : $q[$k];
    $flag = fn($k) => array_key_exists($k, $in) ? (int)!empty($in[$k]) : (int)$q[$k];
    $cur = $num('current_rent') ?? $L['rent'];
    $new = $num('new_rent') ?? $cur;
    $pct = $cur ? round((($new - $cur) / $cur) * 100, 2) : 0.0;
    $curDep = $num('current_deposit') ?? $L['deposit'];
    $newDep = array_key_exists('new_deposit', $in) ? $num('new_deposit') : new_deposit_for($new, $curDep);
    $sdr = ($newDep !== null && $curDep !== null) ? max(0.0, $newDep - $curDep) : ($newDep !== null && $curDep === null ? null : 0.0);
    $incDate = $str('increase_date');
    if ($incDate !== null && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $incDate)) { $ts = strtotime($incDate); $incDate = $ts ? date('Y-m-d', $ts) : null; }

    $catOv = array_key_exists('category_override', $in) ? ($in['category_override'] === '' || $in['category_override'] === null ? null : (int)$in['category_override']) : $q['category_override'];
    $pinned = array_key_exists('pinned_comps', $in) ? json_encode((array)$in['pinned_comps'], JSON_UNESCAPED_SLASHES) : $q['pinned_comps'];
    $median = array_key_exists('pinned_comps', $in) ? cl_median(array_column((array)$in['pinned_comps'], 'price')) : ($q['comp_median'] !== null ? (float)$q['comp_median'] : null);
    db()->prepare(
        "UPDATE renewal_queue SET current_rent = ?, new_rent = ?, pct_inc = ?, step_pct = ?, increase_date = ?,
            current_deposit = ?, new_deposit = ?, sdr_delta = ?, range_top = ?, range_bottom = ?,
            eval_top = ?, eval_recom = ?, eval_bottom = ?, notes = ?, vaoao = ?,
            revisit = ?, special = ?, oa = ?, no_increase = ?, category_override = ?,
            pinned_comps = ?, comp_median = ?, decided_by = ?, decided_at = NOW()
         WHERE id = ?")
        ->execute([$cur, $new, $pct, $num('step_pct'), $incDate,
                   $curDep, $newDep, $sdr, $num('range_top'), $num('range_bottom'),
                   $str('eval_top'), $str('eval_recom'), $str('eval_bottom'), $str('notes'), $str('vaoao'),
                   $flag('revisit'), $flag('special'), $flag('oa'), $flag('no_increase'), $catOv,
                   $pinned, $median, $me['key'], $q['id']]);
    log_event((int)$q['id'], 'saved', ['lease_id' => $id, 'detail' => ['new_rent' => $new, 'pct' => $pct, 'sdr' => $sdr, 'increase_date' => $incDate]]);
    json_out(['ok' => true] + record_payload($id));
}

// Pau renewal: decided, out of the queue for this cycle (ADDON shows it this month)
case 'pau': {
    $id = trim((string)($in['lease_id'] ?? ''));
    $L = lease_one($id);
    if (!$L) { json_out(['ok' => false, 'error' => 'Unknown lease.']); }
    $q = q_open($L);
    db()->prepare("UPDATE renewal_queue SET status = 'pau', pau_at = NOW(), pau_by = ? WHERE id = ? AND status = 'open'")->execute([$me['key'], $q['id']]);
    log_event((int)$q['id'], 'pau', ['lease_id' => $id]);
    json_out(['ok' => true] + record_payload($id));
}
case 'reopen': {
    $id = trim((string)($in['lease_id'] ?? ''));
    $L = lease_one($id);
    if (!$L) { json_out(['ok' => false, 'error' => 'Unknown lease.']); }
    $q = q_open($L);
    db()->prepare("UPDATE renewal_queue SET status = 'open', pau_at = NULL, pau_by = NULL WHERE id = ?")->execute([$q['id']]);
    log_event((int)$q['id'], 'reopened', ['lease_id' => $id]);
    json_out(['ok' => true] + record_payload($id));
}
// Prep/Print is parked: this only stamps the record so the letter run can pick it up later
case 'prepped': {
    $id = trim((string)($in['lease_id'] ?? ''));
    $L = lease_one($id);
    if (!$L) { json_out(['ok' => false, 'error' => 'Unknown lease.']); }
    $q = q_open($L);
    db()->prepare("UPDATE renewal_queue SET printed_at = NOW(), printed_by = ? WHERE id = ?")->execute([$me['key'], $q['id']]);
    log_event((int)$q['id'], 'prepped', ['lease_id' => $id]);
    json_out(['ok' => true] + record_payload($id));
}

case 'events': {
    $id = trim((string)($in['lease_id'] ?? ''));
    $st = db()->prepare("SELECT event, actor, detail, created_at FROM renewal_events WHERE office_id = ? AND lease_id = ? ORDER BY id DESC LIMIT 100");
    $st->execute([oid(), $id]);
    json_out(['ok' => true, 'events' => $st->fetchAll()]);
}

// ---- KPI: this cycle at a glance
case 'kpi': {
    $st = db()->prepare(
        "SELECT status, COUNT(*) n, AVG(pct_inc) avg_pct, SUM(new_rent - current_rent) added, SUM(sdr_delta) sdr
         FROM renewal_queue WHERE office_id = ? AND cycle = ? GROUP BY status");
    $st->execute([oid(), cycle_now()]);
    $by = [];
    foreach ($st as $r) { $by[$r['status']] = $r; }
    $rows = queue_build();
    $cats = [];
    foreach ($rows as $r) { $cats[$r['cat_label']] = ($cats[$r['cat_label']] ?? 0) + 1; }
    json_out(['ok' => true, 'cycle' => cycle_now(), 'by_status' => $by, 'by_cat' => $cats, 'in_queue' => count($rows)]);
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
    $L = lease_one($id);
    if (!$L) { json_out(['ok' => false, 'error' => 'Unknown lease.']); }
    $q = q_open($L);
    $pinned = array_values(array_filter((array)($in['pinned'] ?? []), 'is_array'));
    $median = cl_median(array_column($pinned, 'price'));
    db()->prepare("UPDATE renewal_queue SET pinned_comps = ?, comp_median = ? WHERE id = ?")
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
    log_event(null, 'media_saved', ['detail' => ['pcode' => $pcode, 'cover' => $cover]]);
    json_out(['ok' => true, 'media' => media_row($pcode)]);
}

// ---- Rentvine write-back
case 'rv_plan': {
    $id = trim((string)($in['lease_id'] ?? ''));
    $L = lease_one($id);
    if (!$L) { json_out(['ok' => false, 'error' => 'Unknown lease.']); }
    $q = q_open($L);
    json_out(['ok' => true, 'plan' => rv_plan($q, $L), 'q' => $q]);
}
case 'rv_post': {
    $id = trim((string)($in['lease_id'] ?? ''));
    $L = lease_one($id);
    if (!$L) { json_out(['ok' => false, 'error' => 'Unknown lease.']); }
    $q = q_open($L);
    if ($q['new_rent'] === null) { json_out(['ok' => false, 'error' => 'Save a new rent first.']); }
    if (empty($in['confirm'])) { json_out(['ok' => false, 'error' => 'Confirm required.']); }
    $only = isset($in['only']) ? (string)$in['only'] : null;
    $r = rv_post($q, $L, $only);
    json_out(['ok' => $r['ok'], 'log' => $r['log'], 'done' => $r['done'] ?? false, 'error' => $r['ok'] ? null : ('Step failed: ' . end($r['log'])['error'])] + record_payload($id));
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
    $c = rv_creds();
    json_out(['ok' => true, 'settings' => $out, 'rv_source' => $c['source'], 'rv_base_effective' => $c['base'], 'rv_key_tail' => $c['key'] !== '' ? '…' . substr($c['key'], -4) : '',
              'defaults' => rnw_defaults() + rv_templates_default(), 'verified' => rv_verified()]);
}
case 'settings_set': {
    $allowed = array_merge(array_keys(rnw_defaults()), array_keys(rv_templates_default()), ['timezone', 'region_label', 'rv_base', 'rv_api_key', 'rv_api_secret', 'rv_auth_style', 'rv_auth_header']);
    $changed = [];
    foreach ((array)($in['settings'] ?? []) as $k => $v) {
        if (!in_array($k, $allowed, true)) { continue; }
        if (in_array($k, ['rv_api_key', 'rv_api_secret'], true) && (string)$v === '') { continue; }   // blank keeps the secret
        setting_put((string)$k, $v === null ? null : (string)$v);
        $changed[] = $k;
    }
    log_event(null, 'settings', ['detail' => ['changed' => $changed]]);
    json_out(['ok' => true, 'changed' => $changed]);
}

// ---- linked windows through the server: Main publishes the current
// record per user; Media / Comps poll it. Works across Chrome profiles
// (the launcher uses one per window) and across PCs.
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
        $q = q_row((string)$cur['record_id']);
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
