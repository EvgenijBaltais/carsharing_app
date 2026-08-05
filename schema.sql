SET NAMES utf8mb4;

CREATE DATABASE IF NOT EXISTS `carsharing_app`
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE `carsharing_app`;

CREATE TABLE IF NOT EXISTS `schema_migrations` (
  `migration` VARCHAR(190) NOT NULL,
  `checksum` CHAR(64) NOT NULL,
  `applied_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`migration`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `vehicle_types` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `external_id` INT UNSIGNED NULL,
  `name` VARCHAR(100) NOT NULL,
  `normalized_name` VARCHAR(100) NOT NULL,
  `slug` VARCHAR(100) NOT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_vehicle_types_external_id` (`external_id`),
  UNIQUE KEY `uq_vehicle_types_normalized_name` (`normalized_name`),
  UNIQUE KEY `uq_vehicle_types_slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `brands` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(100) NOT NULL,
  `normalized_name` VARCHAR(100) NOT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_brands_normalized_name` (`normalized_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `vehicle_type_brands` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `vehicle_type_id` INT UNSIGNED NOT NULL,
  `brand_id` INT UNSIGNED NOT NULL,
  `external_id` INT UNSIGNED NULL,
  `source_mode` VARCHAR(30) NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_vehicle_type_brand` (`vehicle_type_id`, `brand_id`),
  UNIQUE KEY `uq_vehicle_type_brands_external_id` (`external_id`),
  KEY `idx_vehicle_type_brands_brand_id` (`brand_id`),
  CONSTRAINT `fk_vehicle_type_brands_type`
    FOREIGN KEY (`vehicle_type_id`) REFERENCES `vehicle_types` (`id`)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT `fk_vehicle_type_brands_brand`
    FOREIGN KEY (`brand_id`) REFERENCES `brands` (`id`)
    ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `models` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `brand_id` INT UNSIGNED NOT NULL,
  `name` VARCHAR(150) NOT NULL,
  `normalized_name` VARCHAR(150) NOT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_models_brand_name` (`brand_id`, `normalized_name`),
  CONSTRAINT `fk_models_brand`
    FOREIGN KEY (`brand_id`) REFERENCES `brands` (`id`)
    ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `vehicle_type_models` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `vehicle_type_brand_id` INT UNSIGNED NOT NULL,
  `model_id` INT UNSIGNED NOT NULL,
  `external_id` INT UNSIGNED NULL,
  `source_mode` VARCHAR(30) NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_vehicle_type_model` (`vehicle_type_brand_id`, `model_id`),
  UNIQUE KEY `uq_vehicle_type_models_external_id` (`external_id`),
  KEY `idx_vehicle_type_models_model_id` (`model_id`),
  CONSTRAINT `fk_vehicle_type_models_type_brand`
    FOREIGN KEY (`vehicle_type_brand_id`) REFERENCES `vehicle_type_brands` (`id`)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT `fk_vehicle_type_models_model`
    FOREIGN KEY (`model_id`) REFERENCES `models` (`id`)
    ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `statuses` (
  `id` SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `source_code` SMALLINT NULL,
  `slug` VARCHAR(50) NOT NULL,
  `name` VARCHAR(100) NOT NULL,
  `is_available` TINYINT(1) NOT NULL DEFAULT 0,
  `description` VARCHAR(255) NULL,
  `sort_order` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_statuses_source_code` (`source_code`),
  UNIQUE KEY `uq_statuses_slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `update_intervals` (
  `code` VARCHAR(50) NOT NULL,
  `title` VARCHAR(150) NOT NULL,
  `interval_minutes` INT UNSIGNED NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `collector_runs` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `endpoint` VARCHAR(255) NULL,
  `started_at` DATETIME NOT NULL,
  `finished_at` DATETIME NULL,
  `run_status` VARCHAR(20) NOT NULL DEFAULT 'running',
  `http_status` SMALLINT UNSIGNED NULL,
  `vehicles_received` INT UNSIGNED NOT NULL DEFAULT 0,
  `free_received` INT UNSIGNED NOT NULL DEFAULT 0,
  `busy_received` INT UNSIGNED NOT NULL DEFAULT 0,
  `overlap_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `latitude` DECIMAL(10,7) NULL,
  `longitude` DECIMAL(10,7) NULL,
  `error_message` TEXT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_collector_runs_started_at` (`started_at`),
  KEY `idx_collector_runs_status` (`run_status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `vehicles` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `external_id` BIGINT UNSIGNED NOT NULL,
  `vehicle_type_model_id` INT UNSIGNED NULL,
  `title` VARCHAR(255) NULL,
  `category_title` VARCHAR(255) NULL,
  `base_category_title` VARCHAR(255) NULL,
  `type_external_id` INT UNSIGNED NULL,
  `image_url` VARCHAR(500) NULL,
  `year` SMALLINT UNSIGNED NULL,
  `fuel_level` SMALLINT UNSIGNED NULL,
  `latitude` DECIMAL(10,7) NULL,
  `longitude` DECIMAL(10,7) NULL,
  `current_status_id` SMALLINT UNSIGNED NULL,
  `source_status_code` SMALLINT NULL,
  `state_info` VARCHAR(255) NULL,
  `state_time_seconds` INT UNSIGNED NULL,
  `state_started_at` DATETIME NULL,
  `status_source_endpoint` VARCHAR(80) NULL,
  `in_garage` TINYINT(1) NULL,
  `service_mode` SMALLINT NULL,
  `is_allocated` TINYINT(1) NULL,
  `first_seen_at` DATETIME NOT NULL,
  `last_seen_at` DATETIME NOT NULL,
  `new_until` DATETIME NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_vehicles_external_id` (`external_id`),
  KEY `idx_vehicles_type_model` (`vehicle_type_model_id`),
  KEY `idx_vehicles_current_status` (`current_status_id`),
  KEY `idx_vehicles_last_seen_at` (`last_seen_at`),
  CONSTRAINT `fk_vehicles_type_model`
    FOREIGN KEY (`vehicle_type_model_id`) REFERENCES `vehicle_type_models` (`id`)
    ON UPDATE CASCADE ON DELETE SET NULL,
  CONSTRAINT `fk_vehicles_current_status`
    FOREIGN KEY (`current_status_id`) REFERENCES `statuses` (`id`)
    ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `vehicle_status_history` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `vehicle_id` BIGINT UNSIGNED NOT NULL,
  `collector_run_id` BIGINT UNSIGNED NOT NULL,
  `status_id` SMALLINT UNSIGNED NULL,
  `source_status_code` SMALLINT NULL,
  `state_info` VARCHAR(255) NULL,
  `state_time_seconds` INT UNSIGNED NULL,
  `state_started_at` DATETIME NULL,
  `source_endpoint` VARCHAR(80) NULL,
  `in_garage` TINYINT(1) NULL,
  `service_mode` SMALLINT NULL,
  `is_allocated` TINYINT(1) NULL,
  `latitude` DECIMAL(10,7) NULL,
  `longitude` DECIMAL(10,7) NULL,
  `observed_at` DATETIME NOT NULL,
  `raw_payload_hash` CHAR(64) NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_vehicle_status_run` (`vehicle_id`, `collector_run_id`),
  KEY `idx_vehicle_status_observed_at` (`observed_at`),
  KEY `idx_vehicle_status_status_id` (`status_id`),
  KEY `idx_vehicle_status_run_id` (`collector_run_id`),
  KEY `idx_vehicle_status_vehicle_observed` (`vehicle_id`, `observed_at`),
  CONSTRAINT `fk_vehicle_status_vehicle`
    FOREIGN KEY (`vehicle_id`) REFERENCES `vehicles` (`id`)
    ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT `fk_vehicle_status_run`
    FOREIGN KEY (`collector_run_id`) REFERENCES `collector_runs` (`id`)
    ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT `fk_vehicle_status_status`
    FOREIGN KEY (`status_id`) REFERENCES `statuses` (`id`)
    ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
  CONSTRAINT `fk_vehicle_events_vehicle`
    FOREIGN KEY (`vehicle_id`) REFERENCES `vehicles` (`id`)
    ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT `fk_vehicle_events_run`
    FOREIGN KEY (`collector_run_id`) REFERENCES `collector_runs` (`id`)
    ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT `fk_vehicle_events_from_status`
    FOREIGN KEY (`from_status_id`) REFERENCES `statuses` (`id`)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT `fk_vehicle_events_to_status`
    FOREIGN KEY (`to_status_id`) REFERENCES `statuses` (`id`)
    ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `statuses`
  (`source_code`, `slug`, `name`, `is_available`, `description`, `sort_order`)
VALUES
  (0,    'free',         'Свободен',          1, 'Точный код STATUS_FREE из APK', 10),
  (1,    'occupied',     'Занят',             0, 'Точный код STATUS_OCCUPIED из APK', 20),
  (12,   'reserved',     'Зарезервирован',    0, 'Точный код STATUS_RESERVED из APK', 30),
  (4,    'unavailable',  'Недоступен',        0, 'Точный код STATUS_UNAVAILABLE из APK', 40),
  (NULL, 'rented_fixed', 'Арендован (оплата до)', 0, 'stateInfo начинается с «Оплачено до»', 50),
  (NULL, 'rented_minute','Арендован (поминутно)', 0, 'stateInfo равен «Поминутный тариф»', 60),
  (NULL, 'maintenance',  'На обслуживании',   0, 'stateInfo равен «На обслуживании»', 70),
  (NULL, 'owner',        'У владельца',        0, 'stateInfo равен «У владельца»', 80),
  (NULL, 'busy_unknown', 'Занят, причина не указана', 0, 'Получен из busy_list, но stateInfo пуст', 90),
  (NULL, 'not_observed', 'Не обнаружен',      0, 'Автомобиль отсутствовал в успешном наблюдении', 100),
  (NULL, 'unknown',      'Неизвестен',         0, 'Неизвестный или ещё не сопоставленный статус', 110)
ON DUPLICATE KEY UPDATE
  `name` = VALUES(`name`),
  `is_available` = VALUES(`is_available`),
  `description` = VALUES(`description`),
  `sort_order` = VALUES(`sort_order`),
  `is_active` = 1;

INSERT IGNORE INTO `update_intervals` (`code`, `title`, `interval_minutes`)
VALUES
  ('vehicle_statuses', 'Статусы автомобилей', 30),
  ('vehicle_catalog', 'Модели и марки автомобилей', 1440);
