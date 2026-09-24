<?php
// Renewal Center - the FileMaker tables loaded into oishi-db (fmp_*), read
// and written by property code. fmp_renewals is the renewal file's own
// record per property (inspection, IA / OA, remarks, ASD, the X box).
// Tolerant: if the table is missing or a column is not there, the card on
// Main just hides. Column list is read live (SHOW COLUMNS) so an import that
// adds fields needs no code change.
declare(strict_types=1);

function fmp_columns(string $table): array {
    static $cache = [];
    if (isset($cache[$table])) { return $cache[$table]; }
    $cols = [];
    try {
        foreach (db()->query("SHOW COLUMNS FROM `" . str_replace('`', '', $table) . "`") as $r) { $cols[] = $r['Field']; }
    } catch (Throwable $e) { /* not on this server */ }
    return $cache[$table] = $cols;
}
function fmp_ready(): bool { return in_array('property_code', fmp_columns('fmp_renewals'), true); }

// the columns Main shows and may edit, in display order: key => [label, kind]
function fmp_renewal_fields(): array {
    return [
        'rnw_insp_date'          => ['Last inspected', 'date'],
        'rnw_insp_by'            => ['Inspected by', 'text'],
        'rnw_insp_time'          => ['Time', 'text'],
        'rnw_insp_type'          => ['Inspection type', 'text'],
        'rnw_insp_aft_p_grade'   => ['Property grade', 'text'],
        'rnw_insp_aft_t_grade'   => ['Tenant grade', 'text'],
        'rnw_insp_aft_rec_rent'  => ['Recommended rent (inspection)', 'text'],
        'rnw_insp_aft_timestamp' => ['Inspection stamped', 'ro'],
        'renew_yrs'              => ['Lease yrs', 'num'],
        'rnw_asd'                => ['ASD', 'num'],
        'rnw_x_box'              => ['X box', 'text'],
        'renew_ia_date'          => ['IA date', 'date'],
        'renew_ia_dropdwn'       => ['IA', 'text'],
        'renew_ia_story'         => ['IA story', 'long'],
        'renew_oa_date'          => ['OA date', 'date'],
        'renew_oa_dropdwn'       => ['OA', 'text'],
        'renew_oa_story'         => ['OA story', 'long'],
        'rnw_remarks'            => ['Remarks', 'long'],
        'imported_at'            => ['Imported from FileMaker', 'ro'],
    ];
}

function fmp_renewal(string $pcode): ?array {
    if ($pcode === '' || !fmp_ready()) { return null; }
    $st = db()->prepare("SELECT * FROM fmp_renewals WHERE office_id = ? AND LOWER(property_code) = LOWER(?) ORDER BY id DESC LIMIT 1");
    $st->execute([oid(), $pcode]);
    $r = $st->fetch();
    return $r ?: null;
}

// write the editable fields; creates the property's row when FileMaker never had one
function fmp_renewal_save(string $pcode, array $fields): array {
    if ($pcode === '' || !fmp_ready()) { return ['ok' => false, 'error' => 'fmp_renewals is not on this server.']; }
    $have = fmp_columns('fmp_renewals');
    $allowed = fmp_renewal_fields();
    $set = []; $vals = [];
    foreach ($fields as $k => $v) {
        if (!isset($allowed[$k]) || $allowed[$k][1] === 'ro' || !in_array($k, $have, true)) { continue; }
        $v = is_string($v) ? trim($v) : $v;
        if ($v === '' || $v === null) { $v = null; }
        elseif ($allowed[$k][1] === 'date') { $ts = strtotime((string)$v); $v = $ts ? date('Y-m-d', $ts) : null; }
        elseif ($allowed[$k][1] === 'num') { $v = is_numeric($v) ? (float)$v : null; }
        $set[] = "`$k` = ?"; $vals[] = $v;
    }
    if (!$set) { return ['ok' => true, 'changed' => 0]; }
    $pdo = db();
    $cur = fmp_renewal($pcode);
    try {
        if ($cur) {
            $vals[] = (int)$cur['id'];
            $pdo->prepare("UPDATE fmp_renewals SET " . implode(', ', $set) . " WHERE id = ?")->execute($vals);
        } else {
            $cols = ['company_id', 'office_id', 'property_code'];
            $ins = [cid(), oid(), $pcode];
            foreach ($set as $i => $s) { $cols[] = trim(explode('=', $s)[0]); $ins[] = $vals[$i]; }
            $pdo->prepare("INSERT INTO fmp_renewals (" . implode(', ', $cols) . ") VALUES (" . implode(', ', array_fill(0, count($cols), '?')) . ")")->execute($ins);
        }
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => 'FileMaker table: ' . $e->getMessage()];
    }
    return ['ok' => true, 'changed' => count($set)];
}
