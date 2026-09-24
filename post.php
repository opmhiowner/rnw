<?php
// Renewal Center - Post window (its own screen): upload one increase month to
// Rentvine. Opened from Prep (Post / Upload…). Pulls the rows that are filled
// out and not yet posted, verifies each against Rentvine (read-only), uploads
// the selected rows in the four steps, verifies again, then makes the cycle
// permanent. Clicking a row flips Main to that record (server link).
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
<title>Renewal Center — Post to Rentvine</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans:wght@400;500;600;700&family=IBM+Plex+Mono:wght@500;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/app.css?v=<?= RNW_REV ?>">
<style>
  .ptbl { border:1px solid var(--hair); border-radius:10px; overflow:auto; flex:1; min-height:0; background:#fff; }
  table.set { border-collapse:collapse; width:100%; font-size:12px; }
  table.set th { position:sticky; top:0; background:var(--field); font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:.04em; color:var(--muted); padding:6px 8px; text-align:left; white-space:nowrap; border-bottom:1px solid var(--hair); z-index:1; }
  table.set th.num, table.set td.num { text-align:right; font-family:var(--mono); }
  table.set td { padding:5px 8px; border-bottom:1px solid var(--hair2); white-space:nowrap; vertical-align:middle; }
  table.set tr.r { cursor:pointer; }
  table.set tr.r:hover td { background:var(--field); }
  table.set tr.on td { background:var(--teal-bg); }
  table.set tr.on td:first-child { box-shadow:inset 4px 0 0 var(--teal); }
  table.set tr.dim td { color:var(--muted); }
  .neg { color:var(--red); } .pos { color:var(--green); }
  .cyc { display:flex; align-items:center; gap:8px; }
  .cyc .lbl { font-size:18px; font-weight:700; white-space:nowrap; }
  .tot { display:grid; grid-template-columns:1fr auto; gap:4px 12px; font-size:12px; }
  .tot strong { font-family:var(--mono); text-align:right; }
  .steps { display:inline-flex; gap:3px; }
  .steps i { width:11px; height:11px; border-radius:50%; border:1px solid var(--hair); background:#fff; display:inline-block; }
  .steps i.on { background:var(--green); border-color:var(--green); }
  .steps i.skip { background:var(--hair); }
  .v { font-weight:700; } .v.ok { color:var(--green); } .v.bad { color:var(--red); } .v.none { color:var(--muted); font-weight:400; }
  .check { display:grid; grid-template-columns:18px 1fr; gap:2px 8px; font-size:12px; padding:4px 0; border-bottom:1px solid var(--hair2); }
  .check .d { grid-column:2; color:var(--muted); font-size:11px; font-family:var(--mono); word-break:break-word; }
  .plan { font-size:12px; display:flex; flex-direction:column; gap:4px; }
  .plan div { display:flex; gap:6px; align-items:baseline; }
  .plan .k { width:14px; }
  .log { font-size:12px; max-height:220px; overflow:auto; display:flex; flex-direction:column; gap:3px; }
  .cred { font-size:11px; color:var(--muted); font-family:var(--mono); word-break:break-all; }
</style>
</head>
<body>
<div class="shell col" style="background:#fff">
  <div class="followbar">
    <span class="title">Post to Rentvine</span>
    <span class="cyc"><button class="btn sm" id="c-prev" aria-label="Previous cycle">&lt;</button><span class="lbl" id="c-label">—</span><button class="btn sm" id="c-next" aria-label="Next cycle">&gt;</button><button class="btn sm" id="c-today">This run</button></span>
    <span class="muted" id="c-info"></span>
    <span class="tag lg pau hide" id="c-final">FINALIZED</span>
    <div class="grow"></div>
    <div class="row" style="gap:4px" id="offices"></div>
    <a href="prep.php" target="rc-prep" style="font-size:12px;font-weight:600">Prep</a>
    <a href="index.php" target="rc-main" style="font-size:12px;font-weight:600">Main window</a>
  </div>
  <div class="shell" style="flex:1;min-height:0">
    <!-- LEFT: what is in this upload -->
    <div class="pane left" style="width:260px;padding:14px;gap:12px;border-right:1px solid var(--hair)">
      <input type="search" id="search" class="in" placeholder="Search tenant, pcode…">
      <div class="label">Show</div>
      <div class="chips" id="filters"></div>
      <div class="label" style="margin-top:6px">This upload</div>
      <div class="tot" id="totals"></div>
      <div class="card white">
        <h3>Rentvine</h3>
        <div class="cred" id="cred">…</div>
        <div class="muted" style="font-size:11px" id="cred-warn"></div>
      </div>
      <div class="strip warn" style="font-size:11px">Order: verify → upload the selected rows → verify again → make permanent. Do this in the upload month, after the letters are out. Finished steps are never repeated.</div>
      <div class="grow"></div>
      <div class="muted" style="font-size:11px" id="synced"></div>
    </div>
    <!-- CENTER: the rows -->
    <div class="pane center" style="padding:12px 16px;gap:8px">
      <div class="row between">
        <span class="label" id="qlabel">Rows</span>
        <span class="row"><button class="btn sm" id="btn-all">Select all ready</button><button class="btn sm" id="btn-none">Select none</button><button class="btn sm" id="btn-reload">Reload</button></span>
      </div>
      <div class="ptbl"><table class="set"><thead><tr>
        <th></th><th>Pcode</th><th>Tenant</th><th class="num">Rent</th><th class="num">New rent</th><th class="num">Chg</th><th class="num">%</th><th>Starts</th><th class="num">ASD</th><th>Due</th><th>Steps</th><th>Verified</th><th>St</th>
      </tr></thead><tbody id="tbody"></tbody></table></div>
    </div>
    <!-- RIGHT: one row + batch actions -->
    <div class="pane right" style="width:360px;padding:14px;gap:12px;border-left:1px solid var(--hair);overflow:auto">
      <div class="card white" id="sel-card"><h3>No row selected</h3><div class="muted" style="font-size:12px">Click a row to see its four steps and verify it. Main follows it.</div></div>
      <div class="card white" id="batch-card">
        <h3>Batch · <span id="b-n">0</span> selected</h3>
        <button class="btn md" id="btn-verify-all">Verify selected in Rentvine</button>
        <button class="btn md pri" id="btn-upload">Upload selected to Rentvine…</button>
        <div class="log" id="b-log"></div>
      </div>
      <div class="actions" style="border-top:1px solid var(--hair);margin:0 -14px -14px;padding:12px 14px">
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
  const S = { cycle: new URLSearchParams(location.search).get('cycle') || LS('renewal.cycle') || '', board: null, rows: [], filter: 'ready', q: '', sel: null, checked: new Set(), busy: false };
  const FILTERS = [['ready', 'Ready'], ['posted', 'Posted'], ['partial', 'Partial'], ['problem', 'Verify failed'], ['excluded', 'Excluded'], ['all', 'All']];
  const STEPS = ['find', 'expire', 'create', 'sdr', 'custom'];

  api('board', {}).then(j => { if (j.ok) display.apply(j.display.on, j.display.scale); });
  api('rv_status', {}).then(j => {
    if (!j.ok) return;
    $('cred').textContent = j.creds.have ? `${j.creds.base} · key ${j.creds.key_tail} · ${j.creds.source}` : 'no credentials for this office';
    const w = [];
    if (!j.creds.have) w.push('Settings › Rentvine: no key / base URL.');
    if (!j.deposit_account) w.push('Deposit GL account id is not set (Settings › Rentvine) - deposit charges will fail.');
    $('cred-warn').textContent = w.join(' ');
    $('cred-warn').classList.toggle('neg', w.length > 0);
  });

  function kind(r) {
    if (r.status === 'posted') return 'posted';
    if (r.partial) return 'partial';
    if (r.ready) return r.verify_ok === 0 ? 'problem' : 'ready';
    return 'excluded';
  }
  function excludedWhy(r) { return r.status === 'pau' ? 'pau' : (r.unfilled ? 'no new rent' : (r.status || '')); }

  async function load(cycle) {
    if (cycle) S.cycle = cycle;
    const j = await api('prep', S.cycle ? { cycle: S.cycle } : {});
    if (!j.ok) { toast(j.error, true); return; }
    S.board = j; S.cycle = j.cycle.cycle; S.rows = j.queue; LS('renewal.cycle', S.cycle);
    if (S.filter === 'ready' && !S.rows.some(r => kind(r) === 'ready')) S.filter = 'all';   // nothing to post: show everything
    // keep the selection to rows that can still be posted
    S.checked = new Set([...S.checked].filter(id => { const r = S.rows.find(x => x.lease_id === id); return r && (r.ready || r.partial); }));
    $('c-label').textContent = 'Increase ' + fmt.date(j.cycle.increase);
    $('c-info').textContent = `upload in ${j.cycle.upload_month} · letters ${j.cycle.row.letters_at ? 'sent ' + fmt.date(j.cycle.row.letters_at) : 'not marked sent'}`;
    $('c-final').classList.toggle('hide', !j.cycle.finalized);
    $('btn-final').textContent = j.cycle.finalized ? 'Reopen cycle' : 'Make permanent (finalize)';
    $('offices').innerHTML = (j.offices.length ? j.offices : [{ code: j.office.code, label: j.office.label }]).map(o => `<button class="btn sm ${o.code === j.office.code ? 'on' : ''}" data-office="${fmt.esc(o.code)}">${fmt.esc(o.code)}</button>`).join('');
    $('offices').querySelectorAll('button').forEach(b => b.onclick = async () => { await api('board', { office: b.dataset.office }); S.checked.clear(); load(); });
    $('synced').textContent = j.sync.last ? 'Sync Center mirror ' + j.sync.last : '';
    render();
  }
  function counts() {
    const c = { ready: 0, posted: 0, partial: 0, problem: 0, excluded: 0, all: S.rows.length };
    S.rows.forEach(r => c[kind(r)]++);
    return c;
  }
  function filtered() {
    const q = S.q.trim().toLowerCase();
    return S.rows.filter(r => (S.filter === 'all' || kind(r) === S.filter) && (!q || [r.tenant, r.pcode, r.property, r.owner].join(' ').toLowerCase().includes(q)));
  }
  function stepsHtml(r) {
    if (!r.rv) return '<span class="muted">—</span>';
    return '<span class="steps" title="find · expire · new rent · deposit · Last Renewal Date">' + STEPS.map(k => `<i class="${r.rv[k] ? (k === 'sdr' && !r.asd ? 'skip' : 'on') : ''}"></i>`).join('') + '</span>';
  }
  function verifyHtml(r) {
    if (r.verify_ok === null || r.verify_ok === undefined) return '<span class="v none">—</span>';
    return `<span class="v ${r.verify_ok ? 'ok' : 'bad'}" title="${fmt.esc(r.verify_note || '')} · ${fmt.esc(r.verified_at || '')}">${r.verify_ok ? '✓' : '✗'}</span>`;
  }
  function render() {
    if (!S.board) return;
    const c = counts();
    $('filters').innerHTML = FILTERS.map(([k, l]) => `<button class="btn xs ${S.filter === k ? 'on' : ''}" data-f="${k}">${l} · ${c[k]}</button>`).join('');
    $('filters').querySelectorAll('button').forEach(b => b.onclick = () => { S.filter = b.dataset.f; render(); });
    const rows = filtered();
    const readyRows = S.rows.filter(r => r.ready || r.partial);
    const inc = readyRows.reduce((a, r) => a + (r.change || 0), 0), asd = readyRows.reduce((a, r) => a + (r.asd || 0), 0);
    $('totals').innerHTML = `<span>In the set</span><strong>${S.rows.length}</strong><span>Ready to post</span><strong>${c.ready + c.problem}</strong><span>Partially posted</span><strong>${c.partial}</strong><span>Posted</span><strong>${c.posted}</strong><span>Excluded</span><strong>${c.excluded}</strong><span>Increase / month</span><strong>${fmt.money(inc)}</strong><span>Deposit increases</span><strong>${fmt.money(asd)}</strong>`;
    $('qlabel').textContent = `${rows.length} rows · ${FILTERS.find(f => f[0] === S.filter)[1]}`;
    $('tbody').innerHTML = rows.map(r => {
      const k = kind(r); const can = r.ready || r.partial;
      return `<tr class="r ${r.lease_id === S.sel ? 'on' : ''} ${k === 'excluded' ? 'dim' : ''}" data-id="${fmt.esc(r.lease_id)}">
        <td>${can ? `<input type="checkbox" data-chk="${fmt.esc(r.lease_id)}" ${S.checked.has(r.lease_id) ? 'checked' : ''}>` : ''}</td>
        <td><strong>${fmt.esc(r.pcode)}</strong></td><td>${fmt.esc(r.tenant)}</td>
        <td class="num">${fmt.money(r.rent)}</td><td class="num">${r.new_rent === null ? '<span class="neg">—</span>' : fmt.money(r.new_rent)}</td>
        <td class="num">${r.change === null ? '' : `<span class="${r.change < 0 ? 'neg' : 'pos'}">${r.change > 0 ? '+' : ''}${fmt.money(r.change)}</span>`}</td>
        <td class="num">${r.pct === null || r.pct === undefined ? '' : fmt.pct(r.pct)}</td>
        <td>${fmt.dateShort(r.increase_date)}</td><td class="num">${r.asd ? fmt.money(r.asd) : ''}</td><td>${r.day_due ? String(r.day_due).padStart(2, '0') : ''}</td>
        <td>${stepsHtml(r)}</td><td>${verifyHtml(r)}</td>
        <td>${k === 'excluded' ? `<span class="muted">${fmt.esc(excludedWhy(r))}</span>` : (k === 'posted' ? '<span class="tag posted">posted</span>' : (k === 'partial' ? '<span class="tag pau">partial</span>' : ''))}</td></tr>`;
    }).join('') || `<tr><td colspan="13" class="muted" style="padding:18px">Nothing here.</td></tr>`;
    $('tbody').querySelectorAll('tr.r').forEach(tr => tr.onclick = (e) => { if (e.target.tagName === 'INPUT') return; pick(tr.dataset.id); });
    $('tbody').querySelectorAll('input[data-chk]').forEach(cb => cb.onchange = () => { cb.checked ? S.checked.add(cb.dataset.chk) : S.checked.delete(cb.dataset.chk); $('b-n').textContent = S.checked.size; });
    $('b-n').textContent = S.checked.size;
    $('btn-upload').disabled = S.board.cycle.finalized || S.busy;
    $('btn-verify-all').disabled = S.busy;
  }

  // ---------- one row
  function publish(r) {
    bus.publish({ from: 'prep', record_id: r.lease_id, cycle: S.cycle, rent_proposal: Number(r.new_rent ?? r.rent ?? 0), current_rent: Number(r.rent || 0), pct: Number(r.pct || 0),
      tenant: r.tenant, property: r.property, unit: r.unit, pcode: r.pcode, address: r.address, zip: r.zip });
  }
  async function pick(id) {
    S.sel = id; render();
    const r = S.rows.find(x => x.lease_id === id); if (!r) return;
    publish(r);
    const k = kind(r);
    $('sel-card').innerHTML = `<h3>${fmt.esc(r.pcode)} · ${fmt.esc(r.tenant)}</h3>
      <div class="muted" style="font-size:12px">${fmt.esc(r.property)} ${r.unit ? '#' + fmt.esc(r.unit) : ''} · lease ${fmt.esc(r.lease_id)}</div>
      <div class="kv" style="font-size:12px"><span>Rent</span><strong class="mono">${fmt.money(r.rent)} → ${r.new_rent === null ? '—' : fmt.money(r.new_rent)} ${r.pct !== null && r.pct !== undefined ? '(' + fmt.pct(r.pct) + ')' : ''}</strong><span>Starts</span><strong>${fmt.date(r.increase_date)}</strong><span>Deposit</span><strong class="mono">${fmt.money(r.deposit)}${r.asd ? ' + ' + fmt.money(r.asd) : ''}</strong>
        <span>Status</span><strong>${k}${r.posted_at ? ' · ' + fmt.esc(r.posted_at) + ' by ' + fmt.esc(r.posted_by || '') : ''}</strong></div>
      ${r.verify_note ? `<div class="strip ${r.verify_ok ? '' : 'err'}">Last verify ${fmt.esc(r.verified_at || '')}: ${fmt.esc(r.verify_note)}</div>` : ''}
      <div class="plan" id="s-plan"><span class="muted">loading the four steps…</span></div>
      <div id="s-checks"></div>
      <div class="row" style="flex-wrap:wrap;gap:6px">
        <button class="btn sm" id="s-verify">Verify in Rentvine</button>
        ${(r.ready || r.partial) && !S.board.cycle.finalized ? `<button class="btn sm pri" id="s-post">${r.partial ? 'Finish posting this one' : 'Post this one'}</button>` : ''}
        <button class="btn sm" id="s-open">Open on Main</button>
      </div>
      <div class="log" id="s-log"></div>`;
    $('s-open').onclick = () => publish(r);
    $('s-verify').onclick = () => verifyOne(r.lease_id, $('s-checks'));
    if ($('s-post')) $('s-post').onclick = () => postOne(r);
    const j = await api('rv_plan', { lease_id: r.lease_id, cycle: S.cycle });
    if (!j.ok) { $('s-plan').innerHTML = `<span class="neg">${fmt.esc(j.error)}</span>`; return; }
    if (S.sel !== id) return;
    $('s-plan').innerHTML = j.plan.steps.map((s, i) => `<div><span class="k ${s.done ? 'pos' : 'muted'}">${s.done ? '✓' : (i + 1)}</span><span>${fmt.esc(s.label)}</span></div>`).join('');
  }
  async function verifyOne(id, box) {
    if (box) box.innerHTML = '<div class="muted" style="font-size:12px">Asking Rentvine…</div>';
    const j = await api('rv_verify', { lease_id: id, cycle: S.cycle });
    const r = S.rows.find(x => x.lease_id === id);
    if (!j.ok) { if (box) box.innerHTML = `<div class="strip err">${fmt.esc(j.error)}</div>`; return null; }
    if (r) { r.verify_ok = j.verified ? 1 : 0; r.verify_note = j.note; r.verified_at = j.at; }
    if (box) box.innerHTML = `<div class="strip ${j.verified ? '' : 'err'}"><strong>${j.verified ? 'Verified' : 'Check'}</strong> · ${fmt.esc(j.note)}</div>` +
      j.checks.map(c => `<div class="check"><span class="v ${c.ok ? 'ok' : 'bad'}">${c.ok ? '✓' : '✗'}</span><span>${fmt.esc(c.label)}</span><span class="d">${fmt.esc(c.detail || '')}</span></div>`).join('') +
      (j.charges.length ? `<details style="font-size:11px;margin-top:6px"><summary class="muted">All ${j.charges.length} recurring charges on the lease</summary>${j.charges.map(c => `<div class="mono">${fmt.esc(c.id)} ${fmt.esc(c.desc)} ${fmt.money(c.amount)} ${c.start || '?'}${c.end ? '→' + c.end : ' open'}${c.is_rent ? ' RENT' : ''}</div>`).join('')}</details>` : '');
    render();
    return j;
  }
  async function postOne(r) {
    if (!confirm(`Post ${r.pcode} · ${r.tenant} to Rentvine now (${fmt.money(r.rent)} → ${fmt.money(r.new_rent)} from ${fmt.date(r.increase_date)})?`)) return;
    $('s-log').innerHTML = '<div class="muted">Posting…</div>';
    const j = await api('rv_post', { lease_id: r.lease_id, cycle: S.cycle, confirm: 1 });
    $('s-log').innerHTML = (j.log || []).map(l => `<div class="${l.ok ? 'pos' : 'neg'}">${l.ok ? '✓' : '✗'} ${fmt.esc(l.step)} ${fmt.esc(l.note || l.error || '')}</div>`).join('') + (j.ok ? '' : `<div class="strip err">${fmt.esc(j.error || 'failed')}</div>`);
    await load(); await pick(r.lease_id); await verifyOne(r.lease_id, $('s-checks'));
  }

  // ---------- batch
  $('btn-all').onclick = () => { S.rows.filter(r => r.ready || r.partial).forEach(r => S.checked.add(r.lease_id)); render(); };
  $('btn-none').onclick = () => { S.checked.clear(); render(); };
  $('btn-verify-all').onclick = async () => {
    const ids = [...S.checked]; if (!ids.length) { toast('Select rows first', true); return; }
    S.busy = true; render(); const log = $('b-log'); log.innerHTML = '';
    let ok = 0, bad = 0;
    for (const id of ids) {
      const r = S.rows.find(x => x.lease_id === id); if (!r) continue;
      const j = await verifyOne(id, null);
      const good = j && j.verified; good ? ok++ : bad++;
      log.insertAdjacentHTML('beforeend', `<div class="${good ? 'pos' : 'neg'}">${good ? '✓' : '✗'} <strong>${fmt.esc(r.pcode)}</strong> ${fmt.esc(r.tenant)} · ${fmt.esc(j ? j.note : 'no reply')}</div>`);
    }
    log.insertAdjacentHTML('afterbegin', `<div class="strip ${bad ? 'err' : ''}">${ok} verified · ${bad} need a look${bad ? ' - uncheck them or fix on Main before uploading' : ''}</div>`);
    S.busy = false; render();
  };
  $('btn-upload').onclick = () => {
    const ids = [...S.checked]; if (!ids.length) { toast('Select rows first', true); return; }
    const rows = ids.map(id => S.rows.find(x => x.lease_id === id)).filter(Boolean);
    const unverified = rows.filter(r => r.verify_ok !== 1).length;
    openModal(`<h2>Upload ${rows.length} to Rentvine · ${fmt.cycle(S.cycle)}</h2>
      <div>Each selected row is sent in the four steps: end the old rent charge the day before, new rent charge from ${fmt.date(S.board.cycle.increase)}, deposit increase to the ledger, Last Renewal Date. Finished steps are never repeated; rows already posted are skipped.</div>
      ${unverified ? `<div class="strip warn">${unverified} of these have not been verified as ✓. Verify first, or go ahead if you have checked them yourself.</div>` : '<div class="strip">All selected rows verified ✓.</div>'}
      <div class="log" id="post-log"></div>
      <div class="row" style="justify-content:flex-end"><button class="btn" id="m-close">Cancel</button><button class="btn pri" id="m-go">Upload ${rows.length} now</button></div>`);
    $('m-close').onclick = closeModal;
    $('m-go').onclick = async () => {
      if (!confirm('Upload ' + rows.length + ' renewals to Rentvine now?')) return;
      $('m-go').disabled = true; $('post-log').innerHTML = '<div class="muted">Posting… a few seconds per lease.</div>';
      const j = await api('cycle_post', { cycle: S.cycle, confirm: 1, only: ids });
      $('post-log').innerHTML = `<div class="strip ${j.failed ? 'err' : ''}">${j.done} posted · ${j.failed} failed · ${j.skipped} skipped</div>` + (j.log || []).map(l => `<div class="${l.ok ? '' : 'neg'}">${l.ok ? '✓' : '✗'} <strong>${fmt.esc(l.pcode)}</strong> ${fmt.esc(l.tenant)}: ${l.steps.map(fmt.esc).join(', ')}</div>`).join('');
      $('m-close').textContent = 'Close';
      await load();
      // verify what was just posted, so the table shows ✓ / ✗ without another click
      $('post-log').insertAdjacentHTML('beforeend', '<div class="muted">Verifying…</div>');
      let v = 0, bad = 0;
      for (const l of (j.log || [])) { const r = await verifyOne(l.lease_id, null); if (r && r.verified) v++; else bad++; }
      $('post-log').insertAdjacentHTML('beforeend', `<div class="strip ${bad ? 'err' : ''}">Verified ${v} · ${bad} need a look (see the Verified column)</div>`);
      S.filter = bad ? 'problem' : 'posted'; S.checked.clear(); render();
    };
  };
  $('btn-final').onclick = async () => {
    if (S.board.cycle.finalized) { const j = await api('cycle_unfinalize', { cycle: S.cycle }); if (j.ok) { toast('Cycle reopened'); load(); } return; }
    const c = counts();
    const left = c.ready + c.problem + c.partial;
    if (!confirm('Make ' + fmt.cycle(S.cycle) + ' permanent? ' + (left ? left + ' rows are not posted yet and will stay that way. ' : '') + 'Rows become read-only (FileMaker "make permanent record").')) return;
    const j = await api('cycle_finalize', { cycle: S.cycle, confirm: 1 }); if (j.ok) { toast('Finalized'); load(); } else toast(j.error, true);
  };

  $('search').addEventListener('input', () => { S.q = $('search').value; render(); });
  $('c-prev').onclick = () => { S.checked.clear(); load(S.board.cycle.prev); }; $('c-next').onclick = () => { S.checked.clear(); load(S.board.cycle.next); }; $('c-today').onclick = () => { S.checked.clear(); load(S.board.cycle.default); };
  $('btn-reload').onclick = () => load();
  function openModal(html) { $('modal-box').innerHTML = html; $('modal').classList.remove('hide'); }
  function closeModal() { $('modal').classList.add('hide'); }
  $('modal').addEventListener('click', (e) => { if (e.target === $('modal')) closeModal(); });
  document.addEventListener('keydown', (e) => {
    if (!$('modal').classList.contains('hide')) { if (e.key === 'Escape') closeModal(); return; }
    const tag = (e.target.tagName || '').toLowerCase(); if (tag === 'input' || tag === 'textarea') return;
    if (e.key === 'ArrowDown' || e.key === 'ArrowUp') { e.preventDefault(); const rows = filtered(); const i = rows.findIndex(r => r.lease_id === S.sel); const n = rows[Math.min(rows.length - 1, Math.max(0, i + (e.key === 'ArrowDown' ? 1 : -1)))]; if (n) pick(n.lease_id); }
  });
  bus.subscribe((m) => { if (m.from === 'main' && m.record_id && m.saved) { if (m.cycle && m.cycle !== S.cycle) return; load(); } }, { poll: 5000 });
  load();
})();
</script>
</body>
</html>
