# NAAP GSD Digital Trip Ticket System

Vehicle request and tracking system for the **National Aviation Academy of the Philippines - General Services Division (GSD)**. Replaces manual paper-based trip tickets with a fully digital workflow: request submission, multi-level approval, GPS-tracked dispatch, post-trip reporting, and audit logging.

**Stack:** PHP 8.2 · MariaDB 10.4 · Tailwind CSS v4 · Google Maps JS + Places · PHPMailer (Composer) · XAMPP (Windows)

---

## Architecture

```
┌─────────────────────────────────────────────────────────┐
│                   Browser (MPA)                         │
│  role folders (personnel/, staff/, driver/, guard/,     │
│  director/, mis/, account/) + shared (ticket.php,       │
│  tracking.php, tracker.php, flags.php, file.php)        │
│  └─ partials shell (layout_head → sidebar → topbar)     │
│  └─ mutations via fetch() → assets/js/api.js (CSRF)     │
│  └─ live updates via SSE (assets/js/SSEClient.js)       │
└──────────────────────┬──────────────────────────────────┘
                       │ POST (JSON/form)
┌──────────────────────▼──────────────────────────────────┐
│  api/trips.php    lifecycle actions (all roles)         │
│  api/admin.php    MIS mutations (role 1 only)           │
│  api/account.php  self-service profile/password/MFA     │
│  api/chatbot.php  rule-based assistant                  │
│  api/sse.php      notification stream (Server-Sent)     │
│  api/calendar.php · api/badge_counts.php                │
│  api/notifications_mark_read.php                        │
│  api/gps/*        ingestion (Bearer) + tracker (session)│
└──────────────────────┬──────────────────────────────────┘
                       │
┌──────────────────────▼──────────────────────────────────┐
│  core/ kernel                                           │
│  Auth (RBAC + session)  TripState (state machine)       │
│  ConflictChecker        TicketNumber (NAAP-MN-YYYY-####)│
│  FlagEvaluator          Gps (ingest + geofence)         │
│  RouteService           RoadsService                    │
│  NotificationService    SecurityEvent                   │
│  SecurityNotificationService  RateLimiter               │
│  PasswordPolicy  MfaService  TotpService  OtpService    │
│  MailService  ChatbotAssistant  ChatbotMatcher          │
│  TripGuard  Audit  Csrf  Settings  TOTP                 │
└──────────────────────┬──────────────────────────────────┘
                       │
┌──────────────────────▼──────────────────────────────────┐
│  MariaDB  naap_mn_vts  (26 tables, 3 audit triggers)    │
│  database/schema.sql · seed*.sql · migrations/          │
└─────────────────────────────────────────────────────────┘

External:
  GPS devices → POST /api/gps/locations.php (Bearer)
  SMTP mail   → core/MailService.php (PHPMailer)
  Cron (every 5 min) → scripts/evaluate_flags.php (CLI)
```

- **No framework** — vanilla PHP with `core/` kernel classes and PDO prepared statements.
- **MPA + progressive JS** — pages render server-side; all mutations go through centralized JSON API gateways.
- **Autoloader** — Composer (`vendor/autoload.php`, PHPMailer) plus `spl_autoload_register` for `core/` and `gps/` classes.

### Project Structure

