// Renewal Center - shared browser helpers
//   api()      one JSON call to api/board.php (always HTTP 200 + ok flag)
//   bus        BroadcastChannel('renewal'): Main publishes {record_id, rent_proposal,
//              tenant, property, ...}; Media and Comps follow. localStorage
//              keeps the last record so a reopened window lands right.
//   display    135 % type scale, on by default, per-PC override in localStorage
//   photoAgent localhost listener that serves the office photo drive
(function () {
  const LS = (k, v) => { try { if (v === undefined) return localStorage.getItem(k); localStorage.setItem(k, v); } catch (e) { return null; } };

  async function api(action, body) {
    const r = await fetch('api/board.php?a=' + action + '&_=' + Date.now(), {
      method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body || {})
    });
    let j; try { j = await r.json(); } catch (e) { j = { ok: false, error: 'Bad reply from server (' + r.status + ')' }; }
    if (j && j.code === 'auth') { location.href = '/login'; }
    return j;
  }

  // Main publishes; Media / Comps follow. Two paths, both used:
  //   1. BroadcastChannel + localStorage - instant, same Chrome profile only
  //   2. the server (current_set / current_get) - polled every 2 s, works across
  //      the launcher's separate profiles and across PCs (meeting room)
  const bus = {
    ch: ('BroadcastChannel' in window) ? new BroadcastChannel('renewal') : null,
    lastSent: '',
    publish(msg) {
      msg.at = Date.now();
      LS('renewal.current', JSON.stringify(msg));
      if (this.ch) this.ch.postMessage(msg);
      const key = JSON.stringify([msg.record_id, msg.rent_proposal, msg.pct]);
      if (key !== this.lastSent) { this.lastSent = key; api('current_set', { current: msg }); }
    },
    subscribe(fn, opts) {
      if (this.ch) this.ch.onmessage = (e) => fn(e.data || {});
      window.addEventListener('storage', (e) => { if (e.key === 'renewal.current' && e.newValue) { try { fn(JSON.parse(e.newValue)); } catch (x) {} } });
      if (opts && opts.poll) {
        let seen = '';
        const tick = async () => {
          const j = await api('current_get', {});
          if (j.ok && j.current && j.current.record_id) {
            const key = JSON.stringify([j.current.record_id, j.current.rent_proposal, j.current.pct, j.row_updated_at]);
            if (key !== seen) { seen = key; j.current.pinned = j.pinned || j.current.pinned; j.current.median = j.comp_median; j.current.via = 'server'; fn(j.current); }
          }
        };
        tick(); setInterval(tick, opts.poll);
      }
    },
    last() { try { return JSON.parse(LS('renewal.current') || 'null'); } catch (e) { return null; } },
    ping(name) { if (this.ch) this.ch.postMessage({ hello: name, at: Date.now() }); }
  };

  const display = {
    apply(serverOn, scale) {
      const local = LS('renewal.display');          // 'on' | 'off' | null = follow office default
      const on = local ? local === 'on' : !!serverOn;
      document.documentElement.dataset.display = on ? 'on' : 'off';
      document.documentElement.style.setProperty('--display-scale', String(scale || 1.35));
      return on;
    },
    set(on) { LS('renewal.display', on ? 'on' : 'off'); document.documentElement.dataset.display = on ? 'on' : 'off'; },
    reset() { try { localStorage.removeItem('renewal.display'); } catch (e) {} }
  };

  const photoAgent = {
    url() { return (LS('renewal.agent') || 'http://localhost:8765').replace(/\/$/, ''); },
    setUrl(u) { LS('renewal.agent', u); },
    async list(pcode) {
      try {
        const r = await fetch(this.url() + '/list?pcode=' + encodeURIComponent(pcode), { cache: 'no-store' });
        if (!r.ok) return { ok: false, error: 'agent HTTP ' + r.status, files: [] };
        return await r.json();
      } catch (e) { return { ok: false, error: 'not running', files: [] }; }
    },
    src(pcode, name) { return this.url() + '/photo/' + encodeURIComponent(pcode) + '/' + encodeURIComponent(name); }
  };

  const fmt = {
    money(v) { if (v === null || v === undefined || v === '' || isNaN(v)) return '—'; return '$' + Number(v).toLocaleString('en-US', { maximumFractionDigits: 0 }); },
    money2(v) { if (v === null || v === undefined || v === '' || isNaN(v)) return '—'; return '$' + Number(v).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); },
    date(d) { if (!d) return '—'; const m = String(d).match(/^(\d{4})-(\d{2})-(\d{2})/); return m ? (m[2] + '/' + m[3] + '/' + m[1]) : d; },
    dateShort(d) { if (!d) return '—'; const m = String(d).match(/^(\d{4})-(\d{2})-(\d{2})/); return m ? (m[2] + '/' + m[3]) : d; },
    pct(v) { if (v === null || v === undefined || isNaN(v)) return '—'; return Number(v).toFixed(1) + '%'; },
    esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]); }
  };

  let toastT;
  function toast(msg, err) {
    let el = document.getElementById('toast');
    if (!el) { el = document.createElement('div'); el.id = 'toast'; el.className = 'toast'; document.body.appendChild(el); }
    el.textContent = msg; el.className = 'toast show' + (err ? ' err' : '');
    clearTimeout(toastT); toastT = setTimeout(() => { el.className = 'toast'; }, err ? 5000 : 2200);
  }

  window.RC = { api, bus, display, photoAgent, fmt, toast, LS };
})();
