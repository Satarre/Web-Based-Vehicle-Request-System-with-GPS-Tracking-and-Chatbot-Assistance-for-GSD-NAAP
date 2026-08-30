-- ============================================================================
-- NAAP GSD Digital Trip Ticket System — schema v1 (Phase 0)
-- Database : naap_mn_vts   Engine: InnoDB   Charset: utf8mb4 / utf8mb4_general_ci
-- Import:  mysql -u root naap_mn_vts < database/schema.sql
--           mysql -u root naap_mn_vts < database/seed.sql
-- ============================================================================

SET NAMES utf8mb4;
SET time_zone = '+08:00';
SET FOREIGN_KEY_CHECKS = 0;

-- ---------------------------------------------------------------- app_settings
CREATE TABLE `app_settings` (
  `setting_key`   VARCHAR(64)  NOT NULL,
  `setting_value` VARCHAR(255) NOT NULL DEFAULT '',
  `value_type`    ENUM('string','int','bool','float') NOT NULL DEFAULT 'string',
  `description`   VARCHAR(255) DEFAULT NULL,
  `updated_by`    INT(11)      DEFAULT NULL,
  `updated_at`    TIMESTAMP    NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ---------------------------------------------------------------------- roles
CREATE TABLE `roles` (
  `role_id`   INT(11) NOT NULL AUTO_INCREMENT,
  `role_name` VARCHAR(50) NOT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`role_id`),
  UNIQUE KEY `role_name` (`role_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- -------------------------------------------------------------------- account
CREATE TABLE `account` (
  `account_id`     INT(11) NOT NULL AUTO_INCREMENT,
  `role_id`        INT(11) DEFAULT NULL,
  `email`          VARCHAR(100) NOT NULL,
  `password`       VARCHAR(255) NOT NULL,
  `full_name`      VARCHAR(100) NOT NULL,
  `created_at`     TIMESTAMP NOT NULL DEFAULT current_timestamp(),
  `updated_at`     TIMESTAMP NULL DEFAULT NULL ON UPDATE current_timestamp(),
  `created_by`     INT(11) DEFAULT NULL,
  `is_active`      TINYINT(1) NOT NULL DEFAULT 1,
  `login_attempts` TINYINT(3) NOT NULL DEFAULT 0,
  `locked_until`   DATETIME DEFAULT NULL,
  `mfa_secret`     VARCHAR(64) DEFAULT NULL COMMENT 'Base32 TOTP secret, NULL = not set up',
  `mfa_enabled`    TINYINT(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`account_id`),
  UNIQUE KEY `email` (`email`),
  KEY `fk_account_role` (`role_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------- password_reset_otp
CREATE TABLE `password_reset_otp` (
  `otp_id`     BIGINT(20) NOT NULL AUTO_INCREMENT,
  `account_id` INT(11) NOT NULL,
  `otp_code`   VARCHAR(6) NOT NULL,
  `expires_at` DATETIME NOT NULL,
  `used`       TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`otp_id`),
  KEY `account_id` (`account_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- -------------------------------------------------------------- request_status
CREATE TABLE `request_status` (
  `status_id`   INT(11) NOT NULL AUTO_INCREMENT,
  `status_name` VARCHAR(50) NOT NULL,
  `is_active`   TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`status_id`),
  UNIQUE KEY `status_name` (`status_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------------- trip_tickets
CREATE TABLE `trip_tickets` (
  `ticket_id`             INT(11) NOT NULL AUTO_INCREMENT,
  `dtt_number`            VARCHAR(20) DEFAULT NULL COMMENT 'DTT-YYYY-####, set when assignment completes',
  `passenger_name`        VARCHAR(150) NOT NULL,
  `surname`               VARCHAR(100) DEFAULT NULL,
  `middle_initial`        VARCHAR(3) DEFAULT NULL COMMENT '1 char + period, e.g. A.',
  `suffix`                VARCHAR(10) DEFAULT NULL COMMENT 'Jr., Sr., III, etc.',
  `id_number`             VARCHAR(50) NOT NULL,
  `requester_type`        ENUM('Student','Personnel') NOT NULL DEFAULT 'Personnel',
  `place_to_visit`        TEXT NOT NULL,
  `place_to_visit_name`   VARCHAR(255) DEFAULT NULL COMMENT 'autocomplete place name',
  `place_to_visit_lat`    DECIMAL(10,8) DEFAULT NULL COMMENT 'autocomplete latitude',
  `place_to_visit_lon`    DECIMAL(11,8) DEFAULT NULL COMMENT 'autocomplete longitude',
  `place_to_visit_place_id` VARCHAR(255) DEFAULT NULL COMMENT 'geocoding provider place ID',
  `purpose`               TEXT NOT NULL,
  `number_of_passengers`  INT(11) NOT NULL DEFAULT 1,
  `email`                 VARCHAR(150) DEFAULT NULL,
  `scheduled_departure`   DATETIME DEFAULT NULL,
  `scheduled_return`      DATETIME DEFAULT NULL,
  `status_id`             INT(11) NOT NULL DEFAULT 1,
  `reason`                TEXT DEFAULT NULL COMMENT 'rejection / cancellation reason',

  -- approval
  `approver_id`           INT(11) DEFAULT NULL,
  `approved_at`           DATETIME DEFAULT NULL,

  -- assignment
  `vehicle_id`            INT(11) DEFAULT NULL,
  `driver_id`             INT(11) DEFAULT NULL,
  `assigned_by`           INT(11) DEFAULT NULL,
  `assigned_at`           DATETIME DEFAULT NULL,

  -- execution timestamps (+ GPS fix captured at each press)
  `actual_departure_at`   DATETIME DEFAULT NULL,
  `departure_lat`         DECIMAL(10,8) DEFAULT NULL,
  `departure_lon`         DECIMAL(11,8) DEFAULT NULL,
  `dest_arrival_at`       DATETIME DEFAULT NULL,
  `dest_arrival_lat`      DECIMAL(10,8) DEFAULT NULL,
  `dest_arrival_lon`      DECIMAL(11,8) DEFAULT NULL,
  `dest_departure_at`     DATETIME DEFAULT NULL,
  `dest_departure_lat`    DECIMAL(10,8) DEFAULT NULL,
  `dest_departure_lon`    DECIMAL(11,8) DEFAULT NULL,
  `return_at`             DATETIME DEFAULT NULL,
  `return_lat`            DECIMAL(10,8) DEFAULT NULL,
  `return_lon`            DECIMAL(11,8) DEFAULT NULL,

  -- Section B: driver post-trip report (official values are odometer-based)
  `odometer_start`        DECIMAL(10,1) DEFAULT NULL,
  `odometer_end`          DECIMAL(10,1) DEFAULT NULL,
  `distance_odometer`     DECIMAL(10,1) DEFAULT NULL COMMENT 'odometer_end - odometer_start',
  `gps_distance_km`       DECIMAL(8,1) DEFAULT NULL COMMENT 'supporting info only, from breadcrumbs',
  `fuel_type`             ENUM('Gasoline','Diesel') DEFAULT NULL,
  `fuel_tank_balance_start` DECIMAL(8,2) DEFAULT NULL,
  `fuel_issued_stock`     DECIMAL(8,2) DEFAULT NULL,
  `fuel_purchased_trip`   DECIMAL(8,2) DEFAULT NULL,
  `fuel_total_available`  DECIMAL(8,2) DEFAULT NULL COMMENT 'start + issued + purchased',
  `fuel_used`             DECIMAL(8,2) DEFAULT NULL,
  `fuel_balance_end`      DECIMAL(8,2) DEFAULT NULL,
  `gear_oil_used`         VARCHAR(100) DEFAULT NULL,
  `lubricant_oil_issued`  VARCHAR(100) DEFAULT NULL,
  `grease_issued`         VARCHAR(100) DEFAULT NULL,
  `remarks`               VARCHAR(500) DEFAULT NULL,

  -- trip costs
  `fuel_cost`             DECIMAL(10,2) DEFAULT NULL,
  `easytrip_cost`         DECIMAL(10,2) DEFAULT NULL,
  `autosweep_cost`        DECIMAL(10,2) DEFAULT NULL,

  -- certification
  `certified_by`          INT(11) DEFAULT NULL,
  `certified_at`          DATETIME DEFAULT NULL,

  -- cancellation
  `cancelled_by`          INT(11) DEFAULT NULL,
  `cancelled_at`          DATETIME DEFAULT NULL,

  -- dispatch override / misc
  `dispatched_without_gps` TINYINT(1) NOT NULL DEFAULT 0,
  `attachment_path`       VARCHAR(255) DEFAULT NULL,

  `submitted_at`          DATETIME DEFAULT NULL,
  `created_by`            INT(11) DEFAULT NULL,
  `updated_by`            INT(11) DEFAULT NULL,
  `is_active`             TINYINT(1) NOT NULL DEFAULT 1,
  `created_at`            TIMESTAMP NOT NULL DEFAULT current_timestamp(),
  `updated_at`            TIMESTAMP NULL DEFAULT NULL ON UPDATE current_timestamp(),

  PRIMARY KEY (`ticket_id`),
  UNIQUE KEY `dtt_number` (`dtt_number`),
  KEY `fk_ticket_status` (`status_id`),
  KEY `fk_ticket_vehicle` (`vehicle_id`),
  KEY `fk_ticket_driver` (`driver_id`),
  KEY `fk_ticket_creator` (`created_by`),
  KEY `idx_ticket_departure` (`scheduled_departure`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ------------------------------------------------------------- trip_passengers
CREATE TABLE `trip_passengers` (
  `passenger_id`   INT(11) NOT NULL AUTO_INCREMENT,
  `ticket_id`      INT(11) NOT NULL,
  `passenger_name` VARCHAR(150) NOT NULL,
  PRIMARY KEY (`passenger_id`),
  KEY `fk_pass_ticket` (`ticket_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------- guard_verifications
CREATE TABLE `guard_verifications` (
  `verification_id` INT(11) NOT NULL AUTO_INCREMENT,
  `ticket_id`       INT(11) NOT NULL,
  `guard_id`        INT(11) NOT NULL,
  `phase`           ENUM('PRE_DEPARTURE','RETURN') NOT NULL,
  `result`          ENUM('VALIDATED','FLAGGED') NOT NULL DEFAULT 'VALIDATED',
  `checked_driver`  TINYINT(1) NOT NULL DEFAULT 0,
  `checked_vehicle` TINYINT(1) NOT NULL DEFAULT 0,
  `checked_plate`   TINYINT(1) NOT NULL DEFAULT 0,
  `checked_passengers` TINYINT(1) NOT NULL DEFAULT 0,
  `notes`           VARCHAR(500) DEFAULT NULL,
  `verified_at`     DATETIME NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`verification_id`),
  KEY `fk_gv_ticket` (`ticket_id`),
  KEY `fk_gv_guard` (`guard_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ------------------------------------------------------------------ trip_flags
CREATE TABLE `trip_flags` (
  `flag_id`           INT(11) NOT NULL AUTO_INCREMENT,
  `ticket_id`         INT(11) NOT NULL,
  `flag_type`         ENUM('NO_TELEMETRY','GPS_SIGNAL_LOST','OVERDUE','MISSED_TRIP',
                           'GEOFENCE_DEVIATION','NEEDS_REVIEW') NOT NULL,
  `detail`            VARCHAR(255) DEFAULT NULL,
  `raised_at`         DATETIME NOT NULL DEFAULT current_timestamp(),
  `resolved_by`       INT(11) DEFAULT NULL,
  `resolved_at`       DATETIME DEFAULT NULL,
  `resolution_reason` VARCHAR(500) DEFAULT NULL,
  PRIMARY KEY (`flag_id`),
  UNIQUE KEY `uq_open_flag` (`ticket_id`,`flag_type`,`resolved_at`),
  KEY `fk_flag_ticket` (`ticket_id`),
  KEY `fk_flag_resolver` (`resolved_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------------- vehicle_types
CREATE TABLE `vehicle_types` (
  `type_id`   INT(11) NOT NULL AUTO_INCREMENT,
  `type_name` VARCHAR(50) NOT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`type_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------------------- vehicle
CREATE TABLE `vehicle` (
  `vehicle_id`        INT(11) NOT NULL AUTO_INCREMENT,
  `plate_number`      VARCHAR(20) NOT NULL,
  `model_name`        VARCHAR(100) NOT NULL,
  `brand`             VARCHAR(50) DEFAULT NULL,
  `type_id`           INT(11) NOT NULL,
  `capacity`          INT(11) NOT NULL DEFAULT 1,
  `default_fuel_type` ENUM('Gasoline','Diesel') DEFAULT 'Gasoline',
  `is_available`      TINYINT(1) NOT NULL DEFAULT 1,
  `is_active`         TINYINT(1) NOT NULL DEFAULT 1,
  `managed_by`        INT(11) DEFAULT NULL,
  `created_at`        TIMESTAMP NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`vehicle_id`),
  UNIQUE KEY `plate_number` (`plate_number`),
  KEY `fk_vehicle_type` (`type_id`),
  KEY `fk_vehicle_admin` (`managed_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ----------------------------------------------------------------- gps_devices
CREATE TABLE `gps_devices` (
  `device_id`       INT(11) NOT NULL AUTO_INCREMENT,
  `device_uid`      VARCHAR(64) NOT NULL COMMENT 'e.g. GPS-NAAP-001',
  `vehicle_id`      INT(11) DEFAULT NULL COMMENT 'NULL = registered, unpaired',
  `device_name`     VARCHAR(100) DEFAULT NULL,
  `manufacturer`    VARCHAR(50) DEFAULT NULL,
  `model`           VARCHAR(50) DEFAULT NULL,
  `protocol`        VARCHAR(30) NOT NULL DEFAULT 'http-json',
  `api_token_hash`  CHAR(64) NOT NULL COMMENT 'sha256 of bearer token; raw token never stored',
  `is_active`       TINYINT(1) NOT NULL DEFAULT 1,
  `last_seen_at`    DATETIME(3) DEFAULT NULL,
  `last_latitude`   DECIMAL(10,8) DEFAULT NULL,
  `last_longitude`  DECIMAL(11,8) DEFAULT NULL,
  `last_speed_kph`  DECIMAL(6,2) DEFAULT NULL,
  `last_heading`    DECIMAL(5,1) DEFAULT NULL,
  `installed_at`    DATETIME DEFAULT NULL,
  `registered_by`   INT(11) DEFAULT NULL,
  `created_at`      TIMESTAMP NOT NULL DEFAULT current_timestamp(),
  `updated_at`      TIMESTAMP NULL DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`device_id`),
  UNIQUE KEY `device_uid` (`device_uid`),
  KEY `fk_device_vehicle` (`vehicle_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------------- gps_positions
CREATE TABLE `gps_positions` (
  `position_id`    BIGINT(20) NOT NULL AUTO_INCREMENT,
  `device_id`      INT(11) NOT NULL,
  `vehicle_id`     INT(11) NOT NULL,
  `ticket_id`      INT(11) DEFAULT NULL COMMENT 'active trip at fix time; NULL = telemetry only',
  `latitude`       DECIMAL(10,8) NOT NULL,
  `longitude`      DECIMAL(11,8) NOT NULL,
  `speed_kph`      DECIMAL(6,2) DEFAULT NULL,
  `heading`        DECIMAL(5,1) DEFAULT NULL,
  `altitude_m`     DECIMAL(7,2) DEFAULT NULL,
  `accuracy_m`     DECIMAL(6,1) DEFAULT NULL,
  `ignition`       TINYINT(1) DEFAULT NULL,
  `inside_geofence` TINYINT(1) DEFAULT NULL,
  `recorded_at`    DATETIME(3) NOT NULL,
  `received_at`    TIMESTAMP NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`position_id`),
  KEY `idx_pos_trip` (`ticket_id`,`recorded_at`),
  KEY `idx_pos_vehicle` (`vehicle_id`,`recorded_at`),
  KEY `idx_pos_device` (`device_id`,`recorded_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ------------------------------------------------------------- geofence_events
CREATE TABLE `geofence_events` (
  `event_id`   BIGINT(20) NOT NULL AUTO_INCREMENT,
  `device_id`  INT(11) NOT NULL,
  `vehicle_id` INT(11) NOT NULL,
  `ticket_id`  INT(11) DEFAULT NULL,
  `event_type` ENUM('GEOFENCE_EXITED','GEOFENCE_ENTERED') NOT NULL,
  `occurred_at` DATETIME(3) NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`event_id`),
  UNIQUE KEY `uq_geofence_transition` (`device_id`,`occurred_at`,`event_type`),
  KEY `fk_geo_ticket` (`ticket_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ----------------------------------------------------------------- audit_trail
CREATE TABLE `audit_trail` (
  `trail_id`     INT(11) NOT NULL AUTO_INCREMENT,
  `timestamp`    TIMESTAMP NOT NULL DEFAULT current_timestamp(),
  `user_id`      INT(11) NOT NULL,
  `event_type`   VARCHAR(50) NOT NULL COMMENT 'LOGIN LOGOUT CREATE MODIFY DELETE ERROR',
  `object_id`    VARCHAR(100) NOT NULL,
  `before_value` TEXT DEFAULT NULL,
  `after_value`  TEXT DEFAULT NULL,
  `outcome`      VARCHAR(20) NOT NULL DEFAULT 'SUCCESS',
  `ip_address`   VARCHAR(45) DEFAULT NULL,
  PRIMARY KEY (`trail_id`),
  KEY `fk_trail_user` (`user_id`),
  KEY `idx_trail_event` (`event_type`,`timestamp`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------------------- chatbot
CREATE TABLE `chatbot` (
  `chatbot_id`     INT(11) NOT NULL AUTO_INCREMENT,
  `prompt_keyword` VARCHAR(255) NOT NULL,
  `response_text`  TEXT NOT NULL,
  `last_updated`   TIMESTAMP NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `managed_by`     INT(11) DEFAULT NULL,
  `is_active`      TINYINT(1) NOT NULL DEFAULT 1,
  `is_displayed`   TINYINT(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`chatbot_id`),
  KEY `fk_chatbot_admin` (`managed_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- -------------------------------------------------------------------- feedback
CREATE TABLE `feedback` (
  `feedback_id`    INT(11) NOT NULL AUTO_INCREMENT,
  `ticket_id`      INT(11) NOT NULL,
  `submitter_name` VARCHAR(255) DEFAULT NULL,
  `details`        TEXT NOT NULL,
  `submitted_at`   TIMESTAMP NOT NULL DEFAULT current_timestamp(),
  `is_active`      TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`feedback_id`),
  KEY `fk_feedback_ticket` (`ticket_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ------------------------------------------------------------------- TRIGGERS
DELIMITER $$

CREATE TRIGGER `trg_trip_tickets_update_status` AFTER UPDATE ON `trip_tickets`
FOR EACH ROW
BEGIN
    IF OLD.status_id <> NEW.status_id THEN
        INSERT INTO `audit_trail`
            (`user_id`, `event_type`, `object_id`, `before_value`, `after_value`, `outcome`)
        VALUES
            (NEW.updated_by, 'MODIFY', CONCAT('trip_tickets:', NEW.ticket_id),
             COALESCE((SELECT status_name FROM request_status WHERE status_id = OLD.status_id), OLD.status_id),
             COALESCE((SELECT status_name FROM request_status WHERE status_id = NEW.status_id), NEW.status_id),
             'SUCCESS');
    END IF;
END$$

CREATE TRIGGER `trg_trip_tickets_update_vehicle` AFTER UPDATE ON `trip_tickets`
FOR EACH ROW
BEGIN
    IF NOT (OLD.vehicle_id <=> NEW.vehicle_id) THEN
        INSERT INTO `audit_trail`
            (`user_id`, `event_type`, `object_id`, `before_value`, `after_value`, `outcome`)
        VALUES
            (NEW.updated_by, 'MODIFY', CONCAT('trip_tickets:', NEW.ticket_id),
             CONCAT('Old Vehicle ID:', IFNULL(OLD.vehicle_id,'None')),
             CONCAT('New Vehicle ID:', IFNULL(NEW.vehicle_id,'None')),
             'SUCCESS');
    END IF;
END$$

CREATE TRIGGER `trg_trip_tickets_delete_standard` BEFORE DELETE ON `trip_tickets`
FOR EACH ROW
BEGIN
    INSERT INTO `audit_trail`
        (`user_id`, `event_type`, `object_id`, `before_value`, `outcome`)
    VALUES
        (OLD.updated_by, 'DELETE', CONCAT('trip_tickets:', OLD.ticket_id),
         CONCAT('Ticket:', IFNULL(OLD.dtt_number, OLD.ticket_id)), 'SUCCESS');
END$$

DELIMITER ;

SET FOREIGN_KEY_CHECKS = 1;
