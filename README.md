# NAAP GSD Digital Trip Ticket System

Official vehicle request and tracking system for the **National Aviation Academy of the Philippines — General Services Division (GSD)**. Replaces manual paper-based trip tickets with a fully digital workflow: request submission, multi-level approval, GPS-tracked dispatch, post-trip reporting, and audit logging.

**Stack:** PHP 8.2 · MariaDB 10.4 · Tailwind CSS v4 · Leaflet/OSM · XAMPP (Windows)

---

## Architecture

```
┌─────────────────────────────────────────────────────────┐
│                   Browser (MPA)                         │
│  role folders (personnel/, staff/, driver/, guard/,     │
│  director/, mis/) + shared (ticket.php, tracking.php,  │
│  flags.php)                                             │
│  └─ partials shell (layout_head → sidebar → topbar)     │
│  └─ mutations via fetch() → assets/js/api.js (CSRF)    │
└──────────────────────┬──────────────────────────────────┘
                       │ POST (JSON/form)
┌──────────────────────▼──────────────────────────────────┐
│  api/trips.php    lifecycle actions (all roles)         │
│  api/admin.php    MIS mutations (role 1 only)           │
│  api/account.php  self-service profile/password         │
│  api/gps/*        device ingestion (Bearer auth)        │
└──────────────────────┬──────────────────────────────────┘
                       │
┌──────────────────────▼──────────────────────────────────┐
│  core/ kernel                                           │
│  Auth (RBAC + session)  TripState (state machine)       │
│  ConflictChecker        TicketNumber (DTT-YYYY-####)    │
│  FlagEvaluator          Gps (ingest + geofence)         │
│  Audit  Csrf  Settings  TOTP                           │
└──────────────────────┬──────────────────────────────────┘
                       │
┌──────────────────────▼──────────────────────────────────┐
│  MariaDB  naap_mn_vts  (17 tables, 3 audit triggers)   │
│  database/schema.sql · seed.sql                         │
└─────────────────────────────────────────────────────────┘

External:
  GPS devices → POST /api/gps/locations.php (Bearer)
  Cron (every 5 min) → scripts/evaluate_flags.php (CLI)
```

- **No framework** — vanilla PHP with `core/` kernel classes and PDO prepared statements.
- **MPA + progressive JS** — pages render server-side; all mutations go through centralized JSON API gateways.
- **Autoloader** — `spl_autoload_register` loads `core/` and `gps/` classes on demand.

### Key Design Decisions

| Decision | Rationale |
|---|---|
| Single `TripState` authority | Prevents illegal status transitions; `can()` is the only gate |
| `ConflictChecker` overlap test | Prevents double-booking vehicles/drivers for overlapping windows |
| `DTT-YYYY-####` numbering | Per-year sequence with unique-key retry; no counter table needed |
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
| Live Tracking | Real-time Leaflet map with fleet positions and geofence overlay |
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

### 2. HR ADMIN / Director (Role 2)

**Landing page:** `director/dashboard.php`
**Example account:** `director@naap.gov.ph`

| Page | Purpose |
|---|---|
| HR Dashboard | Overview of pending requests and trip statistics |
| Verify Requests | Queue of PENDING tickets — verify or reject with reason |
| Trip Tickets | View all trip tickets |
| Reports | Monthly charts, fleet utilization, trip log, Excel export |
| Vehicles | View vehicle fleet (read-only) |
| Feedback | View submitted feedback |
| Chatbot | View/manage FAQ entries |
| Live Tracking | Real-time vehicle map |

**Capabilities:**
- **Verifies** trip requests (PENDING → VERIFIED) — the only role that can do this
- **Rejects** requests with mandatory reason
- Can cancel tickets at PENDING stage
- Can view reports and live tracking
- Cannot assign vehicles/drivers or approve tickets

---

### 3. GSD Staff (Role 3)

**Landing page:** `staff/dashboard.php`
**Example account:** `staff@naap.gov.ph`

| Page | Purpose |
|---|---|
| Staff Dashboard | Overview of assignments and ticket flow |
| Approvals | Queue of VERIFIED tickets — approve or reject |
| Assignments | Assign driver + vehicle, issue DTT number, GPS pairing gate |
| Trip Tickets | View all tickets |
| Flags | View and resolve open trip flags |
| Live Tracking | Real-time vehicle map |
| Reports | Monthly charts, fleet utilization, trip log |