```
NAAP_MN_VTS_Capstone/
├── index.php               # entry: redirects to role dashboard or login
├── ticket.php              # ticket detail + workflow actions (all roles)
├── tracking.php            # live map (Google Maps, polls api/gps/latest.php)
├── tracker.php             # GPS TRACKER (role 7) device status page
├── flags.php               # shared flag desk (MIS + Dispatcher)
├── file.php                # authenticated request-letter streamer (uploads/)
├── log_client_error.php    # sink for assets/js/error_logger.js
├── api/                    # JSON gateways (trips, admin, account, chatbot,
│                           # sse, calendar, badge_counts, notifications_mark_read)
│   └── gps/                # locations.php (Bearer) · latest.php · me.php · tracker_ping.php
├── assets/
│   ├── css/                # tailwind.src.css → tailwind.css (build) + app.css (semantic classes)
│   ├── js/                 # api.js, ui.js, idle.js, SSEClient.js, TrackingClient.js, …
│   │   ├── pages/          # one script per page/feature
│   │   └── vendor/         # chart, xlsx, jszip, qrcode, flatpickr, docx-preview
│   └── img/                # webp logos/hero images + favicon
├── auth/                   # login/logout, MFA challenge + setup, password/email OTP flows
├── account/                # profile, MFA, own audit trail
├── personnel/              # role 6: request form, my requests, history, feedback
├── staff/                  # role 3: dashboard, trips, assignments
├── driver/                 # role 4: dashboard, post-trip report
├── guard/                  # role 5: desk, history
├── director/               # role 2: dashboard, approvals, trips, vehicles, reports, feedback, chatbot
├── mis/                    # role 1: dashboard, accounts, fleet, reports, audit, settings, feedback, chatbot
├── core/                   # kernel classes (Auth, TripState, Gps, FlagEvaluator, services)
├── partials/               # layout shell, sidebar, topbar, calendar, chatbox, settings drawer, icons
├── config/                 # config.php + secrets.example.php (secrets.php is git-ignored)
├── database/               # schema.sql, seed.sql, seed_demo.sql, migrations/, schemaDiagram.sql
├── docs/                   # ARCHITECTURE, DEVICE_API, MANUALS, DEPLOY_HOSTINGER, screenshots
├── hardware/               # esp32_neo7m_gps/ Arduino sketch for the GPS unit
├── scripts/                # evaluate_flags.php (cron), backfills, seeds, class_audit, deploy_check
├── tests/                  # run.php — dependency-free acceptance suite
├── uploads/                # runtime request letters (git-ignored, denied by .htaccess)
├── INSTRUCTIONS/ · README/ · SYSTEM MANUAL/   # standalone deployment docs + package SQL
└── HANDOFF.md · HANDOFF_MIGRATION.md · IMPLEMENT.md   # project notes
```

### Key Design Decisions

| Decision | Rationale |
|---|---|
| Single `TripState` authority | Prevents illegal status transitions; `can()` is the only gate |
| `ConflictChecker` overlap test | Prevents double-booking vehicles/drivers for overlapping windows |
| `NAAP-MN-YYYY-####` numbering | Per-year sequence with unique-key retry; no counter table needed |
| GPS Bearer token (SHA-256) | Raw token shown once at registration; only hash stored |
| Flag evaluator (CLI cron) | Decoupled from web requests; safe to run concurrently |
| Audit triggers + `Audit::log()` | Dual-layer logging — PHP for app events, DB triggers for status/vehicle changes |

---

## Account Types & Roles

The system has **6 roles** with distinct permissions and landing pages.

### 1. MIS ADMIN (Role 1)

**Landing page:** `mis/dashboard.php`
**Default account:** `admin@naap.gov.ph`

| Page | Purpose |
|---|---|
| Dashboard | System overview, open flags card, quick stats |
| Accounts & Roles | Create/edit/deactivate user accounts, assign roles, reset passwords |
| Vehicles & GPS Devices | Register vehicles, GPS devices (one-time token), pair/unpair, manage vehicle types |
| Live Tracking | Real-time Google Maps view with fleet positions and geofence overlay |
| Audit Trail | Full audit log — filter by event type, user, free-text search |
| Chatbot FAQs | CRUD FAQ entries for the personnel chatbot |
| Feedback | View/remove feedback submitted by personnel |
| Reports | Monthly ticket bar chart, fleet utilization, trip log, Excel export |
| System Settings | All application settings (GPS timeouts, grace periods, geofence, SMTP, etc.) |
| Flags (shared) | View and resolve open trip flags with mandatory reason |

**Capabilities:**
- Full system administration (accounts, vehicles, GPS devices, settings)
- Can resolve any open trip flag
- Can view all tickets and live tracking
- Cannot submit trip requests or approve them
- Every mutation is audit-logged

---

### 2. Supervising Administrative Officer (Role 2)

