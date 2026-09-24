# Renewal Center — handoff spec for Claude Code

Web replacement for the OISHI FileMaker "center" renewal database. PHP 8 + vanilla JS + MySQL on the
DigitalOcean droplet, Hub session auth, deployed like the other portfolio apps (`opmhiowner/<app>` repo,
DO-only `deploy.yml`). Target URL: `https://apps.oishis.net/rnw/`.

**Sep 19 (Larry, after handoff): completely web app, no FileMaker at all.** The renewal queue comes from
Sync Center's Rentvine mirror (`sync_records`, `sync_leases` on oishi-db), decisions live in this app's own
tables, and nothing is read from or written to FileMaker. The FileMaker screenshots in `reference/` are the
layout reference only. Sections below that still say "FileMaker" / "FMP" are the original handoff text;
the "Data (revised)" section wins wherever they disagree.

## What exists today (FileMaker)
Opening the renewal FMP file auto-places **three windows on three monitors**:
1. **center** — the input screen (screenshot `reference/fmp-center.png`): owner/tenant, SORT.CALC category,
   evaluation (Top / Recom / Bottom), Current Rent → New Rent with 2/4/6/8 % step buttons and `<` `>` arrows,
   %INC, Original O.Letter ranges (RANGETOP / BASELINE 60% / RENTSTART 80% / RENTDROP 92% / RANGEBOTTOM),
   Lease dates (DMove In, DLease End, DLast Incr, TPast Due, DNextInc, DNextRent), Last Tracker, Last SEV,
   Dmove Out / T.notice, VAOAO, same-building rent history list (11.PF/F.BD.PK.Util), PREP/PRINT, KPI, REVISIT,
   "pau renewal / Print", "Renew mtm every 2 years" reminder.
2. **left** — property photos (12 image slots) + numbered listing description (`reference/fmp-left.png`).
3. **right** — Craigslist comps search, oahu / apartments-housing, keywords from FMP (`reference/fmp-right.png`).

SORT.CALC categories: -3 ADDON (month processed), -2 DUEDATE>1, -1 RNW SPEC, 1 MOVING OUT, 2 NEW LEASE,
3 REVISIT, 4 OA, 5 NO INCREASE, 7 FIXED, 8 MTM. Property types: 1MASTER 2HOUSE 3COTTAGE 4DUPLEX 5TOWNHOUSE
6CONDO 7APARTMENT. Sorted within category by zip > pcode.

## Decisions made with Larry (Sep 19, 2026)
- **Keep three monitors.** One app, three browser windows (routes): `/rnw/media` (left), `/rnw/`
  (center = Main), `/rnw/comps` (right). Every route is itself a 3-pane email-style layout.
