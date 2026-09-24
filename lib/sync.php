<?php
// ============================================================
// Renewal Center - Sync Center reader + queue rules
//
// Sync Center (opmhiowner/sync) mirrors Rentvine into oishi-db:
//   sync_leases   the index: one row per lease, raw = index row
//                 (id, tenant, address, unit, move_in, owner,
//                 legacy, tenant_id, property_id, unit_id, owner_id,
//                 portfolio_id, active). rent / end_date are NULL
//                 in the index BY DESIGN - never rely on them.
//   sync_records  the full Rentvine record per feed (leases, units,
//                 properties, owners, tenants, portfolios), raw JSON.
// This file reads both, joins them per office, and computes the
// renewal queue. It never writes to sync_*.
//
// Rentvine key names are read tolerantly (candidate lists) because
// live data never ships in git. probe.php shows one raw record so
// the lists get confirmed on first deploy.
// ============================================================
declare(strict_types=1);

// ---------- tolerant getter: dotted paths, first non-empty wins
function sx($a, array $keys, $default = null) {
    if (!is_array($a)) { return $default; }
    foreach ($keys as $k) {
        $v = $a;
        foreach (explode('.', $k) as $p) {
            if (!is_array($v) || !array_key_exists($p, $v)) { $v = null; break; }
            $v = $v[$p];
        }
        if ($v !== null && $v !== '' && !is_array($v)) { return $v; }
        if (is_array($v) && $v !== []) { return $v; }
    }
    return $default;
}
function sx_date($a, array $keys): ?string {
    $v = sx($a, $keys);
    if ($v === null || !is_string($v) && !is_numeric($v)) { return null; }
    $ts = strtotime((string)$v);
    return $ts ? date('Y-m-d', $ts) : null;
}
function sx_num($a, array $keys): ?float {
    $v = sx($a, $keys);
    if ($v === null || $v === '') { return null; }
    if (is_array($v)) { return null; }
    $s = preg_replace('/[^0-9.\-]/', '', (string)$v);
    return $s === '' || $s === '-' ? null : (float)$s;
}

// ---------- is Sync Center's mirror present?
function sync_ready(): bool {
    static $ok = null;
    if ($ok !== null) { return $ok; }
    try { db()->query("SELECT 1 FROM sync_leases LIMIT 1"); db()->query("SELECT 1 FROM sync_records LIMIT 1"); $ok = true; }
    catch (Throwable $e) { $ok = false; }
    return $ok;
}