**Landing page:** `director/dashboard.php`
**Example account:** `director@naap.gov.ph`

| Page | Purpose |
|---|---|
| SAO Dashboard | Overview of pending approvals and trip statistics |
| Approve Requests | Queue of PENDING tickets — approve or reject with reason |
| Trip Tickets | View all trip tickets |
| Reports | Monthly charts, fleet utilization, trip log, Excel export |
| Vehicles | View vehicle fleet (read-only) |
| Feedback | View submitted feedback |
| Chatbot | View/manage FAQ entries |
| Live Tracking | Real-time vehicle map |

**Capabilities:**
- **Approves** trip requests straight from PENDING (no verification step) — the only role that can do this
- **Rejects** requests with mandatory reason
- Can view reports and live tracking
- Cannot assign vehicles/drivers

---

### 3. GSD Dispatcher (Role 3)

**Landing page:** `staff/dashboard.php`
**Example account:** `staff@naap.gov.ph`

| Page | Purpose |
|---|---|
| Dispatcher Dashboard | Overview of assignments and ticket flow |
| Assignments | Assign driver + vehicle, dispatch, GPS pairing gate |
| Trip Tickets | View all tickets |
| Flags | View and resolve open trip flags |
| Live Tracking | Real-time vehicle map |
| Reports | Monthly charts, fleet utilization, trip log |

**Capabilities:**
- **Assigns** driver and vehicle (APPROVED → DISPATCHED)
- Checks schedule conflicts before assignment
- Dispatch-without-GPS override (raises NO_TELEMETRY flag)
- **Resolves** trip flags with mandatory reason
- Can reschedule APPROVED tickets (APPROVED → RESCHEDULED) with a reason
- Can reject tickets at the APPROVED stage

---

### 4. Driver (Role 4)

**Landing page:** `driver/dashboard.php`
**Example accounts:** `driver1@naap.gov.ph`, `driver2@naap.gov.ph`

| Page | Purpose |
|---|---|
| My Trips | Dashboard of assigned trips |
| Post-Trip Report | Fill and submit post-trip odometer + fuel report |

**Capabilities:**
- **Starts trip** (DISPATCHED → ON_GOING) — enters odometer start reading
- **Strict step sequence while ON_GOING** (server-enforced, cannot be skipped): Destination Reached → Start Return → Post-Trip Report → **Complete Trip Ticket** (stamps return to base)
- **Submits post-trip report** — odometer end, fuel consumption, lubricants, toll costs, remarks (required before completion)
- **Stops trip** (special cases, in transit only, reason required) → CANCELLED + `NEEDS_REVIEW` flag
- **Corrects the last recorded step** (reason required) → raises `NEEDS_REVIEW`
- Sees only their own assigned trips
- GPS breadcrumbs are automatically attached if vehicle has a paired device; the completed route is road-snapped per leg and stored in `trip_tracks`
- If started late, system shows how many hours/minutes behind schedule

---

### 5. Guard (Role 5)

**Landing page:** `guard/desk.php`
**Example account:** `guard@naap.gov.ph`

| Page | Purpose |
|---|---|
| Departure / Return Desk | Verify departing/returning vehicles |
| Verification History | Past verifications performed |

**Capabilities:**
- **Pre-departure verification** (DISPATCHED or ON_GOING): inspects driver, vehicle, plate number, passengers
- **Return verification** (ON_GOING or COMPLETED): verifies return condition
- Checklist: checked_driver, checked_vehicle, checked_plate, checked_passengers
- Sets result: VALIDATED or FLAGGED (with optional notes)
- Verifications are **events only** — they do NOT change ticket status
- Can also start trip from desk (DISPATCHED → ON_GOING)
- All verifications appear on the ticket detail view

---

### 6. Personnel / Requester (Role 6)

**Landing page:** `personnel/requests.php`
**Example account:** `personnel@naap.gov.ph`

| Page | Purpose |
|---|---|
| My Requests | Create new requests, view status, edit drafts, cancel |
| Request History | Past requests and their outcomes |
| Feedback | Submit feedback for completed trips |

