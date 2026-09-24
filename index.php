<?php
// Renewal Center - Main window (center monitor): queue | record | context + actions
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
<title>Renewal Center</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans:wght@400;500;600;700&family=IBM+Plex+Mono:wght@500;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/app.css?v=<?= RNW_REV ?>">
</head>
<body>
<div class="shell">

  <!-- ============ LEFT: the set lives on the Prep screen now (Larry, Sep 24); this pane is kept
       hidden because the record's Next / Prev and the category counts still come from it ============ -->
  <div class="pane left hide">
    <div class="head">
      <div class="row between">
        <div class="title">Renewals <span class="pill" id="ver">v<?= e(rnw_version()) ?></span></div>
        <div class="row" style="gap:4px" id="offices"></div>
      </div>
      <div class="row" style="gap:6px">
        <button class="btn xs" id="c-prev" aria-label="Previous cycle">&lt;</button>
        <strong id="c-label" style="font-size:13px">—</strong>
        <button class="btn xs" id="c-next" aria-label="Next cycle">&gt;</button>
        <div class="grow"></div>
        <a href="prep.php" target="rc-prep" id="lnk-prep" class="btn xs" style="text-decoration:none;display:inline-flex;align-items:center">Prep window</a>
      </div>
      <div class="muted" style="font-size:11px" id="c-info"></div>
      <input type="search" id="search" class="in" placeholder="Search tenant, property, ID…" aria-label="Search renewals">
      <div class="chips" id="cats"></div>
    </div>
    <div class="label" style="padding:8px 16px" id="qlabel">All categories · zip › pcode</div>
    <div class="body" id="queue"></div>
    <div class="foot"><span id="qcount">—</span><span id="synced">Sync —</span></div>
  </div>

  <!-- ============ CENTER: record ============ -->
  <div class="pane center">
    <div id="rec" class="pane" style="flex:1;min-height:0">
      <div class="row" style="padding:8px 24px 0;gap:8px;flex-wrap:wrap;background:#fff">
        <span class="title" style="font-size:15px">Renewals <span class="pill">v<?= e(rnw_version()) ?></span></span>
        <div class="row" style="gap:4px" id="offices2"></div>
        <span class="muted">·</span>
        <button class="btn xs" id="c-prev2" aria-label="Previous cycle">&lt;</button>
        <strong id="c-label2" style="font-size:13px">—</strong>
        <button class="btn xs" id="c-next2" aria-label="Next cycle">&gt;</button>
        <span class="muted" style="font-size:11px" id="c-info2"></span>
        <div class="grow"></div>
        <span class="muted" style="font-size:11px" id="pos"></span>
        <a href="prep.php" target="rc-prep" id="lnk-prep2" class="btn xs" style="text-decoration:none;display:inline-flex;align-items:center">Prep screen</a>
      </div>
      <div class="rec-head">
        <div class="grow" style="display:flex;flex-direction:column;gap:2px">
          <div class="row" style="gap:10px;flex-wrap:wrap">
            <span class="h1" id="h-prop">Pick a renewal</span>
            <span class="pill" id="h-pcode"></span>
            <span class="tag lg" id="h-cat"></span>
            <span class="tag lg ptype" id="h-ptype"></span>
            <span class="tag lg" id="h-status"></span>
            <button class="btn xs pri hide" id="btn-add-set">Add to this set</button>
          </div>
          <div class="sub" id="h-sub"></div>
        </div>
        <label class="chk"><input type="checkbox" id="f-revisit"> Revisit</label>
        <button class="btn" id="btn-settings">Settings</button>
      </div>

      <div class="body" style="padding:14px 24px;display:flex;flex-direction:column;gap:14px" id="recbody">
        <div class="grid2">
          <!-- rent decision -->
          <div class="card">
            <div class="row between">
              <h3>Rent decision</h3>
              <div style="font-size:12px;color:var(--warn);font-weight:600" id="mtm-note"></div>
            </div>
            <div class="grid3 money-row">
              <div class="fld"><span>Current rent</span><span class="money" id="cur-rent">—</span></div>
              <div class="fld"><span>New rent <span style="color:var(--blue)" id="firstyr"></span></span>
                <span class="row" style="gap:2px"><span class="money blue">$</span><input class="in sm mono money-in" id="f-new-rent"></span></div>
              <div class="fld"><span>% increase</span><span class="money green" id="pct">—</span></div>
            </div>
            <div class="row" style="flex-wrap:wrap;gap:6px">
              <span class="muted" style="width:36px">Step</span>
              <span class="row" id="steps"></span>
              <div class="grow"></div>
              <button class="btn arrow" id="dec" aria-label="Lower new rent">&lt;</button>
              <button class="btn arrow pri" id="inc" aria-label="Raise new rent">&gt;</button>
            </div>
            <div class="grid4 dep-row">
              <div class="fld"><span>Rent starts</span><input class="in sm" id="f-increase-date" type="date"></div>
              <div class="fld"><span>Current deposit</span><span class="row" style="gap:2px"><span class="mono">$</span><input class="in sm mono" id="f-cur-dep"></span></div>
              <div class="fld"><span>New deposit</span><span class="row" style="gap:2px"><span class="mono">$</span><input class="in sm mono" id="f-new-dep"></span></div>
              <div class="fld"><span><strong>SDR increase</strong></span><span class="money red" style="font-size:18px;line-height:34px" id="sdr">—</span></div>
            </div>
            <div class="ranges">
              <div class="grid5">
                <div class="fld"><span>RANGE TOP</span><span class="row" style="gap:2px"><span class="mono">$</span><input class="in sm mono" id="f-range-top"></span></div>
                <div class="fld"><span>BASELINE 60%</span><span class="v" id="r-base">—</span></div>
                <div class="fld"><span>RENTSTART 80%</span><span class="v" id="r-start" style="color:var(--green)">—</span></div>
                <div class="fld"><span>RENTDROP 92%</span><span class="v" id="r-drop" style="color:var(--red)">—</span></div>
                <div class="fld"><span>RANGE BOTTOM</span><span class="row" style="gap:2px"><span class="mono">$</span><input class="in sm mono" id="f-range-bottom"></span></div>
              </div>
              <div class="muted" style="font-size:11px;margin-top:6px" id="r-basis">—</div>
            </div>
          </div>
          <!-- evaluation + lease -->
          <div style="display:flex;flex-direction:column;gap:18px">
            <div class="card eval">
              <div class="row between"><h3>Evaluation</h3><span class="muted" id="last-renewal"></span></div>
              <div class="row" style="gap:6px"><input class="in sm" id="f-prop-vaoao" placeholder="Building / AOAO (e.g. Royal Kuhio AOAO)"><input class="in sm" id="f-prop-color" type="color" title="colour on the Prep list" style="width:44px;padding:2px"></div>
              <div class="grid3" style="gap:8px">
                <label class="fld">Top<input class="in sm" id="f-eval-top"></label>
                <label class="fld">Recom<input class="in sm" id="f-eval-recom"></label>
                <label class="fld">Bottom<input class="in sm" id="f-eval-bottom"></label>
              </div>
              <div class="chips">
                <label class="chk" style="font-size:12px"><input type="checkbox" id="f-special"> RNW spec</label>
                <label class="chk" style="font-size:12px"><input type="checkbox" id="f-oa"> OA</label>
                <label class="chk" style="font-size:12px"><input type="checkbox" id="f-no-increase"> No increase</label>
                <span class="grow"></span>
                <select class="in sm" id="f-cat-override" style="width:auto;height:30px"><option value="">Category: auto</option></select>
              </div>
            </div>
            <div class="card">
              <h3>Lease</h3>
              <div class="grid3" style="gap:8px 12px;font-size:12px" id="lease-grid"></div>
            </div>
          </div>
        </div>

        <div class="grid2">
          <label class="fld vaoao"><span class="label">Renewal special · this property, every cycle</span><textarea class="in" id="f-prop-special" placeholder="e.g. Unit allows a small pet. Call owner before sending renewal."></textarea></label>
          <div style="display:flex;flex-direction:column;gap:6px">
            <label class="fld notes"><span class="label">Notes · this cycle</span><textarea class="in" id="f-notes" placeholder="Notes for this renewal…" style="height:38px"></textarea></label>
            <label class="fld"><span class="label">Remarks (list column)</span><input class="in sm" id="f-remarks" placeholder="short remark shown on the Prep list"></label>
          </div>
        </div>

        <div style="display:flex;flex-direction:column;gap:8px">
          <div class="row between"><span class="label">Same building — rent history</span><span class="muted" id="hist-n"></span></div>
          <div class="tbl hist">
            <div class="tr th"><span>Unit / config</span><span>Rent</span><span>Last renewal</span><span>Move in</span><span>Tenant</span></div>
            <div id="hist"></div>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- ============ RIGHT: context + actions ============ -->
  <div class="pane right">
    <div class="head" style="padding:12px 16px;gap:8px">
      <div class="label">Linked windows · follow this record</div>
      <div class="links">
        <a href="media.php" target="rc-media" id="lnk-media"><span class="dot sm" id="dot-media"></span>Media — left monitor</a>
        <a href="comps.php" target="rc-comps" id="lnk-comps"><span class="dot sm" id="dot-comps"></span>Comps — right monitor</a>
      </div>
    </div>
    <div class="body" style="padding:16px;display:flex;flex-direction:column;gap:14px">
      <div class="strip" id="market">Pin comps on the Comps window to get a market check here.</div>
      <div class="card white">
        <h3>Rentvine</h3>
        <div style="font-size:12px;color:var(--ink2)" id="rv-state">Not posted.</div>
        <div class="row"><button class="btn sm" id="btn-rv-plan">Preview the 4 steps</button><button class="btn sm" id="btn-events">Activity</button></div>
      </div>
      <div class="card white">
        <h3>Past renewals</h3>
        <div id="past" style="font-size:12px;display:flex;flex-direction:column;gap:3px"></div>
      </div>
      <div class="card white">
        <h3>Last SEV</h3>
        <div style="font-size:12px;color:var(--ink2)">Videos for this unit live in SEV Center.</div>
        <a id="lnk-sev" href="https://apps.oishis.net/sev/" target="_blank" style="font-size:12px;font-weight:600">Open in SEV Center →</a>
      </div>
      <div class="card white">
        <h3>Move-out</h3>
        <div class="grid2" style="gap:8px;font-size:12px">
          <div class="fld"><span>D move out</span><strong id="mo-date">—</strong></div>
          <div class="fld"><span>T. notice</span><strong id="mo-notice">—</strong></div>
        </div>
      </div>
      <div class="card white">
        <h3>Contact</h3>
        <div style="font-size:12px;color:var(--ink2)" id="contact">—</div>
      </div>
    </div>
    <div class="actions">
      <div class="grid2" style="gap:8px">
        <button class="btn lg warn" id="btn-prep" title="Open the Prep screen: pull the set for this increase month, add by hand, print the list, upload">Prep / Print</button>
        <button class="btn lg pri" id="btn-save" title="Enter">Save</button>
      </div>
      <div class="grid3" style="gap:8px">
        <button class="btn md" id="btn-pau">Pau renewal</button>
        <button class="btn md" id="btn-kpi">KPI</button>
        <button class="btn md" id="btn-next" title="→">Next →</button>
      </div>
      <button class="btn lg" id="btn-post" style="border-color:var(--teal);color:var(--teal)">Post to Rentvine…</button>
    </div>
  </div>