**Capabilities:**
- **Approves** verified tickets (VERIFIED → APPROVED)
- **Assigns** driver and vehicle (APPROVED → DISPATCHED), issues DTT number
- Checks schedule conflicts before assignment
- Dispatch-without-GPS override (raises NO_TELEMETRY flag)
- **Resolves** trip flags with mandatory reason
- Can reschedule tickets (ON_GOING → RESCHEDULED)
- Can reject tickets at VERIFIED or APPROVED stage
- Can cancel tickets at PENDING, APPROVED, or DISPATCHED stage

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
- Records sub-events while ON_GOING: arrival at destination, departure from destination, return to base
- **Submits post-trip report** — odometer end, fuel consumption, lubricants, toll costs, remarks
- **Completes trip** (ON_GOING → COMPLETED) — certified by driver
- Sees only their own assigned trips
- GPS breadcrumbs are automatically attached if vehicle has a paired device
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
- **Cancels** own tickets (with reason) at PENDING, APPROVED, or DISPATCHED stage
- **Certifies** completed trips (ON_GOING → COMPLETED) after driver's post-trip report
- **Reschedules** own tickets from ON_GOING status
- Uploads/removes request letter attachments (PDF, image, Word)
- Submits feedback for completed trips
- Sees only their own requests

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
| 2 | Personnel | Submit request | DRAFT → PENDING | Requires complete fields + request letter |
| 3 | HR Admin | Verify request | PENDING → VERIFIED | Confirms validity of the request |
| 4 | GSD Staff | Approve request | VERIFIED → APPROVED | Final approval before dispatch |
| 5 | GSD Staff | Assign vehicle + driver | APPROVED → DISPATCHED | System issues DTT-YYYY-#### number. Checks vehicle/driver schedule conflicts. GPS pairing gate. |
| 6 | Driver (or Guard) | Start trip | DISPATCHED → ON_GOING | Enters odometer start. GPS breadcrumbs begin. System notes lateness. |
| 7 | Driver | Record arrival | (sub-event) | GPS fix captured at destination |
| 8 | Driver | Record departure from dest | (sub-event) | GPS fix captured |
| 9 | Driver | Record return to base | (sub-event) | GPS fix captured |
| 10 | Driver | Submit post-trip report | (data update) | Odometer end, fuel, lubricants, tolls, remarks |
| 11 | Personnel | Certify & complete | ON_GOING → COMPLETED | Requester confirms trip is done |
| — | Guard | Pre-departure verify | (event only) | Checklist: driver, vehicle, plate, passengers |
| — | Guard | Return verify | (event only) | Checklist on return |

### Cancellation & Rejection

- **Cancellation**: Available from DRAFT, PENDING, APPROVED, or DISPATCHED. Requires a reason. Can be done by the ticket owner or the authorizing role at that stage.
- **Rejection**: Available from PENDING (by HR Admin) or VERIFIED/APPROVED (by GSD Staff). Requires a written reason. Terminal — ticket cannot proceed.

### Rescheduling

- Available from PENDING, APPROVED, DISPATCHED, or ON_GOING.
- Can be done by GSD Staff or the original requester (for own tickets).
- If the new schedule creates a vehicle/driver conflict, the assignment is cleared and the ticket returns to DISPATCHED for re-assignment.

### Guard Verification

- **Pre-departure**: Allowed while DISPATCHED or ON_GOING. Guard inspects driver, vehicle, plate, and passengers.
- **Return**: Allowed while ON_GOING or COMPLETED. Guard verifies return condition.
- Verifications are **events only** — they record observations but never change the ticket status.
- Result can be VALIDATED or FLAGGED (with optional notes).

---

## GPS Tracking System

### How It Works

1. **GPS devices** (ESP32 or vendor trackers) POST fixes to `/api/gps/locations.php` with a Bearer token
2. Server validates token (SHA-256 lookup), normalizes payload, throttles rapid fixes
3. Fixes are stored in `gps_positions` with `DATETIME(3)` precision
4. Active trips (ON_GOING) automatically attach GPS breadcrumbs
5. Geofence transitions (campus entry/exit) are detected and logged
6. Live map (`tracking.php`) polls every 10 seconds via Leaflet + OpenStreetMap

### Freshness Status

| Status | Definition |
|---|---|
| CURRENT | Last fix < 45 seconds ago |
| DELAYED | Last fix 45–120 seconds ago |
| OFFLINE | Last fix > 120 seconds ago |

### Geofence

- Campus center + radius configured in `app_settings` (NAAP_GEOFENCE_LAT/LNG/RADIUS_METERS)
- Server computes inside/outside on every fix; logs transitions to `geofence_events`
- Vehicle leaving campus during active trip raises `GEOFENCE_DEVIATION` flag

---

## Automated Flags

The system raises flags for anomalous conditions. One open flag per (ticket, type) — deduped.

| Flag | Trigger | Raised By |
|---|---|---|
| `NO_TELEMETRY` | Dispatched without paired GPS device | Assignment (manual override) |
| `GEOFENCE_DEVIATION` | Vehicle leaves campus during active trip | GPS ingest pipeline |
| `OVERDUE` | Active trip exceeds scheduled_return + grace | Cron (every 5 min) |
| `MISSED_TRIP` | Assigned trip not started after departure + grace | Cron (every 5 min) |
| `GPS_SIGNAL_LOST` | Paired device heartbeat stale during active trip | Cron (every 5 min) |
| `NEEDS_REVIEW` | No status progress for N days | Cron (every 5 min) |

**Resolution:** Manual — Staff or MIS Admin investigates and resolves with a mandatory reason. Flags are never auto-resolved.

**DRAFT purge:** Tickets stuck in DRAFT for > 3 days are automatically deleted by the flag evaluator.

---

## Security