**Capabilities:**
- **Creates trip requests** — fills destination (with autocomplete geocoding), purpose, schedule, passenger manifest
- **Autosave** every 10 seconds while editing a draft
- **Submits** draft for approval (DRAFT → PENDING) — requires complete fields + attached request letter
- **Cancels** own tickets (with reason) at DRAFT, PENDING, APPROVED, or DISPATCHED stage
- **Resubmits a new schedule** after the GSD Dispatcher sends a ticket back (RESCHEDULED → PENDING)
- Uploads/removes request letter attachments (PDF, image, Word)
- Submits feedback for completed trips
- Sees only their own requests

**GSD Assistant (sidebar → "Ask GSD Assistant"):**

Rule-based, no external AI. Keeps all 12 seeded FAQ guides (stored keyword +
`|`-separated synonyms, matched by `core/ChatbotMatcher.php`) and adds
task-oriented help:

| Behaviour | Detail |
|---|---|
| Auto-suggest | Topic chips filter as you type (client-side, matches labels, keywords and synonyms) |
| Grouped topics | "Browse all topics" opens a full-panel browser grouped into *For this page / Requests / Status & tracking / Changes & help* (+ *Other topics* for FAQs an admin adds later), with its own filter box |
| Page-aware topics | Chips and actions change per page (`personnel/requests.php`, `history.php`, `feedback.php`, `request_form.php`, `ticket.php`) |
| New-trip button | "Open request form" action links straight to `personnel/request_form.php` |
| Status lookup | Ask `status` / `my request status` — renders the latest 3 tickets as tappable cards with the app's status badges (`badge-*`), schedule, place and next-step guidance |
| Trip details | Ask `trip details` (or type a `NAAP-MN-…` number) — one detail card with badge, destination, schedule, vehicle + driver and guidance |
| Status guidance | Explains what the current status means and the next step the state machine actually allows (`TripState::can()`) |
| Form warnings | Asking about required fields/schedule rules on the request form returns one warning block listing what's missing, each with a **Fix →** button that scrolls to, focuses and flashes that field |
| **Guided request** | Ask `help me create a trip ticket` (or tap **Fill it in with me**) and the assistant walks 7 cards: traveller name → requester details → destination (Places autocomplete) + purpose → schedule → passenger manifest → signed request letter upload → review. **Submit for approval** creates the real ticket; **Save as draft** hands off to the pre-filled request form; **Cancel** erases the draft it created |
| Follow-ups | Every answer suggests relevant next topics |
| Fallback / escalation | Unanswered questions render as a muted "not an answer" bubble; after 3 consecutive misses a **contact card** appears with the GSD contact details and an Open My Requests button |

Every dynamic answer is scoped to `created_by = session user`; no account or
ticket id is ever taken from the client.

---

## Trip Ticket Lifecycle

### Status Flow

```
DRAFT ──→ PENDING ──→ VERIFIED ──→ APPROVED ──→ DISPATCHED ──→ ON_GOING ──→ COMPLETED
  │           │            │            │            │              │
  │           ↓            ↓            ↓            ↓              ↓
  └──→ CANCELLED       REJECTED     REJECTED     CANCELLED    RESCHEDULED
                         (reason)    (reason)     (reason)        │
                                                                 ↓
                                                           DISPATCHED (re-assign)
```

### Step-by-Step Process

