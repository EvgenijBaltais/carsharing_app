SET NAMES utf8mb4;
USE `carsharing_app`;

-- Единое безопасное обновление существующей базы для MySQL 5.7+ / HeidiSQL.
-- Скрипт можно выполнять повторно: недостающие элементы добавляются, существующие пропускаются.

SET @ddl = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'collector_runs' AND COLUMN_NAME = 'free_received') = 0,
  'ALTER TABLE `collector_runs` ADD COLUMN `free_received` INT UNSIGNED NOT NULL DEFAULT 0 AFTER `vehicles_received`',
  'SET @migration_noop = 1'
);
PREPARE migration_statement FROM @ddl; EXECUTE migration_statement; DEALLOCATE PREPARE migration_statement;

SET @ddl = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'collector_runs' AND COLUMN_NAME = 'busy_received') = 0,
  'ALTER TABLE `collector_runs` ADD COLUMN `busy_received` INT UNSIGNED NOT NULL DEFAULT 0 AFTER `free_received`',
  'SET @migration_noop = 1'
);
PREPARE migration_statement FROM @ddl; EXECUTE migration_statement; DEALLOCATE PREPARE migration_statement;

SET @ddl = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'collector_runs' AND COLUMN_NAME = 'overlap_count') = 0
  AND (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'collector_runs' AND COLUMN_NAME = 'conflicts_count') = 1,
  'ALTER TABLE `collector_runs` CHANGE COLUMN `conflicts_count` `overlap_count` INT UNSIGNED NOT NULL DEFAULT 0',
  'SET @migration_noop = 1'
);
PREPARE migration_statement FROM @ddl; EXECUTE migration_statement; DEALLOCATE PREPARE migration_statement;

SET @ddl = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'collector_runs' AND COLUMN_NAME = 'overlap_count') = 0,
  'ALTER TABLE `collector_runs` ADD COLUMN `overlap_count` INT UNSIGNED NOT NULL DEFAULT 0 AFTER `busy_received`',
  'SET @migration_noop = 1'
);
PREPARE migration_statement FROM @ddl; EXECUTE migration_statement; DEALLOCATE PREPARE migration_statement;

SET @ddl = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'collector_runs' AND COLUMN_NAME = 'latitude') = 0,
  'ALTER TABLE `collector_runs` ADD COLUMN `latitude` DECIMAL(10,7) NULL AFTER `overlap_count`',
  'SET @migration_noop = 1'
);
PREPARE migration_statement FROM @ddl; EXECUTE migration_statement; DEALLOCATE PREPARE migration_statement;

SET @ddl = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'collector_runs' AND COLUMN_NAME = 'longitude') = 0,
  'ALTER TABLE `collector_runs` ADD COLUMN `longitude` DECIMAL(10,7) NULL AFTER `latitude`',
  'SET @migration_noop = 1'
);
PREPARE migration_statement FROM @ddl; EXECUTE migration_statement; DEALLOCATE PREPARE migration_statement;

SET @ddl = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'vehicles' AND COLUMN_NAME = 'category_title') = 0,
  'ALTER TABLE `vehicles` ADD COLUMN `category_title` VARCHAR(255) NULL AFTER `title`',
  'SET @migration_noop = 1'
);
PREPARE migration_statement FROM @ddl; EXECUTE migration_statement; DEALLOCATE PREPARE migration_statement;

SET @ddl = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'vehicles' AND COLUMN_NAME = 'base_category_title') = 0,
  'ALTER TABLE `vehicles` ADD COLUMN `base_category_title` VARCHAR(255) NULL AFTER `category_title`',
  'SET @migration_noop = 1'
);
PREPARE migration_statement FROM @ddl; EXECUTE migration_statement; DEALLOCATE PREPARE migration_statement;

SET @ddl = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'vehicles' AND COLUMN_NAME = 'type_external_id') = 0,
  'ALTER TABLE `vehicles` ADD COLUMN `type_external_id` INT UNSIGNED NULL AFTER `base_category_title`',
  'SET @migration_noop = 1'
);
PREPARE migration_statement FROM @ddl; EXECUTE migration_statement; DEALLOCATE PREPARE migration_statement;

SET @ddl = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'vehicles' AND COLUMN_NAME = 'state_info') = 0,
  'ALTER TABLE `vehicles` ADD COLUMN `state_info` VARCHAR(255) NULL AFTER `source_status_code`',
  'SET @migration_noop = 1'
);
PREPARE migration_statement FROM @ddl; EXECUTE migration_statement; DEALLOCATE PREPARE migration_statement;

SET @ddl = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'vehicles' AND COLUMN_NAME = 'state_time_seconds') = 0,
  'ALTER TABLE `vehicles` ADD COLUMN `state_time_seconds` INT UNSIGNED NULL AFTER `state_info`',
  'SET @migration_noop = 1'
);
PREPARE migration_statement FROM @ddl; EXECUTE migration_statement; DEALLOCATE PREPARE migration_statement;

