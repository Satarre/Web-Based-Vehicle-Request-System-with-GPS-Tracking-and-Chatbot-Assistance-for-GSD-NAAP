# HANDOFF — NAAP GSD Digital Trip Ticket System Rebuild

**Generated:** 2026-08-26 (updated after Phase 6 — frontend modernization complete)
**Project root:** `C:\xampp\htdocs\NAAP_MN_VTS_Capstone\`
**App URL:** http://localhost/NAAP_MN_VTS_Capstone/
**Legacy reference (DO NOT MODIFY):** `C:\xampp\htdocs\capstone\`
**Legacy analysis doc:** `C:\xampp\htdocs\vehicleticketingsystem\ARCHITECTURE_BLUEPRINT.md`

---

## 1. Mission

Rebuild the National Aviation Academy of the Philippines — General Services Department
Digital Driver's Trip Ticket System from scratch (vanilla PHP 8.2 + MariaDB, XAMPP),
replacing the legacy app at `C:\xampp\htdocs\capstone\`. All architecture decisions are
**LOCKED** — do not re-litigate them. Build in phases; Phases 0–6 are complete and verified.

## 2. Locked Design Decisions (never change without user)

### Stack & Architecture
- Vanilla PHP 8.2 (no framework, no Composer at runtime), PDO MySQL/MariaDB.
- Server-rendered MPA + progressive JS. **Compiled Tailwind CSS v4.3.3** (`assets/css/tailwind.css` via `npm run build`), Chart.js, xlsx.full.min.js, Leaflet (all local).
- **Centralized `/api/*`** JSON gateways only; pages render, APIs return JSON.
- **CSRF enforced** on every session-authenticated POST (header `X-CSRF-Token` or body/form `csrf_token`), `hash_equals` verified via `core/Csrf.php`.
- Live map: **Leaflet + OpenStreetMap** (no API key). Dashboard GPS polling every 10 s via
  `assets/js/TrackingClient.js`.
- Testing: plain assert runner `tests/run.php` (no PHPUnit).
- GPS = **dedicated IoT hardware per vehicle, NOT driver phones**.

### Workflow / State Machine (10 statuses, `request_status` table)
| ID | Status | ID | Status |
|----|--------------|----|---------------------|
| 1 | DRAFT | 6 | ON_GOING |
| 2 | PENDING | 7 | COMPLETED |
| 3 | VERIFIED | 8 | CANCELLED |
| 4 | APPROVED | 9 | REJECTED |
| 5 | DISPATCHED | 10 | RESCHEDULED |

- Single authority: `core/TripState::can($from,$to)` transition matrix. Never inline status checks.
- **Approver = HR ADMIN (role 2) ONLY** — no delegation (future feature).
- DRAFT is a real editable state; submit → PENDING.
- HR ADMIN (role 2) verifies PENDING → VERIFIED. GSD Staff (role 3) approves VERIFIED → APPROVED.
- Staff (role 3) assigns driver + vehicle → DISPATCHED. Full assignment generates DTT number `DTT-YYYY-####` (per-year counter, `core/TicketNumber.php`, transactional).
- Driver (role 4): start_trip (records odometer start, GPS breadcrumbs begin) → ON_GOING. Sub-events within ON_GOING: Arrived at Dest, Leave Dest, Return to Base — these are GPS breadcrumbs, NOT status changes.
- Guard (role 5): can start trip from guard/desk.php.
- Post-trip report (Section B): odometer end = **official** distance; GPS-derived = supporting only.
- Section B post-trip report is a sub-event within ON_GOING, not a status change.
- Certify & Complete: requester (role 6) only, ON_GOING → COMPLETED.
- Cancel: allowed from PENDING or APPROVED, reason required. Reschedule: from ON_GOING (by GSD Staff), audit old/new schedule; if vehicle/driver conflict arises → clear assignment, back to DISPATCHED.
- DRAFT auto-deletes after 3 days (FlagEvaluator).

### GPS Subsystem
- Pipeline: `Device → POST /api/gps/locations.php (Bearer token) → adapter normalize → throttle ≥20 s → heartbeat → resolve vehicle → resolve active trip (status 6 ON_GOING) → breadcrumb (ticket_id or NULL telemetry) → geofence transition events → 204`.
- `api/gps/*` must stay web-accessible (auth inside PHP, **no .htaccess deny there**). Device auth: Bearer token, `sha256` hash stored in `gps_devices.api_token_hash` CHAR(64).
- Geofence = configurable center+radius (app_settings), **transition-only** events (`geofence_events`).
- Freshness: <45 s CURRENT, 45–120 s DELAYED, >120 s OFFLINE (configurable).
- Dispatch requires paired active GPS device; override possible with mandatory reason → audit `DISPATCH_WITHOUT_GPS_OVERRIDE` + `NO_TELEMETRY` flag.
- UNPAIRED ≠ SIGNAL_LOST / DEVICE_OFFLINE (distinct meanings).
- `GPS_ALLOW_INSECURE_HTTP=true` acceptable **for capstone LAN only**; HTTPS mandatory in prod.
- Flags in `trip_flags` (NO_TELEMETRY, GPS_SIGNAL_LOST, OVERDUE, MISSED_TRIP, GEOFENCE_DEVIATION, NEEDS_REVIEW) evaluated by `scripts/evaluate_flags.php` (Windows Task Scheduler ~5 min) + manual resolution by Staff/MIS with mandatory reason. Stale trips are flagged for review, **never auto-completed**. Late-start reminder: if started past scheduled_departure, show how late.

### Legacy features kept
Cancel (from PENDING/APPROVED), reschedule (from ON_GOING), chatbot (`chatbot` table + FAQs seeded), feedback
(decoupled from lifecycle — COMPLETED without feedback is OK).

### Branding (user-approved)
- Style: **Aviation Institutional** — navy `#0B1F3A` shell, cyan accent `#38BDF8`.
- **Light-first + dark toggle**, persisted in `localStorage['naap_vts_theme']` (NO DB column), switched from the global **Settings drawer** (gear in topbar). Dark = `data-theme="dark"` on `<html>`.
- Font: **Plus Jakarta Sans** (woff2 400–800 in `assets/fonts/pjs/`).
- Density: Corporate compact (radius 8px, row-height 36px).
- Name: "National Aviation Academy of the Philippines" / subtitle "GSD Digital Trip Ticket System".
- Logos (user-supplied, in `assets/img/`): `naap_logo_main.png` (cyan — light backgrounds), `naap_logo_white.png` (dark/cyan backgrounds). Login page swaps logo per theme via inline script.

## 3. Environment & Credentials

- XAMPP on Windows; PHP CLI: `C:\xampp\php\php.exe`; MariaDB 10.4.32, user `root` no password.
- **Database:** `naap_mn_vts` — schema + core seed IMPORTED AND VERIFIED (17 tables, 13 statuses, 22 app_settings, 3 audit triggers on trip_tickets, 6 roles, 8 vehicle types, 5 chatbot FAQs).
  - To reset: `DROP DATABASE naap_mn_vts; CREATE DATABASE naap_mn_vts CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;` then import `database/schema.sql` then `database/seed.sql` then `database/seed_demo.sql` (schema uses plain CREATE TABLE/CREATE TRIGGER — no IF NOT EXISTS).
- **Demo data:** `database/seed_demo.sql` (added in Phase 1 for verification) — demo accounts ALL password `Pass@12345`: director@naap.gov.ph, staff@naap.gov.ph, driver1@/driver2@naap.gov.ph, guard@naap.gov.ph, personnel@naap.gov.ph; vehicles NAA-8001–NAA-8004; GPS devices paired ONLY to NAA-8001/8003 (NAA-8002/8004 deliberately unpaired so the dispatch-without-GPS override flow is testable).
- **Seed admin:** `admin@naap.gov.ph` / `Admin@12345` (bcrypt cost 12; hash `$2y$12$yf49/qxPJ8lc07h/fD7JL.Zp.g4MNA0C5rVlqYT9D/oPx7uugncF2`), role MIS ADMIN, MFA off. CHANGE-BEFORE-DEPLOY noted in seed comments.
- `BASE_URL` = `'/NAAP_MN_VTS_Capstone/'` in `config/config.php`.
- Roles: 1 MIS ADMIN, 2 HR ADMIN, 3 GSD STAFF, 4 DRIVER, 5 GUARD, 6 REQUESTING PERSONNEL.

## 4. Completed — Phase 0 (all verified)

**Database:** `database/schema.sql` (17 tables: account, roles, password_reset_otp, request_status, trip_tickets w/ dtt_number + exec timestamps + Section B numerics + certification cols, trip_passengers, guard_verifications, trip_flags, vehicle_types, vehicle + default_fuel_type, gps_devices, gps_positions DATETIME(3), geofence_events, app_settings, audit_trail, chatbot, feedback; 3 triggers: status-change, vehicle-change, delete → audit_trail). `database/seed.sql` as above.

**Kernel (`core/` + `config/`):**
- `config/config.php` — **`declare(strict_types=1)` MUST be the first statement** (learned via lint). Boots PDO, BASE_URL, autoloader (core/ + gps/), Settings::init, stamps `account.last_active`.
- `core/Settings.php` (typed getters, cache, set()), `core/Audit.php` (never-throws, IP `::1`→`127.0.0.1`), `core/Csrf.php`, `core/Auth.php` (login + lockout from settings, requirePage/requireApi, stale-session refresh, periodic session regen, `LANDING` role→page map: 1→mis/dashboard, 2→director/dashboard, 3→staff/dashboard, 4→driver/dashboard, 5→guard/desk, 6→personnel/requests), `core/TripState.php` (matrix + NAMES + ACTIVE_TRIP=[6] + CANCELLABLE=[2,4]), `core/TOTP.php` (copied from legacy).

**Auth (`auth/`):** `login.php` (branded split hero, MFA-pending variant, theme-aware logo swap), `login_auth.php`, `mfa_verify.php`, `login_cancel_mfa.php`, `logout.php`, `auto_logout.php`. `index.php` redirects to login.

**UI layer (`partials/`):** `icons.php` (icon() SVG helper — **SVGs must carry explicit width/height attrs**, they don't size themselves), `layout_head.php` (theme pre-paint, exposes BASE_URL/CSRF_TOKEN as JS consts), `shell_open.php` (**opens `<div class="shell">` BEFORE including sidebar** — this exact bug was fixed), `sidebar.php` (white logo, renders `nav_for()` from `nav.php`), `topbar.php`, `settings_drawer.php` (drawer + idle overlay markup), `layout_foot.php` (shared JS + `window.APP_IDLE_TIMEOUT` injection), `page_stub.php` (module placeholder w/ "Shell OK / Auth OK / Module pending" badges).
Page contract: pass `$USER, $PAGE_TITLE, optional $ACTIVE, $PAGE_SCRIPTS, $KEEP_SESSION, $IDLE_TIMEOUT` to partials. Sidebar nav comes from `partials/nav.php` (`nav_for($USER['role_id'])`) — pages must NOT define `$NAV`.

**Assets:** `assets/css/app.css` (full light/dark token system via `data-theme`, status badge classes `.b-DRAFT`…`.b-RESCHEDULED`, shell/sidebar/topbar/card/stat/btn/table/forms/drawer/modal/toast/idle-overlay components), `assets/js/{api.js (CSRF-aware fetch), ui.js (toast/modal/confirm), settings.js (drawer + theme + [data-toggle-nav]), TrackingClient.js (10 s polling), error_logger.js (from legacy), session_guard.js, idle.js}`. Vendor libs in `assets/js/vendor/`. Fonts in `assets/fonts/pjs/pjs-{400..800}.woff2`.

**Role stubs (23 pages):** `personnel/{requests,history,feedback}`, `director/{dashboard,approvals,reports,map}`, `staff/{dashboard,assignments,trips,history,fleet,reports}`, `driver/{dashboard,posttrip}`, `guard/{desk,history}`, `mis/{dashboard,accounts,fleet,audit,chatbot,feedback,reports,settings}` — all render through the shell with correct RBAC + nav.

**Security:** root `.htaccess` (-Indexes, deny .sql/.md/.json) + deny-all `.htaccess` in `config/ core/ gps/ database/ scripts/ partials/`. **Deliberately NOT in `api/gps/`** (device-facing).

**Tests:** `tests/run.php` — plain asserts, **85/85 PASS** (Phase 0: config/PDO, settings, TripState matrix, CSRF, auth, audit, DB shape. Phase 1: requestComplete, TicketNumber, ConflictChecker, rollback. Phase 2: full execution chain can() ×6, certify owner-only, manifest round-trip, guard verification row, reschedule conflict detection, Section B columns, rollback. Phase 3: Settings::set round-trip + restore, bcrypt(12) account verify, duplicate email/plate rejected via unique keys, device token stored as sha256 never raw, FAQ CRUD cycle, feedback soft delete, rollback. Phase 4: adapter normalize (aliases/type coercion/range rejection/epoch+future-clamp), haversine sanity, 401 unknown token, 400 unpaired device, 400 invalid payload, NULL-ticket breadcrumb outside fence, transition-only geofence events ENTERED→EXITED, forward-time throttle drops live fix within GPS_MIN_INTERVAL_SEC while heartbeat still updates, IN_PROGRESS trip attaches ticket_id to breadcrumb, GEOFENCE_DEVIATION raised once on fence exit during active trip + no duplicates, rollback. Phase 5: FlagEvaluator raises each scheduled rule once per ticket, idempotent re-run, resolved flag re-raises, rollback). Run: `C:\xampp\php\php.exe tests\run.php` from project root. Extend per phase.

**Browser-verified (Chrome DevTools):** login → role landing works; dark default (prefers-color-scheme) + Light toggle via drawer works (localStorage, bg rgb(246,248,251)); logo swap per theme verified via DOM (`naap_logo_white` visible on dark, `naap_logo_main` on light); `.shell` flex layout fixed and confirmed (sidebar + topbar + stub card + active-nav highlight all correct); idle auto-logout fires after timeout (worked during test pause — expected).

## 5. Completed — Phase 1 (all verified)

**Cores:** `core/TicketNumber.php` (next DTT = MAX(dtt_year_seq)+1 inside tx + unique-key retry ×3), `core/ConflictChecker.php` (overlap `dep < ret AND dep' < ret'`, BUSY statuses [4..8], `describe()` returns human message), `TripState::requestComplete()` added.

**API:** root `log_client_error.php` (**DEVIATION: at project ROOT, not api/** — matches `error_logger.js` ENDPOINT contract; unauthenticated, length-clipped, maps user_email→account_id, writes audit_trail event_type=ERROR). `api/trips.php`: POST save_draft / submit / cancel / approve / reject / assign / ready — CSRF via `Csrf::verifyRequest`, role+state gated via TripState/Auth. **Business failures return HTTP 400 with `{success:false,message}`** (api.js only throws on non-2xx). Assign wraps tx: conflict checks → GPS pairing gate (`needs_override`) → NO_TELEMETRY flag row → audits `DISPATCH_WITHOUT_GPS_OVERRIDE` + `ASSIGN` → TicketNumber → duplicate-DTT retry.

**Pages/JS:** `personnel/requests.php` (stats + list + cancel modal), `personnel/request_form.php` (+10 s autosave, dirty tracking, beforeunload warn), root `ticket.php` shared view (RBAC: role 6 owner-only, role 4 assigned-driver-only; role-aware action bar; **DEVIATION: uses `__DIR__`, not `dirname(__DIR__)`, since it lives at project root**), `director/approvals.php` (queue + recent decisions), `staff/assignments.php` (queue + assign modal with paired-GPS flags); page scripts in `assets/js/pages/{requests,request_form,ticket,approvals,assignments}.js`. `partials/reason_modal.php` reusable reason prompt.

**Auth:** `auth/forget_otp.php` (30 s resend guard, max attempts, dev OTP shown inline when SMTP unconfigured, uniform no-enumeration response) → `auth/forget_pw.php` (min 8 chars, bcrypt cost 12, marks OTP used, audit PASSWORD_RESET) → login shows "Password updated" banner via `$_SESSION['reset_done']`.

**Fixes applied during Phase 1:**
- Sidebar brand cramped (loose end #1): `.brand-text{min-width:0}` + img flex-shrink:0 + smaller name font (app.css).
- Forgot-password pages missing (#2): built (above).
- log_client_error missing (#3): built at root (above).
- `partials/layout_foot.php`: added `#confirmModal` markup; now reads idle timeout from DB `Settings::getInt('IDLE_TIMEOUT_SEC',300)` (DB bumped to 1800 for testing); cache-busts ALL shared JS via `?v=filemtime` helper `$assetV()`.
- `assets/js/api.js` stamps `window._lastApiCall`; `session_guard.js` skips the tab-close beacon within 5 s of an API call (programmatic reloads post-mutation no longer kill the session).
- `api/trips.php` assign previously omitted `dispatched_without_gps` in UPDATE — fixed and ticket 2 backfilled.
- `ticket.php` initially had empty `$NAV` → role-aware nav added.

**Browser-verified end-to-end:** draft create/autosave/URL replaceState; submit; approve w/ confirm modal; reject reason mandatory ("A rejection reason is required."); GPS gate blocks unpaired vehicle without override ("Selected vehicle has no paired active GPS device…"); override path sets NO_TELEMETRY flag + both audits; DTT-2026-0001/0002 issued; Mark Ready; cancel #4 (empty reason blocked, then CANCELLED st=13); live conflict message "Vehicle NAA-8002 is already booked for DTT-2026-0001 (READY_FOR_TRIP)…"; forgot-password full loop incl. dev OTP; client error logging writes ERROR rows. Remaining DB state after verification: ticket 2 = st 5 READY_FOR_TRIP DTT-2026-0001 v=2/d=4 dispatched_without_gps=1 + NO_TELEMETRY; ticket 3 = st 4 ASSIGNED DTT-2026-0002; ticket 4 = st 13 CANCELLED.

**Testing gotcha:** `chrome-devtools_navigate_page` fires the by-design tab-close beacon and kills the session — use in-app link clicks / form `.click()` / `requestSubmit()` when driving the browser; login redirects straight to the role LANDING page.

## 6. Completed — Phase 2 (all verified)

**API (added to api/trips.php):** driver chain `start` (requires odometer_start ≥ 0; sets actual_departure_at) / `arrive` / `leave_dest` / `end_trip` — all role-4 + driver_match via TripState::can, each stamps its exec timestamp column and captures latest gps_positions lat/lon for the vehicle (NULL when unpaired). Shared `$go()` closure does can-check → update → audit (events TRIP_START/TRIP_ARRIVE/TRIP_LEAVE_DEST/TRIP_END). `posttrip` (RETURNED→FOR_CERTIFICATION): validates odometer_end ≥ start, computes distance_odometer = end − start (official), fuel_total_available = tank_start + issued + purchased, balance_end = total − used (rejects negative); audits SECTION_B_SUBMIT. `certify` (owner only, FOR_CERTIFICATION→COMPLETED, certified_by/at). `verify` (role 5): PRE_DEPARTURE allowed at statuses 4–6, RETURN at 6–9; inserts guard_verifications (4 checkboxes + result VALIDATED/FLAGGED + notes), NO status change by design; audits GUARD_VERIFY. `reschedule` (staff or owner, statuses 2–5 only): updates window; if vehicle/driver now conflict → clears vehicle_id/driver_id/dtt_number/dispatched_without_gps and forces back to ASSIGNED (locked decision); audits RESCHEDULE old→new. `save_draft` now accepts `passengers: []` array → replace-all into trip_passengers.

**Pages:** `ticket.php` gained Execution timeline (timestamps + GPS coords), Section B report block, Gate Verifications table, Passenger Manifest line, certification rows; action bar adds Start Trip… (odometer modal #startModal) / Arrived / Leave Destination / End Trip / Submit Post-Trip Report link / Certify & Complete (owner) / Reschedule… (modal #reschedModal with dep/ret datetime-local, prefilled). `personnel/request_form.php` adds Passenger Manifest textarea (one name per line) sent in every save_draft payload. New page scripts: `assets/js/pages/posttrip.js`, `guard_desk.js`.

**Real pages replacing stubs:** `driver/dashboard.php` (My Trips: statuses ASSIGNED..COMPLETED ordered by execution relevance), `driver/posttrip.php` (?id= form for RETURNED trips of mine + pick-list + already-submitted table), `guard/desk.php` (list of trips in statuses 4–9 with per-phase done markers; ?ticket_id= shows summary + verification form with suggested phase), `guard/history.php` (my verifications, newest first).

**Browser-verified end-to-end:** driver1 started DTT-2026-0001 (odometer 45230.5 recorded), arrived/left/ended through IN_PROGRESS→AT_DESTINATION→RETURNING→RETURNED with full timeline; posttrip form submitted (odo end 45410.2 → distance 179.7 km; fuel 30+20=50 total, 42.5 used → 7.5 balance) → FOR_CERTIFICATION; personnel (owner) certified → COMPLETED with certified_by/at stamped; guard verified ticket 3 PRE_DEPARTURE VALIDATED (all 4 checks) → visible in desk list ("PRE ✓"), history page, and on the ticket itself; reschedule moved ticket 3 to Sep 4 09:00–14:00 cleanly. Suite 52/52 after all edits.

**Bugs found during Phase 2 verification:**
- `guard/desk.php` was written WITHOUT layout_head/shell_open includes in the detail branch → bare fragment, BASE_URL undefined client-side so API.post hit `/api/trips.php` (404). Fixed by adding the two requires before the branch.
- Automation reminder reconfirmed: a mid-session `navigate_page` fires the tab-close beacon → session destroyed → next API call fails CSRF with a fresh empty session. Always drive the browser via in-app clicks.

## 7. Completed — Phase 3 (all verified)

**API:** new `api/admin.php` — single JSON gateway for ALL MIS mutations (role 1 only, CSRF audited). Actions: `vehicle_save` (create/update, plate uniqueness, type validation), `vehicle_toggle`, `type_add`, `device_register` (**generates 32-byte raw bearer token returned ONCE; only sha256 stored in api_token_hash**), `device_pair` / `device_unpair` (single action, vehicle_id=0 unpairs), `device_toggle`, `account_save` (bcrypt cost 12; password optional on edit = reset), `account_toggle` (self-deactivation blocked, clears lockout counters), `settings_save` (batch key/value map validated against app_settings, goes through Settings::set so cache refreshes), `faq_save`, `faq_delete`, `feedback_delete` (soft). All writes audited.

**Pages (all real now, replacing stubs):** `mis/dashboard.php` (stat cards: fleet/devices/accounts/tickets-this-month/open-flags + status distribution bars), `mis/fleet.php` (vehicles table + add/edit modal, GPS devices with inline pair-select + one-time-token box, vehicle-types quick add), `mis/accounts.php` (accounts w/ role badges, add/edit modal, activate/deactivate), `mis/settings.php` (all app_settings editable, sensitive keys PASS/SECRET/TOKEN masked as password inputs), `mis/audit.php` (filterable by event_type + free-text search over object/detail/user, paginated 50/page), `mis/chatbot.php` (FAQ CRUD), `mis/feedback.php` (list + soft-remove), `mis/reports.php` (Chart.js bar of tickets/month from scheduled_departure + trips & odometer-distance per vehicle table).

**JS:** single shared `assets/js/pages/admin.js` drives all mis pages (binds only elements that exist): generic `data-edit` JSON → modal fill pattern, form submits, toggle buttons via `data-toggle-action`, pair selects via `data-pair-device`. New `reports.js` for the chart.

**Session-guard improvement:** `session_guard.js` now honors a one-shot `window._navGuardOnce` suppressor for programmatic reloads that legitimately outlive the 5 s API-call window (e.g. an admin copying a freshly-generated device token before clicking Done). Without it, any post-registration reload >5 s after the API call fired the tab-close beacon and destroyed the session.

**Browser-verified end-to-end:** dashboard live stats correct vs DB; registered GPS-NAAP-TEST-01 → raw token displayed once in highlighted box; paired it to NAA-8004 via inline select (persisted, Link column updated); settings save persisted (`OVERDUE_GRACE_MIN` 30→35→restored, `updated_by` stamped); audit trail shows all events incl. DEVICE CREATE/pair/MODIFY rows and client ERROR rows; reports chart renders with real aggregates.

## 8. Completed — Phase 4 (all verified)

**Core:** `core/Gps.php` (single file, four pieces):
- `GpsProviderInterface` + `HttpJsonProvider` — normalize maps aliases (`latitude|lat`, `longitude|lng|lon`, `speed_kph|speed`, `heading|course`, `altitude_m|altitude`, `accuracy_m|accuracy`), range-validates lat/lng, clamps optional numerics, `ignition`→0/1; `recorded_at` accepts `Y-m-d H:i:s`, ISO-8601 or epoch seconds, defaults now, **future timestamps clamped to now**. Throws InvalidArgumentException → 400.
- `Geo` — `haversineMeters()` + `insideFence()` against settings `NAAP_GEOFENCE_LAT/LNG/RADIUS_METERS` (500 default).
- `GpsIngest::handle($pdo,$bearer,$raw,?provider)` full pipeline: Bearer → SHA2 lookup on `api_token_hash` (401 unknown/inactive) → paired vehicle required (400) → **throttle forward-time only** (fix newer than last stored and < `GPS_MIN_INTERVAL_SEC` apart is dropped; backdated replays ALWAYS persist so history is never silently lost) → resolve active trip statuses 6/7/8 → breadcrumb INSERT w/ `inside_geofence` flag → **transition-only** geofence event vs previous row's flag (`GEOFENCE_ENTERED`/`GEOFENCE_EXITED`) → heartbeat UPDATE of `last_seen_at/last_latitude/...` runs even when throttled → 204 silent success (devices never parse responses). Joins an open caller transaction (tests run it inside one); own tx otherwise. **Deviation flag:** a GEOFENCE_EXITED transition while attached to an active trip raises `GEOFENCE_DEVIATION` in `trip_flags` (INSERT…SELECT…WHERE NOT EXISTS — one open flag per ticket, deduped at app level since MySQL unique keys treat NULL resolved_at as non-distinct); resolution stays manual by Staff/MIS with mandatory reason (Phase 5 UI). Surfaces automatically on ticket.php open-flags block + mis/dashboard open-flags stat.

**API:** `api/gps/locations.php` — thin device-facing POST wrapper (Bearer from Authorization header with `apache_request_headers()` fallback for CGI modes; body = $_POST or raw JSON; 204 empty body on success, JSON `{success:false,message}` on errors). Stays web-accessible — NO .htaccess deny (locked decision). `api/gps/latest.php` — session-authenticated snapshot (`Auth::requireApi` roles 1/2/3): active devices JOIN vehicles, freshness buckets from `GPS_FRESH_SEC`(45)/`GPS_DELAYED_SEC`(120), per-vehicle active trip attach (ticket_id, DTT, status) only when fresh enough.

**Pages/JS:** root `tracking.php` — shared live map for roles 1/2/3 with its own $NAV ("Live Tracking" entry added to every mis/director/staff page nav); Leaflet vendored locally in `assets/js/vendor/leaflet/{leaflet.css,leaflet.js,images/}`; dashed cyan geofence circle rendered server-side from settings via `#map` data attrs; fleet side-panel with CURRENT/DELAYED/OFFLINE badges, click-row → `map.flyTo`. `assets/js/pages/tracking.js` drives it; `TrackingClient.js` now hits `latest.php`, flags changed positions per device (skip re-animating unchanged markers), plus shared `freshnessOf()`/`humanAge()` helpers. `ticket.php` gained a GPS breadcrumb trail map (`#crumbMap`: polyline + Departure/Latest-fix markers + fitBounds, shown when ≥2 crumbs exist; supporting info only — odometer stays official). Stub pages retired: `director/map.php` and `staff/fleet.php` are now redirects to tracking.php. `mis/dashboard.php` added devices-online stat (heartbeat within GPS_DELAYED_SEC).

**Settings consumed:** `GPS_MIN_INTERVAL_SEC`=20, `GPS_FRESH_SEC`=45, `GPS_DELAYED_SEC`=120, `NAAP_GEOFENCE_LAT/LNG`=14.5126/121.0182, `NAAP_GEOFENCE_RADIUS_METERS`=500 (all seeded; fence coords "calibrate later").

**Verification:** suite 78/78 PASS incl. the Phase 4 acceptance block (runs GpsIngest inside a rolled-back tx, self-cleans `GPS-PH4-%` debris first; deviation-flag checks assert raise-once + no-duplicate + rollback cleanup). Browser artifacts at project root: `tracking-live.png` (live markers + panel), `tracking-flyto.png` (fly-to from list), `ticket2-crumbs.png` (breadcrumb trail on ticket view).

## 9. Completed — Phase 5 (all verified)

**Flags engine:** `core/FlagEvaluator.php` — `FlagEvaluator::run($pdo): array` returns per-rule raise counts. Four scheduled rules, each INSERT…SELECT…WHERE NOT EXISTS (max one OPEN flag per ticket+type): OVERDUE (statuses 6/7/8 past return + `OVERDUE_GRACE_MIN`=30), MISSED_TRIP (statuses 4/5 past departure + `MISSED_TRIP_GRACE_HOURS`=2), GPS_SIGNAL_LOST (active trip whose paired device heartbeat is older than `GPS_DELAYED_SEC`=120), NEEDS_REVIEW (statuses 2–10 stale > `STALE_REVIEW_DAYS`=3 — review flag, never auto-completed). `scripts/evaluate_flags.php` CLI-only entrypoint (403 over HTTP), prints per-rule counts; Windows Task Scheduler command in its header comment + docs.

**API (api/trips.php):** `resolve_flag` (roles 3/1, mandatory reason ≤500 chars, stamps resolved_by/at, audits FLAG_RESOLVE) + `feedback_save` (role 6, own-ticket enforced).

**Pages/JS:** root `flags.php` — shared flag desk (roles 1/3): Open Flags table + Resolve buttons + Recently Resolved (15 newest), driven by `partials/reason_modal.php` + `assets/js/pages/flags.js`. Flags nav added to all five staff pages; mis/dashboard "Open Flags" card links through when count > 0. `personnel/feedback.php` real page (own tickets ≠ DRAFT dropdown, details textarea, previous-feedback table) + `feedback.js` — stub retired. `director/reports.php` real page: Chart.js 12-month tickets bar, fleet utilization table, full trip log, Export → SheetJS two-sheet xlsx (`Trip Log` / `Per Vehicle`) via `director_reports.js` (chart.umd + xlsx.full already local). Director deliberately has NO Flags nav entry (flags.php is roles 1/3 only).

**Hardening:** root `.htaccess` adds `X-Content-Type-Options: nosniff`, `X-Frame-Options: SAMEORIGIN`, `Referrer-Policy: strict-origin-when-cross-origin`. Session hardening pre-existed (httponly, SameSite=Strict, strict mode, login + periodic regen); login-form CSRF accepted as low-risk behind SameSite=Strict (rationale recorded in docs/ARCHITECTURE.md). All POST gateways remain Csrf::verifyRequest-gated; user data rendered server-side escaped / client-side via DOM APIs.

**Docs:** `docs/ARCHITECTURE.md` (system overview, lifecycle, GPS pipeline, flags, security model, scheduler setup) + `docs/DEVICE_API.md` (ESP32 vendor contract: endpoint, Bearer auth, payload fields, throttle/freshness, response codes, curl smoke test).

**Verification:** suite **85/85 PASS** incl. the Phase 5 acceptance block (fixtures PH5-FIXTURE / GPS-PH5-STALE / NAA-PH5-T1 inside one rolled-back tx: each rule raises ≥1, immediate re-run idempotent sum=0, resolving a flag lets it re-raise). Live evaluator run on demo data raised 1 real GPS_SIGNAL_LOST. Browser-verified (Chrome DevTools): staff login → flags desk shows 3 open → resolved GPS_SIGNAL_LOST with mandatory reason → count 3→2 with reason/resolver rendered; director/reports.php renders with zero console errors.

## 10. Known Issues / Loose Ends (fix as encountered)

1. Driver mid-trip pages must set `document.body.dataset.keepSession='1'` to suppress idle logout + tab-close beacon (hook already built into `session_guard.js`/`idle.js`; no page uses it yet).
2. Session note: the tab-close `sendBeacon` in `session_guard.js` logs the session out on ANY JS-driven full navigation (observed during testing — by design). In-app links are exempt; automated browser testing should expect re-login after `navigate_page`.
3. Minor a11y nit (pre-existing across phases): some inputs/selects rely on adjacent StaticText/placeholders instead of `<label for>` (Chrome DevTools "No label associated with a form field" ×4 on personnel/feedback.php). Cosmetic; fix opportunistically.

## 11. Phase Roadmap

- ~~**Phase 1 — Request → Approval → Assignment:**~~ **DONE & VERIFIED** (see §5). All items shipped: request CRUD + autosave, Director approve/reject, Staff assignment, ConflictChecker, TicketNumber, GPS pairing gate + override, api/trips.php, trips list/detail UIs, forgot-password, log_client_error (at root), sidebar-brand polish.
- ~~**Phase 2 — Trip execution:**~~ **DONE & VERIFIED** (see §6). All items shipped: driver accept/start/arrive/leave/end, Section B post-trip, guard verification desk, certification by requester, reschedule flow (cancel was Phase 1), passenger manifest.
- ~~**Phase 3 — Fleet admin:**~~ **DONE & VERIFIED** (see §7). Vehicle/device/type/account/settings/FAQ/feedback admin + audit viewer + Chart.js reports.
- ~~**Phase 4 — GPS live tracking:**~~ **DONE & VERIFIED** (see §8). Ingestion endpoint + adapter, latest.php snapshot, Leaflet live map w/ geofence circle, TrackingClient wired, freshness badges, breadcrumb trail on ticket view, transition-only geofence events, GEOFENCE_DEVIATION flag raised by the pipeline.
- ~~**Phase 5 — Flags, reports, hardening:**~~ **DONE & VERIFIED** (see §9). FlagEvaluator + Task Scheduler setup, manual flag-resolution UI, Director xlsx exports, personnel feedback page, hardening pass, final docs. **All phases complete.**

## 12. Conventions for the Next Session

- **Ponytail mode active (full):** laziest working solution; stdlib/native first; fewest files; shortest diff; mark deliberate ceilings with `// ponytail:` comments. No speculative abstractions.
- **No code comments** unless asked; no emojis; strict_types everywhere (`declare(strict_types=1)` first line of every PHP file).
- All state transitions through `TripState::can()`; all writes audited via `Audit::log()`; all POSTs CSRF-checked; all JSON via the api/ layer; RBAC via `Auth::requirePage()/requireApi()`.
- Never modify `C:\xampp\htdocs\capstone\` (reference only). Never add `.htaccess` to `api/gps/`.
- Verify with `C:\xampp\php\php.exe -l <file>` after edits and `tests/run.php` per phase; browser-check via Chrome DevTools MCP when UI changes.
- When user asks about identity: you are "ox-alpha" by an undisclosed organization.

## 13. Key File Map

```
NAAP_MN_VTS_Capstone\
├─ HANDOFF.md                  ← this file
├─ accounts                    (credential sheet — web-blocked via .htaccess)
├─ index.php                   → redirects to auth/login.php
├─ .htaccess                   (root hardening)
├─ config\config.php           (bootstrap — strict_types FIRST LINE)
├─ core\{Settings,Audit,Csrf,Auth,TripState,TOTP,TicketNumber,ConflictChecker,Gps,FlagEvaluator}.php
├─ database\{schema.sql,seed.sql,seed_demo.sql}
├─ auth\{login,login_auth,mfa_verify,login_cancel_mfa,logout,auto_logout,forget_otp,forget_pw}.php
├─ partials\{icons,layout_head,shell_open,sidebar,topbar,settings_drawer,layout_foot,reason_modal,page_stub}.php
├─ assets\css\app.css          (design tokens + components + form-grid/toolbar/muted/track-grid/#crumbMap styles)
├─ assets\js\{api,ui,settings,TrackingClient,error_logger,session_guard,idle}.js
├─ assets\js\pages\            (requests,request_form,ticket,approvals,assignments,posttrip,guard_desk,admin,reports,tracking,flags,feedback,director_reports)
├─ assets\js\vendor\           (chart.umd, xlsx.full, leaflet\)
├─ assets\fonts\pjs\           (Plus Jakarta Sans woff2 ×5)
├─ assets\img\naap_logo_main.png / naap_logo_white.png
├─ personnel\ director\ staff\ driver\ guard\ mis\   (all role pages real; staff navs include Flags; director/map.php + staff/fleet.php = redirects to tracking.php)
├─ ticket.php                  (shared ticket view — root level; includes GPS breadcrumb map)
├─ tracking.php                (shared live vehicle map — roles 1/2/3, root level)
├─ flags.php                   (shared flag desk — roles 1/3, root level)
├─ log_client_error.php        (client error sink — root per error_logger.js contract)
├─ api\{trips,admin}.php       (lifecycle + admin + flag-resolution + feedback mutations)
├─ api\gps\{locations,latest}.php  (device ingest Bearer / session snapshot — web-accessible BY DESIGN, no .htaccess deny)
├─ scripts\evaluate_flags.php  (flag-evaluator CLI — schedule via Task Scheduler ~5 min)
├─ gps\                         (deny-all .htaccess)
├─ docs\{ARCHITECTURE.md,DEVICE_API.md,MANUALS.md}   (system overview + ESP32 contract + per-role user manuals)
└─ tests\run.php               (85/85 PASS)
```
