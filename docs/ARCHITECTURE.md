# NAAP GSD Digital Trip Ticket System — Architecture

**Stack:** Vanilla PHP 8.2 (no framework, no Composer at runtime) · MariaDB 10.4 · XAMPP (Windows)
**Pattern:** Server-rendered MPA + progressive JS. Pages render HTML; all mutations go through centralized `/api/*` JSON gateways.
**Root:** `C:\xampp\htdocs\NAAP_MN_VTS_Capstone\` · **URL:** `http://localhost/NAAP_MN_VTS_Capstone/`

## Layers

```
public pages (role folders + root ticket.php/tracking.php/flags.php)
        │ render via partials shell (layout_head → shell_open → sidebar/topbar → layout_foot)
        │ read data server-side (PDO prepared statements)
        │ mutate via fetch() → assets/js/api.js (auto CSRF header)
        ▼
api/trips.php   lifecycle actions (all roles; per-action RBAC)
api/admin.php   MIS mutations (role 1 only)
api/gps/*       device ingest (Bearer) + session snapshot — web-accessible BY DESIGN
        │
        ▼
core/ kernel: Settings, Audit, Csrf, Auth, TripState, TicketNumber,
              ConflictChecker, TOTP, Gps (adapter+Geo+GpsIngest), FlagEvaluator
config/config.php  bootstrap: strict_types, session hardening, PDO, autoloader
database/          schema.sql (17 tables, 3 audit triggers), seed.sql, seed_demo.sql
scripts/           evaluate_flags.php (cron; web-denied by .htaccess)
tests/run.php      plain-assert acceptance suite
```

## State machine

10 statuses (`request_status`). Single authority: `TripState::can($from,$to,$ctx)` — never inline status checks.
Flow: DRAFT → PENDING → VERIFIED → APPROVED → DISPATCHED (DTT issued, GPS pairing gate) → ON_GOING → COMPLETED. Side exits: PENDING/APPROVED → REJECTED/CANCELLED (reason mandatory). ON_GOING → RESCHEDULED (by GSD Staff).
Actors: HR ADMIN verifies (only), GSD Staff approves, Staff assigns, Driver executes (start_trip → ON_GOING, GPS breadcrumbs), Guard verifies *events* (no status change) / starts trip, requester certifies completion. DRAFT auto-deletes after 3 days (FlagEvaluator).

## Numbering & conflicts

`TicketNumber::next()` — `DTT-YYYY-####`, MAX(seq)+1 inside tx, unique-key retry ×3.
`ConflictChecker::vehicle()/driver()` — overlap test `dep < ret AND dep' < ret'` over BUSY statuses [5]. Reschedule that creates a conflict clears the assignment back to DISPATCHED (locked decision).

## Security model

- **CSRF:** every session-authenticated POST verifies `X-CSRF-Token` header or `csrf_token` field via `Csrf::verifyRequest`.
- **Sessions:** httponly cookie, SameSite=Strict, use_strict_mode, regen on login + periodically; role landings via `Auth::LANDING`; idle auto-logout overlay (`IDLE_TIMEOUT_SEC`).
- **RBAC:** `Auth::requirePage($pdo,[roles])` on every page, `Auth::requireApi()` in every API action. Personnel see own tickets only; drivers their assignments only (enforced again in ticket.php).
- **Passwords:** bcrypt cost 12; lockout via settings (`LOGIN_MAX_ATTEMPTS`, `LOGIN_LOCKOUT_MINS`); reset via OTP (`password_reset_otp`, dev OTP shown inline when SMTP unconfigured).
- **MFA:** optional TOTP per account (`core/TOTP.php`); pending state withholds real auth until verified.
- **Audit:** `Audit::log()` on every write (never throws); DB triggers additionally log status/vehicle changes and deletes on trip_tickets.
- **Headers:** `.htaccess` sets nosniff / SAMEORIGIN / Referrer-Policy; -Indexes; .sql/.md/.json denied; deny-all .htaccess in config/, core/, gps/, database/, scripts/, partials/. **Exception: api/gps/ stays web-accessible** (device-facing, auth inside PHP).
- **SQLi/XSS:** PDO prepared statements everywhere (emulation off); output escaped with `htmlspecialchars`.