SET @ddl = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'vehicles' AND COLUMN_NAME = 'state_started_at') = 0,
  'ALTER TABLE `vehicles` ADD COLUMN `state_started_at` DATETIME NULL AFTER `state_time_seconds`',
  'SET @migration_noop = 1'
);
PREPARE migration_statement FROM @ddl; EXECUTE migration_statement; DEALLOCATE PREPARE migration_statement;

SET @ddl = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'vehicles' AND COLUMN_NAME = 'status_source_endpoint') = 0,
  'ALTER TABLE `vehicles` ADD COLUMN `status_source_endpoint` VARCHAR(80) NULL AFTER `state_started_at`',
  'SET @migration_noop = 1'
);
PREPARE migration_statement FROM @ddl; EXECUTE migration_statement; DEALLOCATE PREPARE migration_statement;

SET @ddl = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'vehicles' AND COLUMN_NAME = 'new_until') = 0,
  'ALTER TABLE `vehicles` ADD COLUMN `new_until` DATETIME NULL AFTER `last_seen_at`',
  'SET @migration_noop = 1'
);
PREPARE migration_statement FROM @ddl; EXECUTE migration_statement; DEALLOCATE PREPARE migration_statement;

SET @ddl = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'vehicle_status_history' AND COLUMN_NAME = 'state_info') = 0,
  'ALTER TABLE `vehicle_status_history` ADD COLUMN `state_info` VARCHAR(255) NULL AFTER `source_status_code`',
  'SET @migration_noop = 1'
);
PREPARE migration_statement FROM @ddl; EXECUTE migration_statement; DEALLOCATE PREPARE migration_statement;

SET @ddl = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'vehicle_status_history' AND COLUMN_NAME = 'state_time_seconds') = 0,
  'ALTER TABLE `vehicle_status_history` ADD COLUMN `state_time_seconds` INT UNSIGNED NULL AFTER `state_info`',
  'SET @migration_noop = 1'
);
PREPARE migration_statement FROM @ddl; EXECUTE migration_statement; DEALLOCATE PREPARE migration_statement;

SET @ddl = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'vehicle_status_history' AND COLUMN_NAME = 'state_started_at') = 0,
  'ALTER TABLE `vehicle_status_history` ADD COLUMN `state_started_at` DATETIME NULL AFTER `state_time_seconds`',
  'SET @migration_noop = 1'
);
PREPARE migration_statement FROM @ddl; EXECUTE migration_statement; DEALLOCATE PREPARE migration_statement;

SET @ddl = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'vehicle_status_history' AND COLUMN_NAME = 'source_endpoint') = 0,
  'ALTER TABLE `vehicle_status_history` ADD COLUMN `source_endpoint` VARCHAR(80) NULL AFTER `state_started_at`',
  'SET @migration_noop = 1'
);
PREPARE migration_statement FROM @ddl; EXECUTE migration_statement; DEALLOCATE PREPARE migration_statement;

SET @ddl = IF(
  (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'vehicle_status_history' AND INDEX_NAME = 'idx_vehicle_status_vehicle_observed') = 0,
  'ALTER TABLE `vehicle_status_history` ADD KEY `idx_vehicle_status_vehicle_observed` (`vehicle_id`, `observed_at`)',
  'SET @migration_noop = 1'
);
PREPARE migration_statement FROM @ddl; EXECUTE migration_statement; DEALLOCATE PREPARE migration_statement;

SET @ddl = IF(
  (SELECT COUNT(*) FROM `statuses` WHERE `slug` = 'repair') > 0
  AND (SELECT COUNT(*) FROM `statuses` WHERE `slug` = 'maintenance') = 0,
  'UPDATE `statuses` SET `slug` = ''maintenance'', `name` = ''На обслуживании'', `description` = ''stateInfo равен «На обслуживании»'', `sort_order` = 70 WHERE `slug` = ''repair''',
  'SET @migration_noop = 1'
);
PREPARE migration_statement FROM @ddl; EXECUTE migration_statement; DEALLOCATE PREPARE migration_statement;

INSERT INTO `statuses`
  (`source_code`, `slug`, `name`, `is_available`, `description`, `sort_order`)
VALUES
  (NULL, 'rented_fixed',  'Арендован (оплата до)', 0, 'stateInfo начинается с «Оплачено до»', 50),
  (NULL, 'rented_minute', 'Арендован (поминутно)', 0, 'stateInfo равен «Поминутный тариф»', 60),
  (NULL, 'maintenance',   'На обслуживании', 0, 'stateInfo равен «На обслуживании»', 70),
  (NULL, 'owner',         'У владельца', 0, 'stateInfo равен «У владельца»', 80),
  (NULL, 'busy_unknown',  'Занят, причина не указана', 0, 'Получен из busy_list, но stateInfo пуст', 90),
  (NULL, 'not_observed',  'Не обнаружен', 0, 'Автомобиль отсутствовал в успешном наблюдении', 100),
  (NULL, 'unknown',       'Неизвестен', 0, 'Неизвестный или ещё не сопоставленный статус', 110)
