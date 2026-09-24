<?php
// Renewal Center - Prep window (its own screen): the SET for one increase month.
// FileMaker 1.PREP: pull the set, sort by pcode, work it before / during the
// renewal meeting, print, upload in the following month, then make permanent.
// Clicking a row flips Main to that record (server link, like Media / Comps).
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
<title>Renewal Center — Prep</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans:wght@400;500;600;700&family=IBM+Plex+Mono:wght@500;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/app.css?v=<?= RNW_REV ?>">
<style>
  .ptbl { border:1px solid var(--hair); border-radius:10px; overflow:auto; flex:1; min-height:0; background:#fff; }
  table.set { border-collapse:collapse; width:100%; font-size:12px; }
  table.set th { position:sticky; top:0; background:var(--field); font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:.04em; color:var(--muted); padding:6px 8px; text-align:left; white-space:nowrap; border-bottom:1px solid var(--hair); cursor:pointer; z-index:1; }
  table.set th.num, table.set td.num { text-align:right; font-family:var(--mono); }
  table.set td { padding:5px 8px; border-bottom:1px solid var(--hair2); white-space:nowrap; vertical-align:middle; }
  table.set tr.r { cursor:pointer; }
  table.set tr.r:hover td { background:var(--field); }
  table.set tr.on td { background:var(--teal-bg); }
  table.set tr.on td:first-child { box-shadow:inset 4px 0 0 var(--teal); }
  td.wrap { white-space:normal !important; max-width:260px; font-size:11px; line-height:1.3; }
  .swatch { display:inline-block; width:10px; height:10px; border-radius:2px; vertical-align:middle; margin-right:4px; }
  .neg { color:var(--red); } .pos { color:var(--green); }
  .unf { color:var(--warn); font-weight:700; }
  .cyc { display:flex; align-items:center; gap:8px; }
  .cyc .lbl { font-size:18px; font-weight:700; white-space:nowrap; }
  .tot { display:grid; grid-template-columns:1fr auto; gap:4px 12px; font-size:12px; }
  .tot strong { font-family:var(--mono); text-align:right; }
  @media print { .pane.left, .pane.right, .followbar { display:none !important; } .ptbl { border:none; overflow:visible; } table.set th { position:static; } body { overflow:visible; height:auto; } .shell { height:auto; } }
</style>
</head>
<body>
<div class="shell col" style="background:#fff">
  <div class="followbar">
    <span class="title">Prep</span>
    <span class="cyc"><button class="btn sm" id="c-prev" aria-label="Previous cycle">&lt;</button><span class="lbl" id="c-label">—</span><button class="btn sm" id="c-next" aria-label="Next cycle">&gt;</button><button class="btn sm" id="c-today">This run</button></span>
    <span class="muted" id="c-info"></span>
    <span class="tag lg pau hide" id="c-final">FINALIZED</span>
    <div class="grow"></div>
    <div class="row" style="gap:4px" id="offices"></div>
    <a href="index.php" target="rc-main" style="font-size:12px;font-weight:600">Main window</a>
  </div>
  <div class="shell" style="flex:1;min-height:0">
    <!-- LEFT: filters + totals -->
    <div class="pane left" style="width:250px;padding:14px;gap:12px;border-right:1px solid var(--hair)">
      <input type="search" id="search" class="in" placeholder="Search tenant, pcode, owner…">
      <div class="label">Show</div>
      <div class="chips" id="filters"></div>
      <div class="label">Category</div>
      <div class="chips" id="cats"></div>
      <div class="label" style="margin-top:6px">This set</div>
      <div class="tot" id="totals"></div>
      <div class="grow"></div>
      <div class="muted" style="font-size:11px" id="synced"></div>
    </div>
    <!-- CENTER: the set -->
    <div class="pane center" style="padding:12px 16px;gap:8px">
      <div class="row between">
        <span class="label" id="qlabel">Set · sorted by category › zip › pcode</span>
        <span class="row"><button class="btn sm" id="btn-print">Print list</button><button class="btn sm" id="btn-reload">Reload</button></span>
      </div>
      <div class="ptbl"><table class="set"><thead><tr id="thead"></tr></thead><tbody id="tbody"></tbody></table></div>
    </div>
    <!-- RIGHT: row + cycle actions -->
    <div class="pane right" style="width:330px;padding:14px;gap:12px;border-left:1px solid var(--hair)">
      <div class="card white" id="sel-card"><h3>No row selected</h3><div class="muted" style="font-size:12px">Click a row. Main follows it.</div></div>
      <div class="card white">
        <h3>Add to this set by hand</h3>
        <input class="in sm" id="add-q" placeholder="tenant, pcode or address…">
        <div id="add-results" style="display:flex;flex-direction:column;gap:4px;max-height:220px;overflow:auto"></div>
      </div>
      <div class="card white">
        <h3>Overdue MTM · <span id="ov-n">0</span></h3>
        <div class="muted" style="font-size:11px">Month-to-month, 25+ months since the last increase, not in this set. FileMaker's ">25 MO report".</div>
        <div id="overdue" style="display:flex;flex-direction:column;gap:4px;max-height:200px;overflow:auto"></div>
      </div>
      <div class="actions" style="border-top:1px solid var(--hair);margin:0 -14px -14px;padding:12px 14px">
        <button class="btn md" id="btn-letters">Letters sent</button>
        <button class="btn md pri" id="btn-post">Upload this set to Rentvine…</button>
        <button class="btn md" id="btn-final">Make permanent (finalize)</button>
      </div>
    </div>
  </div>
</div>
<div id="modal" class="modal hide"><div class="box" id="modal-box"></div></div>
<script src="assets/app.js?v=<?= RNW_REV ?>"></script>
<script>
(() => {
  const { api, bus, display, fmt, toast, LS } = RC;
  const $ = (id) => document.getElementById(id);
  const S = { cycle: LS('renewal.cycle') || '', board: null, rows: [], filter: 'all', cat: 'all', q: '', sel: null, sort: null, dir: 1 };
  const FILTERS = [['all', 'All'], ['mtm', 'MTM'], ['fixed', 'Fixed'], ['addon', 'Addon'], ['unfilled', 'Unfilled'], ['exceptions', 'Exceptions'], ['pau', 'Pau'], ['posted', 'Posted'], ['unpulled', 'Not pulled']];
  const COLS = [
    ['increase_date', 'Date', v => fmt.dateShort(v)], ['revisit', 'Rev', v => v ? '✕' : ''], ['pcode', 'Pcode', v => fmt.esc(v)],
    ['last_increase', 'Last incr', v => fmt.dateShort(v)], ['move_in', 'Move in', v => fmt.date(v)], ['end', 'Lease end', (v, r) => r.mtm ? 'MTM' : fmt.date(v)],
    ['new_rent', 'New rent', (v, r) => v === null ? '<span class="unf">—</span>' : fmt.money(v), 'num'],
    ['change', 'Chg', v => v === null ? '' : `<span class="${v < 0 ? 'neg' : 'pos'}">${v > 0 ? '+' : ''}${fmt.money(v)}</span>`, 'num'],
    ['tenant', 'Tenant', v => fmt.esc(v)], ['special', 'Renewal special', v => `<span class="wrap">${fmt.esc(v || '')}</span>`, 'wrap'],
    ['owner', 'Owner', v => fmt.esc(v)], ['deposit', 'Deposit', (v, r) => `<span class="${r.deposit_mismatch ? 'pos' : ''}" title="${r.deposit_mismatch ? 'deposit does not equal rent' : ''}">${fmt.money(v)}</span>`, 'num'],
    ['rent', 'Rent', v => fmt.money(v), 'num'], ['move_out', 'FMO', (v, r) => v ? `<span class="neg">${fmt.dateShort(v)}</span>` : (r.vacating ? '<span class="neg">vacate</span>' : '')],
    ['addon', 'Add', (v, r) => v ? 'ADD' : ''], ['asd', 'ASD', v => v ? fmt.money(v) : '', 'num'], ['day_due', 'Due', v => v ? String(v).padStart(2, '0') : ''],
    ['pct', '%', v => v === null || v === undefined ? '' : fmt.pct(v), 'num'], ['cat_label', 'Cat', v => fmt.esc(v)],
    ['vaoao', 'Building / VAOAO', (v, r) => (r.color ? `<span class="swatch" style="background:${fmt.esc(r.color)}"></span>` : '') + fmt.esc(v || '')],
    ['ptype', 'Type', v => fmt.esc(v)], ['remarks', 'Remarks', v => `<span class="wrap">${fmt.esc(v || '')}</span>`, 'wrap'], ['status', 'St', v => v && v !== 'open' ? `<span class="tag ${v}">${v}</span>` : '']
  ];

  api('board', {}).then(j => { if (j.ok) display.apply(j.display.on, j.display.scale); });

  async function load(cycle) {
    if (cycle) S.cycle = cycle;
    const j = await api('prep', S.cycle ? { cycle: S.cycle } : {});
    if (!j.ok) { toast(j.error, true); return; }
    S.board = j; S.cycle = j.cycle.cycle; S.rows = j.queue; S.unpulled = j.unpulled || []; LS('renewal.cycle', S.cycle);
    $('c-label').textContent = 'Increase ' + fmt.date(j.cycle.increase);
    $('c-info').textContent = `run ${fmt.cycle(j.cycle.run_month)} · letters out by ${fmt.date(j.cycle.letters_by)} · upload in ${j.cycle.upload_month} · fixed ends ${fmt.dateShort(j.cycle.fixed_from)}–${fmt.dateShort(j.cycle.fixed_to)}` + (j.cycle.row.letters_at ? ' · letters sent ' + fmt.date(j.cycle.row.letters_at) : '');
    $('c-final').classList.toggle('hide', !j.cycle.finalized);
    $('btn-final').textContent = j.cycle.finalized ? 'Reopen cycle' : 'Make permanent (finalize)';
    $('offices').innerHTML = (j.offices.length ? j.offices : [{ code: j.office.code, label: j.office.label }]).map(o => `<button class="btn sm ${o.code === j.office.code ? 'on' : ''}" data-office="${fmt.esc(o.code)}">${fmt.esc(o.code)}</button>`).join('');
    $('offices').querySelectorAll('button').forEach(b => b.onclick = async () => { await api('board', { office: b.dataset.office }); load(); });
    $('filters').innerHTML = FILTERS.map(([k, l]) => `<button class="btn xs ${S.filter === k ? 'on' : ''}" data-f="${k}">${l}${k === 'unpulled' && S.unpulled.length ? ' · ' + S.unpulled.length : ''}</button>`).join('');
    $('filters').querySelectorAll('button').forEach(b => b.onclick = () => { S.filter = b.dataset.f; render(); });
    const cats = [['all', 'All']].concat(Object.keys(j.cats).map(k => [k, k + ' ' + j.cats[k]]));
    $('cats').innerHTML = cats.map(([k, l]) => `<button class="btn xs ${S.cat === k ? 'on' : ''}" data-cat="${k}">${fmt.esc(l)}${j.counts[k] ? ' · ' + j.counts[k] : ''}</button>`).join('');
    $('cats').querySelectorAll('button').forEach(b => b.onclick = () => { S.cat = b.dataset.cat; render(); });
    $('synced').textContent = j.sync.ready ? 'Sync Center ' + (j.sync.last ? fmt.date(j.sync.last) + ' ' + String(j.sync.last).slice(11, 16) : '—') + ' · ' + j.sync.leases + ' leases' : 'Sync Center tables not found';
    $('ov-n').textContent = j.overdue.length;
    $('overdue').innerHTML = j.overdue.slice(0, 60).map(o => `<div class="row between" style="font-size:12px;gap:6px"><span class="grow" style="min-width:0;overflow:hidden;text-overflow:ellipsis"><strong>${fmt.esc(o.pcode)}</strong> ${fmt.esc(o.tenant)} <span class="muted">· ${o.months} mo (${fmt.esc(o.source)})</span></span><button class="btn xs" data-add="${fmt.esc(o.lease_id)}">Add</button></div>`).join('') || '<div class="muted" style="font-size:12px">none</div>';
    $('overdue').querySelectorAll('button').forEach(b => b.onclick = () => addLease(b.dataset.add));
    render();
  }
  function filtered() {
    const q = S.q.toLowerCase();
    if (S.filter === 'unpulled') return S.unpulled.filter(r => !q || [r.tenant, r.pcode, r.property, r.owner].join(' ').toLowerCase().includes(q));
    return S.rows.filter(r => {
      if (S.cat !== 'all' && String(r.cat) !== S.cat) return false;
      if (q && ![r.tenant, r.pcode, r.property, r.owner, r.address, r.vaoao, r.special].join(' ').toLowerCase().includes(q)) return false;
      switch (S.filter) {
        case 'mtm': return r.mtm && !r.addon; case 'fixed': return !r.mtm && !r.addon; case 'addon': return r.addon;
        case 'unfilled': return r.unfilled; case 'exceptions': return r.deposit_mismatch || r.move_out || r.vacating || !!r.special;
        case 'pau': return r.status === 'pau'; case 'posted': return r.status === 'posted'; default: return true;
      }
    });
  }
  function render() {
    let rows = filtered();
    if (S.sort) { const k = S.sort; rows = rows.slice().sort((a, b) => ((a[k] ?? '') > (b[k] ?? '') ? 1 : (a[k] ?? '') < (b[k] ?? '') ? -1 : 0) * S.dir); }
    $('thead').innerHTML = COLS.map(([k, l, , cls]) => `<th class="${cls || ''}" data-k="${k}">${l}${S.sort === k ? (S.dir > 0 ? ' ▲' : ' ▼') : ''}</th>`).join('');
    $('thead').querySelectorAll('th').forEach(th => th.onclick = () => { if (S.sort === th.dataset.k) S.dir = -S.dir; else { S.sort = th.dataset.k; S.dir = 1; } render(); });
    $('tbody').innerHTML = rows.map(r => `<tr class="r ${r.lease_id === S.sel ? 'on' : ''}" data-id="${fmt.esc(r.lease_id)}">${COLS.map(([k, , f, cls]) => `<td class="${cls || ''}">${f(r[k], r)}</td>`).join('')}</tr>`).join('')
      || `<tr><td colspan="${COLS.length}" class="muted" style="padding:20px">Nothing in this set for that filter.</td></tr>`;
    $('tbody').querySelectorAll('tr.r').forEach(tr => tr.onclick = () => pick(tr.dataset.id));
    const t = S.board.totals, shown = rows.length;
    $('qlabel').textContent = S.filter === 'unpulled' ? `${shown} decision${shown === 1 ? '' : 's'} saved for leases NOT in this set - kept, not pulled. Add to pull one.` : `${shown} of ${t.count} in the set · ${S.filter === 'all' ? 'sorted by category › zip › pcode' : S.filter}`;
    $('totals').innerHTML = [['Rows', t.count], ['Fixed / MTM / Addon', `${t.fixed} / ${t.mtm} / ${t.addon}`], ['Filled', `${t.filled} of ${t.count}`], ['Unfilled', t.count - t.filled],
      ['Total increase / mo', fmt.money(t.increase)], ['Total ASD', fmt.money(t.asd)], ['Exceptions', t.exceptions], ['Pau / Posted', `${t.pau} / ${t.posted}`]]
      .map(([k, v]) => `<span>${k}</span><strong>${v}</strong>`).join('');
    renderSel();
  }
  function renderSel() {
    const r = S.rows.find(x => x.lease_id === S.sel) || S.unpulled.find(x => x.lease_id === S.sel);
    if (!r) { $('sel-card').innerHTML = '<h3>No row selected</h3><div class="muted" style="font-size:12px">Click a row. Main follows it.</div>'; return; }
    $('sel-card').innerHTML = `<h3>${fmt.esc(r.property)}${r.unit ? ' #' + fmt.esc(r.unit) : ''} <span class="pill">${fmt.esc(r.pcode)}</span></h3>
      <div style="font-size:12px">${fmt.esc(r.tenant)} · ${fmt.esc(r.cat_label)} <span class="muted">${fmt.esc(r.reason)}</span></div>
      <div class="grid2" style="gap:6px;font-size:12px"><div class="fld"><span>Rent → new</span><strong class="mono">${fmt.money(r.rent)} → ${r.new_rent === null ? '<span class="unf">unfilled</span>' : fmt.money(r.new_rent)}</strong></div>
      <div class="fld"><span>Deposit → ASD</span><strong class="mono">${fmt.money(r.deposit)} → ${r.asd ? '+' + fmt.money(r.asd) : '—'}</strong></div></div>
      ${r.special ? `<div class="strip warn" style="font-size:11px">${fmt.esc(r.special)}</div>` : ''}
      <div class="muted" style="font-size:11px">${r.cat === 0 ? 'Not in this set. The saved figures are kept; press Add to pull it.' : (r.addon ? 'In the set by hand.' : 'In the set by the rule.')}</div>
      <div class="row" style="gap:6px"><button class="btn sm" id="s-open">Open on Main</button>${r.cat === 0 && !S.board.cycle.finalized ? `<button class="btn sm pri" id="s-add">Add to set</button>` : ''}${r.addon && r.status !== 'posted' && !S.board.cycle.finalized ? `<button class="btn sm" id="s-remove">Remove from set</button>` : ''}</div>`;
    $('s-open').onclick = () => publish(r);
    const rm = $('s-remove'); if (rm) rm.onclick = () => removeLease(r.lease_id);
    const ad = $('s-add'); if (ad) ad.onclick = () => addLease(r.lease_id);
  }
  function publish(r) {
    bus.publish({ from: 'prep', record_id: r.lease_id, cycle: S.cycle, rent_proposal: Number(r.new_rent ?? r.rent ?? 0), current_rent: Number(r.rent || 0), pct: Number(r.pct || 0),
      tenant: r.tenant, property: r.property, unit: r.unit, pcode: r.pcode, address: r.address, zip: r.zip });
  }
  function pick(id) { S.sel = id; render(); const r = S.rows.find(x => x.lease_id === id); if (r) publish(r); }
  async function addLease(id) {
    const j = await api('cycle_add', { lease_id: id, cycle: S.cycle });
    if (!j.ok) { toast(j.error, true); return; }
    toast('Added to ' + fmt.cycle(S.cycle)); $('add-q').value = ''; $('add-results').innerHTML = ''; await load(); pick(id);
  }
  async function removeLease(id) {
    if (!confirm('Remove this lease from the set? Any saved figures are kept under "Not pulled".')) return;
    const j = await api('cycle_remove', { lease_id: id, cycle: S.cycle });
    if (!j.ok) { toast(j.error, true); return; }
    toast('Removed from the set'); load();
  }
  let addT;
  $('add-q').addEventListener('input', () => { clearTimeout(addT); addT = setTimeout(async () => {
    const q = $('add-q').value.trim(); if (q.length < 2) { $('add-results').innerHTML = ''; return; }
    const j = await api('leases_search', { q });
    const inSet = new Set(S.rows.map(r => r.lease_id));
    $('add-results').innerHTML = (j.rows || []).map(r => `<div class="row between" style="font-size:12px;gap:6px"><span class="grow" style="min-width:0;overflow:hidden;text-overflow:ellipsis"><strong>${fmt.esc(r.pcode)}</strong> ${fmt.esc(r.tenant)} <span class="muted">· ${r.mtm ? 'MTM' : 'ends ' + fmt.dateShort(r.end)} · ${fmt.money(r.rent)}</span></span>${inSet.has(r.lease_id) ? '<span class="tag">in set</span>' : `<button class="btn xs" data-add="${fmt.esc(r.lease_id)}">Add</button>`}</div>`).join('') || '<div class="muted" style="font-size:12px">no match</div>';
    $('add-results').querySelectorAll('button').forEach(b => b.onclick = () => addLease(b.dataset.add));
  }, 250); });
  $('search').addEventListener('input', () => { S.q = $('search').value; render(); });
  $('c-prev').onclick = () => load(S.board.cycle.prev); $('c-next').onclick = () => load(S.board.cycle.next); $('c-today').onclick = () => load(S.board.cycle.default);
  $('btn-reload').onclick = () => load(); $('btn-print').onclick = () => window.print();

  function openModal(html) { $('modal-box').innerHTML = html; $('modal').classList.remove('hide'); }
  function closeModal() { $('modal').classList.add('hide'); }
  $('modal').addEventListener('click', (e) => { if (e.target === $('modal')) closeModal(); });
  $('btn-letters').onclick = async () => { if (!confirm('Mark letters for ' + fmt.cycle(S.cycle) + ' as sent today?')) return; const j = await api('cycle_letters', { cycle: S.cycle }); if (j.ok) { toast('Letters stamped'); load(); } else toast(j.error, true); };
  $('btn-post').onclick = async () => {
    const t = S.board.totals; const n = S.rows.filter(r => !r.unfilled && r.status !== 'posted').length;
    openModal(`<h2>Upload ${fmt.cycle(S.cycle)} to Rentvine</h2>
      <div>${n} filled rows not yet posted will be sent, each in the four steps (end old rent charge, new rent charge from ${fmt.date(S.board.cycle.increase)}, deposit charge, Last Renewal Date). ${t.count - t.filled} unfilled rows are skipped. Finished steps are never repeated.</div>
      <div class="strip warn">Do this in ${S.board.cycle.upload_month}, after the letters are out. Preview any single row's calls from Main › "Preview the 4 steps".</div>
      <div id="post-log"></div>
      <div class="row" style="justify-content:flex-end"><button class="btn" id="m-close">Cancel</button><button class="btn pri" id="m-go" ${n ? '' : 'disabled'}>Upload ${n} now</button></div>`);
    $('m-close').onclick = closeModal;
    $('m-go').onclick = async () => {
      if (!confirm('Upload ' + n + ' renewals to Rentvine now?')) return;
      $('m-go').disabled = true; $('post-log').innerHTML = '<div class="muted">Posting… this takes a few seconds per lease.</div>';
      const j = await api('cycle_post', { cycle: S.cycle, confirm: 1 });
      $('post-log').innerHTML = `<div class="strip ${j.failed ? 'err' : ''}">${j.done} posted · ${j.failed} failed · ${j.skipped} skipped</div>` + (j.log || []).map(l => `<div style="font-size:12px" class="${l.ok ? '' : 'neg'}">${l.ok ? '✓' : '✗'} <strong>${fmt.esc(l.pcode)}</strong> ${fmt.esc(l.tenant)}: ${l.steps.map(fmt.esc).join(', ')}</div>`).join('');
      $('m-close').textContent = 'Close'; load();
    };
  };
  $('btn-final').onclick = async () => {
    if (S.board.cycle.finalized) { const j = await api('cycle_unfinalize', { cycle: S.cycle }); if (j.ok) { toast('Cycle reopened'); load(); } return; }
    if (!confirm('Make ' + fmt.cycle(S.cycle) + ' permanent? Rows become read-only (FileMaker "make permanent record").')) return;
    const j = await api('cycle_finalize', { cycle: S.cycle, confirm: 1 }); if (j.ok) { toast('Finalized'); load(); } else toast(j.error, true);
  };
  document.addEventListener('keydown', (e) => {
    if (!$('modal').classList.contains('hide')) { if (e.key === 'Escape') closeModal(); return; }
    const tag = (e.target.tagName || '').toLowerCase(); if (tag === 'input' || tag === 'textarea') return;
    if (e.key === 'ArrowDown' || e.key === 'ArrowUp') { e.preventDefault(); const rows = filtered(); const i = rows.findIndex(r => r.lease_id === S.sel); const n = rows[Math.min(rows.length - 1, Math.max(0, i + (e.key === 'ArrowDown' ? 1 : -1)))]; if (n) pick(n.lease_id); }
  });
  // Main saved something: refresh the set so the row shows the new figures
  bus.subscribe((m) => { if (m.from === 'main' && m.record_id) { if (m.cycle && m.cycle !== S.cycle) return; S.sel = m.record_id; if (m.saved) load(); else render(); } }, { poll: 3000 });
  load();
})();
</script>
</body>
</html>