- **Main drives the others.** Main publishes `{record_id, rent_proposal}` on `BroadcastChannel('renewal')`
  on every navigation (queue click, Next/Prev, `←`/`→` keys). Media and Comps subscribe and reload; they
  also read the last record from `localStorage` on load so a reopened window lands on the right record.
  `Enter` on Main = Save (to this app's `renewal_queue`).
- **Auto-placement** = the launcher in `launcher/` (Chrome `--app` windows, one per monitor, `--start-fullscreen`,
  separate `--user-data-dir` per window). Install on every PC and the meeting room. URLs already point at
  the routes above.
- **Display mode** (meeting room / TVs): 135 % type scale, ON by default, per-PC toggle in Settings.
- **Print is parked** — see "Printing" below. Do not build yet.
- **Rentvine write-back is v0.1** (see Data). **Bill creation is out of scope entirely.**

## Design (approved canvas rev 4)
`design/` holds the three artboards exactly as approved (Design-canvas `.dc.html` format — treat as
HTML+inline-CSS reference; the `{{holes}}`, `<sc-for>`, `<sc-if>` and `DCLogic` class are the mock's
templating, replace with PHP/JS). Layout:

| Window | Left pane | Center pane | Right pane |
|---|---|---|---|
| Main | Renewal queue: HI/LV switch, search, SORT.CALC category chips, rows (tenant, property, tag, lease end, days) | Record: header (property, ID, category, type, owner, tenant, address, Revisit), Rent decision card (current / new / %, step 2-4-6-8 %, `<` `>`), O.Letter ranges, Evaluation, Lease dates, notes + VAOAO, same-building rent history | Linked-windows status, market-check strip, Last Tracker, Last SEV, Move-out; action strip: Prep/Print, Save→FileMaker, Pau renewal, KPI, Next |
| Media | Photo grid (12) | Large photo + prev/next, Set as cover, Open SEV video | Listing description (editable, saves to FMP) |
| Comps | Craigslist search form (keywords, bed/bath, zip, miles, checkboxes) | Results table, click to pin | Pinned comps, market check (median vs proposed), Attach comps to renewal |

Palette: ground `#ecebe6` / panes `#f7f6f2` / white cards; accent teal `#0f766e`; 1st-year blue `#1d4ed8`;
increase green `#15803d`; warning `#b45309`; type IBM Plex Sans + IBM Plex Mono for money.

## Data (revised Sep 19 — no FileMaker)
- **Source of leases = Sync Center.** Same oishi-db, read directly (the way SEV Center reads `sync_leases`):
  - `sync_leases` — the index: external_id (Rentvine lease id), property_ref, unit_ref, tenant_names,
    phones, emails, start_date, status, `raw` (index row with tenant_id / property_id / unit_id / owner_id /
    portfolio_id / legacy code). **rent and end_date are NULL in the index** — do not rely on them.
  - `sync_records` — the full Rentvine records per feed (`leases`, `units`, `properties`, `owners`,
    `tenants`, `portfolios`), `raw` JSON keyed by external_id. Rent, lease end, lease type (fixed / MTM),
    security deposit and move-out come from the `leases` feed record; bed/bath/sqft/parking from `units`;
    address and property code from `properties`; owner name from `owners`. Field names are read
    tolerantly (list of candidate keys, like SEV's mappers) and `probe.php` shows one raw record so the
    mapping is confirmed against live data on first deploy.
  - Never write to `sync_*`. Never read via HTTP when the table is on the same DB.
- **Queue = leases that need a review.** A lease qualifies when any of: lease end within the review window
  (default 90 days, per-office setting), month-to-month past the last increase by N months (default 24,
  the "Renew MTM every 2 years" rule), no increase in N months, or a manual Revisit flag. SORT.CALC
  categories are computed from these rules, not stored: -3 ADDON (processed this month), -2 DUEDATE>1,
  -1 RNW SPEC, 1 MOVING OUT (move-out date set), 2 NEW LEASE (1st year), 3 REVISIT, 4 OA, 5 NO INCREASE,
  7 FIXED, 8 MTM. Sorted within category by zip > pcode.
- **Decisions live here** (`company_id` + `office_id` on every row; `tenant_id` is reserved for renters):
  - `renewal_queue` — one row per lease per review cycle: lease external_id, snapshot of the lease fields
    used for the decision, current rent, new rent, %INC, evaluation Top/Recom/Bottom, **rent adjustment**
    and **security-deposit (SDR) adjustment** (new deposit, delta), revisit flag, notes, VAOAO/owner alerts,
    status (open / pau / printed), pinned comps JSON, decided_by, decided_at.
  - `renewal_settings` — per-office key/value (review window, MTM months, step %, display mode default).
  - `renewal_events` — every action with who did it (SEV pattern).
  - `renewal_media` — photo slots (1–12, cover flag) and the listing description per property/unit.
- Refresh: the queue is recomputed from `sync_*` on page load (cheap: one office's active leases) and the
  decision row is created lazily on first open. No cron needed for v0.1.
- **Photos stay on the office network drives (Larry, Sep 19)** — nothing is copied to the droplet.
  A browser page on `https://apps.oishis.net` cannot open `\\server\share` or `file://` paths, and Chrome
  blocks plain-HTTP images on an HTTPS page, **but `http://localhost` counts as a secure origin**. So:
  - `launcher/photo-agent.ps1` — a tiny read-only HTTP listener on `http://localhost:8765/` (Windows
    `HttpListener`, no install) that serves files under the mapped photo root and answers
    `/list?pcode=<code>` with the image names for that property (JSON, CORS `*`). Started by
    `launch-renewal.ps1` before the three windows; skipped if already running.
  - Media window loads `http://localhost:8765/<path>` for the 12 slots; a PC without the agent or the
    drive shows "Photo agent not running on this PC" and the rest of the window still works.
  - Per-PC settings (localStorage, Settings on Main): agent URL, photo root pattern with `{pcode}`
    (default `\\server\photos\{pcode}` — **confirm the real convention with Larry**), sort order.
  - Cover-photo choice, slot order and the listing description are the app's data (`renewal_media` on
    oishi-db, keyed by property); the files themselves are never stored by the app.
  - The same agent is the natural home for the parked two-tray print job later.
  - No Upload in v0.1 — staff keep dropping files on the drive as they do today.
- Craigslist: build the same search URL FMP built today; cache results per unit 7 days. Datacenter fetches
  may be blocked — the window always offers "Open in Craigslist" as the fallback.
- **Rentvine write-back — in scope (Larry, Sep 19). Bill creation is NOT.** "Post to Rentvine" on a
  decided renewal does, in order, and records each step in `renewal_events` with Rentvine's reply:
  1. **Expire the current rent recurring charge** on the lease (end date = day before the new rent starts).
  2. **Create a new recurring charge** for rent at the new amount, starting on the increase date.
  3. **Add a one-time charge to the tenant ledger** for the security-deposit (SDR) increase, when > 0.
  4. **Update the lease custom field "Last Renewal Date"** to the decision date.
  Each step is idempotent (the posted Rentvine ids are stored on the queue row; a re-run skips done steps)
  and the whole thing can be previewed as a dry run before anything is sent. Endpoint URLs and JSON bodies
  are per-office settings with `{lease_id}` / `{tenant_id}` / `{amount}` / `{date}` placeholders — matched
  to the working curl commands from the FileMaker scripts, the same way SEV configures its message calls.
  A "Send test" against a test lease reports exactly what Rentvine said.
- **Credentials:** reuse Sync Center's — decrypt `sync_sources.credentials_enc` for the office with the
  `crypto_key_b64` in `/var/www/apps/config/sync.php` (AES-256-GCM, same routine as Sync Center's
  `core/db.php`). Never a second copy of the key. Fallback if that path is refused: a per-office key in
  `renewal_settings`, masked in the UI.

## Printing (parked — for later)
Letters vary by status (MTM vs Fixed etc.), printed automatically as 2 copies: one white, one pink (two trays).
Browser cannot pick trays. Options written up: (A) two Windows printer queues with different default trays +
small local print agent, $0, ~3 h; (B) PrintNode client + API with `bin` per copy, ~$10/mo, ~1 h. App side
identical: HTML template per status → PDF on droplet → preview → job to print service → stamp record.
Open question for Larry: how many letters, and does anything besides status pick the template?

## Build order / estimates (v0.1)
| Step | Est |
|---|---|
| Core (Hub session, DB, self-heal schema, deploy.yml) — SEV pattern | 0.5 h |
| Sync Center reader: lease + unit + property + owner join, queue rules, SORT.CALC, probe.php | 2 h |
| Main window (queue, record, rent + SDR decision, actions) on real data | 2.5 h |
| Media + Comps windows + BroadcastChannel sync | 1.5 h |
| Photo agent (localhost listener on the network drive) + launcher hook | 0.5 h |
| Display mode + settings | 0.5 h |
| Rentvine write-back: expire + new recurring rent charge, SDR ledger charge, Last Renewal Date, dry run, test send | 2 h |
| Test + `renewal-0.1.zip` | 0.5 h |

## Team conventions (from project memory)
Pre-work protocol before any build: diagnosis → root cause → versioned fix → time with finish in HST **and**
PST → "Type start." Never push to GitHub without explicit "push". Zips carry the version in the filename;
`data/` and live config never ship. Prefix command blocks with app/window context. 3-pane layout for every UI.

## Status — v0.1 built (Sep 19, 2026)
Built and tested end to end in a container against a seeded copy of Sync Center's tables (8 leases,
Rentvine-shaped raw records) and a fake Rentvine API: queue rules and SORT.CALC order, record open /
save / pau / reopen / prepped, comps pin + market check, media cover + description, server-side window
link, settings, KPI, activity, and the full four-step Rentvine post (find + expire rent charge, new
recurring charge, SDR ledger charge, Last Renewal Date) including idempotent re-run and the refusal to
edit a posted row. Screenshots of all three windows checked at 1440×900 and 1920×1080 in display mode.
README.txt is the operator's guide. Files: `lib/core.php` `lib/sync.php` `lib/rentvine.php`
`lib/craigslist.php` `api/board.php` `index.php` `media.php` `comps.php` `probe.php` `assets/`
`launcher/photo-agent.ps1` `.github/workflows/deploy.yml`.

**Assumptions to confirm on first deploy (all adjustable without a redeploy where noted):**
1. Rentvine key names for rent / endDate / leaseTypeID / securityDeposit / moveOutDate / customFields —
   read tolerantly in `lease_join()`; `probe.php` shows the live record. Code change if wrong.
2. Rentvine write endpoints and bodies — Settings › Rentvine (no redeploy). **Sep 21–22: all verified**
   against FileMaker's working curl fields (Larry's screenshots) and one captured Rentvine UI request:
   base `https://oishispm.rentvine.com/api/manager`, Basic auth; find rent charge by `account.isRent`;
   expire = POST `/leases/{id}/recurring-charges/{chargeId}` `{"endDate":"MM/DD/YYYY"}`; create = POST
   `/leases/{id}/recurring-charges` `{accountID, amount, dayDue (from the old charge), description,
   endDate:null, frequency:1, startDate}`; deposit = POST `/leases/{id}/charges` `{datePosted, amount,
   description, chargeAccountID}`; Last Renewal Date = POST `/custom-fields/values/4/{id}` `{"3":"<rent
   increase date>"}`. Still needs the deposit GL account id in Settings (rent account is learned live).
   The Rentvine key visible in the screenshots must be rotated; the $1 "test" charge on lease 3606 deleted.
