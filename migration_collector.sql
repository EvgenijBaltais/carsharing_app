SET NAMES utf8mb4;
USE `voron`;

ALTER TABLE `collector_runs`
  ADD COLUMN `free_received` INT UNSIGNED NOT NULL DEFAULT 0 AFTER `vehicles_received`,
  ADD COLUMN `busy_received` INT UNSIGNED NOT NULL DEFAULT 0 AFTER `free_received`,
  ADD COLUMN `conflicts_count` INT UNSIGNED NOT NULL DEFAULT 0 AFTER `busy_received`,
  ADD COLUMN `latitude` DECIMAL(10,7) NULL AFTER `conflicts_count`,
  ADD COLUMN `longitude` DECIMAL(10,7) NULL AFTER `latitude`;

ALTER TABLE `vehicles`
  ADD COLUMN `category_title` VARCHAR(255) NULL AFTER `title`,
  ADD COLUMN `base_category_title` VARCHAR(255) NULL AFTER `category_title`,
  ADD COLUMN `type_external_id` INT UNSIGNED NULL AFTER `base_category_title`;
