SET NAMES utf8mb4;
USE `voron`;

ALTER TABLE `collector_runs`
  CHANGE COLUMN `conflicts_count` `overlap_count` INT UNSIGNED NOT NULL DEFAULT 0;

ALTER TABLE `vehicle_status_history`
  ADD KEY `idx_vehicle_status_vehicle_observed` (`vehicle_id`, `observed_at`);

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
SET h.status_id = s.id;

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
SET v.current_status_id = s.id;

UPDATE `collector_runs` r
SET r.overlap_count = (
  SELECT COUNT(*)
  FROM `vehicle_status_history` h
  WHERE h.collector_run_id = r.id
    AND h.source_endpoint = 'free+busy'
);
