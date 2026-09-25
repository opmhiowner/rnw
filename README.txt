RENEWAL CENTER v0.1 - lease renewals on three monitors
======================================================
  Web replacement for the FileMaker "center" renewal file. Completely
  web: nothing is read from or written to FileMaker. One copy on the
  droplet serves every office (Hub session decides the office).

  Staff:   https://apps.oishis.net/rnw/        Main   (center monitor)
           https://apps.oishis.net/rnw/media   Media  (left)
           https://apps.oishis.net/rnw/comps   Comps  (right)
  Open all three at once with the launcher in launcher/ (see INSTALL.md).


WHERE THE DATA COMES FROM
  Leases      Sync Center's Rentvine mirror on oishi-db, read directly:
              sync_leases (the index) + sync_records (full records for
              leases, units, properties, owners). Rent, lease end,
              lease type, deposit and move-out come from the raw lease
              record - the index leaves rent/end_date NULL on purpose.
              Never written to.
  Decisions   this app's own tables on oishi-db, every row stamped
              company_id + office_id:
                renewal_decisions    one row per lease per cycle: the
                                     lease snapshot, new rent, % inc,
                                     deposit / SDR increase, ranges,
                                     evaluation, flags, notes, pinned
                                     comps, Rentvine step ids, status
                renewal_addons       leases added to a cycle by hand
                renewal_cycles       letters-sent / finalized per cycle
                renewal_property     Renewal Special, VAOAO, colour per pcode
                renewal_settings     per-office knobs + Rentvine templates
                renewal_events       every action, who, what Rentvine said
                renewal_media        cover photo, order, description per pcode
                renewal_comps_cache  Craigslist results, 7 days
              Tables build themselves on first load. No SQL to run.
  Photos      stay on the office network drive. A small read-only agent
              on each PC (launcher/photo-agent.ps1, http://localhost:8765)
              hands them to the Media window. Nothing is uploaded.


FIELD MAPPING (confirmed 2026-09-23 on the live mirror)
  rent, deposit, beds, full/half baths, size  -> the "unit" block of
      the leases record (rent also comes live from the rent charge)
  property label / street                     -> property.address /
      property.address2 ("#1 Davenport Apartment" / "1109 Davenport St #1")
  property code (FileMaker pcode, photo folder) -> the unit's
      importSourceKey / name ("dav001"); the Rentvine lease code
      "228240-dav001" is kept as "code"
  month-to-month  -> lease.isMonthToMonth / monthToMonthStartDate, or
      an endDate in 2049 (Rentvine's "no end" placeholder)
  last increase   -> lease.increaseEligibilityDate minus one year
      (Rentvine pushes that date a year out at each increase);
      next increase = increaseEligibilityDate itself
  moving out      -> moveOutDate / expectedMoveOutDate / noticeDate /
      isMarkedToVacate
  propertyTypeID  -> only 2 = HOUSE is mapped so far; others show
      "type N" until confirmed (RNW_PTYPES in lib/sync.php)

FIRST RUN
  1. Deploy: clone to /var/www/apps/rnw (the URL is /rnw/),
     Apache alias like the other apps, deny lib/ and .git. The
     GitHub Action pulls on every merge to main once the three
     DO_* secrets are on the repo.
  2. Open probe.php while signed in. It prints one lease exactly as
     Sync Center holds it and the mapped fields the app derived.
     If rent / end / deposit / mtm are blank, add the real Rentvine
     key names to lease_join() in lib/sync.php. probe.php?queue=1
     prints the computed queue with the reason for every row.
  3. Settings (button on Main): review window, MTM months, step
     buttons, deposit rule, Craigslist site, and the Rentvine
     endpoints + bodies (paste from the working curl commands).
  4. Run the launcher on each PC (launcher/INSTALL.md). Set the photo
     folder in photo-agent.ps1 once per PC.


THE SET (FileMaker 1.PREP) - v0.2
  Everything is per CYCLE = the increase month. Run in month M for
  the increase on the 1st of M+2 ("pull December in October"):
  letters out by the 11th of M (45-day notice, HRS 521-21), upload
  to Rentvine in M+1, rent changes on the 1st of M+2.

  THE PULL RULE, every month, for increase date D:
    FIXED   lease end (= the sign-up anniversary for a first-year
            lease) from the 2nd of the month before D through D
            itself (for 12/01: lease ends 11/02..12/01 - the first
            "1st of a month" on or after the anniversary). A lease
            ending on 11/01 is November's, not December's. A fixed
            lease longer than a year waits for its own end date.
    MTM     end date 9/9/2049 (or isMonthToMonth) and the last
            increase 24 months before D - but less than 25, so each
            lease is pulled in exactly one cycle. No increase on
            file -> the move-in date is the anchor ("2 years+ since
            move in"). The anchor, best source first: this app's own
            posted renewals, Rentvine's Last Renewal Date custom
            field (when mirrored), Rentvine's increaseEligibilityDate
            minus a year, move-in.
    ADDON   pulled by hand on the Prep window (search, Add) - tagged
            "added by hand <date>". FileMaker's ADD.<yyyy.mm>.
    OVERDUE MTM 25+ months since the last increase is NOT in the set:
            it is the ">25 MO report" on the Prep window's right
            pane, one click to add.
  Rows already in a cycle stay in it whatever the rule says later.
  The Prep window (/rnw/prep) is the set on its own screen: the
  FileMaker columns (date, revisit, pcode, last incr, move in, lease
  end, new rent, change, tenant, renewal special, owner, deposit,
  rent, FMO, addon, ASD, due day, %, building/VAOAO, type, remarks),
  filters ALL / MTM / FIXED / ADDON / Unfilled / Exceptions / Pau /
  Posted, sortable columns, totals (count, filled, total increase,
  total ASD), Print list. Click a row and Main jumps to it.
  The Post window (/rnw/post, Prep > "Post / Upload...") is the upload
  month's screen, separate from the pull: it lists the rows of the
  cycle that are filled out and not yet posted (Ready), the ones
  posted, partially posted (a step failed) and excluded (no new rent,
  pau). Tick rows, "Verify selected in Rentvine" reads the lease's
  recurring charges live and checks: one open rent charge, equal to
  the current rent the decision used, the increase not already there.
  "Upload selected" runs the four steps per row and verifies again:
  old charge ends the day before, new charge = new rent from the
  increase date and open-ended, exactly one rent charge active that
  day, deposit charge and Last Renewal Date recorded. The Verified
  column shows tick / cross with the reason; a row can be posted or
  verified on its own from the right pane. "Make permanent" lives
  here, after the upload.

  TWO SEPARATE THINGS (Larry, Sep 24): WHO IS PULLED and WHAT WAS
  DECIDED never touch each other.
    - The set = the pull rule + renewal_addons (added by hand). That
      is all. Nothing else can put a lease in a month's list.
    - Decisions = renewal_decisions, one row per lease per cycle:
      new rent, deposit increase, flags, notes, Rentvine step ids.
      Saving a decision never adds a lease to the set; opening a
      record that is not in the set only creates its decision row.
      A saved decision for a lease that is not (or no longer) in the
      set is kept and shown under Prep > "Not pulled", with an Add
      button. Removing a hand-added lease keeps its decision there.
      Rows in the set by the rule cannot be removed (use the flags).
  SAVED AND RETRIEVABLE: October's work on the December set is there
  in November for the upload and forever after as history (cycle =
  "2026-12" on every row); Sync Center refreshing the mirror never
  touches it. The record shows "Past renewals" from earlier cycles.

  CATEGORIES (SORT.CALC, computed, a row can be pinned by hand)
    -3 ADDON        added by hand
    -2 DUEDATE>1    (reserved - overdue MTM is the report, not the set)
    -1 RNW SPEC     "RNW spec" ticked
     1 MOVING OUT   move-out / notice date, or marked to vacate
     2 NEW LEASE    fixed lease ending within 13 months of move-in
     3 REVISIT      Revisit ticked
     4 OA           OA ticked (owner approval)
     5 NO INCREASE  "No increase" ticked
     7 FIXED        fixed lease ending in the window
     8 MTM          month-to-month, 24 months since the last increase
  Sorted by category, then zip, then pcode ("sort Pcode.Print").

  NEW RENT STARTS BLANK ("unfilled", FileMaker BLANK NEWRENT). The
  step buttons, arrows or typing fill it. ASD = new deposit - current
  deposit (new deposit defaults to the new rent). "Deposit does not
  equal rent" shows green on the Prep list, as in FileMaker.

  THE MONTH, in order
    1. Prep window, pick the cycle (defaults to this run's), review
       the set, add addons, work the records on Main
    2. Renewal meeting: Prep on the big screen, Main follows clicks
    3. Letters (printing parked) by the 11th; "Letters sent" stamps it
    4. Following month: "Upload this set to Rentvine" - every filled
       row, four steps each, unfilled skipped, finished steps never
       repeated; a failed row stops only itself
    5. "Make permanent (finalize)" - the cycle becomes read-only

THE RECORD (Main, center pane)
  Rent decision   current -> new rent, % increase, step buttons
                  (2/4/6/8 %), < > arrows ($25), rent start date
                  (default: day after lease end, else 1st of next
                  month). Enter = Save. Left/right arrows = prev/next.
  Deposit / SDR   new deposit defaults to the new rent (Settings:
                  deposit_rule = match_rent | keep). SDR increase =
                  new deposit - current deposit, never below zero.
                  Edit either number by hand.
  O.Letter ranges RANGE TOP / BOTTOM default from the pinned comp
                  median (+10 % / -15 %) or, with no comps, from the
                  current rent (+15 % / -10 %). BASELINE 60 %,
                  RENTSTART 80 %, RENTDROP 92 % are positions between
                  bottom and top. Type a top or bottom to override.
                  ASSUMPTION - FileMaker's exact O.Letter formulas were
                  not in the handoff; adjust ranges_for() in
                  api/board.php if the letters need something else.
  Evaluation      Top / Recom / Bottom free text + the flags.
  Notes           "Renewal special" is per PROPERTY and comes back every
                  cycle (FileMaker SPECIAL WO::Renewal Special); notes and
                  the short Remarks are per cycle. Building / AOAO and a
                  colour swatch are per property too.
  Same building   every other active lease on the same property
                  (unit, bed/bath/parking, rent, last renewal, move in).

  Actions (right pane)
    Prep / Print    opens the Prep screen (/rnw/prep) on this increase
                    month: pull the set, filter, add by hand, print the
                    list; from there "Post / Upload..." opens the Post
                    screen (/rnw/post). Letter printing itself
                    (2 copies, 2 trays) is still parked - see CLAUDE.md.
    Post to Rentvine  this one record - see below
    Enter saves the record; < > in the Rent decision card (or the
    arrow keys) move to the previous / next renewal in the set.
    The set itself is the table at the top of Main, three rows
    tall and scrollable (pcode, tenant, property, category, type,
    lease end, rent, new rent, %, status - all from Sync Center
    and this cycle's decisions); the open record is highlighted
    and a click opens a row. Main runs at 115 % on its own
    (Settings > "Main window size"); display mode still wins when
    it is on.


POST TO RENTVINE  (bills are NEVER created)
  Four steps, in order, each recorded in renewal_events with the
  reply, each skipped on a re-run once done:
    1. find + expire the current rent recurring charge on the lease
       (end date = day before the new rent starts)
    2. create a new recurring charge for rent at the new amount from
       the rent start date
    3. one-time charge on the tenant ledger for the SDR increase
       (skipped when the increase is 0)
    4. set the lease custom field "Last Renewal Date" to today
  "Preview the 4 steps" is a dry run: the exact method, URL and body
  of every call, nothing sent. "Send test" does one GET of the lease
  to prove key, base URL and auth style. "Post now" asks once, then
  runs; a failed step stops there and Post again resumes.

  Endpoints and bodies live in Settings > Rentvine with placeholders
  {base} {lease_id} {tenant_id} {charge_id} {amount} {start_date}
  {end_date} {date} {rent_account_id} {deposit_account_id}
  {custom_field_id}.

  WHAT IS VERIFIED (Sep 21, against a working open-source Rentvine
  client, Launch-Engine/rentvine on GitHub):
    base URL   https://<account>.rentvine.com/api/manager
    auth       HTTP Basic, api key : api secret (Sync Center's stored
               auth style is reused as-is)
    GET /leases/{id}                         {"lease":{...}}
    GET /leases/{id}/recurring-charges       [{"recurringCharge":{
               leaseRecurringChargeID, description, amount, endDate},
               "account":{accountID, name, isRent}}]
    GET /leases/{id}/recurring-charges/{cid} one charge + previousCharge
    GET /accounting/accounts                 [{"account":{...}}]
    Rentvine updates are POST (its own client updates a property with
    POST /properties/{id}); object type 4 = Lease.
  The rent charge is picked by account.isRent, and the rent GL
  account id is learned from that charge on the first post if the
  setting is blank.

  ALSO VERIFIED from the FileMaker curl fields (Sep 21): the account
  host is oishispm.rentvine.com; FileMaker ends a recurring charge
  with POST /leases/{leaseID}/recurring-charges/{chargeNo} and body
  {"endDate": ...} - that is our EXPIRE step, now marked verified.
  One-time charge bodies use "datePosted" (not "date"); the
  create-recurring body starts with "accountID".

  CREATE RECURRING CHARGE - verified from a captured Rentvine web-UI
  request (Sep 21): POST /leases/{leaseID}/recurring-charges with
  {"accountID":"16","amount":"1.00","dayDue":1,"description":"test",
   "endDate":null,"frequency":1,"startDate":"09/21/2026"} -> 200 OK.
  Dates are MM/DD/YYYY ({start_date_us} etc.), amounts are strings,
  frequency 1 = monthly, dayDue 1 = the 1st.

  DEPOSIT (one-time) CHARGE - body verified from FileMaker's
  CURL.POST.ASD.CHG: {"datePosted":"MM/DD/YYYY","amount":110,
  "description":"...","chargeAccountID":"<id>"}  (note chargeAccountID).
  LAST RENEWAL DATE - body verified from FileMaker: {"3":"MM/DD/YYYY"}
  - keyed by the custom field id (3 on this account), and the value
  FileMaker writes is the RENT INCREASE DATE, which the app now does too.

  The custom-field URL is verified from FileMaker's MODIFY.LEASE.URL:
  POST /custom-fields/values/4/{leaseID}  (4 = object type Lease).

  Also carried over from FileMaker (P.RCHG fields, Sep 21):
  - dayDue on the new rent charge = the day of the existing rent
    charge (FileMaker's DUE), not a fixed 1st.
  - the endDate is set on the EXISTING rent charge only (the expire
    step); the new charge is created with endDate null. The lease's
    own end date is not touched (Larry, Sep 22).
  - the one-time deposit charge posts to /leases/{leaseID}/charges
    (Larry, Sep 22).

  EVERY CALL IS NOW VERIFIED against FileMaker's working curl fields
  or a captured Rentvine request. Settings still lets any URL or body
  be changed without a redeploy if Rentvine changes.
  "Send test" on a record does the reads for real and lists the
  lease's recurring charges and the rent / deposit GL accounts so
  the ids can be filled from what Rentvine returns.

  Credentials: the office's Rentvine key is read from Sync Center's
  encrypted source row (sync_sources) with the key in
  /var/www/apps/config/sync.php - no second copy. If that is not
  readable, Settings > Rentvine takes a key for this app instead.


LINKED WINDOWS
  Main records the current renewal (record, proposed rent) on the
  server per signed-in user; Media and Comps read it every 2 s and
  follow. In the same Chrome profile they also get it instantly over
  BroadcastChannel. Comps attached on the Comps window show up on
  Main's market strip within 3 s. Because the link is per user, the
  meeting-room PC signed in as the same user follows too.


DISPLAY MODE
  135 % type scale, on by default (Settings > display_mode / scale
  per office). Each PC can override in Settings > "This PC"; that
  choice stays in that browser only.


CRAIGSLIST
  The Comps window builds the same search URL FileMaker did
  (site, area, keywords, min bed/bath, zip + miles). The droplet
  tries to fetch and parse it; Craigslist blocks most datacenter
  addresses, and the window says so and offers "Open in Craigslist"
  instead. Comps can always be pinned by hand (title + price).
  Results are cached per search for 7 days.


NOT IN v0.1
  Printing (parked), bill creation (out of scope), photo upload
  (files stay on the drive), FileMaker anything.

OPEN FROM FILEMAKER (v.6 - the SEV Center pattern)
  A FileMaker button opens Renewal Center signed in, on the record
  the button was pressed on. One-time setup, per office:
    1. Main > Settings > "FileMaker link": Generate a push key, Save.
       It is the only lock on the door and it names the office, so
       each office has its own. Keep it in FileMaker, not on paper.
    2. Same section: "Links sign in as this Hub user" - the Hub
       account the link signs the PC in as (e.g. adminhi@oishis.net).
       Blank = the link goes to the Hub login page instead.
  FileMaker script step:
    Open URL [ "https://apps.oishis.net/rnw/open.php?key="
               & YourTable::RenewalPushKey
               & "&lease=" & YourTable::LeaseID ]
  Parameters (all optional but key):
    lease=<Rentvine lease id>   Main lands on that record
    cycle=YYYY-MM               the increase month to show
    win=main|prep|post|media|comps   which window (default main)
  A PC already signed in to the Hub for that office just goes
  straight to the page; otherwise the link signs it in as the
  configured user (logged in renewal_events as fm_link_login).
  A wrong or missing key never signs anyone in.
  SAME KEY AS SEV: SEV Center's push key and "sign in as" user for
  the office are accepted too (read from sev_settings on the same
  database), so one key in FileMaker opens both apps. Renewal's own
  settings, when filled in, win.

FILEMAKER DATA ON THE SAME DATABASE (v.6)
  The renewal file's own records are on oishi-db as fmp_renewals
  (one row per property code, plus the other fmp_* tables). Main
  shows them in the right pane as "FileMaker renewal record":
  last inspected / by / time / type, property and tenant grade,
  recommended rent, lease yrs, ASD, the X box, IA and OA (date,
  dropdown, story) and remarks. Edit and press "Save FileMaker
  fields" (or Enter inside one of them): the fmp_renewals row is
  updated in place, or created when FileMaker never had one for
  that pcode. Saving the decision (Enter elsewhere) does not touch
  it. Every save is logged in renewal_events as fmp_save.
  Also from FileMaker's other files (same rule: shown until you type
  your own, and what you save is written back to FileMaker's row too):
    Building / AOAO box     fmp_properties.aoao
    Media listing text      fmp_marketing ad copy (f_12_adcopy1_rent_util_online)
  Read-only on the card: the property file (class, grade, area, HSA /
  HNA area, bd/ba, parking, sqft, laundry, AC, water, TMK, PM, addendum
  terms) and marketing (rent history, ad rent, utilities).
  NOT in the imported tables: the pink "Renewal Special" text, the
  Evaluation boxes and the per-property renewal history rows. Those
  live in Renewal Center only (renewal_property / renewal_decisions).
  ALL OF IT: "All FileMaker data..." on the card opens every fmp_
  table for the property (renewals, property, marketing, owner,
  tenant contacts, collections, deposit refund, vacancy, inventory,
  keys ...) with every column the import carried, one form per row,
  "Save row" writes it back. Columns come from the table itself, so
  nothing is hard-coded; id / office / property code / import stamp
  are read-only. The import was a one-time load (Larry, Sep 25), so
  these tables are now the live copy and Main is where they are kept.
