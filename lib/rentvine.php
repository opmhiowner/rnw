<?php
// ============================================================
// Renewal Center - Rentvine write-back
//
// "Post to Rentvine" on a decided renewal, in order:
//   1. expire the current rent recurring charge (end = day before
//      the new rent starts)
//   2. create a new recurring charge for rent at the new amount
//   3. add a one-time charge to the tenant ledger for the security
//      deposit (SDR) increase, when > 0
//   4. update the lease custom field "Last Renewal Date"
// Each step stores what Rentvine returned on the queue row, so a
// re-run skips steps already done. Nothing here creates bills.
//
// Endpoints and bodies are per-office settings with placeholders,
// matched to the working curl commands - defaults below are a
// first guess and say so in Settings. Dry run resolves them and
// sends nothing.
//
// Credentials: reused from Sync Center's encrypted source row
// (sync_sources.credentials_enc, AES-256-GCM with crypto_key_b64
// from /var/www/apps/config/sync.php). Fallback: rv_api_key in
// renewal_settings.
// ============================================================
declare(strict_types=1);

function rv_templates_default(): array {
    return [
        'rv_charges_list_url'   => '{base}/leases/{lease_id}/recurring-charges',
        'rv_charges_list_method'=> 'GET',
        'rv_rent_match'         => 'rent',            // recurring charge whose description/account contains this = the rent charge
        'rv_expire_url'         => '{base}/leases/recurring-charges/{charge_id}',
        'rv_expire_method'      => 'PUT',
        'rv_expire_body'        => '{"endDate":"{end_date}"}',
        'rv_create_url'         => '{base}/leases/{lease_id}/recurring-charges',
        'rv_create_method'      => 'POST',
        'rv_create_body'        => '{"amount":{amount},"startDate":"{start_date}","frequency":"monthly","description":"Rent","accountID":{rent_account_id}}',
        'rv_sdr_url'            => '{base}/leases/{lease_id}/charges',
        'rv_sdr_method'         => 'POST',
        'rv_sdr_body'           => '{"amount":{amount},"date":"{date}","description":"Security deposit increase","accountID":{deposit_account_id}}',
        'rv_custom_url'         => '{base}/leases/{lease_id}/custom-fields',
        'rv_custom_method'      => 'PUT',
        'rv_custom_body'        => '{"customFields":[{"customFieldID":{custom_field_id},"value":"{date}"}]}',
        'rv_rent_account_id'    => '',
        'rv_deposit_account_id' => '',
        'rv_custom_field_id'    => '',
        'rv_custom_field_name'  => 'Last Renewal Date',
    ];
}
function rv_tpl(string $k): string { return (string)setting($k, rv_templates_default()[$k] ?? ''); }

