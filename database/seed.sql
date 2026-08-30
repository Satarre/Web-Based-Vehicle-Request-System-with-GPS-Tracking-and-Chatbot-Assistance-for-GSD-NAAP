-- ============================================================================
-- NAAP GSD Digital Trip Ticket System — seed data v1 (Phase 0)
-- ============================================================================

SET NAMES utf8mb4;

-- Roles ----------------------------------------------------------------------
INSERT INTO `roles` (`role_id`, `role_name`, `is_active`) VALUES
(1, 'MIS ADMIN', 1),
(2, 'HR ADMIN', 1),
(3, 'GSD STAFF', 1),
(4, 'DRIVER', 1),
(5, 'GUARD', 1),
(6, 'PERSONNEL', 1);

-- Statuses (locked state machine) --------------------------------------------
INSERT INTO `request_status` (`status_id`, `status_name`, `is_active`) VALUES
(1,  'DRAFT',         1),
(2,  'PENDING',       1),
(3,  'VERIFIED',      1),
(4,  'APPROVED',      1),
(5,  'DISPATCHED',    1),
(6,  'ON_GOING',      1),
(7,  'COMPLETED',     1),
(8,  'CANCELLED',     1),
(9,  'REJECTED',      1),
(10, 'RESCHEDULED',   1);

-- Vehicle types ---------------------------------------------------------------
INSERT INTO `vehicle_types` (`type_id`, `type_name`, `is_active`) VALUES
(1, 'Van', 1), (2, 'Minibus', 1), (3, 'Bus', 1), (4, 'Coaster', 1),
(5, 'Multicab', 1), (6, 'SUV', 1), (7, 'Sedan', 1), (8, 'Pickup', 1);

-- Initial MIS ADMIN account ---------------------------------------------------
-- email: admin@naap.gov.ph   password: Admin@12345   (CHANGE BEFORE DEPLOY)
INSERT INTO `account`
(`role_id`, `email`, `password`, `full_name`, `created_by`, `is_active`)
VALUES
(1, 'admin@naap.gov.ph',
 '$2y$12$yf49/qxPJ8lc07h/fD7JL.Zp.g4MNA0C5rVlqYT9D/oPx7uugncF2',
 'ADMIN, SYSTEM', NULL, 1);

-- App settings ----------------------------------------------------------------
INSERT INTO `app_settings` (`setting_key`,`setting_value`,`value_type`,`description`) VALUES
('GPS_INGESTION_URL',       '/NAAP_MN_VTS_Capstone/api/gps/locations.php','string','URL GPS devices POST fixes to'),
('GPS_ALLOW_INSECURE_HTTP', 'true',  'bool',  'Capstone LAN only; MUST be false in production'),
('GPS_MIN_INTERVAL_SEC',    '20',    'int',   'Throttle: min seconds between stored fixes per device'),
('GPS_FRESH_SEC',           '45',    'int',   'Telemetry age < this = CURRENT'),
('GPS_DELAYED_SEC',         '120',   'int',   'Age between FRESH and this = DELAYED; beyond = OFFLINE'),
('NAAP_GEOFENCE_LAT',       '14.51260000','float','Campus center lat (calibrate later)'),
('NAAP_GEOFENCE_LNG',       '121.01820000','float','Campus center lng (calibrate later)'),
('NAAP_GEOFENCE_RADIUS_METERS','500','int',  'Campus geofence radius (calibrate later)'),
('GEOFENCE_GRACE_MIN',      '15',    'int',   'Minutes outside before GEOFENCE_DEVIATION flag'),
('OVERDUE_GRACE_MIN',       '30',    'int',   'Minutes past scheduled_return before OVERDUE flag'),
('MISSED_TRIP_GRACE_HOURS', '2',     'int',   'Hours past scheduled_departure w/o start = MISSED_TRIP'),
('STALE_REVIEW_DAYS',       '3',     'int',   'Days unresolved trip auto-flagged NEEDS_REVIEW'),
('RETENTION_RAW_MONTHS',    '',      'string','Empty = keep raw breadcrumbs forever (no pruning)'),
('LOGIN_MAX_ATTEMPTS',      '3',     'int',   'Failed logins before lockout'),
('LOGIN_LOCKOUT_MINS',      '1',     'int',   'Lockout duration in minutes'),
('OTP_TTL_MIN',             '10',    'int',   'Password-reset OTP validity'),
('UPLOAD_MAX_BYTES',        '10485760','int', 'Max attachment size (10 MB)'),
('IDLE_TIMEOUT_SEC',        '300',   'int',   'Idle seconds before logout warning overlay'),
('SESSION_REGEN_INTERVAL',  '900',   'int',   'Session ID regeneration interval (seconds)'),
('SMTP_USER',               '',      'string','Gmail address for outgoing OTP mail'),
('SMTP_PASS',               '',      'string','Gmail app password (16 chars)'),
('SMTP_FROM_NAME',          'NAAP GSD System','string','Display name on outbound mail');

-- Chatbot FAQs (carried over) ---------------------------------------------------
INSERT INTO `chatbot` (`prompt_keyword`,`response_text`,`managed_by`,`is_active`,`is_displayed`) VALUES
('request trip', 'To request a vehicle, go to "My Requests" and click "New Request". Fill out the travel details and submit it for HR verification.', 1, 1, 1),
('ticket status', 'You can track your ticket under "My Requests". Flow: Draft → Pending → Verified → Approved → Dispatched → On-going → Completed.', 1, 1, 1),
('cancel ticket', 'Open the ticket in "My Requests" and click Cancel. Cancellation is allowed before the trip is dispatched, with a reason.', 1, 1, 1),
('driver info', 'Once GSD Staff approves and assigns a driver and vehicle, the DTT number, driver name and plate number appear on the ticket details page.', 1, 1, 0),
('trip purpose', 'Trip tickets are required for all official use of government vehicles to ensure proper log tracking and fuel monitoring. Provide a clear, specific purpose of travel.', 1, 1, 0);
