<?php
// Renewal Center - Comps window (right monitor): Craigslist search | results | pinned + market check
declare(strict_types=1);
require __DIR__ . '/lib/core.php';
schema_ensure();
$me = require_login();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Renewal Center — Comps</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans:wght@400;500;600;700&family=IBM+Plex+Mono:wght@500;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/app.css?v=<?= RNW_REV ?>">
</head>
<body>
<div class="shell col" style="background:#fff">
  <div class="followbar">
    <span class="dot" id="dot"></span>
    <span class="label" style="color:var(--green-ink)" id="follow">Waiting for main window</span>
    <span style="font-size:16px;font-weight:700" id="h-prop"></span>
    <span style="font-size:12px;color:var(--ink2)" id="h-rent"></span>
    <div class="grow"></div>
    <a href="index.php" target="rc-main" style="font-size:12px;font-weight:600">Back to main</a>
  </div>
  <div class="shell" style="flex:1;min-height:0">
    <div class="pane left" style="padding:16px;gap:12px;border-right:1px solid var(--hair)">
      <div style="font-weight:700">Craigslist search</div>
      <label class="fld">Keywords (from the lease)<input class="in" id="f-kw"></label>
      <div class="grid2" style="gap:8px">
        <label class="fld">Min bed<input class="in sm" id="f-bed"></label>
        <label class="fld">Min bath<input class="in sm" id="f-bath"></label>
        <label class="fld">Zip<input class="in sm" id="f-zip"></label>
        <label class="fld">Miles<input class="in sm" id="f-miles"></label>
      </div>
      <div style="display:flex;flex-direction:column;gap:6px">
        <label class="chk" style="font-weight:400"><input type="checkbox" id="f-img"> Has image</label>
        <label class="chk" style="font-weight:400"><input type="checkbox" id="f-week"> Posted this week</label>
        <label class="chk" style="font-weight:400"><input type="checkbox" id="f-same" checked> Same building first</label>
      </div>
      <button class="btn md pri" id="btn-search">Search Craigslist</button>
      <a id="lnk-cl" href="https://honolulu.craigslist.org/search/apa" target="_blank" style="font-size:12px;font-weight:600">Open in Craigslist (new tab) →</a>
      <div class="muted" style="font-size:11px;margin-top:auto" id="note">Search runs the same URL FileMaker built. Results are cached per unit for 7 days.</div>
    </div>
    <div class="pane center" style="padding:20px 24px;gap:12px">
      <div class="row between"><span class="label">Results · <span id="count">0</span> listings</span><span class="muted">click a row to pin it as a comp</span></div>
      <div class="strip warn hide" id="msg"></div>
      <div class="tbl body">
        <div class="tr th" style="grid-template-columns:1fr 3fr 1.6fr 1fr 1fr"><span>Price</span><span>Listing</span><span>Area</span><span>Bd / Ba</span><span>Posted</span></div>
        <div id="rows"></div>
      </div>
    </div>
    <div class="pane right" style="width:380px;padding:20px;gap:12px;border-left:1px solid var(--hair)">
      <div style="font-weight:700">Pinned comps · <span id="pcount">0</span></div>
      <div id="pinned" style="display:flex;flex-direction:column;gap:8px"></div>
      <div class="card" style="background:var(--green-bg);border-color:var(--green-line);gap:6px;padding:14px;border-radius:10px">
        <div class="label" style="color:var(--green-ink)">Market check</div>
        <div class="grid2" style="gap:6px;font-size:12px;color:var(--green-ink)">
          <span>Comp median</span><strong class="mono" id="m-med">—</strong>
          <span>Proposed rent</span><strong class="mono" id="m-prop">—</strong>
          <span>Position</span><strong id="m-pos">—</strong>
        </div>
      </div>
      <label class="fld"><span>Add a comp by hand</span><span class="row"><input class="in sm" id="f-man-title" placeholder="Listing / address"><input class="in sm mono" id="f-man-price" placeholder="$" style="width:90px"><button class="btn sm" id="btn-man">Pin</button></span></label>
      <button class="btn lg pri" id="btn-attach" style="margin-top:auto">Attach comps to renewal</button>
      <div class="muted" style="font-size:11px">Pinned comps save to the renewal record and set the market check on Main.</div>
    </div>
  </div>
