SET NAMES utf8mb4;
USE `carsharing_app`;

ALTER TABLE `vehicles`
  ADD COLUMN `new_until` DATETIME NULL AFTER `last_seen_at`;
