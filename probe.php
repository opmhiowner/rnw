<?php
// ============================================================
// Renewal Center - probe: what does Sync Center actually hold?
// Shows one raw lease record (and its unit / property / owner) so
// the tolerant key lists in lib/sync.php can be confirmed against
// live data on first deploy. Signed-in users only. Read-only.
//   probe.php                 first active lease
//   probe.php?id=<lease id>   that lease
//   probe.php?queue=1         the computed queue with reasons
// ============================================================
declare(strict_types=1);
require __DIR__ . '/lib/core.php';
require __DIR__ . '/lib/sync.php';
schema_ensure();
$me = require_login();
header('Content-Type: text/plain; charset=utf-8');

echo "Renewal Center v", rnw_version(), " probe - office ", office_code(), " (", oid(), ") company ", cid(), "\n";
echo "Sync Center tables: ", sync_ready() ? 'present' : 'MISSING', "  last index update: ", sync_last_run() ?? '-', "\n";
if (!sync_ready()) { exit; }

$counts = [];
foreach (['leases', 'units', 'properties', 'owners', 'tenants'] as $f) { $counts[$f] = count(sync_feed($f)); }
echo "sync_records per feed: ", json_encode($counts), "   sync_leases index rows: ", count(sync_index()), "\n\n";

if (!empty($_GET['queue'])) {
    $rows = queue_build();
    echo "QUEUE (", count($rows), ")\n";
    foreach ($rows as $r) {
        $L = $r['lease'];
        printf("%-14s %-22s %-10s %-8s end %-10s rent %-8s  %s\n", $r['cat_label'], mb_substr($L['tenant'], 0, 22), $L['pcode'], $L['unit'], $L['end'] ?? '-', $L['rent'] ?? '-', $r['reason']);
    }
    exit;
}

$id = trim((string)($_GET['id'] ?? ''));
$ix = null;
foreach (sync_index() as $r) {
    if ($id === '' ? sync_row_active($r, null, null) : (string)sx($r, ['external_id', 'id']) === $id) { $ix = $r; break; }
}
if (!$ix) { echo "No lease", $id !== '' ? " $id" : '', " in the index.\n"; exit; }
$F = sync_feeds_all();
$L = lease_join($ix, $F);
echo "MAPPED (what the app uses):\n", json_encode($L, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n\n";
echo "INDEX ROW (sync_leases.raw + columns):\n", json_encode($ix, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n\n";
echo "RAW LEASE RECORD (sync_records feed=leases):\n", json_encode($F['leases'][$L['lease_id']] ?? null, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n\n";
echo "RAW UNIT:\n", json_encode($F['units'][$L['unit_id']] ?? null, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n\n";
echo "RAW PROPERTY:\n", json_encode($F['properties'][$L['property_id']] ?? null, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n\n";
echo "RAW OWNER:\n", json_encode($F['owners'][$L['owner_id']] ?? null, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
echo "\nIf rent / end / deposit / mtm above are null or wrong, add the real key names to lib/sync.php lease_join().\n";
