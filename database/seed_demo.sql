-- ============================================================================
-- NAAP GSD Digital Trip Ticket System — DEMO seed (dev only, NOT in core seed)
-- Import AFTER schema.sql + seed.sql:
--   mysql -u root naap_mn_vts < database/seed_demo.sql
-- Demo password for every account below: Pass@12345  (dev only)
-- ============================================================================

SET NAMES utf8mb4;

-- Demo accounts ---------------------------------------------------------------
INSERT INTO `account` (`role_id`,`email`,`password`,`full_name`,`created_by`,`is_active`) VALUES
(2, 'director@naap.gov.ph', '$2y$12$3gZw1BOnVt.l0VenpD7NnuvIkFfTAn38XtID9HekXgZCxxx7texF2', 'REYES, MARIA ELENA', 1, 1),
(3, 'staff@naap.gov.ph',    '$2y$12$3gZw1BOnVt.l0VenpD7NnuvIkFfTAn38XtID9HekXgZCxxx7texF2', 'SANTOS, PEDRO JR',   1, 1),
(4, 'driver1@naap.gov.ph',  '$2y$12$3gZw1BOnVt.l0VenpD7NnuvIkFfTAn38XtID9HekXgZCxxx7texF2', 'GARCIA, JOSE',       1, 1),
(4, 'driver2@naap.gov.ph',  '$2y$12$3gZw1BOnVt.l0VenpD7NnuvIkFfTAn38XtID9HekXgZCxxx7texF2', 'RAMOS, ANA LISA',    1, 1),
(5, 'guard@naap.gov.ph',    '$2y$12$3gZw1BOnVt.l0VenpD7NnuvIkFfTAn38XtID9HekXgZCxxx7texF2', 'AQUINO, LITO',       1, 1),
(6, 'personnel@naap.gov.ph','$2y$12$3gZw1BOnVt.l0VenpD7NnuvIkFfTAn38XtID9HekXgZCxxx7texF2', 'LIM, CARLO',         1, 1);

-- Fleet -----------------------------------------------------------------------
INSERT INTO `vehicle` (`plate_number`,`model_name`,`brand`,`type_id`,`capacity`,`default_fuel_type`,`is_available`,`is_active`,`managed_by`) VALUES
('NAA-8001', 'Hiace Commuter',   'Toyota',     1, 15, 'Diesel',   1, 1, 1),
('NAA-8002', 'Staria Premium',   'Hyundai',    1, 11, 'Diesel',   1, 1, 1),
('NAA-8003', 'Montero Sport',    'Mitsubishi', 6,  7, 'Diesel',   1, 1, 1),
('NAA-8004', 'Vios XLE',         'Toyota',     7,  5, 'Gasoline', 1, 1, 1);

-- GPS devices (paired: 001→NAA-8001, 002→NAA-8003; NAA-8002/8004 intentionally unpaired
-- so the dispatch-without-GPS override flow is testable). Bearer tokens are dev-only:
--   GPS-NAAP-001 → dev-token-001   GPS-NAAP-002 → dev-token-002
INSERT INTO `gps_devices` (`device_uid`,`vehicle_id`,`device_name`,`manufacturer`,`model`,`protocol`,`api_token_hash`,`is_active`,`installed_at`,`registered_by`) VALUES
('GPS-NAAP-001', 1, 'Hiace Tracker',   'Concox',  'GT06N', 'http-json', SHA2('dev-token-001', 256), 1, NOW(), 1),
('GPS-NAAP-002', 3, 'Montero Tracker', 'Teltonika','FMB920','http-json', SHA2('dev-token-002', 256), 1, NOW(), 1);
