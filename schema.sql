-- ==================================================================
--  Dr Bakshi Clinic - complete database schema
--  MySQL / MariaDB  .  utf8mb4  .  InnoDB  .  22 tables
--  Generated 17 Sep 2026
--
--  Load into an empty database:
--     mysql -u USER -p DBNAME < schema.sql
--
--  You normally do NOT need this. The app creates every table by
--  itself the first time it runs. Keep this for phpMyAdmin, for
--  handing the project on, or for reviewing the design.
-- ==================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ------------------------------------------------------------------
-- patients
-- ------------------------------------------------------------------
DROP TABLE IF EXISTS `patients`;
CREATE TABLE `patients` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(120) NOT NULL,
  `age` int(11) DEFAULT NULL,
  `sex` varchar(10) DEFAULT NULL,
  `phone` varchar(30) NOT NULL,
  `abha` varchar(40) DEFAULT NULL,
  `city` varchar(80) DEFAULT NULL,
  `conditions` text DEFAULT NULL,
  `allergies` text DEFAULT NULL,
  `care` varchar(30) DEFAULT 'OPD',
  `risk` varchar(20) DEFAULT 'Low',
  `lang` varchar(20) DEFAULT 'English',
  `wa_consent` tinyint(4) DEFAULT 0,
  `consent_at` datetime DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `name` (`name`),
  KEY `phone` (`phone`)
) ENGINE=InnoDB AUTO_INCREMENT=11 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------
-- appointments
-- ------------------------------------------------------------------
DROP TABLE IF EXISTS `appointments`;
CREATE TABLE `appointments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `patient_id` int(11) NOT NULL,
  `appt_date` date NOT NULL,
  `appt_time` varchar(10) NOT NULL,
  `visit_type` varchar(40) DEFAULT 'New',
  `mode` varchar(30) DEFAULT 'In-clinic',
  `reason` text DEFAULT NULL,
  `status` varchar(20) DEFAULT 'Waiting',
  `token` varchar(20) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_appt_day_token` (`appt_date`,`token`),
  KEY `appt_date` (`appt_date`),
  KEY `patient_id` (`patient_id`),
  CONSTRAINT `fk_appt_pt` FOREIGN KEY (`patient_id`) REFERENCES `patients` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=9 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------