</div>

<div id="modal" class="modal hide"><div class="box" id="modal-box"></div></div>

<script src="assets/app.js?v=<?= RNW_REV ?>"></script>
<script>
(() => {
  const { api, bus, display, fmt, toast, LS } = RC;
  const $ = (id) => document.getElementById(id);
  const S = { board: null, queue: [], filtered: [], cat: 'all', q: '', sel: null, rec: null, dirty: false, saving: false, alive: { media: 0, comps: 0 }, cycle: LS('renewal.cycle') || '', lastPick: 0 };
  const C = () => ({ cycle: S.cycle });

  // ---------- board / queue
  async function loadBoard(office) {
    const j = await api('board', Object.assign(office ? { office } : {}, S.cycle ? { cycle: S.cycle } : {}));
    if (!j.ok) { toast(j.error, true); return; }
    S.board = j; S.queue = j.queue; S.cycle = j.cycle.cycle; LS('renewal.cycle', S.cycle);
    $('c-label').textContent = 'Increase ' + fmt.date(j.cycle.increase) + (j.cycle.finalized ? ' · FINAL' : '');
    $('lnk-prep').href = 'prep.php?cycle=' + encodeURIComponent(S.cycle);
    $('c-label2').textContent = $('c-label').textContent; $('lnk-prep2').href = $('lnk-prep').href;
    $('c-info2').textContent = `run ${fmt.cycle(j.cycle.run_month)} · letters by ${fmt.dateShort(j.cycle.letters_by)} · ${j.totals.count} in the set · ${j.totals.filled} filled · +${fmt.money(j.totals.increase)}/mo`;
    $('c-prev2').onclick = () => { S.cycle = j.cycle.prev; S.sel = null; loadBoard(); }; $('c-next2').onclick = () => { S.cycle = j.cycle.next; S.sel = null; loadBoard(); };

    $('c-info').textContent = `run ${fmt.cycle(j.cycle.run_month)} · letters by ${fmt.dateShort(j.cycle.letters_by)} · ${j.totals.filled}/${j.totals.count} filled · +${fmt.money(j.totals.increase)}/mo`;
    $('c-prev').onclick = () => { S.cycle = j.cycle.prev; S.sel = null; loadBoard(); }; $('c-next').onclick = () => { S.cycle = j.cycle.next; S.sel = null; loadBoard(); };
    display.apply(j.display.on, j.display.scale);
    $('offices').innerHTML = (j.offices.length ? j.offices : [{ code: j.office.code, label: j.office.label }])
      .map(o => `<button class="btn sm ${o.code === j.office.code ? 'on' : ''}" data-office="${fmt.esc(o.code)}" aria-label="${fmt.esc(o.label)}">${fmt.esc(o.code)}</button>`).join('');
    $('offices').querySelectorAll('button').forEach(b => b.onclick = () => loadBoard(b.dataset.office));
    $('offices2').innerHTML = $('offices').innerHTML; $('offices2').querySelectorAll('button').forEach(b => b.onclick = () => loadBoard(b.dataset.office));
    const cats = [['all', 'All']].concat(Object.keys(j.cats).map(k => [k, k + ' ' + j.cats[k]]));
    $('cats').innerHTML = cats.map(([k, l]) => `<button class="btn xs ${S.cat === k ? 'on' : ''}" data-cat="${k}">${fmt.esc(l)}${j.counts[k] ? ' · ' + j.counts[k] : ''}</button>`).join('');
    $('cats').querySelectorAll('button').forEach(b => b.onclick = () => { S.cat = b.dataset.cat; loadBoard(); });
    $('synced').textContent = j.sync.ready ? ('Sync ' + (j.sync.last ? fmt.date(j.sync.last) + ' ' + String(j.sync.last).slice(11, 16) : '—') + ' · ' + j.sync.leases + ' leases') : 'Sync Center tables not found';
    const opt = $('f-cat-override');
    if (opt.options.length === 1) { Object.keys(j.cats).forEach(k => { const o = document.createElement('option'); o.value = k; o.textContent = k + ' ' + j.cats[k]; opt.appendChild(o); }); }
    renderQueue();
    if (!S.sel && S.filtered.length) { pick(S.filtered[0].lease_id); }
    else if (S.sel) { const still = S.queue.find(r => r.lease_id === S.sel); if (!still && S.filtered.length) pick(S.filtered[0].lease_id); }
  }

  function renderQueue() {
    const q = S.q.toLowerCase();
    S.filtered = S.queue.filter(r => (S.cat === 'all' || String(r.cat) === S.cat)
      && (!q || [r.tenant, r.property, r.unit, r.pcode, r.lease_id, r.address].join(' ').toLowerCase().includes(q)));
    $('qlabel').textContent = (S.cat === 'all' ? 'All categories' : 'Filtered') + ' · zip › pcode';
    $('qcount').textContent = S.filtered.length + ' in the set · category › zip › pcode';
    const today = new Date(); today.setHours(0, 0, 0, 0);
    $('queue').innerHTML = S.filtered.map(r => {
      const days = r.end ? Math.round((new Date(r.end + 'T00:00:00') - today) / 86400000) : null;
      const due = r.mtm ? 'MTM' : (days === null ? '—' : (days < 0 ? Math.abs(days) + ' d over' : days + ' d'));
      const dueColor = days !== null && days <= 14 ? 'var(--red)' : (days !== null && days <= 45 ? 'var(--warn)' : 'var(--muted)');
      return `<button class="qrow ${r.lease_id === S.sel ? 'on' : ''}" data-id="${fmt.esc(r.lease_id)}">
        <div class="row between"><span class="t">${fmt.esc(r.tenant || '(no tenant)')}</span><span class="due" style="color:${dueColor}">${due}</span></div>
        <div class="p">${fmt.esc(r.property)}${r.unit ? ' #' + fmt.esc(r.unit) : ''}</div>
        <div class="row" style="gap:6px"><span class="tag c${r.cat}">${fmt.esc(r.cat_label)}</span>
          ${r.status ? `<span class="tag ${r.status}">${r.status}</span>` : ''}
          <span class="muted" style="font-size:11px">${r.mtm ? 'MTM' : 'Lease end ' + fmt.dateShort(r.end)}${r.unfilled ? ' · <span style="color:var(--warn);font-weight:700">unfilled</span>' : ' · ' + fmt.money(r.new_rent) + ' ' + fmt.pct(r.pct)}</span></div>
      </button>`;
    }).join('') || '<div class="muted" style="padding:24px 16px;text-align:center">Nothing in the queue for this filter.</div>';
    $('queue').querySelectorAll('.qrow').forEach(b => b.onclick = () => pick(b.dataset.id));
    const i = S.filtered.findIndex(r => r.lease_id === S.sel);
    $('pos').textContent = S.filtered.length ? ((i >= 0 ? (i + 1) + ' of ' : '') + S.filtered.length + ' in the set · Next / ← →') : 'set is empty · open the Prep screen';
  }
  $('search').addEventListener('input', () => { S.q = $('search').value; renderQueue(); });

  // ---------- record
  async function pick(id) {
    if (S.dirty && S.sel && S.sel !== id) { await save(true); }
    S.sel = id; S.lastPick = Date.now();
    renderQueue();
    const j = await api('record', { lease_id: id, cycle: S.cycle, create: 1 });
    if (!j.ok) { toast(j.error, true); return; }
    S.rec = j; S.dirty = false;
    renderRecord();
    publish();
  }
  function publish() {
    const L = S.rec.lease, q = S.rec.q;
    bus.publish({ record_id: L.lease_id, cycle: S.cycle, saved: !!S.justSaved, rent_proposal: Number(q.new_rent || L.rent || 0), current_rent: Number(q.current_rent || L.rent || 0),
      pct: Number(q.pct_inc || 0), tenant: L.tenant, property: L.property, unit: L.unit, pcode: L.pcode, address: L.address,
      zip: L.zip, bed: L.bed, bath: L.bath, sqft: L.sqft, parking: L.parking, city: L.city, pinned: q.pinned_comps || [] });
  }

  function renderRecord() {
    const L = S.rec.lease, q = S.rec.q, R = S.rec.ranges;
    $('h-prop').textContent = (L.property || L.address || 'Lease ' + L.lease_id) + (L.unit ? ' #' + L.unit : '');
    $('h-pcode').textContent = L.pcode || L.lease_id; $('h-pcode').title = L.code || '';
    $('h-cat').textContent = S.rec.cat_label; $('h-cat').className = 'tag lg ' + (S.rec.cat === null ? '' : 'c' + S.rec.cat); $('h-cat').title = S.rec.reason;
    $('btn-add-set').classList.toggle('hide', S.rec.in_set || S.rec.finalized);
    $('h-ptype').textContent = L.ptype || ''; $('h-ptype').classList.toggle('hide', !L.ptype);
    $('h-status').textContent = q.status === 'open' ? '' : q.status; $('h-status').className = 'tag lg ' + q.status; $('h-status').classList.toggle('hide', q.status === 'open');
    $('h-sub').innerHTML = `Owner <strong>${fmt.esc(L.owner || '—')}</strong> &nbsp;·&nbsp; Tenant <strong>${fmt.esc(L.tenant || '—')}</strong> &nbsp;·&nbsp; ${fmt.esc(L.address || '')}${L.zip && !(L.address || '').includes(L.zip) ? ' ' + fmt.esc(L.zip) : ''}`;
    $('mtm-note').textContent = L.mtm ? 'Renew MTM every 2 years' : '';
    $('firstyr').textContent = (S.rec.cat === 2) ? '(blue = 1st yr)' : '';
    $('cur-rent').textContent = fmt.money(q.current_rent);
    $('f-new-rent').value = q.new_rent !== null ? Number(q.new_rent).toFixed(0) : ''; $('f-new-rent').placeholder = q.new_rent === null ? 'unfilled' : '';
    $('f-increase-date').value = q.increase_date || '';
    $('f-cur-dep').value = q.current_deposit !== null ? Number(q.current_deposit).toFixed(0) : '';
    $('f-new-dep').value = q.new_deposit !== null ? Number(q.new_deposit).toFixed(0) : '';
    $('f-range-top').value = R.auto ? '' : Number(R.top).toFixed(0); $('f-range-top').placeholder = Number(R.top).toFixed(0);
    $('f-range-bottom').value = R.auto ? '' : Number(R.bottom).toFixed(0); $('f-range-bottom').placeholder = Number(R.bottom).toFixed(0);
    $('r-basis').textContent = (R.auto ? 'auto from ' + R.basis : 'set by hand') + ' · edit top / bottom to override';
    ['eval_top', 'eval_recom', 'eval_bottom', 'notes', 'remarks'].forEach(k => $('f-' + k.replace('_', '-')).value = q[k] || '');
    const P = S.rec.property || {};
    $('f-prop-special').value = P.special || ''; $('f-prop-vaoao').value = P.vaoao || ''; $('f-prop-color').value = P.color || '#ffffff';
    $('past').innerHTML = (S.rec.past || []).filter(p => p.cycle !== S.cycle).map(p => `<div class="row between"><span>${fmt.cycle(p.cycle)} <span class="tag ${p.status}">${p.status}</span></span><span class="mono">${fmt.money(p.current_rent)} → ${fmt.money(p.new_rent)} ${p.pct_inc !== null ? '(' + fmt.pct(p.pct_inc) + ')' : ''}</span></div>`).join('') || '<span class="muted">none in this app yet</span>';
    const fin = !!S.rec.finalized;
    ['btn-save', 'btn-pau', 'btn-prep'].forEach(id => $(id).disabled = fin);
    if (fin) $('btn-save').textContent = 'Finalized';
    ['revisit', 'special', 'oa', 'no_increase'].forEach(k => $('f-' + k.replace('_', '-')).checked = !!Number(q[k]));
    $('f-cat-override').value = q.category_override === null ? '' : String(q.category_override);
    const A = S.rec.anchor || {}; $('last-renewal').textContent = A.date ? 'Last increase ' + fmt.date(A.date) + ' (' + A.source + ')' : 'No increase date on file';
    const yrs = L.move_in ? ((Date.now() - new Date(L.move_in)) / 31557600000).toFixed(1) : '—';
    $('lease-grid').innerHTML = [['Move in', fmt.date(L.move_in)], ['Lease end', L.mtm ? (L.end ? fmt.date(L.end) + ' (MTM)' : 'MTM') : fmt.date(L.end)], ['Lease yrs', yrs],
      ['Type', L.mtm ? 'Month-to-month' : 'Fixed'], ['Bed / bath', (L.bed ?? '—') + ' / ' + (L.bath ?? '—')], ['Sq ft · parking', (L.sqft ?? '—') + ' · ' + (L.parking || '—')],
      ['Rent starts', fmt.date(q.increase_date)], ['Deposit on file', fmt.money(L.deposit)], ['Increase eligible (Rentvine)', fmt.date(L.next_increase)],
      ['Rentvine lease', L.lease_id + (L.code ? ' · ' + L.code : '') + (L.rent_source ? ' · rent from ' + L.rent_source : '')]]
      .map(([k, v]) => `<div class="fld"><span>${k}</span><strong>${fmt.esc(v)}</strong></div>`).join('');
    $('hist-n').textContent = S.rec.history.length + ' other unit' + (S.rec.history.length === 1 ? '' : 's');
    $('hist').innerHTML = [{ unit: L.unit, bed: L.bed, bath: L.bath, parking: L.parking, rent: L.rent, last_increase: L.last_renewal || L.last_increase, move_in: L.move_in, tenant: L.tenant, me: true }]
      .concat(S.rec.history).map(h => `<div class="tr ${h.me ? 'me' : ''}"><span>#${fmt.esc(h.unit || '—')} · ${h.bed ?? '?'}/${h.bath ?? '?'}${h.parking ? ' · ' + fmt.esc(h.parking) + ' pk' : ''}</span>
        <span class="mono">${fmt.money(h.rent)}</span><span>${fmt.date(h.last_increase)}</span><span>${fmt.date(h.move_in)}</span><span>${fmt.esc(h.tenant)}</span></div>`).join('');
    $('mo-date').textContent = fmt.date(L.move_out); $('mo-notice').textContent = fmt.date(L.notice);
    $('contact').innerHTML = `${fmt.esc(L.phone || '—')}<br>${fmt.esc(L.email || '')}`;
    $('lnk-sev').href = 'https://apps.oishis.net/sev/?q=' + encodeURIComponent(L.pcode || L.address || '');
    $('btn-pau').textContent = q.status === 'open' ? 'Pau renewal' : 'Reopen';
    $('btn-post').textContent = q.status === 'posted' ? 'Posted to Rentvine ✓' : 'Post to Rentvine…';
    $('btn-post').disabled = q.status === 'posted';
    $('rv-state').textContent = q.status === 'posted' ? 'Posted ' + fmt.date(q.posted_at) + ' by ' + q.posted_by
      : (q.rv_new_charge_id ? 'Partly posted - open the preview to finish.' : 'Not posted. Rent starts ' + fmt.date(q.increase_date) + '.');
    $('btn-prep').textContent = 'Prep / Print';
    renderSteps(); recalc(false);
  }

  function renderSteps() {
    const q = S.rec.q, steps = S.rec.steps;
    $('steps').innerHTML = steps.map(s => `<button class="btn mono ${Number(q.step_pct) === s ? 'on' : ''}" style="height:34px;min-width:64px;font-weight:700" data-step="${s}">${s}%</button>`).join('');
    $('steps').querySelectorAll('button').forEach(b => b.onclick = () => {
      const cur = Number(S.rec.q.current_rent || 0), s = Number(b.dataset.step);
      $('f-new-rent').value = Math.round(cur * (1 + s / 100));
      S.rec.q.step_pct = s; markDirty(); recalc(true);
    });
  }

  // live math: pct, SDR, ranges - mirrors the server
  function recalc(dirty) {
    const q = S.rec.q, cur = Number(q.current_rent || 0), raw = $('f-new-rent').value.trim(), nr = raw === '' ? null : Number(raw);
    const pct = (nr !== null && cur) ? ((nr - cur) / cur) * 100 : null;
    $('pct').textContent = pct === null ? '—' : fmt.pct(pct); $('pct').className = 'money ' + (pct > 0 ? 'green' : (pct < 0 ? 'red' : ''));
    const curDep = $('f-cur-dep').value === '' ? null : Number($('f-cur-dep').value);
    if (dirty && $('f-new-dep').dataset.auto !== 'off') { $('f-new-dep').value = nr !== null ? Math.round(nr) : ''; }
    const newDep = $('f-new-dep').value === '' ? null : Number($('f-new-dep').value);
    const sdr = (newDep !== null && curDep !== null) ? Math.max(0, newDep - curDep) : null;
    $('sdr').textContent = sdr === null ? '—' : '+' + fmt.money(sdr);
    const top = Number($('f-range-top').value || $('f-range-top').placeholder || 0), bot = Number($('f-range-bottom').value || $('f-range-bottom').placeholder || 0);
    const pos = p => fmt.money(Math.round(bot + (top - bot) * p));
    $('r-base').textContent = pos(.60); $('r-start').textContent = pos(.80); $('r-drop').textContent = pos(.92);
    $('steps').querySelectorAll('button').forEach(b => b.classList.toggle('on', pct !== null && Math.abs(Number(b.dataset.step) - pct) < 0.05));
    if (dirty) { markDirty(); if (S.rec) { S.rec.q.new_rent = nr; S.rec.q.pct_inc = pct; publish(); } }
  }
  function markDirty() { S.dirty = true; $('btn-save').textContent = 'Save •'; }
  $('inc').onclick = () => { $('f-new-rent').value = Math.round(Number($('f-new-rent').value || S.rec.q.current_rent || 0) + S.rec.step_dollars); recalc(true); };
  ['f-prop-special', 'f-prop-vaoao', 'f-prop-color', 'f-remarks'].forEach(id => $(id).addEventListener('change', markDirty));
  $('dec').onclick = () => { $('f-new-rent').value = Math.max(0, Math.round(Number($('f-new-rent').value || 0) - S.rec.step_dollars)); recalc(true); };
  ['f-new-rent', 'f-cur-dep', 'f-range-top', 'f-range-bottom'].forEach(id => $(id).addEventListener('input', () => recalc(true)));
  $('f-new-dep').addEventListener('input', () => { $('f-new-dep').dataset.auto = 'off'; recalc(true); });
  ['f-increase-date', 'f-eval-top', 'f-eval-recom', 'f-eval-bottom', 'f-notes', 'f-revisit', 'f-special', 'f-oa', 'f-no-increase', 'f-cat-override']
    .forEach(id => $(id).addEventListener('change', markDirty));

  function collect() {
    return { lease_id: S.sel, cycle: S.cycle, new_rent: $('f-new-rent').value, step_pct: S.rec.q.step_pct, increase_date: $('f-increase-date').value,
      prop_special: $('f-prop-special').value, prop_vaoao: $('f-prop-vaoao').value, prop_color: $('f-prop-color').value === '#ffffff' ? '' : $('f-prop-color').value, remarks: $('f-remarks').value,
      current_deposit: $('f-cur-dep').value, new_deposit: $('f-new-dep').value,
      range_top: $('f-range-top').value, range_bottom: $('f-range-bottom').value,
      eval_top: $('f-eval-top').value, eval_recom: $('f-eval-recom').value, eval_bottom: $('f-eval-bottom').value,
      notes: $('f-notes').value,
      revisit: $('f-revisit').checked, special: $('f-special').checked, oa: $('f-oa').checked, no_increase: $('f-no-increase').checked,
      category_override: $('f-cat-override').value };
  }
  async function save(quiet) {
    if (!S.sel || S.saving) return;
    S.saving = true;
    const j = await api('save', collect());
    S.saving = false;
    if (!j.ok) { toast(j.error, true); return false; }
    S.rec = j; S.dirty = false; $('btn-save').textContent = 'Save'; $('f-new-dep').dataset.auto = '';
    renderRecord(); S.justSaved = true; publish(); S.justSaved = false;
    if (!quiet) toast('Saved · ' + fmt.money(j.q.new_rent) + ' (' + fmt.pct(j.q.pct_inc) + ')');
    const row = S.queue.find(r => r.lease_id === S.sel); if (row) { row.new_rent = j.q.new_rent; row.pct = j.q.pct_inc; row.status = j.q.status; row.unfilled = j.q.new_rent === null; renderQueue(); }
    return true;
  }
  $('btn-save').onclick = () => save(false);
  $('btn-pau').onclick = async () => {
    if (S.dirty) { if (!(await save(true))) return; }
    const j = await api(S.rec.q.status === 'open' ? 'pau' : 'reopen', { lease_id: S.sel, cycle: S.cycle });
    if (!j.ok) { toast(j.error, true); return; }
    toast(S.rec.q.status === 'open' ? 'Pau · out of the queue' : 'Reopened');
    S.rec = j; renderRecord(); await loadBoard();
  };
  // Prep / Print = the FileMaker 1.PREP screen: the set for this increase month
  $('btn-prep').onclick = async () => {
    if (S.dirty) { await save(true); }
    window.open('prep.php?cycle=' + encodeURIComponent(S.cycle), 'rc-prep');
  };
  $('btn-next').onclick = () => next(1);
  $('btn-add-set').onclick = async () => {
    const j = await api('cycle_add', { lease_id: S.sel, cycle: S.cycle });
    if (!j.ok) { toast(j.error, true); return; }
    toast('Added to the ' + fmt.cycle(S.cycle) + ' set'); await loadBoard(); pick(S.sel);
  };
  function next(dir) {
    if (!S.filtered.length) return;
    const i = S.filtered.findIndex(r => r.lease_id === S.sel);
    const n = S.filtered[(i + dir + S.filtered.length) % S.filtered.length];
    if (n) pick(n.lease_id);
  }

  // ---------- keys: Enter = Save, ← → = prev/next (outside text areas)
  document.addEventListener('keydown', (e) => {
    const tag = (e.target.tagName || '').toLowerCase();
    if (!$('modal').classList.contains('hide')) { if (e.key === 'Escape') closeModal(); return; }
    if (e.key === 'Enter' && tag !== 'textarea' && tag !== 'button') { e.preventDefault(); save(false); }
    if ((e.key === 'ArrowRight' || e.key === 'ArrowLeft') && tag !== 'input' && tag !== 'textarea' && tag !== 'select') { e.preventDefault(); next(e.key === 'ArrowRight' ? 1 : -1); }
  });

  // ---------- linked windows: green dot = heard from it in the last 20 s
  bus.subscribe((m) => {
    if (m.from === 'prep' && m.record_id && m.record_id !== S.sel) { if (m.cycle && m.cycle !== S.cycle) { S.cycle = m.cycle; loadBoard().then(() => pick(m.record_id)); } else pick(m.record_id); return; }
    if (m.hello === 'media') S.alive.media = Date.now();
    if (m.hello === 'comps') S.alive.comps = Date.now();
    if (m.pinned && m.record_id === S.sel && S.rec) { S.rec.q.pinned_comps = m.pinned; S.rec.q.comp_median = m.median; renderMarket(); }
    if (m.want === 'current' && S.rec) publish();
  });
  let rowSeen = '';
  setInterval(async () => {
    if (!S.sel || S.dirty) return;
    const j = await api('current_get', {});
    if (j.ok && j.current && j.current.from === 'prep' && j.current.record_id && (j.current.record_id !== S.sel || j.current.cycle !== S.cycle) && Date.parse(j.current.at) > S.lastPick) {
      if (j.current.cycle && j.current.cycle !== S.cycle) { S.cycle = j.current.cycle; await loadBoard(); }
      pick(j.current.record_id); return;
    }
    if (j.ok && j.current && j.current.record_id === S.sel && j.row_updated_at) {
      if (rowSeen && rowSeen !== j.row_updated_at && S.rec) { S.rec.q.pinned_comps = j.pinned || []; S.rec.q.comp_median = j.comp_median; renderMarket(); }
      rowSeen = j.row_updated_at;
    }
  }, 3000);
  setInterval(() => {
    ['media', 'comps'].forEach(w => { const on = Date.now() - S.alive[w] < 20000; $('dot-' + w).className = 'dot sm ' + (on ? '' : 'off'); $('lnk-' + w).className = on ? '' : 'off'; $('lnk-' + w).title = on ? 'Linked in this browser' : 'Not heard from in this browser - a window in its own profile follows through the server every 2 s'; });
    if (S.rec) renderMarket();
  }, 5000);
  function renderMarket() {
    const q = S.rec.q, med = q.comp_median ? Number(q.comp_median) : null, nr = Number(q.new_rent || 0);
    $('market').className = 'strip' + (med ? '' : ' warn');
    $('market').innerHTML = med ? `Comp median <strong>${fmt.money(med)}</strong> · new rent is <strong>${(nr / med * 100).toFixed(0)}%</strong> of median and <strong>${fmt.pct(q.pct_inc)}</strong> over current · ${(q.pinned_comps || []).length} pinned`
      : 'Pin comps on the Comps window to get a market check here.';
  }

  // ---------- modals: Rentvine plan, KPI, activity, settings
  function openModal(html) { $('modal-box').innerHTML = html; $('modal').classList.remove('hide'); }
  function closeModal() { $('modal').classList.add('hide'); }
  $('modal').addEventListener('click', (e) => { if (e.target === $('modal')) closeModal(); });

  async function rvPlan(confirmMode) {
    if (S.dirty) { if (!(await save(true))) return; }
    const j = await api('rv_plan', { lease_id: S.sel, cycle: S.cycle });
    if (!j.ok) { toast(j.error, true); return; }
    const p = j.plan, q = j.q;
    const steps = p.steps.map(s => `<li class="${s.done ? 'ok' : 'todo'}"><strong>${s.done ? '✓' : '○'} ${fmt.esc(s.label)}</strong>${s.note ? ' <span class="muted">(' + fmt.esc(s.note) + ')</span>' : ''}
      <div class="muted mono" style="font-size:11px">${fmt.esc(s.method)} ${fmt.esc(s.url)}</div>${s.body ? `<pre class="small">${fmt.esc(s.body)}</pre>` : ''}</li>`).join('');
    openModal(`<h2>Post to Rentvine — ${fmt.esc(S.rec.lease.property)}${S.rec.lease.unit ? ' #' + fmt.esc(S.rec.lease.unit) : ''}</h2>
      <div class="strip ${p.creds.source === 'none' ? 'err' : ''}">Credentials: ${p.creds.source === 'synccenter' ? 'Sync Center source for this office' : (p.creds.source === 'settings' ? 'this app\'s Settings' : 'NONE - connect Rentvine in Settings')} · base ${fmt.esc(p.creds.base || '—')} · key ${fmt.esc(p.creds.key_tail || '—')}</div>
      <div>New rent <strong class="mono">${fmt.money2(q.new_rent)}</strong> (${fmt.pct(q.pct_inc)}) from <strong>${fmt.date(p.start)}</strong> · SDR increase <strong class="mono">${fmt.money2(q.sdr_delta || 0)}</strong></div>
      <ol class="steps" style="padding-left:18px;margin:0">${steps}</ol>
      <div class="muted" style="font-size:11px">This is a dry run: nothing has been sent. Endpoints and bodies come from Settings › Rentvine and must match the working curl commands. Bills are never created.</div>
      <div id="rv-log"></div>
      <div class="row" style="justify-content:flex-end">
        <button class="btn" id="m-test">Send test (GET lease)</button><div class="grow"></div>
        <button class="btn" id="m-close">Close</button>
        <button class="btn pri" id="m-go" ${p.creds.source === 'none' || q.status === 'posted' ? 'disabled' : ''}>Post now</button>
      </div>`);
    $('m-close').onclick = closeModal;
    $('m-test').onclick = async () => {
      $('rv-log').innerHTML = '<div class="muted">Testing…</div>';
      const t = await api('rv_test', { lease_id: S.sel });
      const r = t.test || {};
      const chg = (r.charges || []).map(c => `<div class="tr" style="grid-template-columns:1fr 2fr 1fr 1fr 1.6fr 1fr"><span class="mono">${fmt.esc(c.id)}</span><span>${fmt.esc(c.desc)}</span><span class="mono">${fmt.money2(c.amount)}</span><span>${fmt.esc(c.end || 'open')}</span><span>${fmt.esc(c.account_name)} <span class="mono">#${fmt.esc(c.account)}</span></span><span>${c.is_rent === true ? '<strong>RENT</strong>' : (c.is_rent === false ? '' : '?')}</span></div>`).join('');
      const acc = (r.accounts || []).map(a => `<div class="tr" style="grid-template-columns:1fr 3fr 1fr"><span class="mono">${fmt.esc(a.id)}</span><span>${fmt.esc(a.name)}</span><span>${a.is_rent ? 'isRent' : ''}</span></div>`).join('');
      $('rv-log').innerHTML = `<div class="strip ${r.ok ? '' : 'err'}">${r.ok ? 'Rentvine answered OK' : 'Failed: ' + fmt.esc(r.error || '')} (HTTP ${r.code}) via ${fmt.esc(r.source)} · ${fmt.esc(r.auth_style || '')} auth · ${fmt.esc(r.base || '')}</div>`
        + (r.ok ? `<div class="label" style="margin-top:8px">Recurring charges on this lease ${r.charges_ok ? '' : '(list call failed)'} · rent charge picked: ${r.rent_charge ? '<strong>#' + fmt.esc(r.rent_charge.id) + ' ' + fmt.esc(r.rent_charge.desc) + '</strong>' : '<strong style="color:var(--red)">none</strong>'}</div>
           <div class="tbl"><div class="tr th" style="grid-template-columns:1fr 2fr 1fr 1fr 1.6fr 1fr"><span>Id</span><span>Description</span><span>Amount</span><span>End</span><span>GL account</span><span></span></div>${chg || '<div class="tr muted">none</div>'}</div>
           <div class="label" style="margin-top:8px">GL accounts that look like rent / deposit ${r.accounts_ok ? '' : '(accounts call failed)'} — copy the ids into Settings › Rentvine</div>
           <div class="tbl"><div class="tr th" style="grid-template-columns:1fr 3fr 1fr"><span>Id</span><span>Name</span><span></span></div>${acc || '<div class="tr muted">none</div>'}</div>
           <details><summary class="muted" style="font-size:11px;cursor:pointer">raw replies</summary><pre class="small">${fmt.esc(r.sample || '')}\n\n${fmt.esc(r.charges_raw || '')}</pre></details>` : `<pre class="small">${fmt.esc(r.sample || '')}</pre>`);
    };
    $('m-go').onclick = async () => {
      if (!confirm('Post this renewal to Rentvine now?\n\n' + p.steps.filter(s => !s.done).map(s => '• ' + s.label).join('\n'))) return;
      $('m-go').disabled = true; $('rv-log').innerHTML = '<div class="muted">Posting…</div>';
      const r = await api('rv_post', { lease_id: S.sel, cycle: S.cycle, confirm: 1 });
      $('rv-log').innerHTML = (r.log || []).map(l => `<div class="${l.ok ? 'ok' : 'fail'}">${l.ok ? '✓' : '✗'} ${l.step}: ${fmt.esc(l.note || l.error || '')}${l.body ? `<pre class="small">${fmt.esc(l.body)}</pre>` : ''}</div>`).join('')
        + (r.ok ? `<div class="strip">${r.done ? 'All steps done - lease is posted.' : 'Steps done so far recorded.'}</div>` : `<div class="strip err">${fmt.esc(r.error || 'Failed')}. Fix the setting or Rentvine side and press Post again - finished steps are skipped.</div>`);
      if (r.lease) { S.rec = r; renderRecord(); loadBoard(); }
      $('m-go').disabled = false;
    };
  }
  $('btn-rv-plan').onclick = () => rvPlan(false);
  $('btn-post').onclick = () => rvPlan(true);

  $('btn-kpi').onclick = async () => {
    const j = await api('kpi', C());
    if (!j.ok) { toast(j.error, true); return; }
    const t = j.totals;
    openModal(`<h2>KPI · increase ${fmt.date(j.cycle.increase)}</h2>
      <div class="kv"><span>In the set</span><strong>${t.count} (fixed ${t.fixed} · MTM ${t.mtm} · addon ${t.addon})</strong><span>Filled</span><strong>${t.filled} of ${t.count}</strong>
      <span>Average increase</span><strong>${j.avg_pct === null ? '—' : fmt.pct(j.avg_pct)}</strong><span>Total increase</span><strong class="mono">${fmt.money(t.increase)} / month</strong>
      <span>Total ASD</span><strong class="mono">${fmt.money(t.asd)}</strong><span>Pau / posted</span><strong>${t.pau} / ${t.posted}</strong><span>Exceptions</span><strong>${t.exceptions}</strong></div>
      <div>${Object.entries(j.by_cat).map(([k, v]) => fmt.esc(k) + ' ' + v).join(' · ') || '—'}</div>
      <div class="row" style="justify-content:flex-end"><button class="btn" onclick="document.getElementById('modal').classList.add('hide')">Close</button></div>`);
  };
  $('btn-events').onclick = async () => {
    const j = await api('events', { lease_id: S.sel });
    openModal(`<h2>Activity · ${fmt.esc(S.rec.lease.property)}</h2><div class="tbl"><div class="tr th" style="grid-template-columns:1.4fr 1fr 1fr 3fr"><span>When</span><span>Event</span><span>Who</span><span>Detail</span></div>
      ${(j.events || []).map(e => `<div class="tr" style="grid-template-columns:1.4fr 1fr 1fr 3fr"><span>${fmt.esc(e.created_at)}</span><span>${fmt.esc(e.event)}</span><span>${fmt.esc(e.actor)}</span><span class="mono" style="font-size:11px;word-break:break-all">${fmt.esc(e.detail || '')}</span></div>`).join('') || '<div class="tr">Nothing yet.</div>'}</div>
      <div class="row" style="justify-content:flex-end"><button class="btn" onclick="document.getElementById('modal').classList.add('hide')">Close</button></div>`);
  };

  $('btn-settings').onclick = async () => {
    const j = await api('settings_get', {});
    if (!j.ok) { toast(j.error, true); return; }
    const s = j.settings, d = j.defaults;
    const V = j.verified || {};
    const f = (k, label, type) => `<label>${label}${k in V ? (V[k] ? ' <span class="tag" style="background:var(--green-bg);color:var(--green-ink)">verified</span>' : ' <span class="tag" style="background:#fef3c7;color:#92400e">unverified</span>') : ''}</label><input class="in" data-k="${k}" type="${type || 'text'}" value="${fmt.esc(s[k] ?? '')}" placeholder="${fmt.esc(d[k] ?? '')}">`;
    const localDisplay = LS('renewal.display');
    openModal(`<h2>Settings · ${fmt.esc(S.board.office.label)}</h2>
      <div class="label">This PC (saved in this browser only)</div>
      <div class="kv">
        <label>Display mode (135 % type)</label><select class="in" id="s-display"><option value="" ${!localDisplay ? 'selected' : ''}>Follow office default</option><option value="on" ${localDisplay === 'on' ? 'selected' : ''}>On</option><option value="off" ${localDisplay === 'off' ? 'selected' : ''}>Off</option></select>
        <label>Photo agent URL</label><input class="in" id="s-agent" value="${fmt.esc(LS('renewal.agent') || 'http://localhost:8765')}">
      </div>
      <div class="label">Office rules</div>
      <div class="kv">${f('display_mode', 'Display mode default (1 = on)')}${f('display_scale', 'Display scale')}${f('cycle_offset', 'Run month + N = increase month')}${f('letters_day', 'Letters out by day of run month')}
        ${f('mtm_months', 'MTM: months since last increase (from)')}${f('mtm_months_max', 'MTM: months since last increase (to, exclusive)')}${f('first_year_months', 'NEW LEASE = lease end within N months of move-in')}
        ${f('steps', 'Step buttons (%)')}${f('rent_step_dollars', '< > arrows ($)')}${f('deposit_rule', 'Deposit rule (match_rent | keep)')}
        ${f('cl_site', 'Craigslist site')}${f('cl_area', 'Craigslist area (oah, blank = all)')}${f('cl_miles', 'Craigslist miles')}</div>
      <div class="label">Rentvine (write-back)</div>
      <div class="strip ${j.rv_source === 'none' ? 'err' : ''}">Credentials in use: ${j.rv_source === 'synccenter' ? 'Sync Center source for this office' : (j.rv_source === 'settings' ? 'this app\'s key' : 'none')} · base ${fmt.esc(j.rv_base_effective || '—')} · key ${fmt.esc(j.rv_key_tail || '—')}</div>
      <div class="kv">${f('rv_base', 'Base URL override (blank = Sync Center\'s)')}
        <label>API key (fallback; blank keeps ${fmt.esc(s.rv_api_key_tail || 'none')})</label><input class="in" data-k="rv_api_key" type="password" value="">
        <label>API secret (basic auth only)</label><input class="in" data-k="rv_api_secret" type="password" value="">
        ${f('rv_auth_style', 'Auth style (bearer | header | basic)')}${f('rv_auth_header', 'Header name (header style)')}
        ${f('rv_rent_account_id', 'Rent GL account id')}${f('rv_deposit_account_id', 'Security deposit GL account id')}${f('rv_custom_field_id', 'Custom field id')}${f('rv_custom_field_name', 'Custom field name')}
        ${f('rv_charges_list_url', 'List recurring charges URL')}${f('rv_rent_match', 'Rent charge matches (description contains)')}
        ${f('rv_expire_url', 'Expire charge URL')}${f('rv_expire_method', 'method')}${f('rv_expire_body', 'body')}
        ${f('rv_create_url', 'Create recurring charge URL')}${f('rv_create_method', 'method')}${f('rv_create_body', 'body')}
        ${f('rv_sdr_url', 'Ledger charge URL')}${f('rv_sdr_method', 'method')}${f('rv_sdr_body', 'body')}
        ${f('rv_custom_url', 'Custom field URL')}${f('rv_custom_method', 'method')}${f('rv_custom_body', 'body')}</div>
      <div class="muted" style="font-size:11px">Placeholders: {base} {lease_id} {tenant_id} {property_id} {unit_id} {charge_id} {amount} {start_date} {end_date} {date} (ISO) {start_date_us} {end_date_us} {date_us} (MM/DD/YYYY, what Rentvine's UI sends) {rent_account_id} {deposit_account_id} {custom_field_id} {day_due} (from the existing rent charge). <strong>verified</strong> = confirmed against a working Rentvine client (base URL, Basic auth, the reads). <strong>unverified</strong> = not yet confirmed against FileMaker's working calls (as shipped, every call is verified). "Send test" on a record lists the lease's recurring charges and the rent / deposit GL accounts so the ids above can be filled from what Rentvine actually returns.</div>
      <div class="row" style="justify-content:flex-end"><button class="btn" id="m-close">Cancel</button><button class="btn pri" id="m-save">Save settings</button></div>`);
    $('m-close').onclick = closeModal;
    $('m-save').onclick = async () => {
      const v = $('s-display').value; if (v) display.set(v === 'on'); else { display.reset(); display.apply(s.display_mode === '1', Number(s.display_scale || 1.35)); }
      RC.photoAgent.setUrl($('s-agent').value.trim() || 'http://localhost:8765');
      const settings = {}; $('modal-box').querySelectorAll('[data-k]').forEach(i => settings[i.dataset.k] = i.value);
      const r = await api('settings_set', { settings });
      if (!r.ok) { toast(r.error, true); return; }
      closeModal(); toast('Settings saved'); loadBoard();
    };
  };

  // the record pane collapses to one column when the monitor (after display zoom) is narrow
  const fit = () => { const w = $('recbody').clientWidth; $('recbody').classList.toggle('narrow', w < 760); $('recbody').classList.toggle('tight', w < 560); };
  if ('ResizeObserver' in window) new ResizeObserver(fit).observe($('recbody')); window.addEventListener('resize', fit); fit();

  loadBoard();
  setInterval(() => { if (!S.dirty) loadBoard(); }, 5 * 60 * 1000);
})();
</script>
</body>
</html>
