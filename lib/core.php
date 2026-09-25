<?php
// ============================================================
// Renewal Center - core  (v0.1, one-copy build)
//
// Loaded by every entry point. Handles the Hub session, the shared
// oishi-db connection, the self-healing schema, office context,
// per-office settings and the event log. Same shape as SEV Center's
// lib/core.php so anyone who knows one app knows both.
//
// One copy serves every office. The office comes from the Hub
// session - never from the URL. Every renewal_* row carries
// company_id + office_id. "tenant" is only ever a renter.
//
// The schema applies itself on load (CREATE TABLE IF NOT EXISTS +
// SHOW COLUMNS/ALTER), so a deploy is always just "git pull".
// ============================================================

declare(strict_types=1);

if (!defined('RNW_ROOT')) {
    define('RNW_ROOT', dirname(__DIR__));
}

// Revision counter, bumped by one every release (SEV / Action Inbox
// scheme): v.1 ... v.99, then v1.00.
const RNW_REV = 27;
// Cache-buster for app.css / app.js: the file's own mtime, so a browser never keeps an old
// stylesheet after a deploy even when the release counter above was not bumped.
function asset_v(string $rel): string {
    $f = RNW_ROOT . '/' . $rel;
    return RNW_REV . '.' . (is_file($f) ? (string)filemtime($f) : '0');
}
function rnw_version(): string {
    $r = RNW_REV;
    if ($r < 100) { return '.' . $r; }
    return intdiv($r, 100) . '.' . str_pad((string)($r % 100), 2, '0', STR_PAD_LEFT);
}

ini_set('display_errors', '0');
error_reporting(E_ALL);
date_default_timezone_set('Pacific/Honolulu');   // until an office is set

// ---------- portfolio paths (Rev 2 pattern)
// /var/www/apps/rnw    this clone
// /var/www/apps/core       Hub session library (symlink to hub/core)
// /var/www/apps/config     per-server DB bootstrap + sync.php, never in git
function rnw_apps_root(): string { return dirname(RNW_ROOT); }

function rnw_first_file(array $candidates): ?string {
    foreach ($candidates as $c) { if (is_file($c)) { return $c; } }
    return null;
}

// ---------- office context
const RNW_OFFICE_CODES = [1 => 'HI', 2 => 'LV'];
const RNW_OFFICE_DEFAULTS = [
    1 => ['timezone' => 'Pacific/Honolulu',    'region_label' => 'Hawaii'],
    2 => ['timezone' => 'America/Los_Angeles', 'region_label' => 'Las Vegas'],
];

function rnw_office_state(?int $set = null, ?int $company = null, ?array $hub = null): array {
    static $st = ['office' => 0, 'company' => 1, 'hub' => []];
    if ($set !== null)     { $st['office']  = $set; }
    if ($company !== null) { $st['company'] = $company; }
    if ($hub !== null)     { $st['hub'] = $hub; }
    return $st;
}

function oid(): int {
    $o = (int)rnw_office_state()['office'];
    if ($o < 1) {
        http_response_code(500);
        exit('Renewal: no office in context.');
    }
    return $o;
}
function cid(): int { return (int)rnw_office_state()['company']; }
function office_code(?int $office = null): string {
    $o = $office ?? oid();
    $hub = rnw_office_state()['hub'] ?? [];
    if ($o === oid() && !empty($hub['office_code'])) { return strtoupper((string)$hub['office_code']); }
    return RNW_OFFICE_CODES[$o] ?? ('O' . $o);
}

function rnw_office_defaults(int $office): array {
    $hub = rnw_office_state()['hub'] ?? [];
    $d = RNW_OFFICE_DEFAULTS[$office] ?? ['timezone' => 'Pacific/Honolulu', 'region_label' => 'Office ' . $office];
    if (!empty($hub['timezone']))    { $d['timezone'] = (string)$hub['timezone']; }
    if (!empty($hub['office_name'])) { $d['region_label'] = (string)$hub['office_name']; }
    return $d;
}

function rnw_set_office(int $office, int $company = 1, array $hub = []): void {
    if ($office < 1) { return; }
    rnw_office_state($office, $company, $hub ?: null);
    setting_cache_reset();
    date_default_timezone_set((string)setting('timezone', rnw_office_defaults($office)['timezone']));
    try { db()->exec("SET time_zone = '" . date('P') . "'"); } catch (Throwable $e) {}
}

