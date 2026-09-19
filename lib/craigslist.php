<?php
// ============================================================
// Renewal Center - Craigslist comps
//
// Builds the same search URL the FileMaker "right" window built
// (honolulu.craigslist.org / apartments-housing, keywords, min bed/
// bath, zip + miles) and, when the droplet is allowed to fetch it,
// parses the result list. Results are cached per unit for 7 days.
// Craigslist blocks most datacenter addresses: when the fetch
// fails the window shows the URL to open by hand instead.
// ============================================================
declare(strict_types=1);

function cl_url(array $p): string {
    $site = (string)($p['site'] ?? knob('cl_site'));
    $q = [
        'query' => (string)($p['keywords'] ?? ''),
        'min_bedrooms' => (string)($p['bed'] ?? ''),
        'min_bathrooms' => (string)($p['bath'] ?? ''),
        'postal' => (string)($p['zip'] ?? ''),
        'search_distance' => (string)($p['miles'] ?? knob('cl_miles')),
        'hasPic' => !empty($p['has_image']) ? '1' : '',
        'postedToday' => !empty($p['today']) ? '1' : '',
        'sort' => 'rel',
    ];
    if (!empty($p['week'])) { $q['posted_after'] = (string)(time() - 7 * 86400); }
    $q = array_filter($q, fn($v) => $v !== '');
    $area = (string)($p['area'] ?? knob('cl_area'));
    return 'https://' . $site . '.craigslist.org/search/' . ($area ? $area . '/' : '') . 'apa?' . http_build_query($q);
}

function cl_cache_key(array $p): string {
    return substr(md5(json_encode([oid(), $p['keywords'] ?? '', $p['bed'] ?? '', $p['bath'] ?? '', $p['zip'] ?? '', $p['miles'] ?? '', $p['has_image'] ?? 0, $p['week'] ?? 0])), 0, 40);
}

function cl_search(array $p, bool $force = false): array {
    $url = cl_url($p);
    $key = cl_cache_key($p);
    if (!$force) {
        $st = db()->prepare("SELECT results, fetched_at FROM renewal_comps_cache WHERE office_id = ? AND ckey = ? AND fetched_at > DATE_SUB(NOW(), INTERVAL 7 DAY)");
        $st->execute([oid(), $key]);
        if ($row = $st->fetch()) {
            $j = json_decode((string)$row['results'], true);
            if (is_array($j)) { return ['ok' => true, 'url' => $url, 'cached' => $row['fetched_at'], 'rows' => $j]; }
        }
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) RenewalCenter/0.1',
        CURLOPT_HTTPHEADER => ['Accept: text/html,application/xhtml+xml', 'Accept-Language: en-US,en;q=0.9'],
    ]);
    $html = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($html === false || $code !== 200) {
        return ['ok' => false, 'url' => $url, 'error' => $html === false ? 'Network: ' . $err : 'Craigslist answered HTTP ' . $code . ' (datacenter block is normal) - open the search in a tab.', 'rows' => []];
    }
    $rows = cl_parse((string)$html);
    if ($rows) {
        db()->prepare("INSERT INTO renewal_comps_cache (company_id, office_id, ckey, url, results, fetched_at) VALUES (?, ?, ?, ?, ?, NOW())
                       ON DUPLICATE KEY UPDATE url = VALUES(url), results = VALUES(results), fetched_at = NOW()")
            ->execute([cid(), oid(), $key, $url, json_encode($rows, JSON_UNESCAPED_SLASHES)]);
    }
    return ['ok' => true, 'url' => $url, 'cached' => null, 'rows' => $rows,
            'note' => $rows ? null : 'Craigslist returned a page with no listings we could read (layout change or JS-only list).'];
}

// Two known layouts: the older <li class="result-row"> list and the
// newer <li class="cl-static-search-result"> list. JSON-LD when present.
function cl_parse(string $html): array {
    $rows = [];
    if (preg_match('#<script[^>]*type="application/ld\+json"[^>]*>(.*?)</script>#si', $html, $m)) {
        $j = json_decode(trim($m[1]), true);
        $items = $j['itemListElement'] ?? [];
        foreach ((array)$items as $it) {
            $x = $it['item'] ?? $it;
            if (!is_array($x)) { continue; }
            $price = sx_num($x, ['offers.price', 'price']);
            $rows[] = ['title' => (string)($x['name'] ?? ''), 'price' => $price, 'url' => (string)($x['url'] ?? ''),
                       'area' => (string)($x['address']['addressLocality'] ?? ''), 'bb' => trim(((string)($x['numberOfBedrooms'] ?? '')) . ' / ' . ((string)($x['numberOfBathroomsTotal'] ?? ''))),
                       'posted' => ''];
        }
        if ($rows) { return $rows; }
    }
    if (preg_match_all('#<li class="cl-static-search-result"[^>]*title="([^"]*)">(.*?)</li>#si', $html, $mm, PREG_SET_ORDER)) {
        foreach ($mm as $m) {
            $b = $m[2];
            preg_match('#href="([^"]+)"#', $b, $u);
            preg_match('#class="price">\s*\$?([\d,]+)#', $b, $p);
            preg_match('#class="location">\s*([^<]*)#', $b, $l);
            $rows[] = ['title' => html_entity_decode($m[1]), 'price' => isset($p[1]) ? (float)str_replace(',', '', $p[1]) : null,
                       'url' => $u[1] ?? '', 'area' => trim(html_entity_decode($l[1] ?? '')), 'bb' => '', 'posted' => ''];
        }
        if ($rows) { return $rows; }
    }
    if (preg_match_all('#<li class="result-row"[^>]*>(.*?)</li>#si', $html, $mm)) {
        foreach ($mm[1] as $b) {
            preg_match('#<a href="([^"]+)"[^>]*class="result-title[^"]*"[^>]*>([^<]*)<#', $b, $t);
            preg_match('#class="result-price">\s*\$([\d,]+)#', $b, $p);
            preg_match('#class="result-hood">\s*\(([^)]*)\)#', $b, $l);
            preg_match('#class="housing">\s*([^<]*)#', $b, $h);
            preg_match('#datetime="([^"]+)"#', $b, $d);
            if (!isset($t[2])) { continue; }
            $rows[] = ['title' => html_entity_decode($t[2]), 'price' => isset($p[1]) ? (float)str_replace(',', '', $p[1]) : null,
                       'url' => $t[1], 'area' => trim(html_entity_decode($l[1] ?? '')), 'bb' => trim(preg_replace('/\s+/', ' ', $h[1] ?? '')),
                       'posted' => isset($d[1]) ? substr($d[1], 0, 10) : ''];
        }
    }
    return $rows;
}

function cl_median(array $prices): ?float {
    $p = array_values(array_filter(array_map('floatval', $prices), fn($v) => $v > 0));
    if (!$p) { return null; }
    sort($p);
    $n = count($p);
    return $n % 2 ? $p[intdiv($n, 2)] : ($p[$n / 2 - 1] + $p[$n / 2]) / 2;
}
