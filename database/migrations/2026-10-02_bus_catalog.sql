-- =====================================================================
--  Fleetra — Bus catalog migration
--  database/migrations/2026-10-02_bus_catalog.sql
-- ---------------------------------------------------------------------
--  Run this ONCE on a database that was created before the Excel catalog
--  feature was added. A fresh import of database/fleetra_db.sql already
--  contains everything below, so this file is only needed when you want
--  to keep existing data.
--
--  It is safe to re-run: new tables use IF NOT EXISTS, and each column is
--  only added when it is actually missing (checked against
--  information_schema, which works on both MySQL 5.7+ and MariaDB).
-- =====================================================================

SET NAMES utf8mb4;

-- ---- New tables -----------------------------------------------------

CREATE TABLE IF NOT EXISTS bus_operators (
    id                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
    operator_code       VARCHAR(24)  NOT NULL COMMENT 'Short code from the workbook, e.g. WBTC',
    operator_name       VARCHAR(160) NOT NULL,
    operator_type       VARCHAR(60)  DEFAULT NULL COMMENT 'State Transport, Private, ...',
    state               VARCHAR(120) DEFAULT NULL,
    headquarters        VARCHAR(120) DEFAULT NULL,
    website             VARCHAR(200) DEFAULT NULL,
    source_url          VARCHAR(255) DEFAULT NULL,
    verification_status VARCHAR(40)  NOT NULL DEFAULT 'Needs Verification',
    notes               VARCHAR(500) DEFAULT NULL,
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_bus_operators_code (operator_code),
    UNIQUE KEY uq_bus_operators_name (operator_name),
    KEY idx_bus_operators_state (state)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS data_import_runs (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    source_file     VARCHAR(255) NOT NULL,
    source_label    VARCHAR(60)  DEFAULT NULL,
    terminals_found INT UNSIGNED NOT NULL DEFAULT 0,
    cities_found    INT UNSIGNED NOT NULL DEFAULT 0,
    operators_found INT UNSIGNED NOT NULL DEFAULT 0,
    routes_found    INT UNSIGNED NOT NULL DEFAULT 0,
    stops_found     INT UNSIGNED NOT NULL DEFAULT 0,
    summary         VARCHAR(500) DEFAULT NULL,
    imported_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_import_runs_date (imported_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---- New routes columns --------------------------------------------
-- Each statement no-ops when the column already exists.

SET @db := DATABASE();
SET @sql := '';

SELECT IF(
    COUNT(*) = 0,
    'ALTER TABLE routes ADD COLUMN operator_name VARCHAR(160) DEFAULT NULL',
    'DO 0'
) INTO @sql
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'routes' AND COLUMN_NAME = 'operator_name';
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SELECT IF(
    COUNT(*) = 0,
    'ALTER TABLE routes ADD COLUMN route_type VARCHAR(40) DEFAULT NULL',
    'DO 0'
) INTO @sql
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'routes' AND COLUMN_NAME = 'route_type';
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SELECT IF(
    COUNT(*) = 0,
    'ALTER TABLE routes ADD COLUMN service_type VARCHAR(60) DEFAULT NULL',
    'DO 0'
) INTO @sql
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'routes' AND COLUMN_NAME = 'service_type';
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SELECT IF(
    COUNT(*) = 0,
    'ALTER TABLE routes ADD COLUMN origin_terminal_ref VARCHAR(48) DEFAULT NULL',
    'DO 0'
) INTO @sql
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'routes' AND COLUMN_NAME = 'origin_terminal_ref';
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SELECT IF(
    COUNT(*) = 0,
    'ALTER TABLE routes ADD COLUMN destination_terminal_ref VARCHAR(48) DEFAULT NULL',
    'DO 0'
) INTO @sql
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'routes' AND COLUMN_NAME = 'destination_terminal_ref';
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SELECT IF(
    COUNT(*) = 0,
    'ALTER TABLE routes ADD COLUMN origin_city VARCHAR(120) DEFAULT NULL',
    'DO 0'
) INTO @sql
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'routes' AND COLUMN_NAME = 'origin_city';
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SELECT IF(
    COUNT(*) = 0,
    'ALTER TABLE routes ADD COLUMN destination_city VARCHAR(120) DEFAULT NULL',
    'DO 0'
) INTO @sql
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'routes' AND COLUMN_NAME = 'destination_city';
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SELECT IF(
    COUNT(*) = 0,
    'ALTER TABLE routes ADD COLUMN external_ref VARCHAR(48) DEFAULT NULL',
    'DO 0'
) INTO @sql
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'routes' AND COLUMN_NAME = 'external_ref';
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SELECT IF(
    COUNT(*) = 0,
    'ALTER TABLE routes ADD COLUMN data_source VARCHAR(16) NOT NULL DEFAULT ''manual''',
    'DO 0'
) INTO @sql
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'routes' AND COLUMN_NAME = 'data_source';
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SELECT IF(
    COUNT(*) = 0,
    'ALTER TABLE routes ADD UNIQUE KEY uq_routes_external_ref (external_ref)',
    'DO 0'
) INTO @sql
FROM information_schema.STATISTICS
WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'routes' AND INDEX_NAME = 'uq_routes_external_ref';
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SELECT IF(
    COUNT(*) = 0,
    'ALTER TABLE routes ADD INDEX idx_routes_origin_city (origin_city)',
    'DO 0'
) INTO @sql
FROM information_schema.STATISTICS
WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'routes' AND INDEX_NAME = 'idx_routes_origin_city';
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SELECT IF(
    COUNT(*) = 0,
    'ALTER TABLE routes ADD INDEX idx_routes_destination_city (destination_city)',
    'DO 0'
) INTO @sql
FROM information_schema.STATISTICS
WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'routes' AND INDEX_NAME = 'idx_routes_destination_city';
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SELECT IF(
    COUNT(*) = 0,
    'ALTER TABLE routes ADD INDEX idx_routes_data_source (data_source)',
    'DO 0'
) INTO @sql
FROM information_schema.STATISTICS
WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'routes' AND INDEX_NAME = 'idx_routes_data_source';
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- Now import the catalog data itself:
--   php tools/import_excel.php --apply
-- or import database/excel_bus_catalog.sql in phpMyAdmin.