function rnw_offices(): array {
    $ids = array_keys(RNW_OFFICE_DEFAULTS);
    try {
        foreach (db()->query("SELECT DISTINCT office_id FROM renewal_settings") as $r) { $ids[] = (int)$r['office_id']; }
    } catch (Throwable $e) { /* first run */ }
    $ids = array_values(array_unique(array_filter($ids, fn($i) => $i > 0)));
    sort($ids);
    return $ids;
}

// ---------- database: the per-server bootstrap at
// /var/www/apps/config/db.php (never in git). Tolerant of the
// bootstrap's shape - a PDO, an array of credentials, DB_* constants,
// or a connector function - and prefers the Hub's own core_pdo().
function db(): PDO {
    static $pdo = null;
    if ($pdo instanceof PDO) { return $pdo; }
    $opts = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];
    $boot = rnw_first_file([rnw_apps_root() . '/config/db.php', '/var/www/apps/config/db.php']);
    if ($boot === null) {
        http_response_code(500);
        exit('Renewal: /var/www/apps/config/db.php is missing.');
    }
    try {
        $authLib = rnw_first_file([rnw_apps_root() . '/core/auth.php', '/var/www/apps/core/auth.php']);
        if ($authLib !== null) {
            require_once $authLib;
            if (function_exists('core_pdo')) { $r = core_pdo(); if ($r instanceof PDO) { $pdo = $r; } }
        }
        $ret = $pdo instanceof PDO ? null : require $boot;
        if ($ret instanceof PDO) {
            $pdo = $ret;
        } elseif (is_callable($ret)) {
            $pdo = $ret();
        } else {
            foreach (['core_db', 'db_connect', 'get_pdo', 'hub_db', 'pdo'] as $fn) {
                if (function_exists($fn)) { $r = $fn(); if ($r instanceof PDO) { $pdo = $r; break; } }
            }
        }
        if (!$pdo instanceof PDO) {
            $c = is_array($ret) ? $ret : [];
            $g = function (array $keys, $d = '') use ($c) {
                foreach ($keys as $k) {
                    if (isset($c[$k]) && $c[$k] !== '') { return (string)$c[$k]; }
                    if (defined($k)) { return (string)constant($k); }
                }
                return $d;
            };
            $host = $g(['host', 'db_host', 'DB_HOST'], 'localhost');
            $port = $g(['port', 'db_port', 'DB_PORT'], '');
            $sock = $g(['unix_socket', 'socket', 'DB_SOCKET'], '');
            $name = $g(['name', 'dbname', 'db_name', 'database', 'DB_NAME']);
            $user = $g(['user', 'username', 'db_user', 'DB_USER']);
            $pass = $g(['pass', 'password', 'db_pass', 'DB_PASS']);
            $ca   = $g(['ssl_ca', 'ca', 'DB_SSL_CA'], '');
            $dsn = $sock !== ''
                 ? sprintf('mysql:unix_socket=%s;dbname=%s;charset=utf8mb4', $sock, $name)
                 : sprintf('mysql:host=%s%s;dbname=%s;charset=utf8mb4', $host,
                           $port !== '' ? ';port=' . $port : '', $name);
            $opts[PDO::ATTR_TIMEOUT] = 3;
            if ($ca !== '' && is_file($ca)) { $opts[PDO::MYSQL_ATTR_SSL_CA] = $ca; }
            // managed MySQL (DigitalOcean) requires TLS; same setting the Hub uses
            if (!in_array($host, ['localhost', '127.0.0.1'], true) && defined('PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT')) {
                $opts[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = false;
            }
            $pdo = new PDO($dsn, $user, $pass, $opts);
        }
        foreach ($opts as $k => $v) { @$pdo->setAttribute($k, $v); }
        $pdo->exec("SET time_zone = '" . date('P') . "'");
    } catch (Throwable $e) {
        http_response_code(500);
        exit('Database connection failed. Check /var/www/apps/config/db.php.'
             . (PHP_SAPI === 'cli' ? ' (' . $e->getMessage() . ')' : '') . "\n");
    }
    return $pdo;
}

