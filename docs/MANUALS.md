NAAP GSD DIGITAL TRIP TICKET SYSTEM — USER MANUALS PER ROLE
============================================================
App URL: http://localhost/NAAP_MN_VTS_Capstone/
Applies to rebuild v1.0 (Phases 0–5 complete). See also: accounts (credentials),
docs/ARCHITECTURE.md, docs/DEVICE_API.md.


SHARED BASICS (all roles)
-------------------------
- Sign in at auth/login.php with your NAAP email + password. After 5 failed
  attempts the account locks (MIS ADMIN can unlock).
- Forgot password: "Forgot password?" link → enter email → receive OTP → set a
  new password (min 8 chars). When SMTP is not configured, the OTP is shown on
  screen for testing.
- Top bar gear icon opens Settings: Light/Dark theme toggle, profile links,
  Logout. You are logged out automatically after a period of inactivity.
- The left sidebar is your role menu; the active page is highlighted.
- Ticket view (ticket.php?id=N) is shared by all roles — it shows the request,
  approval, assignment, execution timeline (with GPS coordinates), Section B
  report, gate verifications, passenger manifest, open flags, and certification.
  Buttons on it appear only if your role may perform that action right now.


1. REQUESTING PERSONNEL (role 6) — e.g. personnel@naap.gov.ph
-------------------------------------------------------------
Pages: My Requests, History, Feedback (+ New Request form).

Requesting a vehicle:
1. My Requests → "+ New Request".
2. Fill destination, purpose, departure/return date-time, passengers
   (Passenger Manifest — one name per line). The form autosaves a DRAFT every
   10 s; "Save Draft" saves manually, "Submit for Approval" sends it to the
   HR ADMIN. Drafts remain editable via "Edit".
3. Track status from My Requests / History. Open any ticket to see details.

While it is pending or assigned:
- CANCEL is available from PENDING or APPROVED and requires a reason.
- RESCHEDULE is available from ON_GOING (by GSD Staff); if the new schedule
  now conflicts with another booking, Staff will re-assign driver/vehicle.

After the trip:
- When the driver is ON_GOING and the trip is complete, open the ticket and
  press "Certify & Complete" to close it.
- Feedback page: pick any of your trips (except drafts), write comments, submit.
  Previous feedback is listed below and cannot be edited — contact MIS ADMIN
  to have an entry removed.


2. HR ADMIN (role 2) — e.g. director@naap.gov.ph
----------------------------------------------------
Pages: Dashboard, Approvals, Reports, Live Tracking.

Approving requests (Approvals page):
- Queue shows all PENDING tickets. "View" opens the full ticket.
- "Verify" moves it to VERIFIED (confirm dialog). "Reject…" requires a
  written reason — rejected tickets are terminal for that request.
- Only the HR ADMIN verifies/rejects; no delegation.

Reports (Reports page):
- Monthly tickets bar chart (last 12 months), fleet utilization table
  (trips + km per vehicle), and the full trip log.
- "Export to Excel (.xlsx)" downloads a two-sheet workbook (Trip Log +
  Per Vehicle) of everything currently shown.

Live Tracking: real-time map of GPS-equipped vehicles with freshness badges;
click a row to zoom to that vehicle. The dashed circle is the campus geofence.


3. GSD STAFF (role 3) — e.g. staff@naap.gov.ph
----------------------------------------------
Pages: Dashboard, Assignments, Trips, History, Reports, Flags, Live Tracking.

Assigning driver & vehicle (Assignments page):
1. Queue lists VERIFIED tickets. Press "Assign" → choose driver + vehicle →
   "Assign & Issue DTT". The system checks schedule conflicts automatically
   and issues the official number DTT-YYYY-####.
2. If the chosen vehicle has NO paired active GPS device you must tick the
   dispatch-without-GPS override and give a reason (this raises a NO_TELEMETRY
   flag visible to supervisors).
3. Assigned tickets move to the second list — assigned = DISPATCHED.
- Reschedule… on a ticket (status ON_GOING): updates
  the window; if the change creates a conflict the assignment is cleared and
  the ticket returns to DISPATCHED for re-assignment.