</div>
<script src="assets/app.js?v=<?= RNW_REV ?>"></script>
<script>
(() => {
  const { api, bus, display, fmt, toast } = RC;
  const $ = (id) => document.getElementById(id);
  const S = { cur: null, rows: [], pinned: [], url: '' };
  api('board', {}).then(j => { if (j.ok) { display.apply(j.display.on, j.display.scale); $('f-miles').value = $('f-miles').value || '1'; } });

  function follow(m) {
    if (!m || !m.record_id) return;
    const changed = !S.cur || S.cur.record_id !== m.record_id;
    S.cur = m;
    $('follow').textContent = 'Following main window'; $('dot').className = 'dot';
    $('h-prop').textContent = (m.property || '') + (m.unit ? ' #' + m.unit : '');
    $('h-rent').innerHTML = `current ${fmt.money(m.current_rent)} · proposed <strong style="color:var(--blue)">${fmt.money(m.rent_proposal)}</strong> (${m.pct >= 0 ? '+' : ''}${fmt.pct(m.pct)})`;
    if (changed) {
      $('f-kw').value = m.property || ''; $('f-bed').value = m.bed ?? ''; $('f-bath').value = m.bath ?? ''; $('f-zip').value = m.zip || '';
      S.pinned = Array.isArray(m.pinned) ? m.pinned : []; S.rows = []; renderRows(); renderPinned();
      search();
    } else { renderPinned(); }
  }
  function params() {
    return { keywords: $('f-kw').value, bed: $('f-bed').value, bath: $('f-bath').value, zip: $('f-zip').value, miles: $('f-miles').value,
             has_image: $('f-img').checked, week: $('f-week').checked };
  }
  async function search(force) {
    $('msg').classList.add('hide'); $('rows').innerHTML = '<div class="tr muted">Searching…</div>';
    const j = await api('comps_search', Object.assign(params(), { force: !!force }));
    const r = j.result || {};
    S.url = r.url || ''; $('lnk-cl').href = S.url || $('lnk-cl').href;
    if (!r.ok) { $('msg').classList.remove('hide'); $('msg').textContent = r.error || 'Search failed'; S.rows = []; }
    else { S.rows = r.rows || []; if (r.note) { $('msg').classList.remove('hide'); $('msg').textContent = r.note; } $('note').textContent = r.cached ? 'Cached ' + fmt.date(r.cached) + ' · Search again to refresh.' : 'Fetched just now. Cached 7 days per search.'; }
    if ($('f-same').checked && S.cur) { const kw = (S.cur.property || '').toLowerCase(); S.rows.sort((a, b) => (b.title.toLowerCase().includes(kw) ? 1 : 0) - (a.title.toLowerCase().includes(kw) ? 1 : 0)); }
    renderRows();
  }
  $('btn-search').onclick = () => search(true);
  function isPinned(r) { return S.pinned.some(p => p.url && r.url ? p.url === r.url : (p.title === r.title && p.price === r.price)); }
  function renderRows() {
    $('count').textContent = S.rows.length;
    $('rows').innerHTML = S.rows.map((r, i) => `<button class="tr ${isPinned(r) ? 'on' : ''}" data-i="${i}" style="grid-template-columns:1fr 3fr 1.6fr 1fr 1fr">
      <span class="mono" style="font-weight:700">${fmt.money(r.price)}</span><span style="font-weight:600">${fmt.esc(r.title)}</span><span style="color:var(--ink2)">${fmt.esc(r.area)}</span><span style="color:var(--ink2)">${fmt.esc(r.bb)}</span><span class="muted">${fmt.esc(r.posted)}</span></button>`).join('')
      || (S.cur ? '<div class="tr muted">No listings. Open the search in Craigslist and pin comps by hand on the right.</div>' : '');
    $('rows').querySelectorAll('button').forEach(b => b.onclick = () => toggle(S.rows[Number(b.dataset.i)]));
  }
  function toggle(r) {
    if (isPinned(r)) S.pinned = S.pinned.filter(p => !(p.url && r.url ? p.url === r.url : (p.title === r.title && p.price === r.price)));
    else S.pinned.push({ title: r.title, price: r.price, url: r.url, area: r.area, bb: r.bb });
    renderRows(); renderPinned();
  }
  function median() { const p = S.pinned.map(x => Number(x.price)).filter(v => v > 0).sort((a, b) => a - b); if (!p.length) return null; const n = p.length; return n % 2 ? p[(n - 1) / 2] : (p[n / 2 - 1] + p[n / 2]) / 2; }
  function renderPinned() {
    $('pcount').textContent = S.pinned.length;
    $('pinned').innerHTML = S.pinned.map((p, i) => `<div class="card white" style="flex-direction:row;justify-content:space-between;align-items:center;padding:10px 12px">
      <div style="display:flex;flex-direction:column;gap:2px;min-width:0"><span style="font-weight:600">${p.url ? `<a href="${fmt.esc(p.url)}" target="_blank" style="text-decoration:none;color:inherit">${fmt.esc(p.title)}</a>` : fmt.esc(p.title)}</span><span class="muted" style="font-size:11px">${fmt.esc([p.area, p.bb].filter(Boolean).join(' · '))}</span></div>
      <span class="row"><span class="mono" style="font-weight:700">${fmt.money(p.price)}</span><button class="btn xs" data-i="${i}" aria-label="Unpin">×</button></span></div>`).join('') || '<div class="muted">Nothing pinned yet.</div>';
    $('pinned').querySelectorAll('button').forEach(b => b.onclick = () => { S.pinned.splice(Number(b.dataset.i), 1); renderRows(); renderPinned(); });
    const med = median(), prop = S.cur ? Number(S.cur.rent_proposal || 0) : 0;
    $('m-med').textContent = fmt.money(med); $('m-prop').textContent = fmt.money(prop);
    $('m-pos').textContent = med && prop ? (prop / med * 100).toFixed(0) + '% of median' : '—';
  }
  $('btn-man').onclick = () => {
    const t = $('f-man-title').value.trim(), p = Number(String($('f-man-price').value).replace(/[^0-9.]/g, ''));
    if (!t || !p) { toast('Title and price needed', true); return; }
    S.pinned.push({ title: t, price: p, url: '', area: 'by hand', bb: '' }); $('f-man-title').value = ''; $('f-man-price').value = ''; renderPinned();
  };
  $('btn-attach').onclick = async () => {
    if (!S.cur) return;
    const j = await api('comps_pin', { lease_id: S.cur.record_id, cycle: S.cur.cycle, pinned: S.pinned });
    if (!j.ok) { toast(j.error, true); return; }
    toast('Attached ' + S.pinned.length + ' comps · median ' + fmt.money(j.median));
    if (bus.ch) bus.ch.postMessage({ record_id: S.cur.record_id, pinned: S.pinned, median: j.median, at: Date.now(), from: 'comps' });
  };

  bus.subscribe((m) => { if (m.from === 'comps') return; follow(m); }, { poll: 2000 });
  follow(bus.last());
  bus.ping('comps'); setInterval(() => bus.ping('comps'), 5000);
  if (!bus.last()) { $('dot').className = 'dot off'; if (bus.ch) bus.ch.postMessage({ want: 'current' }); }
})();
</script>
</body>
</html>