-- prescriptions
-- ------------------------------------------------------------------
DROP TABLE IF EXISTS `prescriptions`;
CREATE TABLE `prescriptions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `patient_id` int(11) NOT NULL,
  `rx_date` date NOT NULL,
  `diagnosis` text DEFAULT NULL,
  `vitals` text DEFAULT NULL,
  `meds` text DEFAULT NULL,
  `labs` text DEFAULT NULL,
  `advice` text DEFAULT NULL,
  `follow_up` varchar(20) DEFAULT NULL,
  `ink_file` varchar(255) DEFAULT NULL,
  `ink_mode` tinyint(4) DEFAULT 0,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `patient_id` (`patient_id`),
  KEY `rx_date` (`rx_date`),
  CONSTRAINT `fk_rx_pt` FOREIGN KEY (`patient_id`) REFERENCES `patients` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------
-- templates
-- ------------------------------------------------------------------
DROP TABLE IF EXISTS `templates`;
CREATE TABLE `templates` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(120) NOT NULL,
  `lang` varchar(20) NOT NULL DEFAULT 'English',
  `body` mediumtext NOT NULL,
  `is_default` tinyint(4) DEFAULT 0,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------
-- homecare
-- ------------------------------------------------------------------
DROP TABLE IF EXISTS `homecare`;
CREATE TABLE `homecare` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `patient_id` int(11) NOT NULL,
  `service` varchar(60) DEFAULT NULL,
  `addr` text DEFAULT NULL,
  `equipment` text DEFAULT NULL,
  `staff` text DEFAULT NULL,
  `started` date DEFAULT NULL,
  `rate` int(11) DEFAULT NULL,
  `note` text DEFAULT NULL,
  `status` varchar(20) DEFAULT 'Active',
  PRIMARY KEY (`id`),
  KEY `patient_id` (`patient_id`),
  CONSTRAINT `fk_hc_pt` FOREIGN KEY (`patient_id`) REFERENCES `patients` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------
-- wa_messages
-- ------------------------------------------------------------------
DROP TABLE IF EXISTS `wa_messages`;
CREATE TABLE `wa_messages` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `patient_id` int(11) NOT NULL,
  `rx_id` int(11) DEFAULT NULL,
  `phone` varchar(30) DEFAULT NULL,
  `lang` varchar(20) DEFAULT NULL,
  `body` mediumtext DEFAULT NULL,
  `driver` varchar(20) DEFAULT NULL,
  `status` varchar(20) DEFAULT 'Queued',
  `response` text DEFAULT NULL,
  `sent_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `patient_id` (`patient_id`),
  KEY `fk_wa_rx` (`rx_id`),
  CONSTRAINT `fk_wa_pt` FOREIGN KEY (`patient_id`) REFERENCES `patients` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_wa_rx` FOREIGN KEY (`rx_id`) REFERENCES `prescriptions` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------
-- payments
-- ------------------------------------------------------------------
DROP TABLE IF EXISTS `payments`;
CREATE TABLE `payments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `patient_id` int(11) NOT NULL,
  `rx_id` int(11) DEFAULT NULL,
  `pay_date` date NOT NULL,
  `item` varchar(160) DEFAULT NULL,
  `amount` int(11) NOT NULL DEFAULT 0,
  `paid` tinyint(4) NOT NULL DEFAULT 0,
  `mode` varchar(20) DEFAULT 'Cash',
  `note` text DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `pay_date` (`pay_date`),
  KEY `patient_id` (`patient_id`),
  KEY `fk_pay_rx` (`rx_id`),
  CONSTRAINT `fk_pay_pt` FOREIGN KEY (`patient_id`) REFERENCES `patients` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_pay_rx` FOREIGN KEY (`rx_id`) REFERENCES `prescriptions` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------
-- documents
-- ------------------------------------------------------------------
DROP TABLE IF EXISTS `documents`;
CREATE TABLE `documents` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `patient_id` int(11) NOT NULL,
  `kind` varchar(40) DEFAULT 'Report',
  `title` varchar(200) DEFAULT NULL,
  `file` varchar(255) NOT NULL,
  `doc_date` date DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `patient_id` (`patient_id`),
  CONSTRAINT `fk_doc_pt` FOREIGN KEY (`patient_id`) REFERENCES `patients` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------
-- pad_sessions
-- ------------------------------------------------------------------
DROP TABLE IF EXISTS `pad_sessions`;
CREATE TABLE `pad_sessions` (
  `token` varchar(64) NOT NULL,
  `patient_id` int(11) NOT NULL,
  `appt_id` int(11) DEFAULT NULL,
  `mode` varchar(20) DEFAULT 'write',
  `status` varchar(20) DEFAULT 'waiting',
  `result_file` varchar(255) DEFAULT NULL,
  `result_kind` varchar(20) DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `expires_at` datetime NOT NULL,
  PRIMARY KEY (`token`),
  KEY `fk_pad_pt` (`patient_id`),
  CONSTRAINT `fk_pad_pt` FOREIGN KEY (`patient_id`) REFERENCES `patients` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------
-- wa_replies
-- ------------------------------------------------------------------
DROP TABLE IF EXISTS `wa_replies`;
CREATE TABLE `wa_replies` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `patient_id` int(11) DEFAULT NULL,
  `phone` varchar(30) DEFAULT NULL,
  `body` text DEFAULT NULL,
  `intent` varchar(30) DEFAULT NULL,
  `message_id` varchar(100) DEFAULT NULL,
  `handled` tinyint(4) DEFAULT 0,
  `received_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_reply_message` (`message_id`),
  KEY `patient_id` (`patient_id`),
  CONSTRAINT `fk_rep_pt` FOREIGN KEY (`patient_id`) REFERENCES `patients` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------
-- users
-- ------------------------------------------------------------------
DROP TABLE IF EXISTS `users`;
CREATE TABLE `users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `username` varchar(60) NOT NULL,
  `pass_hash` varchar(255) NOT NULL,
  `name` varchar(120) DEFAULT NULL,
  `role` varchar(20) DEFAULT 'staff',
  `active` tinyint(4) DEFAULT 1,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------
