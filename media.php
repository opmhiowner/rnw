<?php
// Renewal Center - Media window (left monitor): photo grid | big photo | listing description
// Photos come from the office network drive through the localhost photo agent
// (launcher/photo-agent.ps1). Cover, order and the description are app data.
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
<title>Renewal Center — Media</title>
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
    <span class="pill" id="h-pcode"></span>
    <span style="font-size:12px;color:var(--ink2)" id="h-meta"></span>
    <div class="grow"></div>
    <a href="index.php" target="rc-main" style="font-size:12px;font-weight:600">Back to main</a>
  </div>
  <div class="shell" style="flex:1;min-height:0">
    <div class="pane left" style="padding:16px;gap:10px;border-right:1px solid var(--hair)">
      <div class="row between"><span style="font-weight:700" id="ptitle">Photos</span><button class="btn sm" id="btn-reload">Reload</button></div>
      <div class="strip warn hide" id="agent-msg"></div>
      <div class="pgrid body" id="grid"></div>
      <div class="muted" style="font-size:11px" id="agent-path"></div>
    </div>
    <div class="pane center" style="padding:20px 24px;gap:12px">
      <div class="big" id="big">No record yet</div>
      <div class="row">
        <button class="btn arrow" id="prev" aria-label="Previous photo">&lt;</button>
        <button class="btn arrow" id="next" aria-label="Next photo">&gt;</button>
        <span class="muted" id="counter"></span>
        <div class="grow"></div>
        <button class="btn md" id="btn-cover">Set as cover</button>
        <a class="btn md" id="lnk-sev" href="https://apps.oishis.net/sev/" target="_blank" style="display:flex;align-items:center;text-decoration:none;border-color:var(--teal)">Open SEV video</a>
      </div>
    </div>
    <div class="pane right" style="width:420px;padding:20px;gap:12px;border-left:1px solid var(--hair)">
      <div class="row between"><span style="font-weight:700">Listing description</span><span class="row"><button class="btn sm" id="btn-edit">Edit</button><button class="btn sm pri hide" id="btn-save">Save</button></span></div>
      <div class="body" id="desc" style="font-size:13px;line-height:1.55;white-space:pre-wrap"></div>
      <textarea class="in hide body" id="desc-edit" style="height:auto;flex:1;resize:none"></textarea>
      <div class="strip warn" style="margin-top:auto">Numbered listing text for this property. Saved in Renewal Center, per property code.</div>
    </div>
  </div>
