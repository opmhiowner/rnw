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
                renewal_queue        one row per lease per month: the
                                     lease snapshot, new rent, % inc,
                                     deposit / SDR increase, ranges,
                                     evaluation, flags, notes, pinned
                                     comps, Rentvine step ids, status
                renewal_settings     per-office knobs + Rentvine templates
                renewal_events       every action, who, what Rentvine said
                renewal_media        cover photo, order, description per pcode
                renewal_comps_cache  Craigslist results, 7 days
              Tables build themselves on first load. No SQL to run.
  Photos      stay on the office network drive. A small read-only agent
              on each PC (launcher/photo-agent.ps1, http://localhost:8765)
              hands them to the Media window. Nothing is uploaded.


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


THE QUEUE (what used to be SORT.CALC)
  Categories are computed from Sync Center every load, never stored;
  a row can be pinned to a category by hand (Evaluation card).
    -3 ADDON        decided (pau / posted) this month
    -2 DUEDATE>1    fixed lease ended more than a day ago, no decision
    -1 RNW SPEC     "RNW spec" ticked on the record
     1 MOVING OUT   move-out or notice date on the lease
     2 NEW LEASE    move-in within 12 months and lease end inside the window
     3 REVISIT      Revisit ticked
     4 OA           OA ticked (owner approval)
     5 NO INCREASE  "No increase" ticked
     7 FIXED        fixed lease ending inside the review window (90 d)
     8 MTM          month-to-month, 24+ months since the last renewal
  Sorted by category, then zip, then property code, then unit.
  All month counts and the window are Settings.

  "Last renewal" = the Rentvine custom field Last Renewal Date when
  present, else the lease start. That is the anchor for MTM / no
  increase, and it is what Post to Rentvine stamps.


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
  Notes / VAOAO   two text boxes, saved with the record.
  Same building   every other active lease on the same property
                  (unit, bed/bath/parking, rent, last renewal, move in).

  Actions (right pane)
    Prep / Print    PARKED. Stamps the record as prepped so the letter
                    run can find it. Printing (2 copies, 2 trays) is
                    written up in CLAUDE.md "Printing".
    Save            writes the decision (also Enter)
    Pau renewal     decided; leaves the queue (shows as -3 ADDON this
                    month). Reopen from the same button.
    KPI             this month's counts, average %, rent added, SDR
    Next            next row in the filtered queue
    Post to Rentvine  see below


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