// ---------- one feed of sync_records for this office, keyed by external_id
function sync_feed(string $feed, bool $reload = false): array {
    static $cache = [];
    $k = oid() . ':' . $feed;
    if (isset($cache[$k]) && !$reload) { return $cache[$k]; }
    $out = [];
    if (sync_ready()) {
        $st = db()->prepare("SELECT external_id, raw, updated_at FROM sync_records
                             WHERE company_id = ? AND office_id = ? AND feed = ?");
        $st->execute([cid(), oid(), $feed]);
        foreach ($st as $r) {
            $j = json_decode((string)$r['raw'], true);
            if (!is_array($j)) { continue; }
            // Rentvine wraps some records one level down ({"lease": {...}, "tenants": [...]})
            $out[(string)$r['external_id']] = $j;
        }
    }
    return $cache[$k] = $out;
}

// the index rows (sync_leases.raw + columns) for this office
function sync_index(bool $reload = false): array {
    static $rows = null;
    static $for = 0;
    if ($rows !== null && !$reload && $for === oid()) { return $rows; }
    $for = oid();
    $rows = [];
    if (!sync_ready()) { return $rows; }
    $st = db()->prepare("SELECT * FROM sync_leases WHERE company_id = ? AND office_id = ?");
    $st->execute([cid(), oid()]);
    foreach ($st as $r) {
        $rawJ = isset($r['raw']) && is_string($r['raw']) ? json_decode($r['raw'], true) : null;
        $rows[] = is_array($rawJ) ? array_merge($rawJ, array_filter($r, fn($v) => $v !== null && $v !== '')) : $r;
    }
    return $rows;
}

function sync_last_run(): ?string {
    if (!sync_ready()) { return null; }
    try {
        $st = db()->prepare("SELECT MAX(updated_at) FROM sync_leases WHERE company_id = ? AND office_id = ?");
        $st->execute([cid(), oid()]);
        $v = $st->fetchColumn();
        return $v ? (string)$v : null;
    } catch (Throwable $e) { return null; }
}

// ---------- the unwrapped lease record ("lease" one level down on
// some endpoints; the wrapper also carries tenants[] etc.)
function lease_core(array $rec): array {
    return isset($rec['lease']) && is_array($rec['lease']) ? $rec['lease'] : $rec;
}

// ---------- one lease, fully joined. $ix = index row, feeds = the
// sync_records maps. Returns the normalised shape every screen uses.
function lease_join(array $ix, array $F): array {
    $id   = (string)sx($ix, ['external_id', 'id', 'lease_id', 'leaseID']);
    $rec  = $F['leases'][$id] ?? [];
    $L    = lease_core($rec);
    // CONFIRMED 2026-09-23 against the live mirror (probe.php): the leases feed record is
    // {"unit":{beds, fullBaths, halfBaths, size, rent, deposit, address, address2, postalCode,
    //  city, isVacant}, "lease":{code, startDate, endDate, moveInDate, moveOutDate, noticeDate,
    //  expectedMoveOutDate, closedDate, isMonthToMonth, monthToMonthStartDate,
    //  increaseEligibilityDate, isMarkedToVacate, leaseStatusID, tenants[]}, "token"}.
    // endDate 2049-09-09 is Rentvine's "no end" placeholder. Rent lives on the unit block
    // (and on the recurring charge), never on the lease itself.
    $LU   = isset($rec['unit']) && is_array($rec['unit']) ? $rec['unit'] : [];
    $pid  = (string)(sx($ix, ['property_id', 'propertyId']) ?? sx($L, ['propertyID', 'propertyId', 'property_id']) ?? '');
    $uid  = (string)(sx($ix, ['unit_id', 'unitId']) ?? sx($L, ['unitID', 'unitId', 'unit_id']) ?? '');
    $oid_ = (string)(sx($ix, ['owner_id', 'ownerId']) ?? sx($L, ['ownerID', 'ownerId']) ?? '');
    $tid  = (string)(sx($ix, ['tenant_id', 'tenantId']) ?? sx($L, ['tenantID', 'tenantId']) ?? '');
    $P = $F['properties'][$pid] ?? [];  $P = isset($P['property']) && is_array($P['property']) ? $P['property'] : $P;
    $UR = $F['units'][$uid] ?? [];
    $U = isset($UR['unit']) && is_array($UR['unit']) ? $UR['unit'] : $UR;
    if (!$P && isset($UR['property']) && is_array($UR['property'])) { $P = $UR['property']; }
    $O = $F['owners'][$oid_] ?? [];     $O = isset($O['owner']) && is_array($O['owner']) ? $O['owner'] : $O;
    if (!$U) { $U = $LU; }

    // Rentvine "address" is the descriptive label ("#1 Davenport Apartment"); "address2" is
    // the street ("1109 Davenport St #1"). Property label = address; postal address = address2.
    $label  = (string)(sx($P, ['address']) ?? sx($LU, ['address']) ?? sx($ix, ['address']) ?? '');
    $label  = preg_replace('/^[^—]*—\s*/u', '', $label);
    $street = (string)(sx($P, ['address2']) ?? sx($LU, ['address2']) ?? sx($U, ['address2']) ?? '');
    $city   = (string)(sx($P, ['city']) ?? sx($LU, ['city']) ?? '');
    $zip    = (string)(sx($P, ['postalCode', 'zip', 'zipCode']) ?? sx($LU, ['postalCode']) ?? '');
    if ($zip === '' && preg_match('/\b(9\d{4})\b/', $label, $m)) { $zip = $m[1]; }
    $addr = trim(($street !== '' ? $street : $label) . ($city !== '' ? ', ' . $city : '') . ($zip !== '' ? ' ' . $zip : ''), ', ');
    $legacy = (string)(sx($L, ['code']) ?? sx($ix, ['legacy']) ?? sx($P, ['propertyCode', 'code']) ?? '');
    // the FileMaker property code is the unit's import key ("dav001"); the Rentvine lease
    // code is "228240-dav001". Photos and staff know the short one.
    $unitName = (string)(sx($U, ['importSourceKey', 'name', 'unitName', 'number']) ?? sx($LU, ['name']) ?? sx($ix, ['unit', 'unit_ref']) ?? '');
    $pcode = $unitName !== '' ? $unitName : (str_contains($legacy, '-') ? substr($legacy, strrpos($legacy, '-') + 1) : $legacy);
    $multi = (string)(sx($P, ['isMultiUnit']) ?? '0') === '1';

    $start = sx_date($L, ['startDate', 'leaseStartDate', 'start_date']) ?? sx_date($ix, ['move_in', 'start_date']);
    $moveIn = sx_date($L, ['moveInDate', 'move_in_date']) ?? sx_date($ix, ['move_in']) ?? $start;
    $end = sx_date($L, ['endDate', 'leaseEndDate', 'end_date', 'expirationDate']);
    $openEnded = $end !== null && (int)substr($end, 0, 4) >= 2040;   // 2049-09-09 = no end
    if ($openEnded) { $end = null; }
    $moveOut = sx_date($L, ['moveOutDate', 'expectedMoveOutDate', 'moveOutUnitAvailabilityDate', 'move_out_date']);
    $notice = sx_date($L, ['noticeDate', 'noticeGivenDate']);
    $vacating = (string)(sx($L, ['isMarkedToVacate']) ?? '0') === '1';
    $closed = sx_date($L, ['closedDate']);
    $mtmFlag = sx($L, ['isMonthToMonth', 'monthToMonth', 'month_to_month']);
    $mtmStart = sx_date($L, ['monthToMonthStartDate']);
    $mtm = in_array(strtolower((string)$mtmFlag), ['1', 'true', 'yes'], true) || $mtmStart !== null || $openEnded;
    if (!$mtm && $mtmFlag === null && $end !== null && $end < date('Y-m-d')) { $mtm = true; }   // expired fixed term rolls to MTM
    $rent = sx_num($LU, ['rent']) ?? sx_num($U, ['rent']) ?? sx_num($L, ['rent', 'rentAmount', 'monthlyRent']);
    $deposit = sx_num($LU, ['deposit']) ?? sx_num($U, ['deposit']) ?? sx_num($L, ['securityDeposit', 'depositAmount', 'deposit']);
    $eligible = sx_date($L, ['increaseEligibilityDate']);
    // Rentvine moves increaseEligibilityDate a year out at each increase: the last increase is a year before it
    $lastInc = sx_date($L, ['lastRentIncreaseDate', 'lastIncreaseDate']) ?? ($eligible ? date('Y-m-d', strtotime($eligible . ' -1 year')) : null);
    $lastRenewal = sx_date($L, ['customFields.Last Renewal Date', 'lastRenewalDate']);
    if ($lastRenewal === null && isset($L['customFields']) && is_array($L['customFields'])) {
        foreach ($L['customFields'] as $cf) {
            if (!is_array($cf)) { continue; }
            $n = strtolower((string)sx($cf, ['name', 'label', 'fieldName'], ''));
            if (str_contains($n, 'renewal')) { $lastRenewal = sx_date($cf, ['value', 'fieldValue']); break; }
        }
    }
    $bed = sx_num($U, ['beds', 'bedrooms', 'bed']) ?? sx_num($LU, ['beds']);
    $fb = sx_num($U, ['fullBaths']) ?? sx_num($LU, ['fullBaths']); $hb = sx_num($U, ['halfBaths']) ?? sx_num($LU, ['halfBaths']);
    $bath = $fb !== null ? $fb + 0.5 * ($hb ?? 0) : sx_num($U, ['bathrooms', 'baths', 'bath']);
    $sqft = sx_num($U, ['size', 'squareFeet', 'sqft']) ?? sx_num($LU, ['size']);
    $park = sx($U, ['parking', 'parkingSpaces', 'parkingStalls']);
    $ptypeId = (string)(sx($P, ['propertyTypeID']) ?? '');
    $ptype = $ptypeId !== '' ? (RNW_PTYPES[$ptypeId] ?? ('type ' . $ptypeId)) : (string)(sx($P, ['propertyType', 'type']) ?? '');
    $tenantIx = (string)(sx($ix, ['tenant', 'tenant_names']) ?? '');
    if ($tenantIx === '' && isset($L['tenants']) && is_array($L['tenants'])) { $tenantIx = implode('/ ', array_filter(array_map(fn($t) => is_array($t) ? (string)sx($t, ['name', 'fullName'], '') : (string)$t, $L['tenants']))); }

    return [
        'lease_id'     => $id,
        'tenant'       => $tenantIx,
        'phone'        => (string)(sx($ix, ['phone', 'tenant_phones']) ?? ''),
        'email'        => (string)(sx($ix, ['email', 'tenant_emails']) ?? ''),
        'address'      => $addr,
        'unit'         => ($multi || ($unitName !== '' && $unitName !== $pcode)) ? $unitName : '',
        'property'     => $label !== '' ? $label : ($street !== '' ? $street : $pcode),
        'pcode'        => $pcode,
        'code'         => $legacy,
        'zip'          => $zip,
        'city'         => $city,
        'owner'        => (string)(sx($ix, ['owner']) ?? sx($O, ['name', 'fullName', 'displayName', 'companyName']) ?? ''),
        'portfolio'    => (string)(sx($ix, ['portfolio']) ?? ''),
        'property_id'  => $pid, 'unit_id' => $uid, 'owner_id' => $oid_, 'tenant_id' => $tid,
        'ptype'        => $ptype,
        'bed' => $bed, 'bath' => $bath, 'sqft' => $sqft, 'parking' => $park === null ? '' : (string)$park,
        'rent'         => $rent,
        'deposit'      => $deposit,
        'start'        => $start, 'move_in' => $moveIn, 'end' => $end, 'open_ended' => $openEnded,
        'move_out'     => $moveOut, 'notice' => $notice, 'vacating' => $vacating, 'closed' => $closed,
        'mtm'          => $mtm,
        'last_increase'=> $lastInc,
        'next_increase'=> $eligible,
        'last_renewal' => $lastRenewal,
        'active'       => sync_row_active($ix, $closed, $end) && (string)(sx($U, ['isVacant']) ?? '0') !== '1',
        'status_raw'   => (string)(sx($L, ['leaseStatusID', 'status']) ?? sx($ix, ['status']) ?? ''),
        'has_record'   => $rec !== [],
    ];
}

// Rentvine propertyTypeID -> label. Only the ids seen on the live mirror are
// mapped; unknown ids show as "type N" until confirmed (Settings can't change
// this yet - add rows here).
const RNW_PTYPES = [
    '2' => 'HOUSE',
];

function sync_row_active(array $ix, ?string $closed, ?string $end): bool {
    if ($closed !== null) { return false; }
    foreach (['active', 'is_active'] as $k) {
        if (array_key_exists($k, $ix)) { return (int)$ix[$k] === 1; }
    }
    $st = strtolower((string)($ix['status'] ?? ''));
    if ($st !== '') { return !in_array($st, ['inactive', 'ended', 'closed', 'past', 'terminated', 'cancelled', 'canceled'], true); }
    return true;
}

function sync_feeds_all(): array {
    return ['leases' => sync_feed('leases'), 'units' => sync_feed('units'),
            'properties' => sync_feed('properties'), 'owners' => sync_feed('owners')];
}

// every active lease in this office, joined
function leases_all(): array {
    static $out = null; static $for = 0;
    if ($out !== null && $for === oid()) { return $out; }
    $for = oid();
    $F = sync_feeds_all();
    $out = [];
    foreach (sync_index() as $ix) {
        $L = lease_join($ix, $F);
        if ($L['lease_id'] === '' || !$L['active']) { continue; }
        $out[$L['lease_id']] = $L;
    }
    return $out;
}

function lease_one(string $id): ?array {
    $all = leases_all();
    if (isset($all[$id])) { return $all[$id]; }
    foreach (sync_index() as $ix) {
        if ((string)sx($ix, ['external_id', 'id']) === $id) { return lease_join($ix, sync_feeds_all()); }
    }
    return null;
}

// same-building rent history: other active leases on the same property
function building_history(array $L): array {
    $rows = [];
    foreach (leases_all() as $x) {
        if ($x['lease_id'] === $L['lease_id']) { continue; }
        $same = ($L['property_id'] !== '' && $x['property_id'] === $L['property_id'])
             || ($L['pcode'] !== '' && $x['pcode'] === $L['pcode']);
        if (!$same) { continue; }
        $rows[] = $x;
    }
    usort($rows, fn($a, $b) => strnatcmp($a['unit'], $b['unit']));
    return $rows;
}

// ---------- SORT.CALC categories (computed, never stored; a queue
// row may override). Lower sorts first, like FileMaker.
const RNW_CATS = [
    -3 => 'ADDON',  -2 => 'DUEDATE>1', -1 => 'RNW SPEC',
     1 => 'MOVING OUT', 2 => 'NEW LEASE', 3 => 'REVISIT', 4 => 'OA',
     5 => 'NO INCREASE', 7 => 'FIXED', 8 => 'MTM',
];
function cat_label(int $c): string { return $c . ' ' . (RNW_CATS[$c] ?? '?'); }

function months_between(string $from, string $to): int {
    $a = new DateTime($from); $b = new DateTime($to);
    $d = $a->diff($b);
    return ($d->invert ? -1 : 1) * ($d->y * 12 + $d->m);
}

// The anchor for the MTM clock: the last increase. Best source first:
//   1. this app's own posted renewals (the previous cycle's rent start)
//   2. Rentvine's custom field Last Renewal Date (not mirrored yet)
//   3. Rentvine increaseEligibilityDate minus a year
//   4. move-in ("2 years+ since move in")
function increase_anchor(array $L, ?string $lastPosted): array {
    if ($lastPosted) { return [$lastPosted, 'last posted renewal']; }
    if ($L['last_renewal']) { return [$L['last_renewal'], 'Last Renewal Date']; }
    if ($L['last_increase']) { return [$L['last_increase'], 'Rentvine eligibility date - 1 yr']; }
    if ($L['move_in']) { return [$L['move_in'], 'move-in']; }
    return [null, 'no date'];
}

// THE PULL RULE (FileMaker 1.PREP, confirmed with Larry 2026-09-23): for the
// increase on the 1st of month C, run two months earlier.
//   FIXED  lease end between the 1st of C-1 and the 1st of C (inclusive)
//   MTM    end 2049 / month-to-month, last increase 24..25 months before C
//   ADDON  pulled by hand (a queue row with addon=1)
//   plus any lease that already has a decision row in this cycle
// Overdue MTM (>= 25 months) is a separate report, not the set.
// Returns [category, reason] or null when the lease is not in this cycle.
function queue_rule(array $L, ?array $Q, string $cycle, ?string $lastPosted = null, ?array $addon = null): ?array {
    $ci = cycle_info($cycle);
    $inc = $ci['increase'];
    $mtmMin = (int)knob('mtm_months'); $mtmMax = (int)knob('mtm_months_max');
    $firstY = (int)knob('first_year_months');

    // membership: the rule, or added by hand. A decision row alone never adds a lease.
    $cat = null; $why = '';
    if (!$L['mtm'] && $L['end'] !== null && $L['end'] >= $ci['fixed_from'] && $L['end'] <= $ci['fixed_to']) {
        $firstRenewal = $L['move_in'] && months_between($L['move_in'], $L['end']) <= $firstY;
        $cat = $firstRenewal ? 2 : 7; $why = 'lease end ' . $L['end'];
    } elseif ($L['mtm']) {
        [$anchor, $src] = increase_anchor($L, $lastPosted);
        if ($anchor) {
            $m = months_between($anchor, $inc);
            if ($m >= $mtmMin && $m < $mtmMax) { $cat = 8; $why = $m . ' mo since ' . $anchor . ' (' . $src . ')'; }
        }
    }
    if ($cat === null) {
        if ($addon === null) { return null; }
        $cat = -3; $why = 'added by hand ' . substr((string)($addon['added_at'] ?? ''), 0, 10) . ($addon['note'] ? ' - ' . $addon['note'] : '');
    }

    // flags on the decision row re-sort a row that IS in the set, FileMaker style
    if ($Q && $Q['category_override'] !== null) { return [(int)$Q['category_override'], 'set by hand']; }
    if ($L['move_out'] || $L['notice'] || !empty($L['vacating'])) { return [1, 'move-out ' . ($L['move_out'] ?? $L['notice'] ?? 'marked to vacate')]; }
    if ($Q && $Q['special'])     { return [-1, 'RNW spec']; }
    if ($Q && $Q['revisit'])     { return [3, 'revisit']; }
    if ($Q && $Q['oa'])          { return [4, 'owner approval']; }
    if ($Q && $Q['no_increase']) { return [5, 'no increase']; }
    return [$cat, $why];
}

// added by hand for one cycle, keyed by lease
function addons_for(string $cycle): array {
    $st = db()->prepare("SELECT * FROM renewal_addons WHERE office_id = ? AND cycle = ?");
    $st->execute([oid(), $cycle]);
    $out = [];
    foreach ($st as $r) { $out[$r['lease_id']] = $r; }
    return $out;
}

// overdue MTM: >= mtm_months_max since the last increase, not already in the cycle
function queue_overdue(string $cycle, array $inSet, array $lastPosted): array {
    $ci = cycle_info($cycle); $inc = $ci['increase'];
    $mtmMax = (int)knob('mtm_months_max');
    $out = [];
    foreach (leases_all() as $id => $L) {
        if (!$L['mtm'] || isset($inSet[$id])) { continue; }
        [$anchor, $src] = increase_anchor($L, $lastPosted[$id] ?? null);
        if (!$anchor) { continue; }
        $m = months_between($anchor, $inc);
        if ($m >= $mtmMax) { $out[] = ['lease' => $L, 'months' => $m, 'anchor' => $anchor, 'source' => $src]; }
    }
    usort($out, fn($a, $b) => [$b['months'], $a['lease']['pcode']] <=> [$a['months'], $b['lease']['pcode']]);
    return $out;
}

// decision rows for one cycle, keyed by lease
function queue_rows_for(string $cycle): array {
    $st = db()->prepare("SELECT * FROM renewal_decisions WHERE office_id = ? AND cycle = ?");
    $st->execute([oid(), $cycle]);
    $out = [];
    foreach ($st as $r) { $out[$r['lease_id']] = $r; }
    return $out;
}

// the last posted (or pau'd) rent start per lease from this app's own history
function last_posted_map(): array {
    $st = db()->prepare("SELECT lease_id, MAX(increase_date) d FROM renewal_decisions
                         WHERE office_id = ? AND status IN ('posted','pau') AND increase_date IS NOT NULL GROUP BY lease_id");
    $st->execute([oid()]);
    $out = [];
    foreach ($st as $r) { $out[$r['lease_id']] = $r['d']; }
    return $out;
}

// the set for a cycle: [{lease, cat, cat_label, reason, q, addon}], sorted category > zip > pcode.
// Decisions (q) are joined for display only; they never decide membership.
function queue_build(?string $cycle = null): array {
    $cycle = $cycle ?: cycle_default();
    $all = leases_all();
    $Q = queue_rows_for($cycle);
    $AD = addons_for($cycle);
    $LP = last_posted_map();
    $rows = [];
    foreach ($all as $id => $L) {
        $r = queue_rule($L, $Q[$id] ?? null, $cycle, $LP[$id] ?? null, $AD[$id] ?? null);
        if ($r === null) { continue; }
        [$cat, $why] = $r;
        $rows[] = ['lease' => $L, 'cat' => $cat, 'cat_label' => cat_label($cat), 'reason' => $why, 'q' => $Q[$id] ?? null, 'addon' => $AD[$id] ?? null];
    }
    // added by hand for a lease no longer active in the mirror still shows
    foreach ($AD as $id => $a) {
        if (isset($all[$id])) { continue; }
        $L = lease_one($id);
        if (!$L) { continue; }
        $rows[] = ['lease' => $L, 'cat' => -3, 'cat_label' => cat_label(-3), 'reason' => 'added by hand (lease inactive in mirror)', 'q' => $Q[$id] ?? null, 'addon' => $a];
    }
    usort($rows, function ($a, $b) {
        return [$a['cat'], $a['lease']['zip'], $a['lease']['pcode'], $a['lease']['unit']]
           <=> [$b['cat'], $b['lease']['zip'], $b['lease']['pcode'], $b['lease']['unit']];
    });
    return $rows;
}

// decisions saved for this cycle whose lease is NOT in the set (rule changed, addon
// removed, or opened directly): kept, shown under "Not pulled", never in the list
function queue_unpulled(string $cycle, array $setIds): array {
    $out = [];
    foreach (queue_rows_for($cycle) as $id => $q) {
        if (isset($setIds[$id])) { continue; }
        $L = lease_one($id);
        if (!$L) { continue; }
        $out[] = ['lease' => $L, 'cat' => 0, 'cat_label' => 'not pulled', 'reason' => 'decision saved, lease not in this set', 'q' => $q, 'addon' => null];
    }
    return $out;
}