3. O.Letter ranges — RANGE TOP/BOTTOM default from comp median (+10 % / −15 %) or current rent
   (+15 % / −10 %); BASELINE/RENTSTART/RENTDROP are the 60/80/92 % positions. `ranges_for()` in
   `api/board.php` if the letters need FileMaker's exact formulas.
4. New deposit = new rent (Settings `deposit_rule`).
5. Photo folder convention on the drive — `photo-agent.ps1` CONFIG per PC.
6. SDR = security deposit (confirmed by the write-back description).

**Linked windows:** the launcher runs each window in its own Chrome profile, and separate profiles share
no BroadcastChannel/localStorage, so the windows link **through the server** (`current_set` /
`current_get`, polled every 2 s, per signed-in user). BroadcastChannel is still used when windows share a
profile. This replaces the handoff's BroadcastChannel-only design.

**Not done / parked:** printing (2 trays), bill creation (out of scope), photo upload (files stay on the
drive), LV report-only offices have no Rentvine write-back (the plan says so instead of failing silently
— Sync Center marks them `af`).

## v0.2 — the set is per increase month (Sep 23–24, 2026, from FileMaker 1.PREP)
Larry's prep-screen screenshot changed the model. **Cycle = increase month.** Run in month M for the
1st of M+2; letters by the 11th of M (45-day notice); upload in M+1. **Pull rule:** FIXED = lease end in
[1st of M+1, 1st of M+2]; MTM (end 2049) = last increase 24 ≤ months < 25 before the increase date
(anchor: own posted history › Last Renewal Date › eligibility−1y › move-in); ADDON by hand; overdue MTM
(≥ 25 mo) is a report, not the set. New rent starts blank (unfilled). ASD = new deposit − deposit.
Per-property notes (`renewal_property`: special, VAOAO, colour) persist across cycles. New window
`/rnw/prep` = the FileMaker list with its columns, filters, totals, add-by-hand, overdue report, batch
upload (`cycle_post`), Letters sent, Make permanent (`renewal_cycles.finalized_at`, rows read-only).
Main gets a cycle selector and follows Prep clicks through the server link. Decisions are saved per
lease per cycle and retrievable for the upload month and as history. Fixed leases longer than a year
wait for their own end date. `-2 DUEDATE>1` is reserved.
**Open:** Rentvine's Last Renewal Date custom field is not mirrored by Sync Center (no custom-fields
feed), so the MTM anchor for leases never posted through this app falls back to the eligibility date
minus a year, then move-in. Ask Sync Center for a custom-fields feed, or fetch live per lease.