| Step | Actor | Action | Status Change | Notes |
|---|---|---|---|---|
| 1 | Personnel | Create request | → DRAFT | Fills destination, purpose, schedule, passengers |
| 2 | Personnel | Submit request | DRAFT → PENDING | Requires complete fields + request letter. Ticket no. NAAP-MN-YYYY-#### issued |
| 3 | SAO | Approve request | PENDING → APPROVED | Direct approval, no verification step |
| 4 | GSD Dispatcher | Assign vehicle + driver | APPROVED → DISPATCHED | Checks vehicle/driver schedule conflicts. GPS pairing gate. |
| 5 | Driver (or Guard) | Start trip | DISPATCHED → ON_GOING | Enters odometer start. GPS breadcrumbs begin. System notes lateness. |
| 6 | Driver | Destination Reached | (sub-event) | `dest_arrival_at` + GPS fix |
| 7 | Driver | Start Return | (sub-event) | `dest_departure_at` + GPS fix |
| 8 | Driver | Submit post-trip report | (data update) | Odometer end, fuel, lubricants, tolls, remarks (required before completion) |
| 9 | Driver | Complete Trip Ticket | ON_GOING → COMPLETED | Stamps `return_at` + GPS fix; road-snaps the recorded route |
| — | Driver | Stop Trip (in transit, reason) | ON_GOING → CANCELLED | Raises `NEEDS_REVIEW` |
| — | Guard | Pre-departure verify | (event only) | Checklist: driver, vehicle, plate, passengers |
| — | Guard | Return verify | (event only) | Checklist on return |

### Cancellation & Rejection

- **Cancellation**: Available from DRAFT, PENDING, APPROVED, or DISPATCHED, by the **ticket owner only**, with a reason. An ON_GOING trip can be ended early only by the assigned driver using **Stop Trip** (reason required), which moves it to CANCELLED and raises `NEEDS_REVIEW`.
- **Rejection**: Available from PENDING (by SAO) or APPROVED (by GSD Dispatcher). Requires a written reason. Terminal — ticket cannot proceed.

### Rescheduling

- The GSD Dispatcher can send an APPROVED ticket back (APPROVED → RESCHEDULED) with a reason; the requester then sets a new departure/return (RESCHEDULED → PENDING) for SAO re-approval.
- The assignment is cleared on reschedule, so dispatch happens again after re-approval.

### Guard Verification

- **Pre-departure**: Allowed while DISPATCHED or ON_GOING. Guard inspects driver, vehicle, plate, and passengers.
- **Return**: Allowed while ON_GOING or COMPLETED. Guard verifies return condition.
- Verifications are **events only** — they record observations but never change the ticket status.
- Result can be VALIDATED or FLAGGED (with optional notes).

---

## GPS Tracking System

### How It Works

1. **GPS devices** (ESP32, vendor trackers, or software/phone trackers) POST fixes to `/api/gps/locations.php` with a Bearer token
2. Server validates token (SHA-256 lookup), normalizes payload, throttles rapid fixes
3. Fixes are stored in `gps_positions` with `DATETIME(3)` precision
4. Active trips (ON_GOING) automatically attach GPS breadcrumbs
5. Geofence transitions (campus entry/exit) are detected and logged
6. Live map (`tracking.php`) polls every 10 seconds and renders on Google Maps (`assets/js/maps_loader.js`, key from `GOOGLE_MAPS_API_KEY`)

### Software trackers (hidden tracker accounts)

A tracker that isn't a hardware unit can be provisioned from **Vehicles & GPS Devices → Register Tracker Account**. This creates a **hidden system account** (`account.is_system = 1`, role `GPS TRACKER`) that owns a paired `gps_devices` row and its bearer token:

- The account is **excluded from Accounts & Roles** (system account), but it **signs in normally** with the email + password set at registration and lands on `tracker.php` — its own device status page.
- **No separate app needed:** signed in on a phone, **Start sharing** streams the phone's GPS through the same pipeline (session-authenticated, requires HTTPS; offline fixes are queued and replayed with their original timestamps).
- The bearer token is shown **once** at registration; only its SHA-256 is stored — apps/vendor clients post with it: `POST /api/gps/locations.php` + `Authorization: Bearer <token>`, **identical to hardware** (same throttle, geofence, trip-attach, heartbeat, live map).
- Registration takes a **Device UID** (blank = auto-continue the hardware series, e.g. `GPS-NAAP-003`). Tracker devices **look like any other row** in Vehicles & GPS Devices — no badge and no login email are shown. **Edit** (on every device row) manages UID/name; for trackers it also manages the login email and password (blank = keep). Disabling/deleting the device also disables/deactivates the linked account.

### Freshness Status