ON DUPLICATE KEY UPDATE
  `name` = VALUES(`name`),
  `is_available` = VALUES(`is_available`),
  `description` = VALUES(`description`),
  `sort_order` = VALUES(`sort_order`),
  `is_active` = 1;

CREATE TABLE IF NOT EXISTS `vehicle_status_events` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `vehicle_id` BIGINT UNSIGNED NOT NULL,
  `collector_run_id` BIGINT UNSIGNED NOT NULL,
  `from_status_id` SMALLINT UNSIGNED NOT NULL,
  `to_status_id` SMALLINT UNSIGNED NOT NULL,
  `event_type` VARCHAR(50) NOT NULL,
  `detected_at` DATETIME NOT NULL,
  `state_started_at` DATETIME NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_vehicle_event_run` (`vehicle_id`, `collector_run_id`),
  KEY `idx_vehicle_events_detected` (`detected_at`),
  KEY `idx_vehicle_events_type_detected` (`event_type`, `detected_at`),
  KEY `idx_vehicle_events_vehicle_detected` (`vehicle_id`, `detected_at`),
  CONSTRAINT `fk_vehicle_events_vehicle` FOREIGN KEY (`vehicle_id`) REFERENCES `vehicles` (`id`) ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT `fk_vehicle_events_run` FOREIGN KEY (`collector_run_id`) REFERENCES `collector_runs` (`id`) ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT `fk_vehicle_events_from_status` FOREIGN KEY (`from_status_id`) REFERENCES `statuses` (`id`) ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT `fk_vehicle_events_to_status` FOREIGN KEY (`to_status_id`) REFERENCES `statuses` (`id`) ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

UPDATE `vehicle_status_history` h
JOIN `statuses` s ON s.slug = CASE
  WHEN h.state_info LIKE 'Оплачено до%' THEN 'rented_fixed'
  WHEN h.state_info = 'Поминутный тариф' THEN 'rented_minute'
  WHEN h.state_info = 'На обслуживании' THEN 'maintenance'
  WHEN h.state_info = 'У владельца' THEN 'owner'
  WHEN h.source_endpoint = 'free' THEN 'free'
  WHEN h.source_endpoint LIKE '%busy%' AND COALESCE(h.state_info, '') = '' THEN 'busy_unknown'
  ELSE 'unknown'
END
SET h.status_id = s.id
WHERE h.id IS NOT NULL;

UPDATE `vehicles` v
JOIN `statuses` s ON s.slug = CASE
  WHEN v.state_info LIKE 'Оплачено до%' THEN 'rented_fixed'
  WHEN v.state_info = 'Поминутный тариф' THEN 'rented_minute'
  WHEN v.state_info = 'На обслуживании' THEN 'maintenance'
  WHEN v.state_info = 'У владельца' THEN 'owner'
  WHEN v.status_source_endpoint = 'free' THEN 'free'
  WHEN v.status_source_endpoint LIKE '%busy%' AND COALESCE(v.state_info, '') = '' THEN 'busy_unknown'
  ELSE 'unknown'
END
SET v.current_status_id = s.id
WHERE v.id IS NOT NULL;

UPDATE `collector_runs` r
SET r.overlap_count = (
  SELECT COUNT(*)
  FROM `vehicle_status_history` h
  WHERE h.collector_run_id = r.id
    AND h.source_endpoint = 'free+busy'
)
WHERE r.id IS NOT NULL;

CREATE TABLE IF NOT EXISTS `update_intervals` (
  `code` VARCHAR(50) NOT NULL,
  `title` VARCHAR(150) NOT NULL,
  `interval_minutes` INT UNSIGNED NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `update_intervals` (`code`, `title`, `interval_minutes`)
VALUES
  ('vehicle_statuses', 'Статусы автомобилей', 30),
  ('vehicle_catalog', 'Модели и марки автомобилей', 1440);

CREATE TABLE IF NOT EXISTS `schema_migrations` (
  `migration` VARCHAR(190) NOT NULL,
  `checksum` CHAR(64) NOT NULL,
  `applied_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`migration`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `schema_migrations` (`migration`, `checksum`)
VALUES
  ('001_collector', 'eaa5f4a50dd0a5958323700ee119679b3f8001264ee209585883fb3cbf5b075c'),
  ('002_status_tracking', '2aeb660118ec4d24f3cd69ed193d7e50cf3f2d854dc4ec807232a5256e9dacf7'),
  ('003_events', '0e137d5b328774c376fdd2dda8ebe7e5fb0b7be528e12dfcae74d6a5b8d77db0'),
  ('004_new_vehicle_badge', '4465b8b4fc5cc67e9e40f61dcaeecb8bb8d80fbb5d8e2693c2cdc11095530337'),
  ('005_update_intervals', '3ca84e557d932b2f0fd9a7938316875cf1f7f96059f64d8f93b0fe7ce630534e');

SELECT 'Обновление базы carsharing_app завершено' AS result;
