SET NAMES utf8mb4;
USE `carsharing_app`;

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
