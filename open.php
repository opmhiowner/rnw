<?php
// ============================================================
// Renewal Center - one-click open from FileMaker (v.6)
//
//   open.php?key=<office's FileMaker push key>
//           [&lease=<Rentvine lease id>][&cycle=YYYY-MM]
//           [&win=main|prep|post|media|comps]
//
// The SEV Center pattern (sev/open.php): checks the key, and if the
// browser has no Hub session for that office signs it in as the
// office's configured Hub user (Settings > FileMaker link > "Links
// sign in as"), then lands on the window - Main on the lease when
// one is given. Same trust as the key itself; blank setting = go to
// the Hub login instead. A wrong or missing key never signs in.
// ============================================================
declare(strict_types=1);
require __DIR__ . '/lib/core.php';
schema_ensure();

$got = (string)($_GET['key'] ?? '');
$office = 0;
if ($got !== '') {
    foreach (rnw_offices() as $o) {
        rnw_set_office($o);
        $want = (string)setting('fm_push_key', '');
        if ($want !== '' && hash_equals($want, $got)) { $office = $o; break; }
    }
}

$base = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/rnw/open.php'), '/');   // "/rnw"
$win = strtolower(trim((string)($_GET['win'] ?? 'main')));
$lease = trim((string)($_GET['lease'] ?? ($_GET['id'] ?? '')));
$cycle = trim((string)($_GET['cycle'] ?? ''));
$qs = [];
if ($cycle !== '' && cycle_valid($cycle)) { $qs['cycle'] = $cycle; }
$page = ['prep' => '/prep.php', 'post' => '/post.php', 'media' => '/media.php', 'comps' => '/comps.php'][$win] ?? '/';
if ($page === '/' && $lease !== '') { $qs['lease'] = $lease; }
$dest = $base . $page . ($qs ? '?' . http_build_query($qs) : '');

if ($office < 1) { header('Location: /login?next=' . rawurlencode($dest)); exit; }
rnw_set_office($office);

rnw_core_auth();
$cur = function_exists('core_user') ? core_user() : null;
if (is_array($cur) && (int)($cur['office_id'] ?? 0) === $office) {
    header('Location: ' . $dest); exit;                        // already signed in for this office
}

$email = trim((string)setting('fm_login_user', ''));
if ($email === '') { header('Location: /login?next=' . rawurlencode($dest)); exit; }

// same session shape the Hub's core_login() builds; the office key stood in for the password
$pdo = db();
$q = $pdo->prepare("SELECT u.id, u.company_id, u.email, u.display_name, u.role, c.name AS company_name
                      FROM core_users u JOIN core_companies c ON c.id = u.company_id
                     WHERE u.email = ? AND u.status = 'active' AND c.status = 'active' LIMIT 1");
$q->execute([$email]);
$u = $q->fetch();
if (!$u) { http_response_code(500); exit('Renewal open: the configured Hub user was not found or is inactive (Settings > FileMaker link).'); }
$o = $pdo->prepare("SELECT o.id, o.code, o.name, o.timezone FROM core_user_offices uo
                      JOIN core_offices o ON o.id = uo.office_id
                     WHERE uo.user_id = ? AND o.status = 'active' ORDER BY o.id");
$o->execute([$u['id']]);
$offices = $o->fetchAll();
if (!array_filter($offices, fn($x) => (int)$x['id'] === $office)) {
    http_response_code(500); exit('Renewal open: that Hub user is not assigned to this office.');
}

if (function_exists('core_session_start')) { core_session_start(); } else { session_start(); }
session_regenerate_id(true);
$_SESSION['core'] = [
    'user_id'      => (int)$u['id'],
    'company_id'   => (int)$u['company_id'],
    'company_name' => $u['company_name'],
    'email'        => $u['email'],
    'display_name' => $u['display_name'],
    'role'         => $u['role'],
    'offices'      => $offices,
    'office_id'    => $office,
    'logged_in_at' => time(),
    'via'          => 'rnw-filemaker-link',
];
log_event(null, 'fm_link_login', ['actor' => (string)$u['email'], 'detail' => ['win' => $win, 'lease' => $lease, 'cycle' => $cycle]]);
header('Location: ' . $dest);
exit;