Flags desk (Flags page):
- Lists OPEN FLAGS raised by the scheduled evaluator every ~5 min:
  OVERDUE, MISSED_TRIP, GPS_SIGNAL_LOST, NEEDS_REVIEW (+ real-time
  GEOFENCE_DEVIATION / NO_TELEMETRY raised by the pipeline).
- Investigate, then "Resolve…" — a reason is mandatory and recorded in the
  audit trail with your name.


4. DRIVER (role 4) — e.g. driver1@ / driver2@naap.gov.ph
--------------------------------------------------------
Pages: Dashboard ("My Trips"), Post-Trip Report.

Executing a trip (open your ticket from the dashboard):
1. DISPATCHED → wait for departure time.
2. "Start Trip…" → enter the current ODOMETER reading (required). Trip becomes
   ON_GOING; GPS breadcrumbs begin recording if the vehicle has a device.
   If started past scheduled_departure, the system shows how late you are.
3. While ON_GOING, sub-events are GPS breadcrumbs (Arrived at Dest, Leave Dest,
   Return to Base) — NOT status changes.
4. "Submit Post-Trip Report" (or Post-Trip Report page → "Fill Report"):
   enter odometer END (must be ≥ start; end−start is the OFFICIAL distance),
   fuel used/purchased figures, and remarks → "Submit Report".
   The requester then certifies the trip.
- Note: odometer distance is official; GPS-derived distance is supporting
  information only.
- Keep the tab open while driving with GPS active so telemetry keeps flowing.


5. GUARD (role 5) — e.g. guard@naap.gov.ph
------------------------------------------
Pages: Desk, History.

Verifying trips (Desk):
- Lists tickets from DISPATCHED through ON_GOING. "Verify" opens the form with
  the suggested phase pre-selected:
  - PRE_DEPARTURE check (allowed while DISPATCHED):
    inspect driver license / trip ticket / vehicle condition / passengers.
  - RETURN check (ON_GOING): verify return condition.
- Tick the four checklist items, set result VALIDATED or FLAGGED, add notes
  as needed → "Record Verification". Verifications are events only — they do
  NOT change the ticket status. All verifications appear on the ticket view
  and in your History.
- Guard can also start trip from guard/desk.php.


6. MIS ADMIN (role 1) — admin@naap.gov.ph
-----------------------------------------
Pages: Dashboard, Fleet, Accounts, Audit Trail, Chatbot FAQs, Feedback Admin,
       Reports, Settings, Live Tracking. Also sees the Flags desk (same as Staff).

Fleet (vehicles, GPS devices, types):
- Vehicles: "+ Add Vehicle" / edit (plate must be unique) / activate-deactivate.
- GPS devices: "+ Register Device" generates a one-time bearer token — copy it
  immediately, it is shown ONCE (only its SHA-256 hash is stored). Pair/unpair
  a device to a vehicle with the inline selector; toggle devices active/inactive.
- Vehicle types quick-add.

Accounts: create/edit user accounts, reset passwords (leave password blank on
edit to keep it), activate/deactivate. You cannot deactivate yourself;
deactivating also clears lockout counters.

Settings: every application setting is editable here (grace periods, GPS
timeouts, geofence center/radius, idle timeout, …). Keys containing
PASS/SECRET/TOKEN are masked. Values take effect immediately.

Audit Trail: filter by event type and free-text search across object/detail/
user — every mutation in the system is logged here.

Chatbot FAQs / Feedback admin: CRUD the FAQ entries; soft-remove inappropriate
feedback entries.

Open Flags: reachable from the Dashboard "Open Flags" card; resolve with a
mandatory reason (see Staff section).

Scheduled flags job: runs via Windows Task Scheduler every 5 minutes
(scripts\evaluate_flags.php — setup command in HANDOFF.md §9/docs).


STATUS CHEAT SHEET (for everyone)
---------------------------------
DRAFT → PENDING → VERIFIED → APPROVED → DISPATCHED → ON_GOING → COMPLETED
Side branches: PENDING/APPROVED → REJECTED/CANCELLED (with reason).
ON_GOING → RESCHEDULED (by GSD Staff).

Who does what: Personnel requests/cancels/certifies · HR ADMIN
verifies/rejects · Staff approves/assigns/issues DTT/resolves flags ·
Driver executes trip + post-trip report · Guard verifies/starts trip · MIS ADMIN
administers everything.