</div>
<script src="assets/app.js?v=<?= RNW_REV ?>"></script>
<script>
(() => {
  const { api, bus, display, photoAgent, fmt, toast } = RC;
  const $ = (id) => document.getElementById(id);
  const S = { cur: null, files: [], i: 0, media: null, pcode: '' };

  api('board', {}).then(j => { if (j.ok) display.apply(j.display.on, j.display.scale); });

  async function follow(m) {
    if (!m || !m.record_id) return;
    const changed = !S.cur || S.cur.record_id !== m.record_id;
    S.cur = m;
    $('follow').textContent = 'Following main window'; $('dot').className = 'dot';
    $('h-prop').textContent = (m.property || '') + (m.unit ? ' #' + m.unit : '');
    $('h-pcode').textContent = m.pcode || m.record_id;
    $('h-meta').textContent = [m.address, (m.bed ?? '?') + '/' + (m.bath ?? '?'), m.sqft ? m.sqft + ' ft²' : '', m.parking ? m.parking + ' pk' : ''].filter(Boolean).join(' · ');
    $('lnk-sev').href = 'https://apps.oishis.net/sev/?q=' + encodeURIComponent(m.pcode || m.address || '');
    if (changed) { S.pcode = m.pcode || m.record_id; await Promise.all([loadPhotos(), loadMedia()]); }
  }

  async function loadPhotos() {
    const r = await photoAgent.list(S.pcode);
    $('agent-path').textContent = r.ok ? (r.path || '') : '';
    if (!r.ok) {
      $('agent-msg').classList.remove('hide');
      $('agent-msg').textContent = r.error === 'not running' ? 'Photo agent not running on this PC - run the Renewal Center icon (it starts the agent), or check the agent URL in Settings on Main.' : 'Photo agent: ' + r.error;
      S.files = [];
    } else {
      $('agent-msg').classList.toggle('hide', !!r.files.length);
      if (!r.files.length) $('agent-msg').textContent = 'No photos found for ' + S.pcode + (r.path ? ' in ' + r.path : '') + '.';
      S.files = r.files;
    }
    S.i = 0; render();
  }
  async function loadMedia() {
    const j = await api('media_get', { pcode: S.pcode });
    S.media = j.ok ? j.media : { cover: null, slot_order: [], description: '' };
    $('desc').textContent = S.media.description || '(no description yet - press Edit)';
    $('desc-edit').value = S.media.description || '';
    render();
  }
  function ordered() {
    const order = (S.media && S.media.slot_order) || [];
    const known = S.files.slice();
    const out = order.filter(n => known.includes(n));
    known.forEach(n => { if (!out.includes(n)) out.push(n); });
    if (S.media && S.media.cover && out.includes(S.media.cover)) { out.splice(out.indexOf(S.media.cover), 1); out.unshift(S.media.cover); }
    return out;
  }
  function render() {
    const files = ordered();
    $('ptitle').textContent = 'Photos · ' + files.length;
    $('grid').innerHTML = (files.length ? files : Array.from({ length: 12 }, (_, k) => null)).map((n, k) =>
      n ? `<button class="${k === S.i ? 'on' : ''}" data-i="${k}" style="background-image:url('${photoAgent.src(S.pcode, n)}')" title="${fmt.esc(n)}">${S.media && S.media.cover === n ? '<span class="cv">COVER</span>' : ''}</button>`
        : `<button disabled>Image ${k + 1}</button>`).join('');
    $('grid').querySelectorAll('button[data-i]').forEach(b => b.onclick = () => { S.i = Number(b.dataset.i); render(); });
    const n = files[S.i];
    $('big').innerHTML = n ? `<img src="${photoAgent.src(S.pcode, n)}" alt="${fmt.esc(n)}">` : (S.cur ? 'No photo' : 'No record yet');
    $('counter').textContent = files.length ? (S.i + 1) + ' of ' + files.length + ' · ' + n : '';
    $('btn-cover').disabled = !n;
  }
  $('prev').onclick = () => { const f = ordered(); if (f.length) { S.i = (S.i + f.length - 1) % f.length; render(); } };
  $('next').onclick = () => { const f = ordered(); if (f.length) { S.i = (S.i + 1) % f.length; render(); } };
  $('btn-cover').onclick = async () => {
    const n = ordered()[S.i]; if (!n) return;
    const j = await api('media_set', { pcode: S.pcode, cover: n });
    if (!j.ok) { toast(j.error, true); return; }
    S.media = j.media; S.i = 0; render(); toast('Cover set');
  };
  $('btn-reload').onclick = loadPhotos;
  $('btn-edit').onclick = () => { $('desc').classList.add('hide'); $('desc-edit').classList.remove('hide'); $('btn-save').classList.remove('hide'); $('btn-edit').classList.add('hide'); $('desc-edit').focus(); };
  $('btn-save').onclick = async () => {
    const j = await api('media_set', { pcode: S.pcode, description: $('desc-edit').value });
    if (!j.ok) { toast(j.error, true); return; }
    S.media = j.media; $('desc').textContent = S.media.description || '(no description yet)';
    $('desc').classList.remove('hide'); $('desc-edit').classList.add('hide'); $('btn-save').classList.add('hide'); $('btn-edit').classList.remove('hide'); toast('Description saved');
  };
  document.addEventListener('keydown', (e) => {
    const tag = (e.target.tagName || '').toLowerCase();
    if (tag === 'textarea' || tag === 'input') return;
    if (e.key === 'ArrowRight') $('next').click(); if (e.key === 'ArrowLeft') $('prev').click();
  });

  bus.subscribe(follow, { poll: 2000 });
  follow(bus.last());
  bus.ping('media'); setInterval(() => bus.ping('media'), 5000);
  if (!bus.last()) { $('dot').className = 'dot off'; if (bus.ch) bus.ch.postMessage({ want: 'current' }); }
})();
</script>
</body>
</html>