// ---------- credentials
function rv_creds(): array {
    static $c = null;
    if ($c !== null) { return $c; }
    $c = ['base' => '', 'key' => '', 'auth_style' => 'bearer', 'auth_header' => 'X-Api-Key', 'secret' => '', 'source' => 'none'];
    // 1. Sync Center's encrypted source for this office
    $cfgPath = rnw_first_file([rnw_apps_root() . '/config/sync.php', '/var/www/apps/config/sync.php']);
    if ($cfgPath !== null) {
        try {
            $cfg = include $cfgPath;
            $b64 = (string)($cfg['crypto_key_b64'] ?? '');
            $key = $b64 !== '' ? base64_decode($b64, true) : false;
            if ($key !== false && strlen($key) === 32) {
                $st = db()->prepare("SELECT credentials_enc FROM sync_sources
                                     WHERE company_id = ? AND office_id = ? AND enabled = 1 AND kind = 'rv' ORDER BY id LIMIT 1");
                $st->execute([cid(), oid()]);
                $blob = $st->fetchColumn();
                if ($blob) {
                    $iv = substr($blob, 0, 12); $tag = substr($blob, 12, 16); $ct = substr($blob, 28);
                    $pt = openssl_decrypt($ct, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
                    $j = $pt !== false ? json_decode($pt, true) : null;
                    if (is_array($j)) {
                        $rv = (array)($j['rentvine'] ?? []);
                        $c['base'] = rtrim((string)($j['rentvine_base'] ?? $rv['base'] ?? ''), '/');
                        $c['key'] = (string)($rv['api_key'] ?? $rv['key'] ?? '');
                        $c['secret'] = (string)($rv['api_secret'] ?? '');
                        $c['auth_style'] = (string)($rv['auth_style'] ?? 'bearer');
                        $c['auth_header'] = (string)($rv['auth_header'] ?? 'X-Api-Key');
                        $c['source'] = 'synccenter';
                    }
                }
            }
        } catch (Throwable $e) { /* fall through */ }
    }
    // 2. fallback: this app's own settings
    if ($c['key'] === '') {
        $c['base'] = rtrim((string)setting('rv_base', ''), '/');
        $c['key'] = (string)setting('rv_api_key', '');
        $c['secret'] = (string)setting('rv_api_secret', '');
        $c['auth_style'] = (string)setting('rv_auth_style', 'bearer');
        $c['auth_header'] = (string)setting('rv_auth_header', 'X-Api-Key');
        $c['source'] = $c['key'] !== '' ? 'settings' : 'none';
    }
    if ((string)setting('rv_base', '') !== '') { $c['base'] = rtrim((string)setting('rv_base'), '/'); }
    return $c;
}

function rv_headers(array $c): array {
    $h = ['Accept: application/json', 'Content-Type: application/json'];
    switch ($c['auth_style']) {
        case 'header': $h[] = $c['auth_header'] . ': ' . $c['key']; break;
        case 'basic':  $h[] = 'Authorization: Basic ' . base64_encode($c['key'] . ':' . $c['secret']); break;
        default:       $h[] = 'Authorization: Bearer ' . $c['key'];
    }
    return $h;
}

function rv_call(string $method, string $url, ?string $body, int $timeout = 30): array {
    $c = rv_creds();
    if ($c['key'] === '' || $c['base'] === '') {
        return ['ok' => false, 'code' => 0, 'error' => 'Rentvine is not connected (no key / base URL for this office).', 'body' => ''];
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => $timeout, CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CUSTOMREQUEST => strtoupper($method), CURLOPT_HTTPHEADER => rv_headers($c),
    ]);
    if ($body !== null && strtoupper($method) !== 'GET') { curl_setopt($ch, CURLOPT_POSTFIELDS, $body); }
    $out = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($out === false) { return ['ok' => false, 'code' => 0, 'error' => 'Network: ' . $err, 'body' => '']; }
    $j = json_decode((string)$out, true);
    return ['ok' => $code >= 200 && $code < 300, 'code' => $code,
            'error' => $code >= 200 && $code < 300 ? null : 'HTTP ' . $code,
            'body' => (string)$out, 'json' => is_array($j) ? $j : null];
}

function rv_fill(string $tpl, array $vars): string {
    foreach ($vars as $k => $v) { $tpl = str_replace('{' . $k . '}', (string)$v, $tpl); }
    return $tpl;
}

// ---------- the plan: what the four steps would send for this row
function rv_plan(array $q, array $L): array {
    $c = rv_creds();
    $start = $q['increase_date'] ?: date('Y-m-01', strtotime('+1 month'));
    $endOld = date('Y-m-d', strtotime($start . ' -1 day'));
    $vars = [
        'base' => $c['base'], 'lease_id' => $q['lease_id'], 'tenant_id' => $L['tenant_id'] ?? '',
        'property_id' => $L['property_id'] ?? '', 'unit_id' => $L['unit_id'] ?? '',
        'charge_id' => $q['rv_old_charge_id'] ?? '{charge_id}',
        'start_date' => $start, 'end_date' => $endOld, 'date' => date('Y-m-d'),
        'rent_account_id' => rv_tpl('rv_rent_account_id') ?: 'null',
        'deposit_account_id' => rv_tpl('rv_deposit_account_id') ?: 'null',
        'custom_field_id' => rv_tpl('rv_custom_field_id') ?: 'null',
    ];
    $steps = [];
    $steps[] = ['key' => 'find', 'label' => 'Find the current rent recurring charge',
        'method' => rv_tpl('rv_charges_list_method'), 'url' => rv_fill(rv_tpl('rv_charges_list_url'), $vars), 'body' => null,
        'done' => !empty($q['rv_old_charge_id']), 'note' => !empty($q['rv_old_charge_id']) ? 'charge ' . $q['rv_old_charge_id'] : 'match "' . rv_tpl('rv_rent_match') . '"'];
    $steps[] = ['key' => 'expire', 'label' => 'Expire it on ' . $endOld,
        'method' => rv_tpl('rv_expire_method'), 'url' => rv_fill(rv_tpl('rv_expire_url'), $vars), 'body' => rv_fill(rv_tpl('rv_expire_body'), $vars),
        'done' => !empty($q['rv_old_charge_expired_at'])];
    $steps[] = ['key' => 'create', 'label' => 'New rent recurring charge $' . number_format((float)$q['new_rent'], 2) . ' from ' . $start,
        'method' => rv_tpl('rv_create_method'), 'url' => rv_fill(rv_tpl('rv_create_url'), $vars),
        'body' => rv_fill(rv_tpl('rv_create_body'), $vars + ['amount' => number_format((float)$q['new_rent'], 2, '.', '')]),
        'done' => !empty($q['rv_new_charge_id'])];
    $sdr = (float)($q['sdr_delta'] ?? 0);
    $steps[] = ['key' => 'sdr', 'label' => $sdr > 0 ? 'Ledger charge: security deposit increase $' . number_format($sdr, 2) : 'No deposit increase - skipped',
        'method' => rv_tpl('rv_sdr_method'), 'url' => rv_fill(rv_tpl('rv_sdr_url'), $vars),
        'body' => $sdr > 0 ? rv_fill(rv_tpl('rv_sdr_body'), $vars + ['amount' => number_format($sdr, 2, '.', '')]) : null,
        'done' => !empty($q['rv_sdr_charge_id']) || $sdr <= 0];
    $steps[] = ['key' => 'custom', 'label' => rv_tpl('rv_custom_field_name') . ' = ' . date('Y-m-d'),
        'method' => rv_tpl('rv_custom_method'), 'url' => rv_fill(rv_tpl('rv_custom_url'), $vars), 'body' => rv_fill(rv_tpl('rv_custom_body'), $vars),
        'done' => !empty($q['rv_custom_field_at'])];
    return ['creds' => ['source' => $c['source'], 'base' => $c['base'], 'key_tail' => $c['key'] !== '' ? '…' . substr($c['key'], -4) : ''],
            'start' => $start, 'steps' => $steps];
}

// pick the rent charge out of a recurring-charges listing
function rv_pick_rent_charge(?array $j): ?array {
    if (!is_array($j)) { return null; }
    $list = $j;
    foreach (['recurringCharges', 'charges', 'data', 'items', 'results'] as $k) {
        if (isset($j[$k]) && is_array($j[$k])) { $list = $j[$k]; break; }
    }
    $needle = strtolower(rv_tpl('rv_rent_match'));
    $best = null;
    foreach ($list as $c) {
        if (!is_array($c)) { continue; }
        $c2 = isset($c['recurringCharge']) && is_array($c['recurringCharge']) ? $c['recurringCharge'] : $c;
        $desc = strtolower((string)(sx($c2, ['description', 'name', 'memo', 'accountName', 'account.name']) ?? ''));
        $end = sx_date($c2, ['endDate', 'end_date']);
        $open = $end === null || $end >= date('Y-m-d');
        if ($open && ($needle === '' || str_contains($desc, $needle))) {
            $id = sx($c2, ['recurringChargeID', 'id', 'chargeID', 'recurring_charge_id']);
            if ($id !== null) { $best = ['id' => (string)$id, 'amount' => sx_num($c2, ['amount', 'rent']), 'desc' => $desc]; break; }
        }
    }
    return $best;
}

// ---------- run it. $only = one step key for "retry this step"; null = all pending
function rv_post(array $q, array $L, ?string $only = null): array {
    $plan = rv_plan($q, $L);
    $log = [];
    $pdo = db();
    $fail = function (string $step, array $r) use (&$log, $q) {
        $log[] = ['step' => $step, 'ok' => false, 'code' => $r['code'], 'error' => $r['error'], 'body' => mb_substr($r['body'], 0, 600)];
        log_event((int)$q['id'], 'rv_' . $step . '_failed', ['lease_id' => $q['lease_id'], 'detail' => ['code' => $r['code'], 'error' => $r['error'], 'body' => mb_substr($r['body'], 0, 2000)]]);
        return ['ok' => false, 'log' => $log];
    };
    $steps = [];
    foreach ($plan['steps'] as $s) { $steps[$s['key']] = $s; }

    // 1. find
    if (empty($q['rv_old_charge_id']) && ($only === null || $only === 'find' || $only === 'expire')) {
        $s = $steps['find'];
        $r = rv_call($s['method'], $s['url'], null);
        if (!$r['ok']) { return $fail('find', $r); }
        $pick = rv_pick_rent_charge($r['json']);
        if (!$pick) {
            return $fail('find', ['code' => $r['code'], 'error' => 'No open recurring charge matched "' . rv_tpl('rv_rent_match') . '" - check Settings > Rentvine.', 'body' => $r['body']]);
        }
        $pdo->prepare("UPDATE renewal_queue SET rv_old_charge_id = ? WHERE id = ?")->execute([$pick['id'], $q['id']]);
        $q['rv_old_charge_id'] = $pick['id'];
        $log[] = ['step' => 'find', 'ok' => true, 'note' => 'charge ' . $pick['id'] . ' ($' . number_format((float)$pick['amount'], 2) . ' ' . $pick['desc'] . ')'];
        log_event((int)$q['id'], 'rv_find', ['lease_id' => $q['lease_id'], 'detail' => $pick]);
        $plan = rv_plan($q, $L); foreach ($plan['steps'] as $s) { $steps[$s['key']] = $s; }
    }
    // 2. expire
    if (empty($q['rv_old_charge_expired_at']) && ($only === null || $only === 'expire')) {
        $s = $steps['expire'];
        $r = rv_call($s['method'], $s['url'], $s['body']);
        if (!$r['ok']) { return $fail('expire', $r); }
        $pdo->prepare("UPDATE renewal_queue SET rv_old_charge_expired_at = NOW() WHERE id = ?")->execute([$q['id']]);
        $q['rv_old_charge_expired_at'] = date('Y-m-d H:i:s');
        $log[] = ['step' => 'expire', 'ok' => true, 'note' => 'ended ' . $plan['steps'][1]['label']];
        log_event((int)$q['id'], 'rv_expire', ['lease_id' => $q['lease_id'], 'detail' => ['charge' => $q['rv_old_charge_id'], 'reply' => mb_substr($r['body'], 0, 2000)]]);
    }
    // 3. create
    if (empty($q['rv_new_charge_id']) && ($only === null || $only === 'create')) {
        $s = $steps['create'];
        $r = rv_call($s['method'], $s['url'], $s['body']);
        if (!$r['ok']) { return $fail('create', $r); }
        $nid = (string)(sx($r['json'] ?? [], ['recurringChargeID', 'id', 'recurringCharge.recurringChargeID', 'recurringCharge.id', 'data.id']) ?? ('ok-' . date('YmdHis')));
        $pdo->prepare("UPDATE renewal_queue SET rv_new_charge_id = ? WHERE id = ?")->execute([$nid, $q['id']]);
        $q['rv_new_charge_id'] = $nid;
        $log[] = ['step' => 'create', 'ok' => true, 'note' => 'new charge ' . $nid];
        log_event((int)$q['id'], 'rv_create', ['lease_id' => $q['lease_id'], 'detail' => ['charge' => $nid, 'amount' => $q['new_rent'], 'reply' => mb_substr($r['body'], 0, 2000)]]);
    }
    // 4. sdr
    $sdr = (float)($q['sdr_delta'] ?? 0);
    if ($sdr > 0 && empty($q['rv_sdr_charge_id']) && ($only === null || $only === 'sdr')) {
        $s = $steps['sdr'];
        $r = rv_call($s['method'], $s['url'], $s['body']);
        if (!$r['ok']) { return $fail('sdr', $r); }
        $nid = (string)(sx($r['json'] ?? [], ['chargeID', 'id', 'charge.chargeID', 'charge.id', 'transactionID', 'data.id']) ?? ('ok-' . date('YmdHis')));
        $pdo->prepare("UPDATE renewal_queue SET rv_sdr_charge_id = ? WHERE id = ?")->execute([$nid, $q['id']]);
        $q['rv_sdr_charge_id'] = $nid;
        $log[] = ['step' => 'sdr', 'ok' => true, 'note' => 'ledger charge ' . $nid . ' $' . number_format($sdr, 2)];
        log_event((int)$q['id'], 'rv_sdr', ['lease_id' => $q['lease_id'], 'detail' => ['charge' => $nid, 'amount' => $sdr, 'reply' => mb_substr($r['body'], 0, 2000)]]);
    }
    // 5. custom field
    if (empty($q['rv_custom_field_at']) && ($only === null || $only === 'custom')) {
        $s = $steps['custom'];
        $r = rv_call($s['method'], $s['url'], $s['body']);
        if (!$r['ok']) { return $fail('custom', $r); }
        $pdo->prepare("UPDATE renewal_queue SET rv_custom_field_at = NOW() WHERE id = ?")->execute([$q['id']]);
        $q['rv_custom_field_at'] = date('Y-m-d H:i:s');
        $log[] = ['step' => 'custom', 'ok' => true, 'note' => rv_tpl('rv_custom_field_name') . ' set'];
        log_event((int)$q['id'], 'rv_custom', ['lease_id' => $q['lease_id'], 'detail' => ['reply' => mb_substr($r['body'], 0, 2000)]]);
    }
    $allDone = !empty($q['rv_old_charge_expired_at']) && !empty($q['rv_new_charge_id'])
            && ($sdr <= 0 || !empty($q['rv_sdr_charge_id'])) && !empty($q['rv_custom_field_at']);
    if ($allDone && $q['status'] !== 'posted') {
        $u = current_user();
        $pdo->prepare("UPDATE renewal_queue SET status = 'posted', posted_at = NOW(), posted_by = ? WHERE id = ?")
            ->execute([$u['key'] ?? 'system', $q['id']]);
        log_event((int)$q['id'], 'posted', ['lease_id' => $q['lease_id']]);
    }
    return ['ok' => true, 'done' => $allDone, 'log' => $log];
}

// "Send test": one GET against the lease so the key, base URL and
// auth style are proven before anything is written
function rv_test(string $leaseId): array {
    $c = rv_creds();
    $r = rv_call('GET', $c['base'] . '/leases/' . rawurlencode($leaseId), null, 20);
    return ['ok' => $r['ok'], 'code' => $r['code'], 'error' => $r['error'], 'source' => $c['source'],
            'base' => $c['base'], 'sample' => mb_substr($r['body'], 0, 1500)];
}
