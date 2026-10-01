-- =====================================================================
--  Fleetra — Smart Transport Management System
--  database/fleetra_db.sql
-- ---------------------------------------------------------------------
--  Full relational schema (InnoDB / utf8mb4) plus demo seed data.
--
--  HOW TO IMPORT (XAMPP)
--    1. Start Apache + MySQL from the XAMPP Control Panel.
--    2. Open http://localhost/phpmyadmin
--    3. Click "Import" in the top bar, choose this file, press "Import".
--    4. Open http://localhost/Fleetra/
--
--  This script is idempotent: it drops and recreates the Fleetra tables,
--  so it is safe to re-run while developing. Tables are dropped in
--  reverse dependency order.
--
--  DEMO ACCOUNTS — every seeded account uses the password: Fleetra@123
--    admin@fleetra.com       Administrator
--    manager@fleetra.com     Transport Manager
--    dispatcher@fleetra.com  Dispatcher
--    driver1@fleetra.com     Driver (Suresh Kumar)
--    driver2@fleetra.com     Driver (Imran Sheikh)
--    driver3@fleetra.com     Driver (Vikram Singh)
--    passenger@fleetra.com   Passenger (Ananya Sharma)
--    rahul.verma@fleetra.com Passenger (Rahul Verma)
--    meera.iyer@fleetra.com  Passenger (Meera Iyer)
--
--  NOTE ON DATES: schedules, trips and bookings are seeded relative to
--  CURDATE() and NOW(), and today's statuses are derived at import time.
--  The dashboard therefore always has relevant "today" data.
-- =====================================================================

SET NAMES utf8mb4;
SET time_zone = '+05:30';

-- The database is created first so this script can be imported directly
-- from the phpMyAdmin Import tab without selecting a database.
CREATE DATABASE IF NOT EXISTS fleetra_db
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE fleetra_db;

SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS activity_logs;
DROP TABLE IF EXISTS incidents;
DROP TABLE IF EXISTS notifications;
DROP TABLE IF EXISTS bus_locations;
DROP TABLE IF EXISTS payments;
DROP TABLE IF EXISTS tickets;
DROP TABLE IF EXISTS bookings;
DROP TABLE IF EXISTS maintenance;
DROP TABLE IF EXISTS trips;
DROP TABLE IF EXISTS schedules;
DROP TABLE IF EXISTS stops;
DROP TABLE IF EXISTS locations;
DROP TABLE IF EXISTS routes;
DROP TABLE IF EXISTS drivers;
DROP TABLE IF EXISTS buses;
DROP TABLE IF EXISTS password_resets;
DROP TABLE IF EXISTS users;
DROP TABLE IF EXISTS settings;

SET FOREIGN_KEY_CHECKS = 1;