| Status | Definition |
|---|---|
| CURRENT | Last fix < 45 seconds ago |
| DELAYED | Last fix 45–120 seconds ago |
| OFFLINE | Last fix > 120 seconds ago |

### Geofence

- Campus center + radius configured in `app_settings` (NAAP_GEOFENCE_LAT/LNG/RADIUS_METERS)
- Server computes inside/outside on every fix; logs transitions to `geofence_events`
- **Start Trip** / **Trip Complete** raise `GEOFENCE_DEVIATION` when the vehicle's latest fresh fix (≤ `GPS_DELAYED_SEC`) is outside the fence; actions are never blocked. Unpaired vehicles skip the check (`NO_TELEMETRY` applies).

---

## Automated Flags

The system raises flags for anomalous conditions. One open flag per (ticket, type) — deduped.

| Flag | Trigger | Raised By |
|---|---|---|
| `NO_TELEMETRY` | Dispatched without paired GPS device | Assignment (manual override) |
| `GEOFENCE_DEVIATION` | Trip started or completed outside the campus fence | Start Trip / Trip Complete actions |
| `OVERDUE` | Active trip exceeds scheduled_return + grace | Cron (every 5 min) |
| `MISSED_TRIP` | Assigned trip not started after departure + grace | Cron (every 5 min) |
| `GPS_SIGNAL_LOST` | Paired device heartbeat stale during active trip | Cron (every 5 min) |
| `IDLE_DETECTED` | All fixes in `IDLE_STILL_MINUTES` below `IDLE_STILL_KPH` during ON_GOING | Cron (every 5 min) |
| `NEEDS_REVIEW` | No status progress for N days | Cron (every 5 min) |

**Resolution:** Manual — Staff or MIS Admin investigates and resolves with a mandatory reason. Flags are never auto-resolved.

**DRAFT purge:** Tickets stuck in DRAFT for > 3 days are automatically deleted by the flag evaluator.

---

## Security

- **CSRF protection** — every POST verified via `X-CSRF-Token` header or `csrf_token` field
- **Session hardening** — httponly, SameSite=Strict, strict mode, periodic regeneration
- **RBAC** — page-level (`Auth::requirePage`) and API-level (`Auth::requireApi`) guards
- **Password policy** — bcrypt cost 12, lockout after N failed attempts, OTP reset (`core/PasswordPolicy.php`)
- **MFA** — optional TOTP with self-service enrollment, recovery codes and an MFA challenge at login (`core/MfaService.php`, `auth/mfa_setup.php`); `config/mfa_toggle.php` can force it
- **Rate limiting** — login, MFA, OTP, email-verification and account endpoints (`core/RateLimiter.php`)
- **Security events** — lockouts/step-up events recorded in `security_events`, with email alerts via `core/SecurityNotificationService.php`
- **Audit trail** — every mutation logged (`Audit::log` + DB triggers for status/vehicle changes)
- **SQL injection** — PDO prepared statements with emulation disabled
- **XSS** — `htmlspecialchars()` on all output
- **Security headers** — nosniff, SAMEORIGIN, Referrer-Policy via `.htaccess`

---

## Database Schema (26 Tables)

| Table | Purpose |
|---|---|
| `app_settings` | Runtime configuration key-value store |
| `roles` | 7 roles (6 user roles + hidden `GPS TRACKER`) |
| `account` | User accounts with passwords, MFA, lockout; `is_system = 1` marks hidden tracker accounts |
| `email_otps` | One-time codes for OTP login, email verification and password reset |
| `mfa_recovery_codes` | Single-use MFA recovery codes per account |
| `password_reset_sessions` | Password-reset tokens after OTP verification |
| `security_events` | Lockouts, MFA and other security events |
| `request_status` | 10 trip ticket statuses |
| `trip_tickets` | Core trip request + execution data |
| `trip_passengers` | Passenger manifest per trip |
| `trip_drafts` | Private, unsubmitted request drafts |
| `trip_draft_passengers` | Passenger manifest for drafts |
| `guard_verifications` | Guard inspection records |
| `trip_flags` | Automated/manual flags |
| `trip_routes` | Google Routes result per leg (distance, traffic ETA, polyline) |
| `trip_tracks` | Recorded (road-snapped) route per trip leg, written at completion |
| `vehicle_types` | Vehicle classification |
| `vehicle` | Fleet registry |
| `gps_devices` | GPS tracker registry with token hash; `account_id` links hidden tracker accounts |
| `gps_positions` | Raw GPS breadcrumb log |
| `geofence_events` | Campus entry/exit transitions |
| `audit_trail` | Full audit log |
| `chatbot` | FAQ entries for chatbot |
| `chat_conversations` | Chatbot transcript per question/answer |
| `notifications` | In-app notifications streamed by `api/sse.php` |
| `feedback` | Personnel feedback on trips |