- **CSRF protection** — every POST verified via `X-CSRF-Token` header or `csrf_token` field
- **Session hardening** — httponly, SameSite=Strict, strict mode, periodic regeneration
- **RBAC** — page-level (`Auth::requirePage`) and API-level (`Auth::requireApi`) guards
- **Password policy** — bcrypt cost 12, lockout after N failed attempts, OTP reset
- **MFA** — optional TOTP per account (setup via `core/TOTP.php`)
- **Audit trail** — every mutation logged (`Audit::log` + DB triggers for status/vehicle changes)
- **SQL injection** — PDO prepared statements with emulation disabled
- **XSS** — `htmlspecialchars()` on all output
- **Security headers** — nosniff, SAMEORIGIN, Referrer-Policy via `.htaccess`

---

## Database Schema (17 Tables)

| Table | Purpose |
|---|---|
| `app_settings` | Runtime configuration key-value store |
| `roles` | 6 user roles |
| `account` | User accounts with passwords, MFA, lockout |
| `password_reset_otp` | OTP tokens for password reset |
| `request_status` | 10 trip ticket statuses |
| `trip_tickets` | Core trip request + execution data |
| `trip_passengers` | Passenger manifest per trip |
| `guard_verifications` | Guard inspection records |
| `trip_flags` | Automated/manual flags |
| `vehicle_types` | Vehicle classification |
| `vehicle` | Fleet registry |
| `gps_devices` | GPS tracker registry with token hash |
| `gps_positions` | Raw GPS breadcrumb log |
| `geofence_events` | Campus entry/exit transitions |
| `audit_trail` | Full audit log |
| `chatbot` | FAQ entries for chatbot |
| `feedback` | Personnel feedback on trips |

---

## Setup

```bash
# 1. Import database
mysql -u root naap_mn_vts < database/schema.sql
mysql -u root naap_mn_vts < database/seed.sql

# 2. Install frontend dependencies
npm install

# 3. Build Tailwind CSS
npm run build

# 4. Access the app
# http://localhost/NAAP_MN_VTS_Capstone/
# Default admin: admin@naap.gov.ph / Admin@12345
```

### Flag Evaluator Cron (Windows)

```cmd
schtasks /create /tn "NAAP VTS Flags" /sc minute /mo 5 /tr ^
  "\"C:\xampp\php\php.exe\" \"C:\xampp\htdocs\NAAP_MN_VTS_Capstone\scripts\evaluate_flags.php\""
```

---

## Missing / Incomplete Features

| Feature | Status | Notes |
|---|---|---|
| **SMTP email delivery** | Not configured | `SMTP_USER`/`SMTP_PASS` empty in settings. OTP is shown inline for testing. Password reset emails are not sent. |
| **MFA self-service setup** | Partial | TOTP infrastructure exists (`core/TOTP.php`, `account/mfa.php`), but no self-service enrollment flow exposed to all roles. |
| **Driver keep-alive / heartbeat monitoring** | Not implemented | No mechanism to detect if a driver closes the browser tab during an active trip (GPS telemetry would continue from the device, but no app-level check). |
| **Push notifications** | Not implemented | No real-time notifications for status changes, flag raises, or assignment. All polling/page-refresh based. |
| **Email notifications** | Not implemented | No email on ticket submission, approval, rejection, assignment, or completion. |
| **Mobile responsive design** | Partial | Tailwind-based UI is functional but not optimized for mobile devices. |
| **Batch operations** | Not implemented | No bulk approve/reject, bulk assignment, or bulk flag resolution. |
| **Advanced reporting** | Basic | Monthly bar chart + fleet utilization table + Excel export. No custom date range reports, no fuel cost analytics, no driver performance metrics. |
| **Vehicle maintenance tracking** | Not implemented | No service history, maintenance schedules, or vehicle health monitoring. |
| **Fuel price tracking** | Not implemented | Fuel cost is entered per-trip but no centralized fuel price database or cost analysis. |
| **Multi-language support** | Not implemented | English only. |
| **API rate limiting** | Not implemented | GPS ingestion has interval-based throttling, but general API endpoints have no rate limiting. |
| **Data export beyond Excel** | Not implemented | Only XLSX export. No CSV, PDF, or print-optimized views. |
| **Ticket attachment management** | Limited | Only request letter upload (PDF/image/Word). No support for additional attachments like receipts, photos, or documents during the trip. |
| **Passenger contact info** | Not implemented | Passenger manifest stores names only — no phone numbers or emergency contacts. |
| **Trip notes/comments** | Not implemented | No inline commenting or note-taking on tickets between roles. |
| **Dashboard widgets** | Basic | Role dashboards exist but have limited widget variety. No customizable dashboard layout. |
| **Automated DRAFT purge config** | Hardcoded | DRAFT purge at 3 days is hardcoded in `FlagEvaluator`. Not configurable via `app_settings`. |
| **Vehicle availability calendar** | Not implemented | Conflict checker prevents double-booking, but no visual calendar view of vehicle/driver availability. |
| **Integration with HR systems** | Not implemented | Standalone — no integration with NAAP HR or payroll systems. |