-- =====================================================================
-- 1. users — every human that signs in to Fleetra
-- =====================================================================
CREATE TABLE users (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name          VARCHAR(120)  NOT NULL,
    email         VARCHAR(160)  NOT NULL,
    phone         VARCHAR(20)   DEFAULT NULL,
    password      VARCHAR(255)  NOT NULL COMMENT 'password_hash() output — never plain text',
    role          VARCHAR(30)   NOT NULL DEFAULT 'passenger'
                  COMMENT 'admin | manager | dispatcher | driver | passenger (VARCHAR so new roles need no migration)',
    profile_image VARCHAR(255)  DEFAULT NULL,
    status        ENUM('active','inactive','suspended','deleted') NOT NULL DEFAULT 'active',
    last_login    DATETIME      DEFAULT NULL,
    created_at    DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_users_email (email),
    KEY idx_users_role (role),
    KEY idx_users_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
-- 2. buses — fleet master data
-- =====================================================================
CREATE TABLE buses (
    id                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
    bus_number          VARCHAR(20)   NOT NULL COMMENT 'Internal Fleetra number, e.g. FLT-101',
    registration_number VARCHAR(30)   NOT NULL,
    manufacturer        VARCHAR(80)   DEFAULT NULL,
    model               VARCHAR(80)   DEFAULT NULL,
    manufacturing_year  SMALLINT UNSIGNED DEFAULT NULL,
    bus_type            ENUM('seater','semi_sleeper','sleeper','ac_seater','ac_sleeper','mini') NOT NULL DEFAULT 'seater',
    capacity            SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    fuel_type           ENUM('diesel','petrol','cng','electric','hybrid') NOT NULL DEFAULT 'diesel',
    current_mileage     DECIMAL(10,2) NOT NULL DEFAULT 0.00 COMMENT 'Odometer reading in km',
    status              ENUM('active','inactive','maintenance') NOT NULL DEFAULT 'active',
    image               VARCHAR(255)  DEFAULT NULL,
    created_at          DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_buses_number (bus_number),
    UNIQUE KEY uq_buses_registration (registration_number),
    KEY idx_buses_status (status),
    KEY idx_buses_type (bus_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
-- 3. drivers — driver profile linked to a user account
-- =====================================================================
CREATE TABLE drivers (
    id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id           INT UNSIGNED NOT NULL,
    employee_id       VARCHAR(20)  NOT NULL,
    license_number    VARCHAR(40)  NOT NULL,
    license_expiry    DATE         NOT NULL,
    date_of_birth     DATE         DEFAULT NULL,
    address           VARCHAR(255) DEFAULT NULL,
    experience_years  TINYINT UNSIGNED NOT NULL DEFAULT 0,
    assigned_bus_id   INT UNSIGNED DEFAULT NULL,
    employment_status ENUM('active','on_leave','suspended','terminated','resigned') NOT NULL DEFAULT 'active',
    joining_date      DATE         DEFAULT NULL,
    created_at        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_drivers_user (user_id),
    UNIQUE KEY uq_drivers_employee (employee_id),
    UNIQUE KEY uq_drivers_license (license_number),
    KEY idx_drivers_status (employment_status),
    KEY idx_drivers_license_expiry (license_expiry),
    CONSTRAINT fk_drivers_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_drivers_bus  FOREIGN KEY (assigned_bus_id) REFERENCES buses (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
-- 4. routes — service routes between a source and a destination
-- =====================================================================
CREATE TABLE routes (
    id                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
    route_code         VARCHAR(20)  NOT NULL,
    route_name         VARCHAR(140) NOT NULL,
    source             VARCHAR(120) NOT NULL,
    destination        VARCHAR(120) NOT NULL,
    distance           DECIMAL(7,2) NOT NULL DEFAULT 0.00 COMMENT 'Kilometres',
    estimated_duration SMALLINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Minutes',
    base_fare          DECIMAL(8,2) NOT NULL DEFAULT 0.00,
    status             ENUM('active','inactive') NOT NULL DEFAULT 'active',
    created_at         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_routes_code (route_code),
    KEY idx_routes_status (status),
    KEY idx_routes_source_destination (source, destination)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
-- 5. stops — ordered stops belonging to a route
-- =====================================================================
CREATE TABLE stops (
    id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    route_id       INT UNSIGNED NOT NULL,
    stop_name      VARCHAR(140) NOT NULL,
    stop_order     SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    latitude       DECIMAL(10,7) DEFAULT NULL,
    longitude      DECIMAL(10,7) DEFAULT NULL,
    arrival_offset SMALLINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Minutes after route departure',
    created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_stops_route_order (route_id, stop_order),
    KEY idx_stops_route (route_id),
    CONSTRAINT fk_stops_route FOREIGN KEY (route_id) REFERENCES routes (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
-- 5b. locations — India-wide reference data for search + autocomplete
--
--     This is the master place list (bus terminals, stands, stops,
--     landmarks and cities) used by the passenger search and the
--     "Enter location" field. Coordinates are curated public reference
--     points (city/terminal centroids) used for search and map centring,
--     not survey-grade GPS. search_text is a generated index target so
--     matching stays fast as the dataset grows.
-- =====================================================================
CREATE TABLE locations (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name          VARCHAR(160) NOT NULL COMMENT 'Terminal / stand / stop / landmark name',
    city          VARCHAR(120) NOT NULL,
    state         VARCHAR(120) NOT NULL COMMENT 'State or union territory',
    location_type ENUM('terminal','bus_stand','bus_stop','landmark','city') NOT NULL DEFAULT 'bus_stop',
    latitude      DECIMAL(10,7) DEFAULT NULL,
    longitude     DECIMAL(10,7) DEFAULT NULL,
    is_verified   TINYINT(1) NOT NULL DEFAULT 1 COMMENT '1 = curated from public transport reference data',
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    search_text   VARCHAR(420) GENERATED ALWAYS AS (LOWER(CONCAT(city, ' ', name, ' ', state))) STORED,
    PRIMARY KEY (id),
    UNIQUE KEY uq_locations_key (city, name, state),
    KEY idx_locations_search (search_text),
    KEY idx_locations_city (city),
    KEY idx_locations_state (state),
    KEY idx_locations_type (location_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
-- 6. schedules — a dated departure of a route
-- =====================================================================
CREATE TABLE schedules (
    id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    route_id       INT UNSIGNED NOT NULL,
    bus_id         INT UNSIGNED NOT NULL,
    driver_id      INT UNSIGNED NOT NULL,
    schedule_date  DATE NOT NULL,
    departure_time TIME NOT NULL,
    arrival_time   TIME NOT NULL,
    status         ENUM('scheduled','running','completed','cancelled') NOT NULL DEFAULT 'scheduled',
    created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    -- These two keys make overlapping bus/driver bookings impossible.
    UNIQUE KEY uq_schedules_bus_slot (bus_id, schedule_date, departure_time),
    UNIQUE KEY uq_schedules_driver_slot (driver_id, schedule_date, departure_time),
    KEY idx_schedules_date (schedule_date),
    KEY idx_schedules_status (status),
    KEY idx_schedules_route_date (route_id, schedule_date),
    CONSTRAINT fk_schedules_route  FOREIGN KEY (route_id)  REFERENCES routes (id)  ON DELETE RESTRICT,
    CONSTRAINT fk_schedules_bus    FOREIGN KEY (bus_id)    REFERENCES buses (id)   ON DELETE RESTRICT,
    CONSTRAINT fk_schedules_driver FOREIGN KEY (driver_id) REFERENCES drivers (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
-- 7. trips — the operational execution of a schedule
-- =====================================================================
CREATE TABLE trips (
    id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
    schedule_id       INT UNSIGNED NOT NULL,
    bus_id            INT UNSIGNED NOT NULL,
    driver_id         INT UNSIGNED NOT NULL,
    route_id          INT UNSIGNED NOT NULL,
    actual_start_time DATETIME DEFAULT NULL,
    actual_end_time   DATETIME DEFAULT NULL,
    trip_status       ENUM('scheduled','boarding','running','delayed','completed','cancelled') NOT NULL DEFAULT 'scheduled',
    passenger_count   SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    delay_minutes     SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    remarks           VARCHAR(255) DEFAULT NULL,
    created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_trips_schedule (schedule_id),
    KEY idx_trips_status (trip_status),
    KEY idx_trips_bus (bus_id),
    KEY idx_trips_driver (driver_id),
    KEY idx_trips_route (route_id),
    CONSTRAINT fk_trips_schedule FOREIGN KEY (schedule_id) REFERENCES schedules (id) ON DELETE CASCADE,
    CONSTRAINT fk_trips_bus      FOREIGN KEY (bus_id)      REFERENCES buses (id)     ON DELETE RESTRICT,
    CONSTRAINT fk_trips_driver   FOREIGN KEY (driver_id)   REFERENCES drivers (id)   ON DELETE RESTRICT,
    CONSTRAINT fk_trips_route    FOREIGN KEY (route_id)    REFERENCES routes (id)    ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
-- 8. bookings — a passenger seat reservation on a schedule
-- =====================================================================
CREATE TABLE bookings (
    id                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
    booking_number     VARCHAR(24)  NOT NULL,
    user_id            INT UNSIGNED NOT NULL,
    schedule_id        INT UNSIGNED NOT NULL,
    boarding_stop_id   INT UNSIGNED DEFAULT NULL,
    destination_stop_id INT UNSIGNED DEFAULT NULL,
    seat_number        VARCHAR(6)   NOT NULL,
    fare               DECIMAL(8,2) NOT NULL DEFAULT 0.00,
    booking_status     ENUM('pending','confirmed','completed','cancelled','no_show') NOT NULL DEFAULT 'pending',
    payment_status     ENUM('unpaid','pending','paid','refunded','failed') NOT NULL DEFAULT 'unpaid',
    booking_date       DATE NOT NULL,
    created_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    -- seat_lock is NULL for cancelled/no-show rows, so a unique index on it
    -- guarantees a seat can never be booked twice for the same schedule
    -- while still allowing cancelled bookings to keep their history.
    seat_lock VARCHAR(40) GENERATED ALWAYS AS (
        CASE
            WHEN booking_status IN ('pending','confirmed','completed')
            THEN CONCAT(schedule_id, ':', seat_number)
            ELSE NULL
        END
    ) STORED,
    PRIMARY KEY (id),
    UNIQUE KEY uq_bookings_number (booking_number),
    UNIQUE KEY uq_bookings_active_seat (seat_lock),
    KEY idx_bookings_user (user_id),
    KEY idx_bookings_schedule (schedule_id),
    KEY idx_bookings_status (booking_status),
    KEY idx_bookings_payment (payment_status),
    KEY idx_bookings_date (booking_date),
    CONSTRAINT fk_bookings_user       FOREIGN KEY (user_id)             REFERENCES users (id)      ON DELETE RESTRICT,
    CONSTRAINT fk_bookings_schedule   FOREIGN KEY (schedule_id)         REFERENCES schedules (id)  ON DELETE RESTRICT,
    CONSTRAINT fk_bookings_boarding   FOREIGN KEY (boarding_stop_id)    REFERENCES stops (id)      ON DELETE SET NULL,
    CONSTRAINT fk_bookings_destination FOREIGN KEY (destination_stop_id) REFERENCES stops (id)     ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
-- 9. tickets — one travel ticket per booking
-- =====================================================================
CREATE TABLE tickets (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    ticket_number VARCHAR(24) NOT NULL,
    booking_id    INT UNSIGNED NOT NULL,
    qr_code       VARCHAR(255) DEFAULT NULL COMMENT 'Signed verification payload rendered as a QR image',
    issued_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    status        ENUM('valid','used','cancelled','expired') NOT NULL DEFAULT 'valid',
    PRIMARY KEY (id),
    UNIQUE KEY uq_tickets_number (ticket_number),
    UNIQUE KEY uq_tickets_booking (booking_id),
    KEY idx_tickets_status (status),
    CONSTRAINT fk_tickets_booking FOREIGN KEY (booking_id) REFERENCES bookings (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
-- 10. payments — payment record per booking (simulated locally)
-- =====================================================================
CREATE TABLE payments (
    id                    INT UNSIGNED NOT NULL AUTO_INCREMENT,
    booking_id            INT UNSIGNED NOT NULL,
    transaction_reference VARCHAR(60) NOT NULL,
    payment_method        ENUM('cash','card','upi','net_banking','wallet','simulated') NOT NULL DEFAULT 'simulated',
    amount                DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    status                ENUM('pending','paid','failed','refunded') NOT NULL DEFAULT 'pending',
    payment_date          DATETIME DEFAULT NULL,
    created_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_payments_reference (transaction_reference),
    KEY idx_payments_booking (booking_id),
    KEY idx_payments_status (status),
    CONSTRAINT fk_payments_booking FOREIGN KEY (booking_id) REFERENCES bookings (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
-- 11. bus_locations — GPS breadcrumb trail (simulated or real device)
-- =====================================================================
CREATE TABLE bus_locations (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    bus_id      INT UNSIGNED NOT NULL,
    trip_id     INT UNSIGNED DEFAULT NULL,
    latitude    DECIMAL(10,7) NOT NULL,
    longitude   DECIMAL(10,7) NOT NULL,
    speed       DECIMAL(6,2) NOT NULL DEFAULT 0.00 COMMENT 'km/h',
    heading     SMALLINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Degrees, 0-359',
    source      ENUM('simulated','device') NOT NULL DEFAULT 'simulated'
                COMMENT 'Keeps demo tracking clearly separated from real GPS hardware',
    recorded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_locations_bus_time (bus_id, recorded_at),
    KEY idx_locations_trip (trip_id),
    CONSTRAINT fk_locations_bus  FOREIGN KEY (bus_id)  REFERENCES buses (id) ON DELETE CASCADE,
    CONSTRAINT fk_locations_trip FOREIGN KEY (trip_id) REFERENCES trips (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
-- 12. maintenance — workshop and servicing records
-- =====================================================================
CREATE TABLE maintenance (
    id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
    bus_id            INT UNSIGNED NOT NULL,
    maintenance_type  ENUM('routine','repair','inspection','tyre','oil_change','brake','electrical','bodywork','other') NOT NULL DEFAULT 'routine',
    description       TEXT,
    service_date      DATE NOT NULL,
    next_service_date DATE DEFAULT NULL,
    odometer_reading  DECIMAL(10,2) DEFAULT NULL,
    cost              DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    service_provider  VARCHAR(140) DEFAULT NULL,
    status            ENUM('scheduled','in_progress','completed','overdue','cancelled') NOT NULL DEFAULT 'scheduled',
    remarks           VARCHAR(255) DEFAULT NULL,
    created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_maintenance_bus (bus_id),
    KEY idx_maintenance_status (status),
    KEY idx_maintenance_next (next_service_date),
    CONSTRAINT fk_maintenance_bus FOREIGN KEY (bus_id) REFERENCES buses (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
-- 13. notifications — in-app notification centre
-- =====================================================================
CREATE TABLE notifications (
    id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id           INT UNSIGNED NOT NULL,
    title             VARCHAR(160) NOT NULL,
    message           TEXT NOT NULL,
    notification_type ENUM('trip','delay','maintenance','booking','emergency','system') NOT NULL DEFAULT 'system',
    reference_id      INT UNSIGNED DEFAULT NULL COMMENT 'Related record id (trip, booking, bus...)',
    is_read           TINYINT(1) NOT NULL DEFAULT 0,
    created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_notifications_user_read (user_id, is_read),
    KEY idx_notifications_created (created_at),
    CONSTRAINT fk_notifications_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
-- 14. incidents — operational incidents and emergency alerts
-- =====================================================================
CREATE TABLE incidents (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    trip_id       INT UNSIGNED DEFAULT NULL,
    driver_id     INT UNSIGNED DEFAULT NULL,
    bus_id        INT UNSIGNED DEFAULT NULL,
    reported_by   INT UNSIGNED DEFAULT NULL,
    incident_type ENUM('breakdown','accident','traffic','medical','security','weather','other') NOT NULL DEFAULT 'other',
    severity      ENUM('low','medium','high','critical') NOT NULL DEFAULT 'low',
    description   TEXT NOT NULL,
    latitude      DECIMAL(10,7) DEFAULT NULL,
    longitude     DECIMAL(10,7) DEFAULT NULL,
    status        ENUM('open','investigating','resolved','closed') NOT NULL DEFAULT 'open',
    reported_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    resolved_at   DATETIME DEFAULT NULL,
    PRIMARY KEY (id),
    KEY idx_incidents_status (status),
    KEY idx_incidents_severity (severity),
    KEY idx_incidents_trip (trip_id),
    KEY idx_incidents_reported (reported_at),
    CONSTRAINT fk_incidents_trip   FOREIGN KEY (trip_id)     REFERENCES trips (id)   ON DELETE SET NULL,
    CONSTRAINT fk_incidents_driver FOREIGN KEY (driver_id)   REFERENCES drivers (id) ON DELETE SET NULL,
    CONSTRAINT fk_incidents_bus    FOREIGN KEY (bus_id)      REFERENCES buses (id)   ON DELETE SET NULL,
    CONSTRAINT fk_incidents_user   FOREIGN KEY (reported_by) REFERENCES users (id)   ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
-- 15. activity_logs — audit trail for important actions
-- =====================================================================
CREATE TABLE activity_logs (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id     INT UNSIGNED DEFAULT NULL,
    action      VARCHAR(160) NOT NULL,
    module      VARCHAR(60)  NOT NULL,
    record_id   INT UNSIGNED DEFAULT NULL,
    description VARCHAR(255) DEFAULT NULL,
    ip_address  VARCHAR(45)  DEFAULT NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_logs_user (user_id),
    KEY idx_logs_module (module),
    KEY idx_logs_created (created_at),
    CONSTRAINT fk_logs_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
-- 16. password_resets — forgot-password tokens (hash stored, never raw)
-- =====================================================================
CREATE TABLE password_resets (
    id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id    INT UNSIGNED NOT NULL,
    email      VARCHAR(160) NOT NULL,
    token_hash CHAR(64) NOT NULL COMMENT 'sha256 of the emailed token',
    expires_at DATETIME NOT NULL,
    used_at    DATETIME DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_resets_token (token_hash),
    KEY idx_resets_user (user_id),
    CONSTRAINT fk_resets_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
-- 17. settings — editable system configuration
-- =====================================================================
CREATE TABLE settings (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    setting_key   VARCHAR(60) NOT NULL,
    setting_value TEXT,
    setting_group VARCHAR(40) NOT NULL DEFAULT 'general',
    updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_settings_key (setting_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- =====================================================================
--  SEED DATA
--  All demo accounts share the password: Fleetra@123
--  The stored value below is a password_hash() bcrypt digest.
-- =====================================================================
SET @demo_password = '$2y$10$M15QwOF.OqgzK.6Qw.Cqy..Pd8mqigPpqCllKnkXLw8GsDb8j8y7K';

INSERT INTO users (id, name, email, phone, password, role, status, created_at) VALUES
(1, 'Rajesh Menon',   'admin@fleetra.com',       '+91 98860 41120', @demo_password, 'admin',      'active', NOW() - INTERVAL 180 DAY),
(2, 'Priya Nair',     'manager@fleetra.com',     '+91 98860 41235', @demo_password, 'manager',    'active', NOW() - INTERVAL 150 DAY),
(3, 'Arjun Rao',      'dispatcher@fleetra.com',  '+91 98860 41987', @demo_password, 'dispatcher', 'active', NOW() - INTERVAL 120 DAY),
(4, 'Suresh Kumar',   'driver1@fleetra.com',     '+91 90080 12345', @demo_password, 'driver',     'active', NOW() - INTERVAL 300 DAY),
(5, 'Imran Sheikh',   'driver2@fleetra.com',     '+91 90080 22345', @demo_password, 'driver',     'active', NOW() - INTERVAL 240 DAY),
(6, 'Vikram Singh',   'driver3@fleetra.com',     '+91 90080 32345', @demo_password, 'driver',     'active', NOW() - INTERVAL 95 DAY),
(7, 'Ananya Sharma',  'passenger@fleetra.com',   '+91 99860 55110', @demo_password, 'passenger',  'active', NOW() - INTERVAL 60 DAY),
(8, 'Rahul Verma',    'rahul.verma@fleetra.com', '+91 99860 55223', @demo_password, 'passenger',  'active', NOW() - INTERVAL 45 DAY),
(9, 'Meera Iyer',     'meera.iyer@fleetra.com',  '+91 99860 55334', @demo_password, 'passenger',  'active', NOW() - INTERVAL 30 DAY);

INSERT INTO buses (id, bus_number, registration_number, manufacturer, model, manufacturing_year, bus_type, capacity, fuel_type, current_mileage, status) VALUES
(1, 'FLT-101', 'KA-01-AB-1234', 'Volvo',        '9400 Intercity',      2021, 'ac_seater',    45, 'diesel', 184320.50, 'active'),
(2, 'FLT-102', 'KA-01-CD-5678', 'Ashok Leyland','Viking 222',          2019, 'seater',       52, 'diesel', 263450.00, 'active'),
(3, 'FLT-103', 'KA-02-EF-9012', 'Tata Motors',  'Starbus Urban 9/9',   2022, 'mini',         32, 'cng',     98450.75, 'active'),
(4, 'FLT-104', 'KA-03-GH-3456', 'Scania',       'Metrolink HD',        2020, 'sleeper',      40, 'diesel', 312880.20, 'maintenance'),
(5, 'FLT-105', 'KA-04-IJ-7890', 'Eicher',       'Skyline Pro 3011',    2018, 'semi_sleeper', 45, 'diesel', 421760.00, 'inactive');

INSERT INTO drivers (id, user_id, employee_id, license_number, license_expiry, date_of_birth, address, experience_years, assigned_bus_id, employment_status, joining_date) VALUES
(1, 4, 'DRV-1001', 'KA0120160004521', DATE_ADD(CURDATE(), INTERVAL 420 DAY), '1985-04-12', '14, 3rd Cross, Vijayanagar, Bengaluru 560040', 12, 1, 'active', '2019-06-10'),
(2, 5, 'DRV-1002', 'KA0520180011873', DATE_ADD(CURDATE(), INTERVAL 95 DAY),  '1990-11-23', '22, Kankanady Road, Mangaluru 575002',        8,  2, 'active', '2021-02-01'),
(3, 6, 'DRV-1003', 'KA0920200025640', DATE_ADD(CURDATE(), INTERVAL 620 DAY), '1993-07-08', '7, Mission Street, Udupi 576101',             6,  3, 'active', NOW() - INTERVAL 95 DAY);

INSERT INTO routes (id, route_code, route_name, source, destination, distance, estimated_duration, base_fare, status) VALUES
(1, 'R-01', 'City Express',    'Bengaluru — Majestic',    'Mysuru',              146.20, 195, 380.00, 'active'),
(2, 'R-02', 'Coastal Link',    'Mangaluru — State Bank',  'Udupi',                62.40,  90, 150.00, 'active'),
(3, 'R-03', 'Airport Shuttle', 'Kempegowda Airport',      'Electronic City',      52.80,  75, 220.00, 'active');

INSERT INTO stops (id, route_id, stop_name, stop_order, latitude, longitude, arrival_offset) VALUES
(1,  1, 'Majestic Bus Station',      1, 12.9776000, 77.5714000,   0),
(2,  1, 'Nayandahalli Junction',     2, 12.9437000, 77.5202000,  25),
(3,  1, 'Bidadi Industrial Area',    3, 12.8000000, 77.3900000,  70),
(4,  1, 'Ramanagara Bus Stand',      4, 12.7217000, 77.2800000, 105),
(5,  1, 'Channapatna Bypass',        5, 12.6514000, 77.2065000, 140),
(6,  1, 'Mysuru Central Bus Stand',  6, 12.3052000, 76.6552000, 195),
(7,  2, 'Mangaluru State Bank',      1, 12.8698000, 74.8424000,   0),
(8,  2, 'Surathkal Toll Gate',       2, 13.0068000, 74.7942000,  22),
(9,  2, 'Mulki Check Post',          3, 13.0891000, 74.7869000,  40),
(10, 2, 'Padubidri Junction',        4, 13.1487000, 74.7896000,  62),
(11, 2, 'Udupi City Bus Stand',      5, 13.3409000, 74.7421000,  90),
(12, 3, 'Kempegowda Airport T2',     1, 13.1989000, 77.7064000,   0),
(13, 3, 'Hebbal Flyover',            2, 13.0358000, 77.5913000,  28),
(14, 3, 'Mekhri Circle',             3, 13.0118000, 77.5850000,  38),
(15, 3, 'Silk Board Junction',       4, 12.9172000, 77.6229000,  58),
(16, 3, 'Electronic City Phase 1',   5, 12.8452000, 77.6602000,  75);

-- =====================================================================
-- 5b. locations seed — major bus terminals, stands, stops and landmarks
--     across every Indian state and union territory. Names are the
--     commonly used public names; coordinates are city/terminal centroids
--     used for search and map centring.
-- =====================================================================
INSERT INTO locations (name, city, state, location_type, latitude, longitude) VALUES
-- Andhra Pradesh
('Pandit Nehru Bus Station', 'Vijayawada', 'Andhra Pradesh', 'terminal', 16.5151000, 80.6205000),
('RTC Complex Visakhapatnam', 'Visakhapatnam', 'Andhra Pradesh', 'terminal', 17.7231000, 83.3012000),
('NTR Bus Station Guntur', 'Guntur', 'Andhra Pradesh', 'terminal', 16.3067000, 80.4365000),
('Sri Krishnadevaraya Bus Station', 'Tirupati', 'Andhra Pradesh', 'terminal', 13.6288000, 79.4192000),
('Nellore RTC Bus Stand', 'Nellore', 'Andhra Pradesh', 'bus_stand', 14.4426000, 79.9865000),
('Rajahmundry RTC Complex', 'Rajahmundry', 'Andhra Pradesh', 'bus_stand', 17.0005000, 81.8040000),
('Kurnool Bus Station', 'Kurnool', 'Andhra Pradesh', 'bus_stand', 15.8281000, 78.0373000),
('Kakinada RTC Bus Complex', 'Kakinada', 'Andhra Pradesh', 'bus_stand', 16.9600000, 82.2380000),
('Anantapur Bus Station', 'Anantapur', 'Andhra Pradesh', 'bus_stand', 14.6819000, 77.6006000),
('Kadapa RTC Bus Stand', 'Kadapa', 'Andhra Pradesh', 'bus_stand', 14.4673000, 78.8242000),
-- Arunachal Pradesh
('Naharlagun ISBT', 'Naharlagun', 'Arunachal Pradesh', 'terminal', 27.1021000, 93.6237000),
('Itanagar Bus Station', 'Itanagar', 'Arunachal Pradesh', 'bus_stand', 27.0844000, 93.6053000),
-- Assam
('ISBT Guwahati Khanapara', 'Guwahati', 'Assam', 'terminal', 26.1445000, 91.7362000),
('Dibrugarh Chowkidinghee Bus Stand', 'Dibrugarh', 'Assam', 'bus_stand', 27.4728000, 94.9120000),
('Silchar ISBT', 'Silchar', 'Assam', 'terminal', 24.8333000, 92.7789000),
('Jorhat Bus Stand', 'Jorhat', 'Assam', 'bus_stand', 26.7509000, 94.2037000),
('Tezpur Bus Stand', 'Tezpur', 'Assam', 'bus_stand', 26.6338000, 92.8000000),
('Nagaon Bus Station', 'Nagaon', 'Assam', 'bus_stand', 26.3464000, 92.6840000),
-- Bihar
('Mithapur Bus Stand', 'Patna', 'Bihar', 'terminal', 25.5941000, 85.1376000),
('Gaya Bus Stand', 'Gaya', 'Bihar', 'bus_stand', 24.7914000, 84.9994000),
('Bhagalpur Bus Stand', 'Bhagalpur', 'Bihar', 'bus_stand', 25.2425000, 86.9842000),
('Muzaffarpur Bus Stand', 'Muzaffarpur', 'Bihar', 'bus_stand', 26.1209000, 85.3647000),
('Darbhanga Bus Stand', 'Darbhanga', 'Bihar', 'bus_stand', 26.1542000, 85.8918000),
('Purnia Bus Stand', 'Purnia', 'Bihar', 'bus_stand', 25.7771000, 87.4753000),
('Ara Bus Stand', 'Ara', 'Bihar', 'bus_stand', 25.5541000, 84.6600000),
-- Chhattisgarh
('Pandri Bus Stand', 'Raipur', 'Chhattisgarh', 'terminal', 21.2514000, 81.6296000),
('Bhilai Bus Stand', 'Bhilai', 'Chhattisgarh', 'bus_stand', 21.1938000, 81.3509000),
('Bilaspur Bus Stand', 'Bilaspur', 'Chhattisgarh', 'bus_stand', 22.0797000, 82.1409000),
('Durg Bus Stand', 'Durg', 'Chhattisgarh', 'bus_stand', 21.1904000, 81.2849000),
('Jagdalpur Bus Stand', 'Jagdalpur', 'Chhattisgarh', 'bus_stand', 19.0748000, 82.0348000),
('Ambikapur Bus Stand', 'Ambikapur', 'Chhattisgarh', 'bus_stand', 23.1200000, 83.1950000),
-- Goa
('Kadamba Bus Stand', 'Panaji', 'Goa', 'terminal', 15.4909000, 73.8278000),
('KTC Bus Stand Margao', 'Margao', 'Goa', 'terminal', 15.2832000, 73.9862000),
('Vasco Bus Stand', 'Vasco da Gama', 'Goa', 'bus_stand', 15.3980000, 73.8110000),
('Mapusa Bus Stand', 'Mapusa', 'Goa', 'bus_stand', 15.5937000, 73.8143000),
('Ponda Bus Stand', 'Ponda', 'Goa', 'bus_stand', 15.4026000, 74.0078000),
-- Gujarat
('Geeta Mandir Bus Stand', 'Ahmedabad', 'Gujarat', 'terminal', 23.0225000, 72.5714000),
('Surat Central Bus Stand', 'Surat', 'Gujarat', 'terminal', 21.1702000, 72.8311000),
('Sayaji Ganj Bus Depot', 'Vadodara', 'Gujarat', 'bus_stand', 22.3072000, 73.1812000),
('Rajkot Bus Stand', 'Rajkot', 'Gujarat', 'bus_stand', 22.3039000, 70.8022000),
('Bhavnagar Bus Stand', 'Bhavnagar', 'Gujarat', 'bus_stand', 21.7645000, 72.1519000),
('Jamnagar Bus Stand', 'Jamnagar', 'Gujarat', 'bus_stand', 22.4707000, 70.0577000),
('Gandhinagar Bus Stand', 'Gandhinagar', 'Gujarat', 'bus_stand', 23.2156000, 72.6369000),
('Junagadh Bus Stand', 'Junagadh', 'Gujarat', 'bus_stand', 21.5222000, 70.4579000),
('Anand Bus Stand', 'Anand', 'Gujarat', 'bus_stand', 22.5645000, 72.9289000),
('Bharuch Bus Stand', 'Bharuch', 'Gujarat', 'bus_stand', 21.7051000, 72.9959000),
('Bhuj Bus Stand', 'Bhuj', 'Gujarat', 'bus_stand', 23.2420000, 69.6669000),
('Porbandar Bus Stand', 'Porbandar', 'Gujarat', 'bus_stand', 21.6417000, 69.6293000),
('Surendranagar Bus Stand', 'Surendranagar', 'Gujarat', 'bus_stand', 22.7270000, 71.6460000),
('Navsari Bus Stand', 'Navsari', 'Gujarat', 'bus_stand', 20.9467000, 72.9520000),
('Vapi Bus Stand', 'Vapi', 'Gujarat', 'bus_stand', 20.3893000, 72.9106000),
-- Haryana
('Gurugram Bus Stand', 'Gurugram', 'Haryana', 'terminal', 28.4595000, 77.0266000),
('Faridabad Bus Stand', 'Faridabad', 'Haryana', 'bus_stand', 28.4089000, 77.3178000),
('Panipat Bus Stand', 'Panipat', 'Haryana', 'bus_stand', 29.3909000, 76.9635000),
('Ambala Bus Stand', 'Ambala', 'Haryana', 'bus_stand', 30.3782000, 76.7767000),
('Karnal Bus Stand', 'Karnal', 'Haryana', 'bus_stand', 29.6857000, 76.9905000),
('Hisar Bus Stand', 'Hisar', 'Haryana', 'bus_stand', 29.1492000, 75.7217000),
('Rohtak Bus Stand', 'Rohtak', 'Haryana', 'bus_stand', 28.8955000, 76.6066000),
('Sonipat Bus Stand', 'Sonipat', 'Haryana', 'bus_stand', 28.9931000, 77.0151000),
('Yamunanagar Bus Stand', 'Yamunanagar', 'Haryana', 'bus_stand', 30.1290000, 77.2674000),
('Bhiwani Bus Stand', 'Bhiwani', 'Haryana', 'bus_stand', 28.7975000, 76.1322000),
-- Himachal Pradesh
('ISBT Shimla', 'Shimla', 'Himachal Pradesh', 'terminal', 31.1048000, 77.1734000),
('Manali Bus Stand', 'Manali', 'Himachal Pradesh', 'bus_stand', 32.2432000, 77.1892000),
('Dharamshala Bus Stand', 'Dharamshala', 'Himachal Pradesh', 'bus_stand', 32.2190000, 76.3234000),
('Mandi Bus Stand', 'Mandi', 'Himachal Pradesh', 'bus_stand', 31.7080000, 76.9318000),
('Solan Bus Stand', 'Solan', 'Himachal Pradesh', 'bus_stand', 30.9045000, 77.0967000),
('Kullu Bus Stand', 'Kullu', 'Himachal Pradesh', 'bus_stand', 31.9578000, 77.1095000),
('Una Bus Stand', 'Una', 'Himachal Pradesh', 'bus_stand', 31.4685000, 76.2708000),
('Hamirpur Bus Stand', 'Hamirpur', 'Himachal Pradesh', 'bus_stand', 31.6842000, 76.5211000),
('Bilaspur Bus Stand', 'Bilaspur', 'Himachal Pradesh', 'bus_stand', 31.3300000, 76.7500000),
('Rampur Bus Stand', 'Rampur', 'Himachal Pradesh', 'bus_stand', 31.4497000, 77.6307000),
-- Jharkhand
('Ratu Road Bus Stand', 'Ranchi', 'Jharkhand', 'terminal', 23.3441000, 85.3096000),
('Sakchi Bus Stand', 'Jamshedpur', 'Jharkhand', 'bus_stand', 22.8046000, 86.2029000),
('Dhanbad Bus Stand', 'Dhanbad', 'Jharkhand', 'bus_stand', 23.7957000, 86.4304000),
('Bokaro Bus Stand', 'Bokaro', 'Jharkhand', 'bus_stand', 23.6693000, 86.1511000),
('Deoghar Bus Stand', 'Deoghar', 'Jharkhand', 'bus_stand', 24.4823000, 86.6991000),
('Hazaribagh Bus Stand', 'Hazaribagh', 'Jharkhand', 'bus_stand', 23.9968000, 85.3674000),
('Dumka Bus Stand', 'Dumka', 'Jharkhand', 'bus_stand', 24.2674000, 87.2494000),
-- Karnataka
('Kempegowda Bus Station Majestic', 'Bengaluru', 'Karnataka', 'terminal', 12.9776000, 77.5714000),
('Mysuru Central Bus Stand', 'Mysuru', 'Karnataka', 'terminal', 12.3052000, 76.6552000),
('State Bank Bus Stand', 'Mangaluru', 'Karnataka', 'terminal', 12.8698000, 74.8424000),
('Hubballi Bus Stand', 'Hubballi', 'Karnataka', 'bus_stand', 15.3647000, 75.1240000),
('Udupi City Bus Stand', 'Udupi', 'Karnataka', 'bus_stand', 13.3409000, 74.7421000),
('Belagavi Central Bus Stand', 'Belagavi', 'Karnataka', 'bus_stand', 15.8497000, 74.4977000),
('Dharwad Bus Stand', 'Dharwad', 'Karnataka', 'bus_stand', 15.4589000, 75.0078000),
('Davanagere Bus Stand', 'Davanagere', 'Karnataka', 'bus_stand', 14.4644000, 75.9218000),
('Ballari Bus Stand', 'Ballari', 'Karnataka', 'bus_stand', 15.1394000, 76.9214000),
('Kalaburagi Central Bus Stand', 'Kalaburagi', 'Karnataka', 'bus_stand', 17.3297000, 76.8343000),
('Shivamogga Bus Stand', 'Shivamogga', 'Karnataka', 'bus_stand', 13.9299000, 75.5681000),
('Vijayapura Bus Stand', 'Vijayapura', 'Karnataka', 'bus_stand', 16.8302000, 75.7100000),
('Tumakuru Bus Stand', 'Tumakuru', 'Karnataka', 'bus_stand', 13.3379000, 77.1173000),
('Raichur Bus Stand', 'Raichur', 'Karnataka', 'bus_stand', 16.2076000, 77.3463000),
('Bidar Bus Stand', 'Bidar', 'Karnataka', 'bus_stand', 17.9104000, 77.5199000),
-- Kerala
('Central Bus Station Thampanoor', 'Thiruvananthapuram', 'Kerala', 'terminal', 8.5241000, 76.9366000),
('Vyttila Mobility Hub', 'Kochi', 'Kerala', 'terminal', 9.9312000, 76.2673000),
('KSRTC Bus Stand Kozhikode', 'Kozhikode', 'Kerala', 'terminal', 11.2588000, 75.7804000),
('Shakthan Thampuran Bus Stand', 'Thrissur', 'Kerala', 'bus_stand', 10.5276000, 76.2144000),
('Kollam Bus Stand', 'Kollam', 'Kerala', 'bus_stand', 8.8932000, 76.6141000),
('Alappuzha Bus Stand', 'Alappuzha', 'Kerala', 'bus_stand', 9.4981000, 76.3388000),
('Kannur Bus Stand', 'Kannur', 'Kerala', 'bus_stand', 11.8745000, 75.3704000),
('Kottayam Bus Stand', 'Kottayam', 'Kerala', 'bus_stand', 9.5916000, 76.5222000),
('Palakkad Bus Stand', 'Palakkad', 'Kerala', 'bus_stand', 10.7867000, 76.6548000),
('Malappuram Bus Stand', 'Malappuram', 'Kerala', 'bus_stand', 11.0510000, 76.0711000),
('Pathanamthitta Bus Stand', 'Pathanamthitta', 'Kerala', 'bus_stand', 9.2648000, 76.7870000),
('Idukki Bus Stand', 'Idukki', 'Kerala', 'bus_stand', 9.8500000, 76.9700000),
-- Madhya Pradesh
('ISBT Bhopal', 'Bhopal', 'Madhya Pradesh', 'terminal', 23.2599000, 77.4126000),
('Gangwal Bus Terminal', 'Indore', 'Madhya Pradesh', 'terminal', 22.7196000, 75.8577000),
('Gwalior Bus Stand', 'Gwalior', 'Madhya Pradesh', 'bus_stand', 26.2183000, 78.1828000),
('Jabalpur Bus Stand', 'Jabalpur', 'Madhya Pradesh', 'bus_stand', 23.1815000, 79.9864000),
('Ujjain Bus Stand', 'Ujjain', 'Madhya Pradesh', 'bus_stand', 23.1765000, 75.7885000),
('Sagar Bus Stand', 'Sagar', 'Madhya Pradesh', 'bus_stand', 23.8388000, 78.7378000),
('Satna Bus Stand', 'Satna', 'Madhya Pradesh', 'bus_stand', 24.5700000, 80.8300000),
('Rewa Bus Stand', 'Rewa', 'Madhya Pradesh', 'bus_stand', 24.5362000, 81.3037000),
('Ratlam Bus Stand', 'Ratlam', 'Madhya Pradesh', 'bus_stand', 23.3315000, 75.0367000),
('Dewas Bus Stand', 'Dewas', 'Madhya Pradesh', 'bus_stand', 22.9676000, 76.0550000),
('Chhindwara Bus Stand', 'Chhindwara', 'Madhya Pradesh', 'bus_stand', 22.0574000, 78.9382000),
-- Maharashtra
('Borivali Bus Depot', 'Mumbai', 'Maharashtra', 'terminal', 19.2307000, 72.8567000),
('Shivajinagar ST Stand', 'Pune', 'Maharashtra', 'terminal', 18.5308000, 73.8478000),
('Ganeshpeth Bus Stand', 'Nagpur', 'Maharashtra', 'terminal', 21.1458000, 79.0882000),
('CBS Nashik', 'Nashik', 'Maharashtra', 'terminal', 19.9975000, 73.7898000),
('Thane Bus Stand', 'Thane', 'Maharashtra', 'bus_stand', 19.2183000, 72.9781000),
('Chhatrapati Sambhajinagar Bus Stand', 'Aurangabad', 'Maharashtra', 'bus_stand', 19.8762000, 75.3433000),
('Solapur Bus Stand', 'Solapur', 'Maharashtra', 'bus_stand', 17.6599000, 75.9064000),
('Kolhapur Bus Stand', 'Kolhapur', 'Maharashtra', 'bus_stand', 16.7050000, 74.2433000),
('Amravati Bus Stand', 'Amravati', 'Maharashtra', 'bus_stand', 20.9320000, 77.7523000),
('Nanded Bus Stand', 'Nanded', 'Maharashtra', 'bus_stand', 19.1383000, 77.3210000),
('Jalgaon Bus Stand', 'Jalgaon', 'Maharashtra', 'bus_stand', 21.0077000, 75.5626000),
('Akola Bus Stand', 'Akola', 'Maharashtra', 'bus_stand', 20.7002000, 77.0082000),
('Latur Bus Stand', 'Latur', 'Maharashtra', 'bus_stand', 18.4088000, 76.5604000),
('Sangli Bus Stand', 'Sangli', 'Maharashtra', 'bus_stand', 16.8524000, 74.5815000),
('Ahmednagar Bus Stand', 'Ahmednagar', 'Maharashtra', 'bus_stand', 19.0948000, 74.7480000),
('Navi Mumbai Bus Stand', 'Navi Mumbai', 'Maharashtra', 'bus_stand', 19.0330000, 73.0297000),
-- Manipur
('ISBT Imphal', 'Imphal', 'Manipur', 'terminal', 24.8170000, 93.9368000),
-- Meghalaya
('Bara Bazar ISBT Shillong', 'Shillong', 'Meghalaya', 'terminal', 25.5788000, 91.8933000),
-- Mizoram
('Aizawl Bus Stand', 'Aizawl', 'Mizoram', 'bus_stand', 23.7271000, 92.7177000),
-- Nagaland
('Kohima Bus Stand', 'Kohima', 'Nagaland', 'bus_stand', 25.6751000, 94.1086000),
('Dimapur Bus Stand', 'Dimapur', 'Nagaland', 'bus_stand', 25.9060000, 93.7275000),
-- Odisha
('Baramunda Bus Terminal', 'Bhubaneswar', 'Odisha', 'terminal', 20.2961000, 85.8245000),
('Badambadi Bus Stand', 'Cuttack', 'Odisha', 'terminal', 20.4625000, 85.8830000),
('Chhend Bus Stand', 'Rourkela', 'Odisha', 'bus_stand', 22.2604000, 84.8536000),
('Sambalpur Bus Stand', 'Sambalpur', 'Odisha', 'bus_stand', 21.4669000, 83.9756000),
('Berhampur Bus Stand', 'Berhampur', 'Odisha', 'bus_stand', 19.3150000, 84.7941000),
('Puri Bus Stand', 'Puri', 'Odisha', 'bus_stand', 19.8135000, 85.8312000),
('Balasore Bus Stand', 'Balasore', 'Odisha', 'bus_stand', 21.4934000, 86.9335000),
('Baripada Bus Stand', 'Baripada', 'Odisha', 'bus_stand', 21.9333000, 86.7333000),
-- Punjab
('Bus Stand Ludhiana', 'Ludhiana', 'Punjab', 'terminal', 30.9010000, 75.8573000),
('ISBT Amritsar', 'Amritsar', 'Punjab', 'terminal', 31.6340000, 74.8723000),
('Jalandhar Bus Stand', 'Jalandhar', 'Punjab', 'bus_stand', 31.3260000, 75.5762000),
('Patiala Bus Stand', 'Patiala', 'Punjab', 'bus_stand', 30.3398000, 76.3869000),
('Bathinda Bus Stand', 'Bathinda', 'Punjab', 'bus_stand', 30.2110000, 74.9455000),
('Mohali Bus Stand', 'Mohali', 'Punjab', 'bus_stand', 30.7046000, 76.7179000),
('Pathankot Bus Stand', 'Pathankot', 'Punjab', 'bus_stand', 32.2643000, 75.6421000),
('Hoshiarpur Bus Stand', 'Hoshiarpur', 'Punjab', 'bus_stand', 31.5320000, 75.9115000),
('Moga Bus Stand', 'Moga', 'Punjab', 'bus_stand', 30.8158000, 75.1717000),
-- Rajasthan
('Sindhi Camp Bus Stand', 'Jaipur', 'Rajasthan', 'terminal', 26.9124000, 75.7873000),
('Jodhpur Bus Stand', 'Jodhpur', 'Rajasthan', 'bus_stand', 26.2389000, 73.0243000),
('Udaipur Bus Stand', 'Udaipur', 'Rajasthan', 'bus_stand', 24.5854000, 73.7125000),
('Kota Bus Stand', 'Kota', 'Rajasthan', 'bus_stand', 25.2138000, 75.8648000),
('Ajmer Bus Stand', 'Ajmer', 'Rajasthan', 'bus_stand', 26.4499000, 74.6399000),
('Bikaner Bus Stand', 'Bikaner', 'Rajasthan', 'bus_stand', 28.0229000, 73.3119000),
('Alwar Bus Stand', 'Alwar', 'Rajasthan', 'bus_stand', 27.5665000, 76.6250000),
('Bharatpur Bus Stand', 'Bharatpur', 'Rajasthan', 'bus_stand', 27.2170000, 77.4900000),
('Sikar Bus Stand', 'Sikar', 'Rajasthan', 'bus_stand', 27.6094000, 75.1399000),
('Bhilwara Bus Stand', 'Bhilwara', 'Rajasthan', 'bus_stand', 25.3407000, 74.6313000),
('Pali Bus Stand', 'Pali', 'Rajasthan', 'bus_stand', 25.7711000, 73.3234000),
-- Sikkim
('SNT Bus Terminal', 'Gangtok', 'Sikkim', 'terminal', 27.3389000, 88.6065000),
-- Tamil Nadu
('CMBT Koyambedu', 'Chennai', 'Tamil Nadu', 'terminal', 13.0700000, 80.1948000),
('Gandhipuram Bus Stand', 'Coimbatore', 'Tamil Nadu', 'terminal', 11.0168000, 76.9558000),
('Mattuthavani Bus Stand', 'Madurai', 'Tamil Nadu', 'terminal', 9.9252000, 78.1198000),
('Central Bus Stand Trichy', 'Tiruchirappalli', 'Tamil Nadu', 'bus_stand', 10.7905000, 78.7047000),
('Salem New Bus Stand', 'Salem', 'Tamil Nadu', 'bus_stand', 11.6643000, 78.1460000),
('Tirunelveli Bus Stand', 'Tirunelveli', 'Tamil Nadu', 'bus_stand', 8.7139000, 77.7567000),
('Erode Bus Stand', 'Erode', 'Tamil Nadu', 'bus_stand', 11.3410000, 77.7172000),
('Vellore Bus Stand', 'Vellore', 'Tamil Nadu', 'bus_stand', 12.9165000, 79.1325000),
('Thanjavur Bus Stand', 'Thanjavur', 'Tamil Nadu', 'bus_stand', 10.7870000, 79.1378000),
('Tiruppur Bus Stand', 'Tiruppur', 'Tamil Nadu', 'bus_stand', 11.1085000, 77.3411000),
('Hosur Bus Stand', 'Hosur', 'Tamil Nadu', 'bus_stand', 12.7409000, 77.8253000),
('Nagercoil Bus Stand', 'Nagercoil', 'Tamil Nadu', 'bus_stand', 8.1833000, 77.4119000),
('Kanyakumari Bus Stand', 'Kanyakumari', 'Tamil Nadu', 'bus_stand', 8.0883000, 77.5385000),
-- Telangana
('MGBS Mahatma Gandhi Bus Station', 'Hyderabad', 'Telangana', 'terminal', 17.3850000, 78.4867000),
('Warangal Bus Stand', 'Warangal', 'Telangana', 'bus_stand', 17.9689000, 79.5941000),
('Nizamabad Bus Stand', 'Nizamabad', 'Telangana', 'bus_stand', 18.6725000, 78.0941000),
('Karimnagar Bus Stand', 'Karimnagar', 'Telangana', 'bus_stand', 18.4386000, 79.1288000),
('Khammam Bus Stand', 'Khammam', 'Telangana', 'bus_stand', 17.2473000, 80.1514000),
('Mahbubnagar Bus Stand', 'Mahbubnagar', 'Telangana', 'bus_stand', 16.7488000, 77.9854000),
-- Tripura
('Nagerjala Bus Stand', 'Agartala', 'Tripura', 'terminal', 23.8315000, 91.2868000),
-- Uttar Pradesh
('Alambagh Bus Station', 'Lucknow', 'Uttar Pradesh', 'terminal', 26.8467000, 80.9462000),
('Jhakarkati Bus Station', 'Kanpur', 'Uttar Pradesh', 'terminal', 26.4499000, 80.3319000),
('Cantt Bus Station Varanasi', 'Varanasi', 'Uttar Pradesh', 'terminal', 25.3176000, 82.9739000),
('Idgah Bus Stand', 'Agra', 'Uttar Pradesh', 'terminal', 27.1767000, 78.0081000),
('Noida Bus Stand', 'Noida', 'Uttar Pradesh', 'bus_stand', 28.5355000, 77.3910000),
('Ghaziabad Bus Stand', 'Ghaziabad', 'Uttar Pradesh', 'bus_stand', 28.6692000, 77.4538000),
('Civil Lines Bus Stand', 'Prayagraj', 'Uttar Pradesh', 'bus_stand', 25.4358000, 81.8463000),
('Meerut Bus Stand', 'Meerut', 'Uttar Pradesh', 'bus_stand', 28.9845000, 77.7064000),
('Bareilly Bus Stand', 'Bareilly', 'Uttar Pradesh', 'bus_stand', 28.3670000, 79.4304000),
('Gorakhpur Bus Stand', 'Gorakhpur', 'Uttar Pradesh', 'bus_stand', 26.7606000, 83.3732000),
('Aligarh Bus Stand', 'Aligarh', 'Uttar Pradesh', 'bus_stand', 27.8974000, 78.0880000),
('Moradabad Bus Stand', 'Moradabad', 'Uttar Pradesh', 'bus_stand', 28.8386000, 78.7733000),
('Mathura Bus Stand', 'Mathura', 'Uttar Pradesh', 'bus_stand', 27.4924000, 77.6737000),
('Jhansi Bus Stand', 'Jhansi', 'Uttar Pradesh', 'bus_stand', 25.4484000, 78.5685000),
('Ayodhya Bus Stand', 'Ayodhya', 'Uttar Pradesh', 'bus_stand', 26.7996000, 82.2044000),
('Faizabad Bus Stand', 'Faizabad', 'Uttar Pradesh', 'bus_stand', 26.7732000, 82.1463000),
-- Uttarakhand
('ISBT Dehradun', 'Dehradun', 'Uttarakhand', 'terminal', 30.3165000, 78.0322000),
('Haridwar Bus Stand', 'Haridwar', 'Uttarakhand', 'bus_stand', 29.9457000, 78.1642000),
('Roorkee Bus Stand', 'Roorkee', 'Uttarakhand', 'bus_stand', 29.8543000, 77.8880000),
('Haldwani Bus Stand', 'Haldwani', 'Uttarakhand', 'bus_stand', 29.2183000, 79.5130000),
('Rishikesh Bus Stand', 'Rishikesh', 'Uttarakhand', 'bus_stand', 30.0869000, 78.2676000),
('Rudrapur Bus Stand', 'Rudrapur', 'Uttarakhand', 'bus_stand', 28.9845000, 79.4000000),
('Nainital Bus Stand', 'Nainital', 'Uttarakhand', 'bus_stand', 29.3803000, 79.4636000),
('Srinagar Garhwal Bus Stand', 'Srinagar', 'Uttarakhand', 'bus_stand', 30.2220000, 78.7830000),
-- West Bengal
('Esplanade Bus Terminus', 'Kolkata', 'West Bengal', 'terminal', 22.5600000, 88.3510000),
('Howrah Bus Stand', 'Howrah', 'West Bengal', 'bus_stand', 22.5958000, 88.2636000),
('Tenzing Norgay Central Bus Terminus', 'Siliguri', 'West Bengal', 'terminal', 26.7271000, 88.3953000),
('Durgapur Bus Stand', 'Durgapur', 'West Bengal', 'bus_stand', 23.5204000, 87.3119000),
('Asansol Bus Stand', 'Asansol', 'West Bengal', 'bus_stand', 23.6739000, 86.9524000),
('Kharagpur Bus Stand', 'Kharagpur', 'West Bengal', 'bus_stand', 22.3460000, 87.2320000),
('Bardhaman Bus Stand', 'Bardhaman', 'West Bengal', 'bus_stand', 23.2324000, 87.8615000),
('Malda Bus Stand', 'Malda', 'West Bengal', 'bus_stand', 25.0119000, 88.1433000),
('Berhampore Bus Stand', 'Berhampore', 'West Bengal', 'bus_stand', 24.0988000, 88.2678000),
('Darjeeling Bus Stand', 'Darjeeling', 'West Bengal', 'bus_stand', 27.0410000, 88.2663000),
('Kalyani Bus Stand', 'Kalyani', 'West Bengal', 'bus_stand', 22.9750000, 88.4340000),
('Cooch Behar Bus Stand', 'Cooch Behar', 'West Bengal', 'bus_stand', 26.3450000, 89.4480000),
('Haldia Bus Stand', 'Haldia', 'West Bengal', 'bus_stand', 22.0667000, 88.0698000),
('Digha Bus Stand', 'Digha', 'West Bengal', 'bus_stand', 21.6270000, 87.5090000),
-- Delhi
('Kashmere Gate ISBT', 'Delhi', 'Delhi', 'terminal', 28.6675000, 77.2285000),
('Anand Vihar ISBT', 'Delhi', 'Delhi', 'terminal', 28.6469000, 77.3160000),
('Sarai Kale Khan ISBT', 'Delhi', 'Delhi', 'terminal', 28.5885000, 77.2570000),
('Dwarka Bus Stand', 'Delhi', 'Delhi', 'bus_stand', 28.5921000, 77.0460000),
-- Jammu and Kashmir
('TRC Bus Stand Srinagar', 'Srinagar', 'Jammu and Kashmir', 'terminal', 34.0837000, 74.7973000),
('Jammu Bus Stand', 'Jammu', 'Jammu and Kashmir', 'bus_stand', 32.7266000, 74.8570000),
-- Ladakh
('Leh Bus Stand', 'Leh', 'Ladakh', 'bus_stand', 34.1526000, 77.5771000),
('Kargil Bus Stand', 'Kargil', 'Ladakh', 'bus_stand', 34.5560000, 76.1260000),
-- Chandigarh
('ISBT Sector 43', 'Chandigarh', 'Chandigarh', 'terminal', 30.7333000, 76.7794000),
-- Puducherry
('Puducherry Bus Stand', 'Puducherry', 'Puducherry', 'bus_stand', 11.9416000, 79.8083000),
-- Andaman and Nicobar Islands
('Port Blair Bus Stand', 'Port Blair', 'Andaman and Nicobar Islands', 'bus_stand', 11.6234000, 92.7265000),
-- Lakshadweep
('Kavaratti Bus Stand', 'Kavaratti', 'Lakshadweep', 'bus_stand', 10.5669000, 72.6420000),
-- Dadra and Nagar Haveli and Daman and Diu
('Silvassa Bus Stand', 'Silvassa', 'Dadra and Nagar Haveli and Daman and Diu', 'bus_stand', 20.2739000, 73.0170000),
('Daman Bus Stand', 'Daman', 'Dadra and Nagar Haveli and Daman and Diu', 'bus_stand', 20.3974000, 72.8328000),
('Diu Bus Stand', 'Diu', 'Dadra and Nagar Haveli and Daman and Diu', 'bus_stand', 20.7144000, 70.9874000);

INSERT INTO schedules (id, route_id, bus_id, driver_id, schedule_date, departure_time, arrival_time, status) VALUES
(1,  1, 1, 1, CURDATE(),                          '06:30:00', '09:45:00', 'scheduled'),
(2,  1, 2, 2, CURDATE(),                          '14:00:00', '17:15:00', 'scheduled'),
(3,  2, 3, 3, CURDATE(),                          '08:00:00', '09:30:00', 'scheduled'),
(11, 2, 2, 2, CURDATE(),                          '18:30:00', '20:00:00', 'scheduled'),
(12, 3, 3, 3, CURDATE(),                          '21:30:00', '22:45:00', 'scheduled'),
(4,  3, 2, 2, DATE_ADD(CURDATE(), INTERVAL 1 DAY), '05:45:00', '07:00:00', 'scheduled'),
(5,  1, 1, 1, DATE_ADD(CURDATE(), INTERVAL 1 DAY), '06:30:00', '09:45:00', 'scheduled'),
(6,  2, 3, 3, DATE_SUB(CURDATE(), INTERVAL 1 DAY), '08:00:00', '09:30:00', 'completed'),
(7,  3, 1, 1, DATE_SUB(CURDATE(), INTERVAL 1 DAY), '16:30:00', '17:45:00', 'completed'),
(8,  1, 2, 2, DATE_SUB(CURDATE(), INTERVAL 2 DAY), '06:30:00', '09:45:00', 'completed'),
(9,  2, 3, 3, DATE_SUB(CURDATE(), INTERVAL 2 DAY), '08:00:00', '09:30:00', 'completed'),
(10, 1, 1, 1, DATE_SUB(CURDATE(), INTERVAL 3 DAY), '06:30:00', '09:45:00', 'completed');

INSERT INTO trips (id, schedule_id, bus_id, driver_id, route_id, trip_status, remarks) VALUES
(1,  1,  1, 1, 1, 'scheduled', 'Morning service to Mysuru'),
(2,  2,  2, 2, 1, 'scheduled', 'Afternoon service to Mysuru'),
(3,  3,  3, 3, 2, 'scheduled', 'Coastal Link morning run'),
(4,  4,  2, 2, 3, 'scheduled', 'Early airport shuttle'),
(5,  5,  1, 1, 1, 'scheduled', 'Morning service to Mysuru'),
(6,  6,  3, 3, 2, 'completed', NULL),
(7,  7,  1, 1, 3, 'completed', NULL),
(8,  8,  2, 2, 1, 'completed', NULL),
(9,  9,  3, 3, 2, 'completed', NULL),
(10, 10, 1, 1, 1, 'completed', NULL),
(11, 11, 2, 2, 2, 'scheduled', 'Evening Coastal Link service'),
(12, 12, 3, 3, 3, 'scheduled', 'Late night airport shuttle');

-- Derive operational status from the actual clock time so the demo always
-- shows a realistic mix of running / completed / scheduled services, no
-- matter what time of day the database is imported.
UPDATE schedules s
SET s.status = CASE
        WHEN TIMESTAMP(s.schedule_date, s.arrival_time)   < NOW() THEN 'completed'
        WHEN TIMESTAMP(s.schedule_date, s.departure_time) <= NOW() THEN 'running'
        ELSE 'scheduled'
    END;

UPDATE trips t
JOIN schedules s ON s.id = t.schedule_id
SET t.trip_status = CASE
        WHEN TIMESTAMP(s.schedule_date, s.arrival_time)   < NOW() THEN 'completed'
        WHEN TIMESTAMP(s.schedule_date, s.departure_time) <= NOW() THEN 'running'
        ELSE 'scheduled'
    END,
    t.actual_start_time = CASE
        WHEN TIMESTAMP(s.schedule_date, s.departure_time) <= NOW()
        THEN TIMESTAMP(s.schedule_date, s.departure_time)
        ELSE NULL
    END,
    t.actual_end_time = CASE
        WHEN TIMESTAMP(s.schedule_date, s.arrival_time) < NOW()
        THEN TIMESTAMP(s.schedule_date, s.arrival_time)
        ELSE NULL
    END;

-- Two recorded delays so the delay analysis report has real data.
UPDATE trips
SET delay_minutes = 12,
    remarks = 'Ran 12 minutes behind schedule due to traffic near Nayandahalli Junction.'
WHERE schedule_id = 1;

UPDATE trips
SET delay_minutes = 18,
    remarks = 'Delayed by a rear suspension air pressure loss reported at Mulki Check Post.'
WHERE schedule_id = 6;

-- Passenger counts come from the ticketing data: online bookings in the
-- system plus passengers counted onboard by the conductor.
UPDATE trips
SET passenger_count = (
        SELECT COUNT(*)
        FROM bookings b
        WHERE b.schedule_id = trips.schedule_id
          AND b.booking_status IN ('confirmed', 'completed')
    ) + CASE WHEN trips.trip_status = 'completed' THEN 28 ELSE 0 END;

INSERT INTO bookings (id, booking_number, user_id, schedule_id, boarding_stop_id, destination_stop_id, seat_number, fare, booking_status, payment_status, booking_date, created_at) VALUES
(1, 'FLB-0001', 7, 1,  1,  6, 'A1', 380.00, 'confirmed',  'paid',     CURDATE(),                          NOW() - INTERVAL 3 DAY),
(2, 'FLB-0002', 8, 1,  2,  6, 'B3', 380.00, 'confirmed',  'paid',     CURDATE(),                          NOW() - INTERVAL 2 DAY),
(3, 'FLB-0003', 7, 5,  1,  6, 'A2', 380.00, 'confirmed',  'paid',     DATE_SUB(CURDATE(), INTERVAL 1 DAY), NOW() - INTERVAL 1 DAY),
(4, 'FLB-0004', 9, 3,  7, 11, 'A4', 150.00, 'confirmed',  'paid',     CURDATE(),                          NOW() - INTERVAL 1 DAY),
(5, 'FLB-0005', 9, 2,  2,  6, 'A5', 380.00, 'pending',    'unpaid',   CURDATE(),                          NOW() - INTERVAL 4 HOUR),
(6, 'FLB-0006', 8, 6,  7, 11, 'C2', 150.00, 'completed',  'paid',     DATE_SUB(CURDATE(), INTERVAL 1 DAY), NOW() - INTERVAL 2 DAY),
(7, 'FLB-0007', 7, 8,  1,  6, 'A1', 380.00, 'cancelled',  'refunded', DATE_SUB(CURDATE(), INTERVAL 2 DAY), NOW() - INTERVAL 5 DAY);

UPDATE bookings
SET booking_status = 'completed'
WHERE schedule_id IN (6, 7, 8, 9, 10) AND booking_status = 'confirmed';

INSERT INTO tickets (id, ticket_number, booking_id, qr_code, issued_at, status) VALUES
(1, 'TKT-0001', 1, CONCAT('FLTRA|TKT-0001|', SHA2(CONCAT('FLB-0001', 'fleetra-salt'), 256)), NOW() - INTERVAL 3 DAY, 'valid'),
(2, 'TKT-0002', 2, CONCAT('FLTRA|TKT-0002|', SHA2(CONCAT('FLB-0002', 'fleetra-salt'), 256)), NOW() - INTERVAL 2 DAY, 'valid'),
(3, 'TKT-0003', 3, CONCAT('FLTRA|TKT-0003|', SHA2(CONCAT('FLB-0003', 'fleetra-salt'), 256)), NOW() - INTERVAL 1 DAY, 'valid'),
(4, 'TKT-0004', 4, CONCAT('FLTRA|TKT-0004|', SHA2(CONCAT('FLB-0004', 'fleetra-salt'), 256)), NOW() - INTERVAL 1 DAY, 'valid'),
(5, 'TKT-0005', 6, CONCAT('FLTRA|TKT-0005|', SHA2(CONCAT('FLB-0006', 'fleetra-salt'), 256)), NOW() - INTERVAL 2 DAY, 'used');

INSERT INTO payments (id, booking_id, transaction_reference, payment_method, amount, status, payment_date) VALUES
(1, 1, 'TXN-2026-000101', 'upi',      380.00, 'paid',     NOW() - INTERVAL 3 DAY),
(2, 2, 'TXN-2026-000102', 'card',     380.00, 'paid',     NOW() - INTERVAL 2 DAY),
(3, 3, 'TXN-2026-000103', 'net_banking', 380.00, 'paid',  NOW() - INTERVAL 1 DAY),
(4, 4, 'TXN-2026-000104', 'wallet',   150.00, 'paid',     NOW() - INTERVAL 1 DAY),
(5, 6, 'TXN-2026-000105', 'upi',      150.00, 'paid',     NOW() - INTERVAL 2 DAY),
(6, 7, 'TXN-2026-000106', 'simulated',380.00, 'refunded', NOW() - INTERVAL 5 DAY);

-- Simulated breadcrumbs (source = 'simulated'). Real GPS devices post the
-- same rows with source = 'device' once hardware is connected.
INSERT INTO bus_locations (bus_id, trip_id, latitude, longitude, speed, heading, source, recorded_at) VALUES
(1, 1, 12.9776000, 77.5714000,  0.00,  45, 'simulated', NOW() - INTERVAL 35 MINUTE),
(1, 1, 12.9437000, 77.5202000, 42.50,  50, 'simulated', NOW() - INTERVAL 20 MINUTE),
(1, 1, 12.8691000, 77.4412000, 58.20,  62, 'simulated', NOW() - INTERVAL 8 MINUTE),
(2, 2, 12.7217000, 77.2800000, 55.40,  68, 'simulated', NOW() - INTERVAL 90 MINUTE),
(2, 2, 12.6514000, 77.2065000, 51.10,  70, 'simulated', NOW() - INTERVAL 40 MINUTE),
(3, 3, 13.3409000, 74.7421000,  0.00, 180, 'simulated', NOW() - INTERVAL 1 DAY);

INSERT INTO maintenance (id, bus_id, maintenance_type, description, service_date, next_service_date, odometer_reading, cost, service_provider, status) VALUES
(1, 1, 'routine',    'Full periodic service: engine oil, filters, coolant top-up and 40-point inspection.', DATE_SUB(CURDATE(), INTERVAL 20 DAY), DATE_ADD(CURDATE(), INTERVAL 10 DAY), 182000.00,  8500.00, 'Volvo Service Centre, Bengaluru', 'completed'),
(2, 4, 'repair',     'Gearbox overhaul after reported vibration at highway speed. Vehicle held in workshop.', DATE_SUB(CURDATE(), INTERVAL 2 DAY), DATE_ADD(CURDATE(), INTERVAL 28 DAY), 312000.00, 46500.00, 'Scania Authorised Workshop, Bengaluru', 'in_progress'),
(3, 2, 'tyre',       'Replacement of four rear tyres and wheel alignment correction.', DATE_SUB(CURDATE(), INTERVAL 45 DAY), DATE_SUB(CURDATE(), INTERVAL 5 DAY),  258900.00, 22000.00, 'Mahindra Tyre House, Bengaluru', 'completed'),
(4, 3, 'inspection', 'Annual fitness and emission inspection completed and certificate renewed.', DATE_SUB(CURDATE(), INTERVAL 10 DAY), DATE_ADD(CURDATE(), INTERVAL 80 DAY),  97500.00,  3200.00, 'Tata Motors Service, Bengaluru', 'completed'),
(5, 5, 'electrical', 'Battery bank replacement and alternator testing before return to service.', DATE_ADD(CURDATE(), INTERVAL 4 DAY), NULL, 421000.00,  7500.00, 'Eicher Care Point, Bengaluru', 'scheduled'),
(6, 1, 'brake',      'Brake pad and air-dryer replacement as per 185,000 km service plan.', DATE_ADD(CURDATE(), INTERVAL 9 DAY), NULL, 186500.00, 12000.00, 'Volvo Service Centre, Bengaluru', 'scheduled');

INSERT INTO incidents (id, trip_id, driver_id, bus_id, reported_by, incident_type, severity, description, latitude, longitude, status, reported_at, resolved_at) VALUES
(1, 1, 1, 1, 4, 'traffic',   'medium', 'Heavy congestion near Nayandahalli Junction. Trip is running approximately 12 minutes behind schedule. Passengers have been informed.', 12.9437000, 77.5202000, 'investigating', NOW() - INTERVAL 25 MINUTE, NULL),
(2, 6, 3, 3, 6, 'breakdown', 'high',   'Air pressure loss in the rear suspension detected at Mulki Check Post. Passengers transferred to a replacement vehicle and the bus was taken to the workshop.', 13.0891000, 74.7869000, 'resolved', TIMESTAMP(DATE_SUB(CURDATE(), INTERVAL 1 DAY), '09:12:00'), TIMESTAMP(DATE_SUB(CURDATE(), INTERVAL 1 DAY), '11:40:00'));

INSERT INTO notifications (user_id, title, message, notification_type, reference_id, is_read, created_at) VALUES
(1, 'Trip FLT-101 running late',        'Trip to Mysuru (R-01) is running 12 minutes behind schedule near Nayandahalli Junction.', 'delay',       1, 0, NOW() - INTERVAL 20 MINUTE),
(1, 'Maintenance due for FLT-102',      'Scheduled tyre service for FLT-102 is overdue since the last service date.',              'maintenance', 3, 0, NOW() - INTERVAL 5 HOUR),
(1, 'New booking FLB-0005',             'Meera Iyer created a pending booking for the 14:00 service on route R-01.',                'booking',     5, 1, NOW() - INTERVAL 4 HOUR),
(2, 'Fleet availability update',        'FLT-104 is in the workshop for a gearbox overhaul and is unavailable for assignment.',     'maintenance', 2, 0, NOW() - INTERVAL 1 DAY),
(3, 'Incident reported on trip #1',     'A traffic delay incident was reported by driver Suresh Kumar. Status: investigating.',      'emergency',   1, 0, NOW() - INTERVAL 25 MINUTE),
(7, 'Booking confirmed — FLB-0001',     'Your seat A1 on the 06:30 City Express from Majestic is confirmed. Ticket TKT-0001.',      'booking',     1, 0, NOW() - INTERVAL 3 DAY),
(7, 'Trip update — FLT-101',            'Your bus is running approximately 12 minutes late. Please arrive at the boarding point accordingly.', 'delay', 1, 0, NOW() - INTERVAL 18 MINUTE),
(7, 'Booking cancelled — FLB-0007',     'Your booking for FLB-0007 was cancelled and the fare has been refunded.',                   'booking',     7, 1, NOW() - INTERVAL 4 DAY),
(8, 'Booking confirmed — FLB-0002',     'Your seat B3 on the 06:30 City Express from Nayandahalli is confirmed. Ticket TKT-0002.',   'booking',     2, 1, NOW() - INTERVAL 2 DAY),
(9, 'Ticket ready — TKT-0004',          'Your ticket for the Coastal Link service to Udupi has been issued.',                        'booking',     4, 0, NOW() - INTERVAL 1 DAY);

INSERT INTO activity_logs (user_id, action, module, record_id, description, ip_address, created_at) VALUES
(1, 'Created bus FLT-103',        'buses',      3, 'Tata Starbus Urban 9/9 added to the fleet',            '127.0.0.1', NOW() - INTERVAL 40 DAY),
(1, 'Updated route R-02',         'routes',     2, 'Base fare revised to 150.00',                          '127.0.0.1', NOW() - INTERVAL 12 DAY),
(2, 'Created schedule #3',        'schedules',  3, 'Coastal Link 08:00 departure assigned to FLT-103',     '127.0.0.1', NOW() - INTERVAL 6 DAY),
(2, 'Assigned driver DRV-1003',   'drivers',    3, 'Vikram Singh assigned to bus FLT-103',                 '127.0.0.1', NOW() - INTERVAL 6 DAY),
(3, 'Updated trip #1 status',     'trips',      1, 'Trip status moved to running with a 12 minute delay',  '127.0.0.1', NOW() - INTERVAL 30 MINUTE),
(1, 'Created maintenance record', 'maintenance',2, 'Gearbox overhaul logged for FLT-104',                  '127.0.0.1', NOW() - INTERVAL 2 DAY),
(1, 'Sent announcement',          'notifications', NULL, 'Fleet availability update sent to transport managers', '127.0.0.1', NOW() - INTERVAL 1 DAY);

INSERT INTO settings (setting_key, setting_value, setting_group) VALUES
('company_name',            'Fleetra Transport Services',   'general'),
('support_email',           'support@fleetra.com',          'general'),
('support_phone',           '+91 80 4123 8899',             'general'),
('timezone',                'Asia/Kolkata',                 'general'),
('currency_symbol',         '₹',                            'general'),
('default_seat_layout',     '2x2',                          'booking'),
('booking_window_days',     '30',                           'booking'),
('cancellation_hours',      '4',                            'booking'),
('maintenance_warning_days','15',                           'maintenance'),
('tracking_source',         'simulated',                    'tracking'),
('tracking_refresh_seconds','15',                           'tracking'),
('sms_notifications',       '0',                            'notifications'),
('email_notifications',     '0',                            'notifications');

-- =====================================================================
--  VERIFICATION — quick row counts after import
-- =====================================================================
SELECT 'users' AS table_name, COUNT(*) AS rows_inserted FROM users
UNION ALL SELECT 'buses',         COUNT(*) FROM buses
UNION ALL SELECT 'drivers',       COUNT(*) FROM drivers
UNION ALL SELECT 'routes',        COUNT(*) FROM routes
UNION ALL SELECT 'stops',         COUNT(*) FROM stops
UNION ALL SELECT 'schedules',     COUNT(*) FROM schedules
UNION ALL SELECT 'trips',         COUNT(*) FROM trips
UNION ALL SELECT 'bookings',      COUNT(*) FROM bookings
UNION ALL SELECT 'tickets',       COUNT(*) FROM tickets
UNION ALL SELECT 'payments',      COUNT(*) FROM payments
UNION ALL SELECT 'maintenance',   COUNT(*) FROM maintenance
UNION ALL SELECT 'notifications', COUNT(*) FROM notifications
UNION ALL SELECT 'incidents',     COUNT(*) FROM incidents
UNION ALL SELECT 'activity_logs', COUNT(*) FROM activity_logs
UNION ALL SELECT 'settings',      COUNT(*) FROM settings;
