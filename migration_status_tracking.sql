SET NAMES utf8mb4;
USE `carsharing_app`;

ALTER TABLE `vehicles`
  ADD COLUMN `state_info` VARCHAR(255) NULL AFTER `source_status_code`,
  ADD COLUMN `state_time_seconds` INT UNSIGNED NULL AFTER `state_info`,
  ADD COLUMN `state_started_at` DATETIME NULL AFTER `state_time_seconds`,
  ADD COLUMN `status_source_endpoint` VARCHAR(80) NULL AFTER `state_started_at`;

ALTER TABLE `vehicle_status_history`
  ADD COLUMN `state_info` VARCHAR(255) NULL AFTER `source_status_code`,
  ADD COLUMN `state_time_seconds` INT UNSIGNED NULL AFTER `state_info`,
  ADD COLUMN `state_started_at` DATETIME NULL AFTER `state_time_seconds`,
  ADD COLUMN `source_endpoint` VARCHAR(80) NULL AFTER `state_started_at`;

UPDATE `statuses`
SET `slug` = 'maintenance',
    `name` = 'На обслуживании',
    `description` = 'stateInfo равен «На обслуживании»',
    `sort_order` = 70
WHERE `slug` = 'repair';

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
