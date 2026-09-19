# Renewal Center — handoff spec for Claude Code

Web replacement for the OISHI FileMaker "center" renewal database. PHP 8 + vanilla JS + MySQL on the
DigitalOcean droplet, Hub session auth, deployed like the other portfolio apps (`opmhiowner/<app>` repo,
DO-only `deploy.yml`). Target URL: `https://apps.oishis.net/renewal/`.

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
- **Keep three monitors.** One app, three browser windows (routes): `/renewal/media` (left), `/renewal/`
  (center = Main), `/renewal/comps` (right). Every route is itself a 3-pane email-style layout.
- **Main drives the others.** Main publishes `{record_id, rent_proposal}` on `BroadcastChannel('renewal')`
  on every navigation (queue click, Next/Prev, `←`/`→` keys). Media and Comps subscribe and reload; they
  also read the last record from `localStorage` on load so a reopened window lands on the right record.
  `Enter` on Main = Save → FileMaker.
- **Auto-placement** = the launcher in `launcher/` (Chrome `--app` windows, one per monitor, `--start-fullscreen`,
  separate `--user-data-dir` per window). Install on every PC and the meeting room. URLs already point at
  the routes above.
- **Display mode** (meeting room / TVs): 135 % type scale, ON by default, per-PC toggle in Settings.
- **Print is parked** — see "Printing" below. Do not build yet.
- **Rentvine bill creation** is v0.2, after FMP write-back is proven.

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

## Data
- Source of truth stays FileMaker for v0.1: read/write via **FileMaker Data API** (needs fmrest privilege on
  a dedicated account; rotate the existing Rentvine key embedded in FMP curl fields while there).
- Fields to write back: New Rent, %INC, evaluation Top/Recom/Bottom, Revisit flag, notes, pinned comps,
  printed-stamp. Everything else read-only in v0.1.
- Cache the queue in MySQL (`renewal_queue`, `company_id` + `office_id` per portfolio convention;
  `tenant_id` is reserved for renters, never offices) and refresh from FMP on a cron + on Save.
- Photos: `/mnt/media/renewal/<company_id>/<office_id>/<property_id>/`.
- Craigslist: build the same search URL FMP builds today; cache results per unit 7 days.

## Printing (parked — for later)
Letters vary by status (MTM vs Fixed etc.), printed automatically as 2 copies: one white, one pink (two trays).
Browser cannot pick trays. Options written up: (A) two Windows printer queues with different default trays +
small local print agent, $0, ~3 h; (B) PrintNode client + API with `bin` per copy, ~$10/mo, ~1 h. App side
identical: HTML template per status → PDF on droplet → preview → job to print service → stamp record.
Open question for Larry: how many letters, and does anything besides status pick the template?

## Build order / estimates (v0.1)
| Step | Est |
|---|---|
| FMP Data API read of renewal set + write-back | 2 h |
| Main window (queue, record, actions) on real data | 2.5 h |
| Media + Comps windows + BroadcastChannel sync | 1.5 h |
| Display mode + settings | 0.5 h |
| Test + `renewal-0.1.zip` | 0.5 h |

## Team conventions (from project memory)
Pre-work protocol before any build: diagnosis → root cause → versioned fix → time with finish in HST **and**
PST → "Type start." Never push to GitHub without explicit "push". Zips carry the version in the filename;
`data/` and live config never ship. Prefix command blocks with app/window context. 3-pane layout for every UI.