// ---------- schema: self-healing, additive only
function schema_ensure(): void {
    static $done = false;
    if ($done) { return; }
    $done = true;
    try {
        schema_apply();
    } catch (Throwable $e) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
        exit("Renewal: schema step failed - " . $e->getMessage() . "\n(the tables build themselves on load; this is the SQL the database refused)\n");
    }
}

function schema_apply(): void {
    $pdo = db();

    $tables = [];

    $tables['renewal_settings'] = "
        CREATE TABLE IF NOT EXISTS renewal_settings (
          company_id INT UNSIGNED NOT NULL DEFAULT 1,
          office_id INT UNSIGNED NOT NULL DEFAULT 1,
          skey VARCHAR(64) NOT NULL,
          sval TEXT NULL,
          updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          PRIMARY KEY (office_id, skey),
          KEY idx_company (company_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    // DECISIONS: one row per lease per cycle - new rent, deposit
    // increase, flags, notes, Rentvine step ids. A decision row does
    // NOT put a lease in the set: the set is the pull rule plus
    // renewal_addons. The lease_* columns are a snapshot of what Sync
    // Center said when the row was opened, so a decision is explainable
    // later even after Rentvine changes. (v0.2 renamed from renewal_decisions.)
    $tables['renewal_decisions'] = "
        CREATE TABLE IF NOT EXISTS renewal_decisions (
          id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
          company_id INT UNSIGNED NOT NULL DEFAULT 1,
          office_id INT UNSIGNED NOT NULL DEFAULT 1,
          lease_id VARCHAR(64) NOT NULL,
          cycle VARCHAR(7) NOT NULL,
          status ENUM('open','pau','posted') NOT NULL DEFAULT 'open',
          category_override SMALLINT NULL,
          special TINYINT(1) NOT NULL DEFAULT 0,
          oa TINYINT(1) NOT NULL DEFAULT 0,
          revisit TINYINT(1) NOT NULL DEFAULT 0,
          no_increase TINYINT(1) NOT NULL DEFAULT 0,
          lease_tenant VARCHAR(255) NULL,
          lease_property VARCHAR(255) NULL,
          lease_unit VARCHAR(60) NULL,
          lease_pcode VARCHAR(40) NULL,
          lease_zip VARCHAR(12) NULL,
          lease_rent DECIMAL(10,2) NULL,
          lease_deposit DECIMAL(10,2) NULL,
          lease_start DATE NULL,
          lease_end DATE NULL,
          lease_mtm TINYINT(1) NOT NULL DEFAULT 0,
          current_rent DECIMAL(10,2) NULL,
          new_rent DECIMAL(10,2) NULL,
          pct_inc DECIMAL(6,2) NULL,
          step_pct DECIMAL(5,2) NULL,
          increase_date DATE NULL,
          current_deposit DECIMAL(10,2) NULL,
          new_deposit DECIMAL(10,2) NULL,
          sdr_delta DECIMAL(10,2) NULL,
          range_top DECIMAL(10,2) NULL,
          range_bottom DECIMAL(10,2) NULL,
          eval_top VARCHAR(120) NULL,
          eval_recom VARCHAR(120) NULL,
          eval_bottom VARCHAR(120) NULL,
          notes TEXT NULL,
          vaoao TEXT NULL,
          pinned_comps JSON NULL,
          comp_median DECIMAL(10,2) NULL,
          printed_at DATETIME NULL,
          printed_by VARCHAR(64) NULL,
          pau_at DATETIME NULL,
          pau_by VARCHAR(64) NULL,
          rv_old_charge_id VARCHAR(64) NULL,
          rv_old_charge_expired_at DATETIME NULL,
          rv_new_charge_id VARCHAR(64) NULL,
          rv_sdr_charge_id VARCHAR(64) NULL,
          rv_custom_field_at DATETIME NULL,
          posted_at DATETIME NULL,
          posted_by VARCHAR(64) NULL,
          decided_by VARCHAR(64) NULL,
          decided_at DATETIME NULL,
          created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
          updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          UNIQUE KEY uq_lease_cycle (office_id, lease_id, cycle),
          KEY idx_office_status (office_id, status),
          KEY idx_company (company_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    $tables['renewal_events'] = "
        CREATE TABLE IF NOT EXISTS renewal_events (
          id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
          company_id INT UNSIGNED NOT NULL DEFAULT 1,
          office_id INT UNSIGNED NOT NULL DEFAULT 1,
          queue_id INT UNSIGNED NULL,
          lease_id VARCHAR(64) NULL,
          event VARCHAR(40) NOT NULL,
          actor VARCHAR(64) NULL,
          detail TEXT NULL,
          created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
          KEY idx_queue (queue_id),
          KEY idx_office_time (office_id, created_at),
          KEY idx_company (company_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    // Per property (pcode): which slot is the cover, the slot order
    // and the listing description. The files live on the office
    // network drive and are served by the localhost photo agent.
    $tables['renewal_media'] = "
        CREATE TABLE IF NOT EXISTS renewal_media (
          company_id INT UNSIGNED NOT NULL DEFAULT 1,
          office_id INT UNSIGNED NOT NULL DEFAULT 1,
          pcode VARCHAR(40) NOT NULL,
          cover VARCHAR(255) NULL,
          slot_order JSON NULL,
          description TEXT NULL,
          updated_by VARCHAR(64) NULL,
          updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          PRIMARY KEY (office_id, pcode),
          KEY idx_company (company_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    // ADDED BY HAND: the only way into the set besides the pull rule.
    $tables['renewal_addons'] = "
        CREATE TABLE IF NOT EXISTS renewal_addons (
          company_id INT UNSIGNED NOT NULL DEFAULT 1,
          office_id INT UNSIGNED NOT NULL DEFAULT 1,
          cycle VARCHAR(7) NOT NULL,
          lease_id VARCHAR(64) NOT NULL,
          note VARCHAR(255) NULL,
          added_by VARCHAR(64) NULL,
          added_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (office_id, cycle, lease_id),
          KEY idx_company (company_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    // One row per increase month per office: the "set". Finalized = made permanent
    // (FileMaker "Make permanent record"); rows in a finalized cycle are read-only.
    $tables['renewal_cycles'] = "
        CREATE TABLE IF NOT EXISTS renewal_cycles (
          company_id INT UNSIGNED NOT NULL DEFAULT 1,
          office_id INT UNSIGNED NOT NULL DEFAULT 1,
          cycle VARCHAR(7) NOT NULL,
          finalized_at DATETIME NULL,
          finalized_by VARCHAR(64) NULL,
          letters_at DATETIME NULL,
          letters_by VARCHAR(64) NULL,
          notes TEXT NULL,
          PRIMARY KEY (office_id, cycle),
          KEY idx_company (company_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    // Per property, forever (FileMaker "Renewal Special", VAOAO / building, colour):
    // comes back every cycle the property is in.
    $tables['renewal_property'] = "
        CREATE TABLE IF NOT EXISTS renewal_property (
          company_id INT UNSIGNED NOT NULL DEFAULT 1,
          office_id INT UNSIGNED NOT NULL DEFAULT 1,
          pcode VARCHAR(40) NOT NULL,
          special TEXT NULL,
          vaoao VARCHAR(160) NULL,
          color VARCHAR(16) NULL,
          updated_by VARCHAR(64) NULL,
          updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          PRIMARY KEY (office_id, pcode),
          KEY idx_company (company_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    // Craigslist results per unit, kept 7 days.
    $tables['renewal_comps_cache'] = "
        CREATE TABLE IF NOT EXISTS renewal_comps_cache (
          company_id INT UNSIGNED NOT NULL DEFAULT 1,
          office_id INT UNSIGNED NOT NULL DEFAULT 1,
          ckey VARCHAR(64) NOT NULL,
          url TEXT NULL,
          results JSON NULL,
          fetched_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (office_id, ckey),
          KEY idx_company (company_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    // v0.2: renewal_queue -> renewal_decisions (keep the rows), addon flags -> renewal_addons
    $have = [];
    foreach ($pdo->query("SHOW TABLES") as $r) { $have[strtolower((string)reset($r))] = true; }
    if (isset($have['renewal_queue']) && !isset($have['renewal_decisions'])) {
        try {
            $pdo->exec("RENAME TABLE renewal_queue TO renewal_decisions");
        } catch (Throwable $e) {
            // managed MySQL users often lack DROP (which RENAME needs): copy instead, leave the old table
            $pdo->exec("CREATE TABLE renewal_decisions LIKE renewal_queue");
            $pdo->exec("INSERT INTO renewal_decisions SELECT * FROM renewal_queue");
        }
    }
    foreach ($tables as $sql) { $pdo->exec($sql); }


    // columns added after v0.1
    $addcols = [];
    $addcols['renewal_decisions']['rv_day_due'] = "ALTER TABLE renewal_decisions ADD COLUMN rv_day_due SMALLINT NULL AFTER rv_old_charge_id";
    $addcols['renewal_decisions']['addon']      = "ALTER TABLE renewal_decisions ADD COLUMN addon TINYINT(1) NOT NULL DEFAULT 0 AFTER special";
    $addcols['renewal_decisions']['remarks']    = "ALTER TABLE renewal_decisions ADD COLUMN remarks VARCHAR(255) NULL AFTER notes";
    $addcols['renewal_decisions']['rv_verified_at'] = "ALTER TABLE renewal_decisions ADD COLUMN rv_verified_at DATETIME NULL";
    $addcols['renewal_decisions']['rv_verify_ok']   = "ALTER TABLE renewal_decisions ADD COLUMN rv_verify_ok TINYINT(1) NULL";
    $addcols['renewal_decisions']['rv_verify_note'] = "ALTER TABLE renewal_decisions ADD COLUMN rv_verify_note VARCHAR(500) NULL";
    $addcols['renewal_decisions']['rent_source']    = "ALTER TABLE renewal_decisions ADD COLUMN rent_source VARCHAR(16) NULL";
    $addcols['renewal_decisions']['deposit_source'] = "ALTER TABLE renewal_decisions ADD COLUMN deposit_source VARCHAR(16) NULL";
    $addcols['renewal_decisions']['rent_checked_at'] = "ALTER TABLE renewal_decisions ADD COLUMN rent_checked_at DATETIME NULL";
    foreach ($addcols as $table => $cols) {
        $have = [];
        foreach ($pdo->query("SHOW COLUMNS FROM `$table`") as $r) { $have[$r['Field']] = true; }
        foreach ($cols as $col => $sql) { if (!isset($have[$col])) { $pdo->exec($sql); } }
    }
    // rows added by hand before v0.2 kept their flag on the decision row: carry them over once
    $pdo->exec("INSERT IGNORE INTO renewal_addons (company_id, office_id, cycle, lease_id, added_by, added_at)
                SELECT company_id, office_id, cycle, lease_id, decided_by, created_at FROM renewal_decisions WHERE addon = 1");
}

// ---------- per-office settings
function setting_cache_reset(): void { setting('', null, true); }
function setting(string $key, $default = null, bool $reset = false) {
    static $cache = null;
    if ($reset) { $cache = null; return null; }
    if ($cache === null) {
        $cache = [];
        $st = db()->prepare("SELECT skey, sval FROM renewal_settings WHERE office_id = ?");
        $st->execute([oid()]);
        foreach ($st as $r) { $cache[$r['skey']] = $r['sval']; }
    }
    return $cache[$key] ?? $default;
}
function setting_put(string $key, ?string $val): void {
    if ($val === null || $val === '') {
        db()->prepare("DELETE FROM renewal_settings WHERE office_id = ? AND skey = ?")->execute([oid(), $key]);
    } else {
        db()->prepare("INSERT INTO renewal_settings (company_id, office_id, skey, sval) VALUES (?, ?, ?, ?)
                       ON DUPLICATE KEY UPDATE sval = VALUES(sval), company_id = VALUES(company_id)")
            ->execute([cid(), oid(), $key, $val]);
    }
    setting_cache_reset();
}

// The knobs the queue rules and the rent card use, with defaults.
function rnw_defaults(): array {
    return [
        'cycle_offset'       => '2',     // run in month M for the increase on the 1st of M+2 ("pull December in October")
        'letters_day'        => '11',    // letters out by the 11th of the run month (45-day notice, HRS 521-21)
        'mtm_months'         => '24',    // MTM: last increase this many months before the increase date ("every 2 years")
        'mtm_months_max'     => '25',    // ... and less than this; beyond it = overdue report, added by hand
        'first_year_months'  => '13',    // NEW LEASE = fixed lease ending within this of move-in (first renewal)
        'rent_step_dollars'  => '25',    // < > arrows
        'steps'              => '2,4,6,8',
        'deposit_rule'       => 'match_rent',   // new deposit = new rent
        'display_mode'       => '0',     // 135 % type scale: off by default (on for the meeting-room TVs, Settings)
        'display_scale'      => '1.35',
        'main_scale'         => '1.15',  // Main window alone, when display mode is off
        'cl_site'            => 'honolulu',
        'cl_area'            => 'oah',
        'cl_miles'           => '1',
    ];
}
function knob(string $key) {
    return setting($key, rnw_defaults()[$key] ?? null);
}

// ---------- session and auth: the Hub owns login.
// /var/www/apps/core/auth.php (symlink to hub/core) provides
// core_require_login() / core_user() with user_id, company_id, email,
// display_name, role, offices[], office_id, office_code, office_name,
// timezone. This app keeps no password of its own.
function rnw_core_auth(): void {
    static $loaded = false;
    if ($loaded) { return; }
    $loaded = true;
    $f = rnw_first_file([rnw_apps_root() . '/core/auth.php', '/var/www/apps/core/auth.php']);
    if ($f === null) {
        http_response_code(500);
        exit('Renewal: /var/www/apps/core/auth.php is missing (Hub session library).');
    }
    require_once $f;
}

function rnw_user_from_core($u): ?array {
    if (!is_array($u) || !$u) { return null; }
    $g = function (array $keys, $d = null) use ($u) {
        foreach ($keys as $k) {
            if (isset($u[$k]) && $u[$k] !== '' && $u[$k] !== null) { return $u[$k]; }
        }
        return $d;
    };
    $email = (string)$g(['email', 'username', 'login'], '');
    $key = (string)$g(['username', 'login', 'handle'],
                      $email !== '' ? strstr($email, '@', true) ?: $email : 'u' . $g(['user_id', 'id'], '0'));
    $name = (string)$g(['display_name', 'name', 'full_name', 'first_name'], $key);
    $office = (int)$g(['office_id', 'current_office_id', 'office'], 0);
    $offs = $g(['offices'], []);
    if ($office < 1 && is_array($offs) && $offs) {
        $first = reset($offs);
        $office = is_array($first) ? (int)($first['office_id'] ?? $first['id'] ?? 0) : (int)$first;
    }
    $list = [];
    foreach ((array)$offs as $o) {
        if (is_array($o)) {
            $list[] = ['id' => (int)($o['office_id'] ?? $o['id'] ?? 0),
                       'code' => strtoupper((string)($o['code'] ?? $o['office_code'] ?? '')),
                       'label' => (string)($o['name'] ?? $o['office_name'] ?? $o['code'] ?? '')];
        } elseif ((int)$o > 0) {
            $list[] = ['id' => (int)$o, 'code' => RNW_OFFICE_CODES[(int)$o] ?? '', 'label' => RNW_OFFICE_DEFAULTS[(int)$o]['region_label'] ?? ''];
        }
    }
    return [
        'key'        => $key,
        'name'       => $name,
        'user_id'    => (int)$g(['user_id', 'id'], 0),
        'company_id' => (int)$g(['company_id'], 1),
        'office_id'  => $office,
        'role'       => (string)$g(['role'], ''),
        'offices'    => $list,
        'hub'        => ['timezone' => (string)$g(['timezone'], ''), 'office_name' => (string)$g(['office_name'], ''),
                         'office_code' => (string)$g(['office_code'], '')],
    ];
}

function current_user(): ?array {
    static $me = false;
    if ($me !== false) { return $me; }
    if (PHP_SAPI === 'cli') { return $me = null; }
    rnw_core_auth();
    $raw = function_exists('core_user') ? core_user() : null;
    $me = rnw_user_from_core($raw);
    if ($me && $me['office_id'] > 0) { rnw_set_office($me['office_id'], $me['company_id'] ?: 1, $me['hub']); }
    return $me;
}

function require_login(): array {
    $isApi = str_contains($_SERVER['REQUEST_URI'] ?? '', '/api/');
    $u = current_user();
    if (!$u) {
        rnw_core_auth();
        if ($isApi) {
            header('Content-Type: application/json');
            http_response_code(200);
            exit(json_encode(['ok' => false, 'error' => 'Not signed in.', 'code' => 'auth']));
        }
        if (function_exists('core_require_login')) {
            $u = rnw_user_from_core(core_require_login());   // redirects to the Hub when signed out
        }
        if (!$u) { header('Location: /login'); exit; }
        if ($u['office_id'] > 0) { rnw_set_office($u['office_id'], $u['company_id'] ?: 1, $u['hub']); }
    }
    if ($u['office_id'] < 1) {
        http_response_code(403);
        exit('Your Hub account has no office assigned.');
    }
    return $u;
}

// office switch via the Hub (?office=HI) - validated against the
// user's own office list, the way Sync Center does it
function switch_office_code(string $code, array $me): bool {
    foreach ($me['offices'] as $o) {
        if ($o['code'] === strtoupper($code) && $o['id'] > 0) {
            if (function_exists('core_set_office')) { core_set_office($o['id']); }
            rnw_set_office($o['id'], $me['company_id'] ?: 1, ['office_code' => $o['code'], 'office_name' => $o['label']]);
            return true;
        }
    }
    return false;
}

function hub_logout_url(): string {
    return function_exists('core_logout_url') ? (string)core_logout_url() : '/login';
}

// ---------- events
function log_event(?int $queueId, string $event, array $opt = []): void {
    $u = current_user();
    $detail = $opt['detail'] ?? null;
    if (is_array($detail)) { $detail = json_encode($detail, JSON_UNESCAPED_SLASHES); }
    db()->prepare(
        "INSERT INTO renewal_events (company_id, office_id, queue_id, lease_id, event, actor, detail)
         VALUES (?, ?, ?, ?, ?, ?, ?)")
        ->execute([cid(), oid(), $queueId, $opt['lease_id'] ?? null, $event,
                   $opt['actor'] ?? ($u['key'] ?? 'system'), $detail]);
}

// ---------- helpers
function e(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function json_out(array $p): void {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    http_response_code(200);
    echo json_encode($p, JSON_UNESCAPED_SLASHES);
    exit;
}
function body_json(): array {
    $raw = file_get_contents('php://input');
    $d = json_decode((string)$raw, true);
    return is_array($d) ? $d : [];
}
function money(?float $v): string { return $v === null ? '' : number_format($v, 0); }
// ---------- cycles: the increase month. Run in month M for the 1st of M+offset.
// "This run": the increase month whose letters deadline is still ahead. On the 1st..11th
// of M the run is M (increase M+2); after the 11th, M's letters are out and the run is M+1
// (increase M+3). Sep 23 -> December, as FileMaker shows.
function cycle_default(): string {
    $day = (int)date('j');
    $runShift = $day > (int)knob('letters_day') ? 1 : 0;
    return date('Y-m', strtotime(date('Y-m-01') . ' +' . ((int)knob('cycle_offset') + $runShift) . ' months'));
}
function cycle_now(): string { return cycle_default(); }
function cycle_valid(string $c): bool { return (bool)preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $c); }
function cycle_info(string $cycle): array {
    $inc = $cycle . '-01';
    $run = date('Y-m-01', strtotime($inc . ' -' . (int)knob('cycle_offset') . ' months'));
    // FIXED window: anniversaries from the 2nd of the month before through the 1st of the
    // increase month (signed up 11/02..12/01 -> increase 12/01). A lease ending on the 1st
    // belongs to THAT month's set, never the next.
    $winStart = date('Y-m-02', strtotime($inc . ' -1 month'));
    return [
        'cycle' => $cycle, 'increase' => $inc, 'label' => date('F Y', strtotime($inc)),
        'run_month' => substr($run, 0, 7),
        'letters_by' => date('Y-m-d', strtotime($run . ' +' . ((int)knob('letters_day') - 1) . ' days')),
        'upload_month' => date('F', strtotime($inc . ' -1 month')),
        'fixed_from' => $winStart, 'fixed_to' => $inc,
        'prev' => date('Y-m', strtotime($inc . ' -1 month')), 'next' => date('Y-m', strtotime($inc . ' +1 month')),
    ];
}
function cycle_row(string $cycle): array {
    $st = db()->prepare("SELECT * FROM renewal_cycles WHERE office_id = ? AND cycle = ?");
    $st->execute([oid(), $cycle]);
    return $st->fetch() ?: ['cycle' => $cycle, 'finalized_at' => null, 'finalized_by' => null, 'letters_at' => null, 'letters_by' => null, 'notes' => null];
}
function cycle_finalized(string $cycle): bool { return !empty(cycle_row($cycle)['finalized_at']); }