-- audit
-- ------------------------------------------------------------------
DROP TABLE IF EXISTS `audit`;
CREATE TABLE `audit` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `username` varchar(60) DEFAULT NULL,
  `action` varchar(60) DEFAULT NULL,
  `entity` varchar(40) DEFAULT NULL,
  `entity_id` int(11) DEFAULT NULL,
  `detail` text DEFAULT NULL,
  `ip` varchar(45) DEFAULT NULL,
  `at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `at` (`at`)
) ENGINE=InnoDB AUTO_INCREMENT=64 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------
-- drugs
-- ------------------------------------------------------------------
DROP TABLE IF EXISTS `drugs`;
CREATE TABLE `drugs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(160) NOT NULL,
  `generic` varchar(120) DEFAULT NULL,
  `form` varchar(30) DEFAULT 'Tab',
  `strength` varchar(40) DEFAULT NULL,
  `def_dose` varchar(20) DEFAULT '1',
  `def_unit` varchar(20) DEFAULT 'tab',
  `def_when` varchar(30) DEFAULT 'After Food',
  `def_freq` varchar(20) DEFAULT 'OD',
  `def_duration` varchar(30) DEFAULT '5 days',
  `notes` text DEFAULT NULL,
  `uses` int(11) DEFAULT 0,
  `active` tinyint(4) DEFAULT 1,
  PRIMARY KEY (`id`),
  KEY `name` (`name`),
  KEY `generic` (`generic`)
) ENGINE=InnoDB AUTO_INCREMENT=21 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------
-- labs
-- ------------------------------------------------------------------
DROP TABLE IF EXISTS `labs`;
CREATE TABLE `labs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(120) NOT NULL,
  `grp` varchar(60) DEFAULT 'General',
  `uses` int(11) DEFAULT 0,
  `active` tinyint(4) DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `name` (`name`)
) ENGINE=InnoDB AUTO_INCREMENT=19 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------
-- consult_notes
-- ------------------------------------------------------------------
DROP TABLE IF EXISTS `consult_notes`;
CREATE TABLE `consult_notes` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `patient_id` int(11) NOT NULL,
  `appt_id` int(11) DEFAULT NULL,
  `rx_id` int(11) DEFAULT NULL,
  `transcript` mediumtext DEFAULT NULL,
  `turns` mediumtext DEFAULT NULL,
  `summary` mediumtext DEFAULT NULL,
  `ended_at` datetime DEFAULT NULL,
  `secs` int(11) DEFAULT 0,
  `disease` varchar(200) DEFAULT NULL,
  `dr_points` mediumtext DEFAULT NULL,
  `pt_points` mediumtext DEFAULT NULL,
  `lang` varchar(20) DEFAULT 'en-IN',
  `picked` text DEFAULT NULL,
  `started_at` datetime DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `patient_id` (`patient_id`),
  KEY `rx_id` (`rx_id`),
  KEY `disease` (`disease`),
  CONSTRAINT `fk_note_pt` FOREIGN KEY (`patient_id`) REFERENCES `patients` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_note_rx` FOREIGN KEY (`rx_id`) REFERENCES `prescriptions` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------
-- vitals
-- ------------------------------------------------------------------
DROP TABLE IF EXISTS `vitals`;
CREATE TABLE `vitals` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `patient_id` int(11) NOT NULL,
  `rx_id` int(11) DEFAULT NULL,
  `taken_on` date NOT NULL,
  `kind` varchar(16) NOT NULL,
  `val` varchar(24) NOT NULL,
  `num` decimal(6,2) DEFAULT NULL,
  `num2` decimal(6,2) DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `patient_kind_taken` (`patient_id`,`kind`,`taken_on`),
  KEY `kind_num` (`kind`,`num`),
  KEY `fk_vit_rx` (`rx_id`),
  CONSTRAINT `fk_vit_pt` FOREIGN KEY (`patient_id`) REFERENCES `patients` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_vit_rx` FOREIGN KEY (`rx_id`) REFERENCES `prescriptions` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------
-- rx_sets
-- ------------------------------------------------------------------
DROP TABLE IF EXISTS `rx_sets`;
CREATE TABLE `rx_sets` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(160) NOT NULL,
  `diagnosis` text DEFAULT NULL,
  `meds` text DEFAULT NULL,
  `labs` text DEFAULT NULL,
  `advice` text DEFAULT NULL,
  `uses` int(11) DEFAULT 0,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------
-- settings
-- ------------------------------------------------------------------
DROP TABLE IF EXISTS `settings`;
CREATE TABLE `settings` (
  `skey` varchar(60) NOT NULL,
  `sval` text DEFAULT NULL,
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`skey`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------
-- picklists
-- ------------------------------------------------------------------
DROP TABLE IF EXISTS `picklists`;
CREATE TABLE `picklists` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `kind` varchar(30) NOT NULL,
  `val` varchar(80) NOT NULL,
  `sort` int(11) DEFAULT 0,
  `active` tinyint(4) DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_kind_val` (`kind`,`val`),
  KEY `kind` (`kind`)
) ENGINE=InnoDB AUTO_INCREMENT=47 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------
-- brands
-- ------------------------------------------------------------------
DROP TABLE IF EXISTS `brands`;
CREATE TABLE `brands` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `brand` varchar(80) NOT NULL,
  `generic` varchar(80) NOT NULL,
  `active` tinyint(4) DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_brand` (`brand`),
  KEY `generic` (`generic`)
) ENGINE=InnoDB AUTO_INCREMENT=104 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------
-- diagnoses
-- ------------------------------------------------------------------
DROP TABLE IF EXISTS `diagnoses`;
CREATE TABLE `diagnoses` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(160) NOT NULL,
  `icd` varchar(20) DEFAULT NULL,
  `uses` int(11) DEFAULT 0,
  `active` tinyint(4) DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_dx` (`name`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------
-- advice_lines
-- ------------------------------------------------------------------
DROP TABLE IF EXISTS `advice_lines`;
CREATE TABLE `advice_lines` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `text` varchar(240) NOT NULL,
  `lang` varchar(20) DEFAULT 'English',
  `uses` int(11) DEFAULT 0,
  `active` tinyint(4) DEFAULT 1,
  PRIMARY KEY (`id`),
  KEY `lang` (`lang`)
) ENGINE=InnoDB AUTO_INCREMENT=14 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