---

## Setup

```bash
# 1. Import database
mysql -u root naap_mn_vts < database/schema.sql
mysql -u root naap_mn_vts < database/seed.sql
# Existing database? Apply the pending database/migrations/*.sql files in date order
# (skip *_rollback.sql), e.g.:
# mysql -u root naap_mn_vts < database/migrations/migration_security_fixes.sql

# 2. Install PHP dependencies (PHPMailer) + frontend dependencies
composer install
npm install

# 3. Build Tailwind CSS
npm run build

# 4. Optional: sanity checks
C:\xampp\php\php.exe tests\run.php
C:\xampp\php\php.exe scripts\deploy_check.php

# 5. Access the app
# http://localhost/NAAP_MN_VTS_Capstone/
# Default admin: admin@naap.gov.ph / Admin@12345
```

`.user.ini` (upload limits, OPcache) and `config/secrets.php` (DB/SMTP keys, copied from
`config/secrets.example.php`) are required for a full-featured install; see
[`INSTRUCTIONS/instructions.md`](INSTRUCTIONS/instructions.md) for the Hostinger walkthrough.

### Flag Evaluator Cron (Windows)

```cmd
schtasks /create /tn "NAAP VTS Flags" /sc minute /mo 5 /tr ^
  "\"C:\xampp\php\php.exe\" \"C:\xampp\htdocs\NAAP_MN_VTS_Capstone\scripts\evaluate_flags.php\""
```

### Hostinger deployment

See [`docs/DEPLOY_HOSTINGER.md`](docs/DEPLOY_HOSTINGER.md) for the full production guide
(files to upload/exclude, database import, `config/secrets.php`, SMTP, cron job, HTTPS/GPS, checklist).

---

## Missing / Incomplete Features