## GPS subsystem

Pipeline: device → `POST /api/gps/locations.php` (Bearer) → `HttpJsonProvider::normalize` (alias mapping, range checks, future-clamp) → SHA256 token lookup (`gps_devices.api_token_hash`) → paired vehicle required → forward-time throttle ≥ `GPS_MIN_INTERVAL_SEC` (backdated replays always persist) → active-trip attach (status 6 ON_GOING) → breadcrumb into `gps_positions` DATETIME(3) → transition-only `geofence_events` vs previous row's fence flag (+ `GEOFENCE_DEVIATION` flag when an attached trip leaves campus) → heartbeat update even when throttled → HTTP 204 empty.

Freshness buckets from settings: <45 s CURRENT, 45–120 s DELAYED, >120 s OFFLINE. Geofence = center+radius in app_settings (transition-only events). Live map = Leaflet + OSM (local vendor), polling every 10 s via `TrackingClient.js` (SSE-swappable abstraction).

Dispatch requires a paired active device; override stores mandatory reason → audits `DISPATCH_WITHOUT_GPS_OVERRIDE` + raises `NO_TELEMETRY`. UNPAIRED ≠ SIGNAL_LOST ≠ OFFLINE (distinct meanings).

## Flags

| Flag | Raised by |
|---|---|
| NO_TELEMETRY | dispatch-without-GPS override |
| GEOFENCE_DEVIATION | ingest pipeline (fence exit during active trip) |
| OVERDUE | cron: active trip past scheduled_return + grace |
| MISSED_TRIP | cron: assigned/ready not started after departure + grace |
| GPS_SIGNAL_LOST | cron: paired device heartbeat stale during active trip |
| NEEDS_REVIEW | cron: no progress for STALE_REVIEW_DAYS |

One open flag per (ticket, type) — deduped by `NOT EXISTS resolved_at IS NULL`; resolution is manual (Staff/MIS on flags.php) with mandatory reason, audited `FLAG_RESOLVE`. Stale trips are flagged, **never auto-completed**.

### Scheduler setup (Windows Task Scheduler)

```
schtasks /create /tn "NAAP VTS Flags" /sc minute /mo 5 /tr ^
  "\"C:\xampp\php\php.exe\" \"C:\xampp\htdocs\NAAP_MN_VTS_Capstone\scripts\evaluate_flags.php\""
```
The script is CLI-only (403 over HTTP), prints per-type raise counts, exit 0. Safe to run concurrently with the app (row-level dedupe inside its own statements). Manual run: `C:\xampp\php\php.exe scripts\evaluate_flags.php`.

## Settings (app_settings)

All runtime knobs are DB rows edited at mis/settings.php: GPS_MIN_INTERVAL_SEC(20), GPS_FRESH_SEC(45), GPS_DELAYED_SEC(120), NAAP_GEOFENCE_LAT/LNG/RADIUS_METERS, OVERDUE_GRACE_MIN(30), MISSED_TRIP_GRACE_HOURS(2), STALE_REVIEW_DAYS(3), RETENTION_RAW_MONTHS(empty=keep forever), IDLE_TIMEOUT_SEC, LOGIN_*, OTP_TTL_MIN, SMTP_*.

## Testing & verification

`C:\xampp\php\php.exe tests\run.php` — plain asserts across Phases 0–5 (bootstrap/settings/TripState/CSRF/auth, request completeness + TicketNumber + conflicts, execution chain, fleet admin, GPS ingest pipeline incl. geofence/throttle/deviation-flag, flag evaluator idempotency + re-raise). Transactions roll back — safe against live data.
Lint each file after edits: `C:\xampp\php\php.exe -l <file>`.

## Device API

See [DEVICE_API.md](DEVICE_API.md) for the ESP32/GPS-vendor contract.