| Feature | Status | Notes |
|---|---|---|
| **SMTP email delivery** | Implemented (needs SMTP keys) | `core/MailService.php` (PHPMailer) sends OTP, password-reset and security-alert mail. Without `SMTP_USER`/`SMTP_PASS` in MIS Settings the codes fall back to inline display for testing. |
| **MFA self-service setup** | Implemented | Enrollment/disable at `account/mfa.php` → `auth/mfa_setup.php` with recovery codes (`core/MfaService.php`); `config/mfa_toggle.php` can force MFA for everyone. |
| **Driver keep-alive / heartbeat monitoring** | Not implemented | No mechanism to detect if a driver closes the browser tab during an active trip (GPS telemetry would continue from the device, but no app-level check). |
| **Push notifications** | Partial | In-app notifications stream live over SSE (`api/sse.php` + `assets/js/SSEClient.js`, `api/badge_counts.php` fallback). No browser/OS push, no service worker. |
| **Email notifications** | Not implemented (tickets) | Ticket lifecycle changes only create in-app notifications; email is used for OTP and security alerts (`core/SecurityNotificationService.php`). |
| **Mobile responsive design** | Partial | Tailwind-based UI is functional but not optimized for mobile devices. |
| **Batch operations** | Not implemented | No bulk approve/reject, bulk assignment, or bulk flag resolution. |
| **Advanced reporting** | Partial | Monthly bar chart + per-vehicle table + Excel export, plus query-time KPIs: average approval time, turnaround time, trip duration, completion rate, active-trip utilization hours, and km/L fuel efficiency. No custom date range reports or driver performance metrics. |
| **Route deviation detection** | Removed | The `ROUTE_DEVIATION` flag is gone; `GEOFENCE_DEVIATION` is now raised at Start Trip / Trip Complete when the vehicle is outside the fence (`GpsIngest::raiseGeofenceFlag`). Traffic-aware rerouting keeps `ROUTE_DEVIATION_METERS` as its off-route trigger. |
| **Traffic-aware trip routing** | Implemented | Server-side Google Routes API (`DRIVE` / `TRAFFIC_AWARE_OPTIMAL` / `BEST_GUESS`); outbound and return are separate requests stored per leg in `trip_routes`; ETA panel shows distance, traffic ETA, normal time, and traffic delay; auto-rerouted from live GPS by the cron + a manual Refresh button (`core/RouteService.php`). |
| **Idle-time detection** | Implemented | `IDLE_DETECTED` flag raised by the flag cron when all fixes in `IDLE_STILL_MINUTES` are below `IDLE_STILL_KPH` during ON_GOING (`core/FlagEvaluator.php`). |
| **GPS path distance** | Implemented | `gps_distance_km` now computed from trip breadcrumbs (haversine) at trip completion (`api/trips.php` complete_trip → `Geo::pathKm`). |
| **Chatbot conversation log** | Implemented | Every `ask` is appended to `chat_conversations` (account, question, keyword, score, answer, form state); form state is checked for missing/conflicting fields and surfaced as warnings in the panel. Escalated rows store the contact-GSD message in `answer` (`WHERE answer LIKE 'Please contact the GSD%'` counts them). |
| **Chatbot task assistance** | Implemented | Rule-based only (`core/ChatbotAssistant.php`): status + trip-details lookups scoped to the requester's own tickets, rendered as tappable cards with the app's status badges; grouped topic browser, page-aware chips/actions, clickable in-app actions, contextual follow-ups, client-side auto-suggest, field-level "fix" buttons on form warnings, muted fallback bubble and a contact card on 3 consecutive misses. **Guided request wizard**: a 7-step card flow that collects the full request (incl. Places-geocoded destination, passenger manifest and letter upload) and drives the existing `save_draft` → `upload_request_letter` → `submit` APIs — no new endpoints, wizard field values never touch the chat log. Stored FAQs keep their old matching and text; `chat_conversations` still logs plain text. |
| **Vehicle maintenance tracking** | Not implemented | No service history, maintenance schedules, or vehicle health monitoring. |
| **Fuel price tracking** | Not implemented | Fuel cost is entered per-trip but no centralized fuel price database or cost analysis. |
| **Multi-language support** | Not implemented | English only. |
| **API rate limiting** | Partial | `core/RateLimiter.php` guards login, MFA, OTP, email-verification and account endpoints; GPS ingestion has interval throttling. Other API endpoints are unthrottled. |
| **Data export beyond Excel** | Not implemented | Only XLSX export. No CSV, PDF, or print-optimized views. |
| **Ticket attachment management** | Limited | Only request letter upload (PDF/image/Word). No support for additional attachments like receipts, photos, or documents during the trip. |
| **Passenger contact info** | Not implemented | Passenger manifest stores names only — no phone numbers or emergency contacts. |
| **Trip notes/comments** | Not implemented | No inline commenting or note-taking on tickets between roles. |
| **Dashboard widgets** | Basic | Role dashboards exist but have limited widget variety. No customizable dashboard layout. |
| **Automated DRAFT purge config** | Hardcoded | DRAFT purge at 3 days is hardcoded in `FlagEvaluator`. Not configurable via `app_settings`. |
| **Vehicle availability calendar** | Implemented | Month calendar partial (`partials/calendar.php`, `api/calendar.php`) embedded in the staff, director, guard, driver and personnel dashboards; shows dispatched/ongoing trips per role scope. Vehicles/drivers are chosen by conflict check, not by clicking a calendar slot. |
| **Integration with HR systems** | Not implemented | Standalone — no integration with NAAP HR or payroll systems. |
