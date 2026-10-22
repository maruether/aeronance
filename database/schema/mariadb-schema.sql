/*M!999999\- enable the sandbox mode */ 
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*M!100616 SET @OLD_NOTE_VERBOSITY=@@NOTE_VERBOSITY, NOTE_VERBOSITY=0 */;
DROP TABLE IF EXISTS `activity_log`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `activity_log` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `log_name` varchar(255) DEFAULT NULL,
  `description` text NOT NULL,
  `subject_type` varchar(255) DEFAULT NULL,
  `subject_id` bigint(20) unsigned DEFAULT NULL,
  `event` varchar(255) DEFAULT NULL,
  `causer_type` varchar(255) DEFAULT NULL,
  `causer_id` bigint(20) unsigned DEFAULT NULL,
  `attribute_changes` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`attribute_changes`)),
  `properties` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`properties`)),
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `subject` (`subject_type`,`subject_id`),
  KEY `causer` (`causer_type`,`causer_id`),
  KEY `activity_log_log_name_index` (`log_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `aircraft`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `aircraft` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `registration` varchar(32) NOT NULL,
  `model` varchar(96) NOT NULL,
  `aircraft_type_id` bigint(20) unsigned DEFAULT NULL,
  `manufacturer` varchar(96) DEFAULT NULL,
  `serial_number` varchar(96) DEFAULT NULL,
  `year_built` smallint(5) unsigned DEFAULT NULL,
  `airframe_constructions` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`airframe_constructions`)),
  `propulsion` varchar(16) DEFAULT NULL,
  `holder_id` bigint(20) unsigned DEFAULT NULL,
  `optional_counters` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`optional_counters`)),
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `in_service_since` date DEFAULT NULL,
  `onboarded_at` date DEFAULT NULL,
  `note` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `aircraft_registration_unique` (`registration`),
  KEY `aircraft_holder_id_foreign` (`holder_id`),
  KEY `aircraft_is_active_index` (`is_active`),
  KEY `aircraft_aircraft_type_id_foreign` (`aircraft_type_id`),
  CONSTRAINT `aircraft_aircraft_type_id_foreign` FOREIGN KEY (`aircraft_type_id`) REFERENCES `aircraft_types` (`id`) ON DELETE SET NULL,
  CONSTRAINT `aircraft_holder_id_foreign` FOREIGN KEY (`holder_id`) REFERENCES `holders` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `aircraft_documents`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `aircraft_documents` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `aircraft_id` bigint(20) unsigned NOT NULL,
  `type` varchar(32) NOT NULL,
  `title` varchar(160) NOT NULL,
  `reference` varchar(128) DEFAULT NULL,
  `issued_at` date DEFAULT NULL,
  `valid_until` date DEFAULT NULL,
  `issued_by` varchar(160) DEFAULT NULL,
  `link` varchar(500) DEFAULT NULL,
  `note` text DEFAULT NULL,
  `user_id` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `aircraft_documents_user_id_foreign` (`user_id`),
  KEY `aircraft_documents_aircraft_id_type_index` (`aircraft_id`,`type`),
  KEY `aircraft_documents_type_index` (`type`),
  KEY `aircraft_documents_valid_until_index` (`valid_until`),
  CONSTRAINT `aircraft_documents_aircraft_id_foreign` FOREIGN KEY (`aircraft_id`) REFERENCES `aircraft` (`id`) ON DELETE CASCADE,
  CONSTRAINT `aircraft_documents_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `aircraft_type_certificates`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `aircraft_type_certificates` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `aircraft_type_id` bigint(20) unsigned NOT NULL,
  `number` varchar(64) NOT NULL,
  `authority` varchar(16) DEFAULT NULL,
  `data_sheet_url` text DEFAULT NULL,
  `is_primary` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `aircraft_type_certificates_unique` (`aircraft_type_id`,`number`,`deleted_at`),
  KEY `aircraft_type_certificates_number_index` (`number`),
  KEY `aircraft_type_certificates_authority_index` (`authority`),
  KEY `aircraft_type_certificates_is_primary_index` (`is_primary`),
  CONSTRAINT `aircraft_type_certificates_aircraft_type_id_foreign` FOREIGN KEY (`aircraft_type_id`) REFERENCES `aircraft_types` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `aircraft_types`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `aircraft_types` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `designation` varchar(96) NOT NULL,
  `manufacturer` varchar(160) DEFAULT NULL,
  `sheet_variant` varchar(16) DEFAULT NULL,
  `undercarriage` varchar(24) DEFAULT NULL,
  `type_support` varchar(160) DEFAULT NULL,
  `without_type_support` tinyint(1) NOT NULL DEFAULT 0,
  `type_certificate` varchar(64) DEFAULT NULL,
  `certificate_authority` varchar(16) DEFAULT NULL,
  `data_sheet_url` text DEFAULT NULL,
  `data_sheet_checked_at` date DEFAULT NULL,
  `directive_overview_url` text DEFAULT NULL,
  `source` varchar(64) NOT NULL DEFAULT 'manual',
  `note` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `aircraft_types_designation_unique` (`designation`),
  KEY `aircraft_types_type_certificate_index` (`type_certificate`),
  KEY `aircraft_types_certificate_authority_index` (`certificate_authority`),
  KEY `aircraft_types_source_index` (`source`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `airworthiness_reviews`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `airworthiness_reviews` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `aircraft_id` bigint(20) unsigned NOT NULL,
  `certificate_reference` varchar(128) DEFAULT NULL,
  `issued_at` date NOT NULL,
  `valid_until` date NOT NULL,
  `issued_by_name` varchar(160) DEFAULT NULL,
  `issued_by_approval` varchar(64) DEFAULT NULL,
  `user_id` bigint(20) unsigned DEFAULT NULL,
  `note` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `airworthiness_reviews_aircraft_id_foreign` (`aircraft_id`),
  KEY `airworthiness_reviews_user_id_foreign` (`user_id`),
  KEY `airworthiness_reviews_valid_until_index` (`valid_until`),
  CONSTRAINT `airworthiness_reviews_aircraft_id_foreign` FOREIGN KEY (`aircraft_id`) REFERENCES `aircraft` (`id`) ON DELETE CASCADE,
  CONSTRAINT `airworthiness_reviews_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `break_glass_records`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `break_glass_records` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `target_email` varchar(255) NOT NULL,
  `target_user_id` bigint(20) unsigned DEFAULT NULL,
  `shell_user` varchar(128) DEFAULT NULL,
  `origin_ip` varchar(45) DEFAULT NULL,
  `hostname` varchar(255) DEFAULT NULL,
  `reason` text NOT NULL,
  `granted_at` timestamp NOT NULL,
  `expires_at` timestamp NULL DEFAULT NULL,
  `revoked_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `break_glass_records_granted_at_index` (`granted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `cache`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `cache` (
  `key` varchar(255) NOT NULL,
  `value` mediumtext NOT NULL,
  `expiration` bigint(20) NOT NULL,
  PRIMARY KEY (`key`),
  KEY `cache_expiration_index` (`expiration`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `cache_locks`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `cache_locks` (
  `key` varchar(255) NOT NULL,
  `owner` varchar(255) NOT NULL,
  `expiration` bigint(20) NOT NULL,
  PRIMARY KEY (`key`),
  KEY `cache_locks_expiration_index` (`expiration`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `component_limits`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `component_limits` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `installation_id` bigint(20) unsigned NOT NULL,
  `kind` varchar(24) NOT NULL,
  `basis` varchar(20) NOT NULL DEFAULT 'since_overhaul',
  `value` decimal(12,2) DEFAULT NULL,
  `tolerance_percent` decimal(5,2) DEFAULT NULL,
  `tolerance_absolute` decimal(12,2) DEFAULT NULL,
  `due_on` date DEFAULT NULL,
  `last_done_at` date DEFAULT NULL,
  `last_done_value` decimal(12,2) DEFAULT NULL,
  `last_due_at` date DEFAULT NULL,
  `last_due_value` decimal(12,2) DEFAULT NULL,
  `source` varchar(160) DEFAULT NULL,
  `note` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `component_limits_installation_id_foreign` (`installation_id`),
  KEY `component_limits_kind_index` (`kind`),
  CONSTRAINT `component_limits_installation_id_foreign` FOREIGN KEY (`installation_id`) REFERENCES `installations` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `component_type_limits`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `component_type_limits` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `component_type_id` bigint(20) unsigned NOT NULL,
  `kind` varchar(24) NOT NULL,
  `value` decimal(12,2) NOT NULL,
  `tolerance_percent` decimal(5,2) DEFAULT NULL,
  `tolerance_absolute` decimal(12,2) DEFAULT NULL,
  `source` varchar(160) DEFAULT NULL,
  `note` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `component_type_limits_component_type_id_foreign` (`component_type_id`),
  KEY `component_type_limits_kind_index` (`kind`),
  CONSTRAINT `component_type_limits_component_type_id_foreign` FOREIGN KEY (`component_type_id`) REFERENCES `component_types` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `component_types`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `component_types` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `designation` varchar(160) NOT NULL,
  `manufacturer` varchar(160) DEFAULT NULL,
  `kind` varchar(24) NOT NULL,
  `type_certificate` varchar(64) DEFAULT NULL,
  `certificate_authority` varchar(16) DEFAULT NULL,
  `data_sheet_url` text DEFAULT NULL,
  `directive_overview_url` text DEFAULT NULL,
  `part_number` varchar(96) DEFAULT NULL,
  `part_type_id` bigint(20) unsigned DEFAULT NULL,
  `source` varchar(64) NOT NULL DEFAULT 'manual',
  `note` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `component_types_designation_manufacturer_unique` (`designation`,`manufacturer`),
  UNIQUE KEY `component_types_part_type_id_unique` (`part_type_id`),
  KEY `component_types_kind_index` (`kind`),
  KEY `component_types_type_certificate_index` (`type_certificate`),
  KEY `component_types_part_number_index` (`part_number`),
  KEY `component_types_source_index` (`source`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `counter_readings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `counter_readings` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `aircraft_id` bigint(20) unsigned NOT NULL,
  `kind` varchar(24) NOT NULL,
  `value` decimal(12,2) NOT NULL,
  `read_at` date NOT NULL,
  `user_id` bigint(20) unsigned DEFAULT NULL,
  `corrects_reading_id` bigint(20) unsigned DEFAULT NULL,
  `note` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `counter_readings_user_id_foreign` (`user_id`),
  KEY `counter_readings_corrects_reading_id_foreign` (`corrects_reading_id`),
  KEY `counter_readings_aircraft_id_kind_read_at_index` (`aircraft_id`,`kind`,`read_at`),
  KEY `counter_readings_kind_index` (`kind`),
  KEY `counter_readings_read_at_index` (`read_at`),
  CONSTRAINT `counter_readings_aircraft_id_foreign` FOREIGN KEY (`aircraft_id`) REFERENCES `aircraft` (`id`) ON DELETE CASCADE,
  CONSTRAINT `counter_readings_corrects_reading_id_foreign` FOREIGN KEY (`corrects_reading_id`) REFERENCES `counter_readings` (`id`) ON DELETE SET NULL,
  CONSTRAINT `counter_readings_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `directive_applications`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `directive_applications` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `directive_id` bigint(20) unsigned NOT NULL,
  `aircraft_id` bigint(20) unsigned NOT NULL,
  `aircraft_registration` varchar(32) NOT NULL,
  `state` varchar(24) NOT NULL DEFAULT 'open',
  `assessed_at` date DEFAULT NULL,
  `assessed_by` bigint(20) unsigned DEFAULT NULL,
  `assessed_by_name` varchar(160) DEFAULT NULL,
  `qualification_type` varchar(64) DEFAULT NULL,
  `qualification_reference` varchar(128) DEFAULT NULL,
  `reason` text DEFAULT NULL,
  `method` text DEFAULT NULL,
  `counters_at_compliance` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`counters_at_compliance`)),
  `task_card_reference` varchar(64) DEFAULT NULL,
  `next_due_at` date DEFAULT NULL,
  `next_due_value` decimal(12,2) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `directive_applications_directive_id_aircraft_id_unique` (`directive_id`,`aircraft_id`),
  KEY `directive_applications_aircraft_id_foreign` (`aircraft_id`),
  KEY `directive_applications_assessed_by_foreign` (`assessed_by`),
  KEY `directive_applications_aircraft_registration_index` (`aircraft_registration`),
  KEY `directive_applications_state_index` (`state`),
  KEY `directive_applications_task_card_reference_index` (`task_card_reference`),
  KEY `directive_applications_next_due_at_index` (`next_due_at`),
  CONSTRAINT `directive_applications_aircraft_id_foreign` FOREIGN KEY (`aircraft_id`) REFERENCES `aircraft` (`id`) ON DELETE CASCADE,
  CONSTRAINT `directive_applications_assessed_by_foreign` FOREIGN KEY (`assessed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `directive_applications_directive_id_foreign` FOREIGN KEY (`directive_id`) REFERENCES `directives` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `directive_credentials`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `directive_credentials` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `profile` varchar(255) NOT NULL,
  `username` text NOT NULL,
  `password` text NOT NULL,
  `updated_by` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `directive_credentials_profile_unique` (`profile`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `directives`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `directives` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `source` varchar(64) NOT NULL DEFAULT 'manual',
  `external_reference` varchar(128) DEFAULT NULL,
  `number` varchar(64) NOT NULL,
  `title` text NOT NULL,
  `summary` text DEFAULT NULL,
  `kind` varchar(16) NOT NULL,
  `bindingness` varchar(16) NOT NULL DEFAULT 'mandatory',
  `issuer` varchar(160) DEFAULT NULL,
  `issued_at` date DEFAULT NULL,
  `comply_before` date DEFAULT NULL,
  `subject_kind` varchar(24) NOT NULL,
  `subject_model` varchar(300) DEFAULT NULL,
  `aircraft_type_id` bigint(20) unsigned DEFAULT NULL,
  `subject_designation` varchar(300) DEFAULT NULL,
  `subject_part_number` varchar(96) DEFAULT NULL,
  `component_type_id` bigint(20) unsigned DEFAULT NULL,
  `serial_from` varchar(64) DEFAULT NULL,
  `serial_to` varchar(64) DEFAULT NULL,
  `is_recurring` tinyint(1) NOT NULL DEFAULT 0,
  `interval_months` int(10) unsigned DEFAULT NULL,
  `interval_counter` varchar(32) DEFAULT NULL,
  `interval_value` decimal(12,2) DEFAULT NULL,
  `superseded_by_id` bigint(20) unsigned DEFAULT NULL,
  `reference_url` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `directives_source_number_unique` (`source`,`number`),
  KEY `directives_superseded_by_id_foreign` (`superseded_by_id`),
  KEY `directives_source_index` (`source`),
  KEY `directives_kind_index` (`kind`),
  KEY `directives_comply_before_index` (`comply_before`),
  KEY `directives_subject_kind_index` (`subject_kind`),
  KEY `directives_subject_model_index` (`subject_model`),
  KEY `directives_is_recurring_index` (`is_recurring`),
  KEY `directives_bindingness_index` (`bindingness`),
  KEY `directives_aircraft_type_id_foreign` (`aircraft_type_id`),
  KEY `directives_component_type_id_foreign` (`component_type_id`),
  CONSTRAINT `directives_aircraft_type_id_foreign` FOREIGN KEY (`aircraft_type_id`) REFERENCES `aircraft_types` (`id`) ON DELETE SET NULL,
  CONSTRAINT `directives_component_type_id_foreign` FOREIGN KEY (`component_type_id`) REFERENCES `component_types` (`id`) ON DELETE SET NULL,
  CONSTRAINT `directives_superseded_by_id_foreign` FOREIGN KEY (`superseded_by_id`) REFERENCES `directives` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `external_groups`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `external_groups` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `provider` varchar(32) NOT NULL,
  `value` varchar(191) NOT NULL,
  `label` varchar(191) DEFAULT NULL,
  `member_count` int(10) unsigned DEFAULT NULL,
  `first_seen_at` timestamp NULL DEFAULT NULL,
  `last_seen_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `external_groups_unique` (`provider`,`value`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `external_identities`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `external_identities` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `provider` varchar(32) NOT NULL,
  `subject` varchar(191) NOT NULL,
  `username` varchar(191) DEFAULT NULL,
  `last_seen_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `external_identities_provider_subject_unique` (`provider`,`subject`),
  UNIQUE KEY `external_identities_user_id_provider_unique` (`user_id`,`provider`),
  CONSTRAINT `external_identities_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `external_role_grants`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `external_role_grants` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `role_id` bigint(20) unsigned NOT NULL,
  `provider` varchar(32) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `external_role_grants_unique` (`user_id`,`role_id`,`provider`),
  KEY `external_role_grants_role_id_foreign` (`role_id`),
  CONSTRAINT `external_role_grants_role_id_foreign` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE CASCADE,
  CONSTRAINT `external_role_grants_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `external_work_orders`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `external_work_orders` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `aircraft_id` bigint(20) unsigned NOT NULL,
  `shop_name` varchar(160) NOT NULL,
  `shop_approval` varchar(64) DEFAULT NULL,
  `order_reference` varchar(128) DEFAULT NULL,
  `scope` text NOT NULL,
  `sent_at` date NOT NULL,
  `expected_back_at` date DEFAULT NULL,
  `returned_at` date DEFAULT NULL,
  `sent_by` bigint(20) unsigned DEFAULT NULL,
  `state` varchar(16) NOT NULL DEFAULT 'commissioned',
  `released_by` varchar(16) DEFAULT NULL,
  `released_at` date DEFAULT NULL,
  `release_reference` varchar(128) DEFAULT NULL,
  `released_by_name` varchar(160) DEFAULT NULL,
  `released_by_approval` varchar(64) DEFAULT NULL,
  `released_by_user` bigint(20) unsigned DEFAULT NULL,
  `qualification_type` varchar(64) DEFAULT NULL,
  `qualification_reference` varchar(128) DEFAULT NULL,
  `qualification_category` varchar(64) DEFAULT NULL,
  `report_reference` text DEFAULT NULL,
  `note` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `external_work_orders_aircraft_id_foreign` (`aircraft_id`),
  KEY `external_work_orders_sent_by_foreign` (`sent_by`),
  KEY `external_work_orders_released_by_user_foreign` (`released_by_user`),
  KEY `external_work_orders_sent_at_index` (`sent_at`),
  KEY `external_work_orders_expected_back_at_index` (`expected_back_at`),
  KEY `external_work_orders_returned_at_index` (`returned_at`),
  KEY `external_work_orders_state_index` (`state`),
  KEY `external_work_orders_released_by_index` (`released_by`),
  CONSTRAINT `external_work_orders_aircraft_id_foreign` FOREIGN KEY (`aircraft_id`) REFERENCES `aircraft` (`id`) ON DELETE CASCADE,
  CONSTRAINT `external_work_orders_released_by_user_foreign` FOREIGN KEY (`released_by_user`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `external_work_orders_sent_by_foreign` FOREIGN KEY (`sent_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `failed_jobs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `failed_jobs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `uuid` varchar(255) NOT NULL,
  `connection` varchar(255) NOT NULL,
  `queue` varchar(255) NOT NULL,
  `payload` longtext NOT NULL,
  `exception` longtext NOT NULL,
  `failed_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `failed_jobs_uuid_unique` (`uuid`),
  KEY `failed_jobs_connection_queue_failed_at_index` (`connection`,`queue`,`failed_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `findings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `findings` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `aircraft_id` bigint(20) unsigned NOT NULL,
  `task_card_id` bigint(20) unsigned DEFAULT NULL,
  `number` varchar(32) NOT NULL,
  `title` varchar(160) NOT NULL,
  `description` text NOT NULL,
  `state` varchar(16) NOT NULL DEFAULT 'open',
  `is_blocking` tinyint(1) NOT NULL DEFAULT 1,
  `found_by` bigint(20) unsigned DEFAULT NULL,
  `found_by_name` varchar(160) DEFAULT NULL,
  `found_on` date NOT NULL,
  `reported_qualification_type` varchar(64) DEFAULT NULL,
  `reported_qualification_reference` varchar(128) DEFAULT NULL,
  `deferred_until` date DEFAULT NULL,
  `deferral_reason` text DEFAULT NULL,
  `deferred_by` bigint(20) unsigned DEFAULT NULL,
  `deferred_by_name` varchar(160) DEFAULT NULL,
  `deferral_qualification_type` varchar(64) DEFAULT NULL,
  `deferral_qualification_reference` varchar(128) DEFAULT NULL,
  `resolving_task_card_id` bigint(20) unsigned DEFAULT NULL,
  `resolved_on` date DEFAULT NULL,
  `resolution` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `findings_number_unique` (`number`),
  KEY `findings_task_card_id_foreign` (`task_card_id`),
  KEY `findings_found_by_foreign` (`found_by`),
  KEY `findings_deferred_by_foreign` (`deferred_by`),
  KEY `findings_aircraft_id_state_index` (`aircraft_id`,`state`),
  KEY `findings_state_index` (`state`),
  KEY `findings_is_blocking_index` (`is_blocking`),
  KEY `findings_found_on_index` (`found_on`),
  KEY `findings_resolving_task_card_id_index` (`resolving_task_card_id`),
  CONSTRAINT `findings_aircraft_id_foreign` FOREIGN KEY (`aircraft_id`) REFERENCES `aircraft` (`id`) ON DELETE CASCADE,
  CONSTRAINT `findings_deferred_by_foreign` FOREIGN KEY (`deferred_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `findings_found_by_foreign` FOREIGN KEY (`found_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `findings_task_card_id_foreign` FOREIGN KEY (`task_card_id`) REFERENCES `task_cards` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `holders`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `holders` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(160) NOT NULL,
  `type` varchar(16) NOT NULL DEFAULT 'private',
  `user_id` bigint(20) unsigned DEFAULT NULL,
  `contact` varchar(255) DEFAULT NULL,
  `note` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `holders_user_id_foreign` (`user_id`),
  KEY `holders_type_index` (`type`),
  CONSTRAINT `holders_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `incoming_inspection_checks`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `incoming_inspection_checks` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `incoming_inspection_id` bigint(20) unsigned NOT NULL,
  `item` varchar(40) NOT NULL,
  `result` varchar(24) DEFAULT NULL,
  `note` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `incoming_inspection_checks_incoming_inspection_id_item_unique` (`incoming_inspection_id`,`item`),
  CONSTRAINT `incoming_inspection_checks_incoming_inspection_id_foreign` FOREIGN KEY (`incoming_inspection_id`) REFERENCES `incoming_inspections` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `incoming_inspections`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `incoming_inspections` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `stock_movement_id` bigint(20) unsigned NOT NULL,
  `part_type_id` bigint(20) unsigned NOT NULL,
  `stock_lot_id` bigint(20) unsigned DEFAULT NULL,
  `state` varchar(24) NOT NULL DEFAULT 'open',
  `arrived_at` timestamp NOT NULL,
  `decided_by_id` bigint(20) unsigned DEFAULT NULL,
  `decided_by_name` varchar(255) DEFAULT NULL,
  `decided_at` timestamp NULL DEFAULT NULL,
  `decision_note` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `incoming_inspections_stock_movement_id_unique` (`stock_movement_id`),
  KEY `incoming_inspections_part_type_id_foreign` (`part_type_id`),
  KEY `incoming_inspections_stock_lot_id_foreign` (`stock_lot_id`),
  KEY `incoming_inspections_decided_by_id_foreign` (`decided_by_id`),
  KEY `incoming_inspections_state_arrived_at_index` (`state`,`arrived_at`),
  CONSTRAINT `incoming_inspections_decided_by_id_foreign` FOREIGN KEY (`decided_by_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `incoming_inspections_part_type_id_foreign` FOREIGN KEY (`part_type_id`) REFERENCES `part_types` (`id`),
  CONSTRAINT `incoming_inspections_stock_lot_id_foreign` FOREIGN KEY (`stock_lot_id`) REFERENCES `stock_lots` (`id`) ON DELETE SET NULL,
  CONSTRAINT `incoming_inspections_stock_movement_id_foreign` FOREIGN KEY (`stock_movement_id`) REFERENCES `stock_movements` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `installations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `installations` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `aircraft_id` bigint(20) unsigned NOT NULL,
  `origin` varchar(16) NOT NULL DEFAULT 'stock',
  `transcribed_from` varchar(255) DEFAULT NULL,
  `transcribed_at` date DEFAULT NULL,
  `transcribed_by` bigint(20) unsigned DEFAULT NULL,
  `transcribed_by_name` varchar(160) DEFAULT NULL,
  `external_work_order_id` bigint(20) unsigned DEFAULT NULL,
  `part_name` varchar(160) NOT NULL,
  `component_type_id` bigint(20) unsigned DEFAULT NULL,
  `part_number` varchar(128) DEFAULT NULL,
  `type_designation` varchar(128) DEFAULT NULL,
  `manufacturer` varchar(128) DEFAULT NULL,
  `stock_lot_id` bigint(20) unsigned DEFAULT NULL,
  `stock_lot_number` varchar(32) DEFAULT NULL,
  `part_type_id` bigint(20) unsigned DEFAULT NULL,
  `serial_number` varchar(128) DEFAULT NULL,
  `quantity` decimal(12,3) NOT NULL DEFAULT 1.000,
  `document_type` varchar(32) NOT NULL DEFAULT 'none',
  `document_reference` varchar(128) DEFAULT NULL,
  `document_issuer` varchar(160) DEFAULT NULL,
  `document_issuer_approval` varchar(64) DEFAULT NULL,
  `document_issued_at` date DEFAULT NULL,
  `position` varchar(160) DEFAULT NULL,
  `lever_arm_mm` int(11) DEFAULT NULL,
  `is_present` tinyint(1) NOT NULL DEFAULT 1,
  `is_minimum_equipment` tinyint(1) NOT NULL DEFAULT 0,
  `installed_at` date NOT NULL,
  `installed_by` bigint(20) unsigned DEFAULT NULL,
  `installed_by_name` varchar(160) DEFAULT NULL,
  `work_order_reference` varchar(64) DEFAULT NULL,
  `counters_at_installation` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`counters_at_installation`)),
  `carried_since_new` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`carried_since_new`)),
  `carried_since_overhaul` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`carried_since_overhaul`)),
  `overhauled_at` date DEFAULT NULL,
  `overhaul_reference` varchar(128) DEFAULT NULL,
  `removed_at` date DEFAULT NULL,
  `removed_by` bigint(20) unsigned DEFAULT NULL,
  `counters_at_removal` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`counters_at_removal`)),
  `removal_reason` text DEFAULT NULL,
  `note` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `installations_installed_by_foreign` (`installed_by`),
  KEY `installations_removed_by_foreign` (`removed_by`),
  KEY `installations_aircraft_id_removed_at_index` (`aircraft_id`,`removed_at`),
  KEY `installations_stock_lot_id_index` (`stock_lot_id`),
  KEY `installations_stock_lot_number_index` (`stock_lot_number`),
  KEY `installations_part_type_id_index` (`part_type_id`),
  KEY `installations_serial_number_index` (`serial_number`),
  KEY `installations_installed_at_index` (`installed_at`),
  KEY `installations_removed_at_index` (`removed_at`),
  KEY `installations_is_minimum_equipment_index` (`is_minimum_equipment`),
  KEY `installations_transcribed_by_foreign` (`transcribed_by`),
  KEY `installations_origin_index` (`origin`),
  KEY `installations_external_work_order_id_foreign` (`external_work_order_id`),
  KEY `installations_component_type_id_foreign` (`component_type_id`),
  CONSTRAINT `installations_aircraft_id_foreign` FOREIGN KEY (`aircraft_id`) REFERENCES `aircraft` (`id`) ON DELETE CASCADE,
  CONSTRAINT `installations_component_type_id_foreign` FOREIGN KEY (`component_type_id`) REFERENCES `component_types` (`id`) ON DELETE SET NULL,
  CONSTRAINT `installations_external_work_order_id_foreign` FOREIGN KEY (`external_work_order_id`) REFERENCES `external_work_orders` (`id`) ON DELETE SET NULL,
  CONSTRAINT `installations_installed_by_foreign` FOREIGN KEY (`installed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `installations_removed_by_foreign` FOREIGN KEY (`removed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `installations_transcribed_by_foreign` FOREIGN KEY (`transcribed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `job_batches`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `job_batches` (
  `id` varchar(255) NOT NULL,
  `name` varchar(255) NOT NULL,
  `total_jobs` int(11) NOT NULL,
  `pending_jobs` int(11) NOT NULL,
  `failed_jobs` int(11) NOT NULL,
  `failed_job_ids` longtext NOT NULL,
  `options` mediumtext DEFAULT NULL,
  `cancelled_at` int(11) DEFAULT NULL,
  `created_at` int(11) NOT NULL,
  `finished_at` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `jobs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `jobs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `queue` varchar(255) NOT NULL,
  `payload` longtext NOT NULL,
  `attempts` smallint(5) unsigned NOT NULL,
  `reserved_at` int(10) unsigned DEFAULT NULL,
  `available_at` int(10) unsigned NOT NULL,
  `created_at` int(10) unsigned NOT NULL,
  PRIMARY KEY (`id`),
  KEY `jobs_queue_index` (`queue`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `lot_state_changes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `lot_state_changes` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `stock_lot_id` bigint(20) unsigned NOT NULL,
  `from_state` varchar(32) NOT NULL,
  `to_state` varchar(32) NOT NULL,
  `reason` text NOT NULL,
  `aircraft_reference` varchar(32) DEFAULT NULL,
  `aircraft_type` varchar(64) DEFAULT NULL,
  `quarantine_tag` varchar(16) DEFAULT NULL,
  `tag_printed_at` timestamp NULL DEFAULT NULL,
  `user_id` bigint(20) unsigned DEFAULT NULL,
  `determined_by_name` varchar(255) DEFAULT NULL,
  `qualification_type` varchar(64) DEFAULT NULL,
  `qualification_reference` varchar(128) DEFAULT NULL,
  `qualification_category` varchar(64) DEFAULT NULL,
  `qualification_valid_until` date DEFAULT NULL,
  `occurred_at` timestamp NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `lot_state_changes_quarantine_tag_unique` (`quarantine_tag`),
  KEY `lot_state_changes_user_id_foreign` (`user_id`),
  KEY `lot_state_changes_stock_lot_id_occurred_at_index` (`stock_lot_id`,`occurred_at`),
  KEY `lot_state_changes_occurred_at_index` (`occurred_at`),
  CONSTRAINT `lot_state_changes_stock_lot_id_foreign` FOREIGN KEY (`stock_lot_id`) REFERENCES `stock_lots` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `lot_state_changes_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `maintenance_manuals`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `maintenance_manuals` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `aircraft_type_id` bigint(20) unsigned DEFAULT NULL,
  `aircraft_id` bigint(20) unsigned DEFAULT NULL,
  `kind` varchar(24) NOT NULL DEFAULT 'maintenance',
  `title` varchar(200) NOT NULL,
  `reference` varchar(128) DEFAULT NULL,
  `revision` varchar(64) NOT NULL,
  `revision_date` date DEFAULT NULL,
  `effective_from` date DEFAULT NULL,
  `superseded_at` timestamp NULL DEFAULT NULL,
  `superseded_by_id` bigint(20) unsigned DEFAULT NULL,
  `withdrawn_at` date DEFAULT NULL,
  `withdrawn_reason` varchar(255) DEFAULT NULL,
  `note` text DEFAULT NULL,
  `recorded_by_id` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `maintenance_manuals_superseded_by_id_foreign` (`superseded_by_id`),
  KEY `maintenance_manuals_recorded_by_id_foreign` (`recorded_by_id`),
  KEY `maintenance_manuals_aircraft_type_id_kind_superseded_at_index` (`aircraft_type_id`,`kind`,`superseded_at`),
  KEY `maintenance_manuals_aircraft_id_kind_superseded_at_index` (`aircraft_id`,`kind`,`superseded_at`),
  CONSTRAINT `maintenance_manuals_aircraft_id_foreign` FOREIGN KEY (`aircraft_id`) REFERENCES `aircraft` (`id`) ON DELETE CASCADE,
  CONSTRAINT `maintenance_manuals_aircraft_type_id_foreign` FOREIGN KEY (`aircraft_type_id`) REFERENCES `aircraft_types` (`id`) ON DELETE CASCADE,
  CONSTRAINT `maintenance_manuals_recorded_by_id_foreign` FOREIGN KEY (`recorded_by_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `maintenance_manuals_superseded_by_id_foreign` FOREIGN KEY (`superseded_by_id`) REFERENCES `maintenance_manuals` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `media`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `media` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `model_type` varchar(255) NOT NULL,
  `model_id` bigint(20) unsigned NOT NULL,
  `uuid` uuid DEFAULT NULL,
  `collection_name` varchar(255) NOT NULL,
  `name` varchar(255) NOT NULL,
  `file_name` varchar(255) NOT NULL,
  `mime_type` varchar(255) DEFAULT NULL,
  `disk` varchar(255) NOT NULL,
  `conversions_disk` varchar(255) DEFAULT NULL,
  `size` bigint(20) unsigned NOT NULL,
  `manipulations` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`manipulations`)),
  `custom_properties` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`custom_properties`)),
  `generated_conversions` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`generated_conversions`)),
  `responsive_images` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`responsive_images`)),
  `order_column` int(10) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `media_uuid_unique` (`uuid`),
  KEY `media_model_type_model_id_index` (`model_type`,`model_id`),
  KEY `media_order_column_index` (`order_column`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `migrations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `migrations` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `migration` varchar(255) NOT NULL,
  `batch` int(11) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `model_has_permissions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `model_has_permissions` (
  `permission_id` bigint(20) unsigned NOT NULL,
  `model_type` varchar(255) NOT NULL,
  `model_id` bigint(20) unsigned NOT NULL,
  PRIMARY KEY (`permission_id`,`model_id`,`model_type`),
  KEY `model_has_permissions_model_id_model_type_index` (`model_id`,`model_type`),
  CONSTRAINT `model_has_permissions_permission_id_foreign` FOREIGN KEY (`permission_id`) REFERENCES `permissions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `model_has_roles`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `model_has_roles` (
  `role_id` bigint(20) unsigned NOT NULL,
  `model_type` varchar(255) NOT NULL,
  `model_id` bigint(20) unsigned NOT NULL,
  PRIMARY KEY (`role_id`,`model_id`,`model_type`),
  KEY `model_has_roles_model_id_model_type_index` (`model_id`,`model_type`),
  CONSTRAINT `model_has_roles_role_id_foreign` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `modules`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `modules` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(64) NOT NULL,
  `version` varchar(32) NOT NULL,
  `enabled_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `modules_name_unique` (`name`),
  KEY `modules_enabled_at_index` (`enabled_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `part_types`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `part_types` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(128) NOT NULL,
  `description` text DEFAULT NULL,
  `classification` varchar(32) NOT NULL,
  `supplier_id` bigint(20) unsigned DEFAULT NULL,
  `storage_compartment_id` bigint(20) unsigned DEFAULT NULL,
  `order_code` varchar(128) DEFAULT NULL,
  `ipc_part_number` varchar(128) DEFAULT NULL,
  `unit_of_measure` varchar(16) NOT NULL DEFAULT 'St',
  `minimum_stock` int(10) unsigned DEFAULT NULL,
  `maximum_stock` int(10) unsigned DEFAULT NULL,
  `shelf_life_days` smallint(5) unsigned DEFAULT NULL,
  `life_limit_type` varchar(16) NOT NULL DEFAULT 'none',
  `requires_form_one` tinyint(1) NOT NULL DEFAULT 0,
  `serial_tracked` tinyint(1) NOT NULL DEFAULT 0,
  `net_purchase_price` decimal(10,2) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `part_types_name_unique` (`name`),
  KEY `part_types_supplier_id_foreign` (`supplier_id`),
  KEY `part_types_storage_compartment_id_foreign` (`storage_compartment_id`),
  KEY `part_types_classification_index` (`classification`),
  KEY `part_types_ipc_part_number_index` (`ipc_part_number`),
  KEY `part_types_life_limit_type_index` (`life_limit_type`),
  CONSTRAINT `part_types_storage_compartment_id_foreign` FOREIGN KEY (`storage_compartment_id`) REFERENCES `storage_compartments` (`id`) ON DELETE SET NULL,
  CONSTRAINT `part_types_supplier_id_foreign` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `password_reset_tokens`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `password_reset_tokens` (
  `email` varchar(255) NOT NULL,
  `token` varchar(255) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `permissions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `permissions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `guard_name` varchar(255) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `permissions_name_guard_name_unique` (`name`,`guard_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `pilot_owner_authorisations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `pilot_owner_authorisations` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `aircraft_id` bigint(20) unsigned NOT NULL,
  `user_id` bigint(20) unsigned NOT NULL,
  `listed_name` varchar(160) NOT NULL,
  `listed_at` date NOT NULL,
  `valid_until` date DEFAULT NULL,
  `note` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `pilot_owner_authorisations_aircraft_id_user_id_unique` (`aircraft_id`,`user_id`),
  KEY `pilot_owner_authorisations_user_id_foreign` (`user_id`),
  CONSTRAINT `pilot_owner_authorisations_aircraft_id_foreign` FOREIGN KEY (`aircraft_id`) REFERENCES `aircraft` (`id`) ON DELETE CASCADE,
  CONSTRAINT `pilot_owner_authorisations_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `purchase_order_lines`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `purchase_order_lines` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `purchase_order_id` bigint(20) unsigned NOT NULL,
  `part_type_id` bigint(20) unsigned NOT NULL,
  `quantity_ordered` decimal(12,3) NOT NULL,
  `quantity_received` decimal(12,3) NOT NULL DEFAULT 0.000,
  `note` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `purchase_order_lines_purchase_order_id_foreign` (`purchase_order_id`),
  KEY `purchase_order_lines_part_type_id_foreign` (`part_type_id`),
  CONSTRAINT `purchase_order_lines_part_type_id_foreign` FOREIGN KEY (`part_type_id`) REFERENCES `part_types` (`id`),
  CONSTRAINT `purchase_order_lines_purchase_order_id_foreign` FOREIGN KEY (`purchase_order_id`) REFERENCES `purchase_orders` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `purchase_orders`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `purchase_orders` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `order_number` varchar(64) DEFAULT NULL,
  `supplier_id` bigint(20) unsigned NOT NULL,
  `ordered_at` date NOT NULL,
  `expected_at` date DEFAULT NULL,
  `state` varchar(24) NOT NULL DEFAULT 'open',
  `cancelled_at` date DEFAULT NULL,
  `cancel_reason` varchar(255) DEFAULT NULL,
  `note` text DEFAULT NULL,
  `created_by_id` bigint(20) unsigned DEFAULT NULL,
  `reminded_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `purchase_orders_supplier_id_foreign` (`supplier_id`),
  KEY `purchase_orders_created_by_id_foreign` (`created_by_id`),
  KEY `purchase_orders_state_expected_at_index` (`state`,`expected_at`),
  CONSTRAINT `purchase_orders_created_by_id_foreign` FOREIGN KEY (`created_by_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `purchase_orders_supplier_id_foreign` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `qualification_limitations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `qualification_limitations` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `qualification_id` bigint(20) unsigned NOT NULL,
  `subject` varchar(32) DEFAULT NULL,
  `text` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `qualification_limitations_qualification_id_index` (`qualification_id`),
  KEY `qualification_limitations_subject_index` (`subject`),
  CONSTRAINT `qualification_limitations_qualification_id_foreign` FOREIGN KEY (`qualification_id`) REFERENCES `qualifications` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `qualifications`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `qualifications` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `type` varchar(64) NOT NULL,
  `subject` varchar(200) DEFAULT NULL,
  `reference` varchar(128) DEFAULT NULL,
  `issuer` varchar(160) DEFAULT NULL,
  `category` varchar(64) DEFAULT NULL,
  `categories` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`categories`)),
  `no_maintenance_exceeding_ma803b` tinyint(1) NOT NULL DEFAULT 0,
  `scope` varchar(64) DEFAULT NULL,
  `valid_from` date NOT NULL,
  `valid_until` date DEFAULT NULL,
  `note` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `qualifications_user_id_type_index` (`user_id`,`type`),
  KEY `qualifications_type_index` (`type`),
  KEY `qualifications_scope_index` (`scope`),
  CONSTRAINT `qualifications_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `releases`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `releases` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `work_order_id` bigint(20) unsigned NOT NULL,
  `aircraft_id` bigint(20) unsigned NOT NULL,
  `aircraft_registration` varchar(32) NOT NULL,
  `aircraft_model` varchar(96) DEFAULT NULL,
  `number` varchar(32) NOT NULL,
  `statement` text NOT NULL,
  `maintenance_data` varchar(255) DEFAULT NULL,
  `released_at` timestamp NOT NULL,
  `released_by` bigint(20) unsigned DEFAULT NULL,
  `released_by_name` varchar(160) NOT NULL,
  `is_external` tinyint(1) NOT NULL DEFAULT 0,
  `recorded_by` bigint(20) unsigned DEFAULT NULL,
  `recorded_by_name` varchar(160) DEFAULT NULL,
  `external_organisation` varchar(160) DEFAULT NULL,
  `qualification_type` varchar(64) NOT NULL,
  `qualification_reference` varchar(128) DEFAULT NULL,
  `qualification_category` varchar(64) DEFAULT NULL,
  `qualification_limitations` varchar(255) DEFAULT NULL,
  `qualification_valid_until` date DEFAULT NULL,
  `counters_at_release` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`counters_at_release`)),
  `supersedes_release_id` bigint(20) unsigned DEFAULT NULL,
  `correction_reason` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `releases_number_unique` (`number`),
  UNIQUE KEY `releases_supersedes_release_id_unique` (`supersedes_release_id`),
  KEY `releases_released_by_foreign` (`released_by`),
  KEY `releases_aircraft_id_released_at_index` (`aircraft_id`,`released_at`),
  KEY `releases_aircraft_registration_index` (`aircraft_registration`),
  KEY `releases_work_order_id_foreign` (`work_order_id`),
  KEY `releases_recorded_by_foreign` (`recorded_by`),
  CONSTRAINT `releases_aircraft_id_foreign` FOREIGN KEY (`aircraft_id`) REFERENCES `aircraft` (`id`),
  CONSTRAINT `releases_recorded_by_foreign` FOREIGN KEY (`recorded_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `releases_released_by_foreign` FOREIGN KEY (`released_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `releases_supersedes_release_id_foreign` FOREIGN KEY (`supersedes_release_id`) REFERENCES `releases` (`id`) ON DELETE SET NULL,
  CONSTRAINT `releases_work_order_id_foreign` FOREIGN KEY (`work_order_id`) REFERENCES `work_orders` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `repair_dispatches`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `repair_dispatches` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `part_type_id` bigint(20) unsigned NOT NULL,
  `stock_lot_id` bigint(20) unsigned DEFAULT NULL,
  `quantity` decimal(12,3) NOT NULL,
  `serial_number` varchar(128) DEFAULT NULL,
  `destination` varchar(16) NOT NULL DEFAULT 'external',
  `supplier_id` bigint(20) unsigned DEFAULT NULL,
  `shop_name` varchar(160) DEFAULT NULL,
  `shop_approval` varchar(64) DEFAULT NULL,
  `dispatch_reference` varchar(128) DEFAULT NULL,
  `reason` text NOT NULL,
  `restricted_to_aircraft` varchar(32) DEFAULT NULL,
  `aircraft_type` varchar(64) DEFAULT NULL,
  `dispatched_at` date NOT NULL,
  `dispatched_by` bigint(20) unsigned DEFAULT NULL,
  `expected_back_at` date DEFAULT NULL,
  `state` varchar(16) NOT NULL DEFAULT 'dispatched',
  `returned_at` date DEFAULT NULL,
  `returned_by` bigint(20) unsigned DEFAULT NULL,
  `returned_lot_id` bigint(20) unsigned DEFAULT NULL,
  `return_note` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `repair_dispatches_part_type_id_foreign` (`part_type_id`),
  KEY `repair_dispatches_stock_lot_id_foreign` (`stock_lot_id`),
  KEY `repair_dispatches_dispatched_by_foreign` (`dispatched_by`),
  KEY `repair_dispatches_returned_by_foreign` (`returned_by`),
  KEY `repair_dispatches_returned_lot_id_foreign` (`returned_lot_id`),
  KEY `repair_dispatches_destination_index` (`destination`),
  KEY `repair_dispatches_restricted_to_aircraft_index` (`restricted_to_aircraft`),
  KEY `repair_dispatches_dispatched_at_index` (`dispatched_at`),
  KEY `repair_dispatches_expected_back_at_index` (`expected_back_at`),
  KEY `repair_dispatches_state_index` (`state`),
  KEY `repair_dispatches_supplier_id_foreign` (`supplier_id`),
  CONSTRAINT `repair_dispatches_dispatched_by_foreign` FOREIGN KEY (`dispatched_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `repair_dispatches_part_type_id_foreign` FOREIGN KEY (`part_type_id`) REFERENCES `part_types` (`id`) ON DELETE CASCADE,
  CONSTRAINT `repair_dispatches_returned_by_foreign` FOREIGN KEY (`returned_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `repair_dispatches_returned_lot_id_foreign` FOREIGN KEY (`returned_lot_id`) REFERENCES `stock_lots` (`id`) ON DELETE SET NULL,
  CONSTRAINT `repair_dispatches_stock_lot_id_foreign` FOREIGN KEY (`stock_lot_id`) REFERENCES `stock_lots` (`id`) ON DELETE SET NULL,
  CONSTRAINT `repair_dispatches_supplier_id_foreign` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `role_has_permissions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `role_has_permissions` (
  `permission_id` bigint(20) unsigned NOT NULL,
  `role_id` bigint(20) unsigned NOT NULL,
  PRIMARY KEY (`permission_id`,`role_id`),
  KEY `role_has_permissions_role_id_foreign` (`role_id`),
  CONSTRAINT `role_has_permissions_permission_id_foreign` FOREIGN KEY (`permission_id`) REFERENCES `permissions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `role_has_permissions_role_id_foreign` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `role_mappings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `role_mappings` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `provider` varchar(32) NOT NULL,
  `kind` varchar(16) NOT NULL,
  `value` varchar(191) NOT NULL,
  `role_id` bigint(20) unsigned NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `role_mappings_unique` (`provider`,`kind`,`value`,`role_id`),
  KEY `role_mappings_role_id_foreign` (`role_id`),
  CONSTRAINT `role_mappings_role_id_foreign` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `roles`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `roles` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `guard_name` varchar(255) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `roles_name_guard_name_unique` (`name`,`guard_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `sessions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `sessions` (
  `id` varchar(255) NOT NULL,
  `user_id` bigint(20) unsigned DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `payload` longtext NOT NULL,
  `last_activity` int(11) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `sessions_user_id_index` (`user_id`),
  KEY `sessions_last_activity_index` (`last_activity`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `settings` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `key` varchar(128) NOT NULL,
  `value` text DEFAULT NULL,
  `is_secret` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `settings_key_unique` (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `stock_lots`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `stock_lots` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `part_type_id` bigint(20) unsigned NOT NULL,
  `origin` varchar(16) NOT NULL DEFAULT 'supplier',
  `removed_from_aircraft` varchar(32) DEFAULT NULL,
  `removed_from_aircraft_type` varchar(64) DEFAULT NULL,
  `removed_at` date DEFAULT NULL,
  `removal_reason` text DEFAULT NULL,
  `repair_dispatch_id` bigint(20) unsigned DEFAULT NULL,
  `lot_number` varchar(32) NOT NULL,
  `serial_number` varchar(128) DEFAULT NULL,
  `batch_number` varchar(128) DEFAULT NULL,
  `document_type` varchar(32) NOT NULL DEFAULT 'none',
  `document_reference` varchar(128) DEFAULT NULL,
  `document_issuer` varchar(255) DEFAULT NULL,
  `document_issuer_approval` varchar(128) DEFAULT NULL,
  `document_issued_at` date DEFAULT NULL,
  `document_signatory` varchar(255) DEFAULT NULL,
  `supplier_id` bigint(20) unsigned DEFAULT NULL,
  `storage_compartment_id` bigint(20) unsigned DEFAULT NULL,
  `received_at` date NOT NULL,
  `expires_at` date DEFAULT NULL,
  `state` varchar(32) NOT NULL DEFAULT 'serviceable',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `stock_lots_lot_number_unique` (`lot_number`),
  KEY `stock_lots_supplier_id_foreign` (`supplier_id`),
  KEY `stock_lots_storage_compartment_id_foreign` (`storage_compartment_id`),
  KEY `stock_lots_part_type_id_state_index` (`part_type_id`,`state`),
  KEY `stock_lots_part_type_id_expires_at_index` (`part_type_id`,`expires_at`),
  KEY `stock_lots_serial_number_index` (`serial_number`),
  KEY `stock_lots_document_reference_index` (`document_reference`),
  KEY `stock_lots_expires_at_index` (`expires_at`),
  KEY `stock_lots_state_index` (`state`),
  KEY `stock_lots_origin_index` (`origin`),
  KEY `stock_lots_removed_from_aircraft_index` (`removed_from_aircraft`),
  KEY `stock_lots_repair_dispatch_id_foreign` (`repair_dispatch_id`),
  CONSTRAINT `stock_lots_part_type_id_foreign` FOREIGN KEY (`part_type_id`) REFERENCES `part_types` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `stock_lots_repair_dispatch_id_foreign` FOREIGN KEY (`repair_dispatch_id`) REFERENCES `repair_dispatches` (`id`) ON DELETE SET NULL,
  CONSTRAINT `stock_lots_storage_compartment_id_foreign` FOREIGN KEY (`storage_compartment_id`) REFERENCES `storage_compartments` (`id`) ON DELETE SET NULL,
  CONSTRAINT `stock_lots_supplier_id_foreign` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `stock_movements`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `stock_movements` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `part_type_id` bigint(20) unsigned NOT NULL,
  `stock_lot_id` bigint(20) unsigned DEFAULT NULL,
  `type` varchar(32) NOT NULL,
  `quantity` decimal(12,3) NOT NULL,
  `occurred_at` timestamp NOT NULL,
  `user_id` bigint(20) unsigned DEFAULT NULL,
  `work_order_reference` varchar(64) DEFAULT NULL,
  `aircraft_reference` varchar(32) DEFAULT NULL,
  `reverses_movement_id` bigint(20) unsigned DEFAULT NULL,
  `note` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `stock_movements_reverses_movement_id_unique` (`reverses_movement_id`),
  KEY `stock_movements_stock_lot_id_foreign` (`stock_lot_id`),
  KEY `stock_movements_user_id_foreign` (`user_id`),
  KEY `stock_movements_part_type_id_occurred_at_index` (`part_type_id`,`occurred_at`),
  KEY `stock_movements_type_index` (`type`),
  KEY `stock_movements_occurred_at_index` (`occurred_at`),
  KEY `stock_movements_work_order_reference_index` (`work_order_reference`),
  KEY `stock_movements_aircraft_reference_index` (`aircraft_reference`),
  CONSTRAINT `stock_movements_part_type_id_foreign` FOREIGN KEY (`part_type_id`) REFERENCES `part_types` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `stock_movements_reverses_movement_id_foreign` FOREIGN KEY (`reverses_movement_id`) REFERENCES `stock_movements` (`id`) ON DELETE SET NULL,
  CONSTRAINT `stock_movements_stock_lot_id_foreign` FOREIGN KEY (`stock_lot_id`) REFERENCES `stock_lots` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `stock_movements_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `storage_compartments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `storage_compartments` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `storage_location_id` bigint(20) unsigned NOT NULL,
  `name` varchar(128) NOT NULL,
  `description` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `storage_compartments_storage_location_id_name_unique` (`storage_location_id`,`name`),
  CONSTRAINT `storage_compartments_storage_location_id_foreign` FOREIGN KEY (`storage_location_id`) REFERENCES `storage_locations` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `storage_locations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `storage_locations` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(128) NOT NULL,
  `description` text DEFAULT NULL,
  `is_quarantine` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `storage_locations_name_unique` (`name`),
  KEY `storage_locations_is_quarantine_index` (`is_quarantine`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `suppliers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `suppliers` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(128) NOT NULL,
  `approval_number` varchar(64) DEFAULT NULL,
  `approval_scope` varchar(128) DEFAULT NULL,
  `approval_expires_at` date DEFAULT NULL,
  `address` text DEFAULT NULL,
  `contact` text DEFAULT NULL,
  `description` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `suppliers_name_unique` (`name`),
  KEY `suppliers_approval_expires_at_index` (`approval_expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `task_card_times`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `task_card_times` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `task_card_id` bigint(20) unsigned NOT NULL,
  `user_id` bigint(20) unsigned NOT NULL,
  `person_name` varchar(160) NOT NULL,
  `participation` varchar(16) NOT NULL DEFAULT 'executed',
  `minutes` int(10) unsigned NOT NULL,
  `worked_on` date NOT NULL,
  `note` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `task_card_times_task_card_id_foreign` (`task_card_id`),
  KEY `task_card_times_user_id_foreign` (`user_id`),
  KEY `task_card_times_participation_index` (`participation`),
  KEY `task_card_times_worked_on_index` (`worked_on`),
  CONSTRAINT `task_card_times_task_card_id_foreign` FOREIGN KEY (`task_card_id`) REFERENCES `task_cards` (`id`) ON DELETE CASCADE,
  CONSTRAINT `task_card_times_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `task_cards`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `task_cards` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `work_order_id` bigint(20) unsigned NOT NULL,
  `number` varchar(32) NOT NULL,
  `title` varchar(160) NOT NULL,
  `instruction` text DEFAULT NULL,
  `manual_reference` varchar(255) DEFAULT NULL,
  `aircraft_registration` varchar(32) NOT NULL,
  `aircraft_model` varchar(96) DEFAULT NULL,
  `ata_chapter` varchar(16) DEFAULT NULL,
  `activity_kind` varchar(24) NOT NULL DEFAULT 'maintenance',
  `critical` tinyint(1) NOT NULL DEFAULT 0,
  `critical_reason` varchar(160) DEFAULT NULL,
  `within_pilot_owner_scope` tinyint(1) DEFAULT NULL,
  `state` varchar(16) NOT NULL DEFAULT 'open',
  `completed_at` timestamp NULL DEFAULT NULL,
  `completed_by` bigint(20) unsigned DEFAULT NULL,
  `completed_by_name` varchar(160) DEFAULT NULL,
  `work_performed` text DEFAULT NULL,
  `inspected_at` timestamp NULL DEFAULT NULL,
  `inspected_by` bigint(20) unsigned DEFAULT NULL,
  `inspected_by_name` varchar(160) DEFAULT NULL,
  `inspection_note` text DEFAULT NULL,
  `inspection_qualification_type` varchar(64) DEFAULT NULL,
  `inspection_qualification_reference` varchar(128) DEFAULT NULL,
  `certified_at` timestamp NULL DEFAULT NULL,
  `certified_by` bigint(20) unsigned DEFAULT NULL,
  `certified_by_name` varchar(160) DEFAULT NULL,
  `qualification_type` varchar(64) DEFAULT NULL,
  `qualification_reference` varchar(128) DEFAULT NULL,
  `qualification_category` varchar(64) DEFAULT NULL,
  `qualification_limitations` varchar(255) DEFAULT NULL,
  `cancellation_reason` text DEFAULT NULL,
  `component_limit_id` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `task_cards_number_unique` (`number`),
  KEY `task_cards_work_order_id_foreign` (`work_order_id`),
  KEY `task_cards_completed_by_foreign` (`completed_by`),
  KEY `task_cards_certified_by_foreign` (`certified_by`),
  KEY `task_cards_number_index` (`number`),
  KEY `task_cards_aircraft_registration_index` (`aircraft_registration`),
  KEY `task_cards_ata_chapter_index` (`ata_chapter`),
  KEY `task_cards_activity_kind_index` (`activity_kind`),
  KEY `task_cards_state_index` (`state`),
  KEY `task_cards_component_limit_id_index` (`component_limit_id`),
  KEY `task_cards_inspected_by_foreign` (`inspected_by`),
  KEY `task_cards_critical_state_index` (`critical`,`state`),
  CONSTRAINT `task_cards_certified_by_foreign` FOREIGN KEY (`certified_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `task_cards_completed_by_foreign` FOREIGN KEY (`completed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `task_cards_inspected_by_foreign` FOREIGN KEY (`inspected_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `task_cards_work_order_id_foreign` FOREIGN KEY (`work_order_id`) REFERENCES `work_orders` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `tool_calibrations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `tool_calibrations` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `tool_id` bigint(20) unsigned NOT NULL,
  `performed_at` date NOT NULL,
  `valid_until` date DEFAULT NULL,
  `result` varchar(24) DEFAULT NULL,
  `provider` varchar(255) DEFAULT NULL,
  `certificate_reference` varchar(128) DEFAULT NULL,
  `gap_started_at` date DEFAULT NULL,
  `gap_reason` varchar(24) DEFAULT NULL,
  `gap_reviewed_at` timestamp NULL DEFAULT NULL,
  `gap_reviewed_by_id` bigint(20) unsigned DEFAULT NULL,
  `gap_review_note` text DEFAULT NULL,
  `note` text DEFAULT NULL,
  `recorded_by_id` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `tool_calibrations_gap_reviewed_by_id_foreign` (`gap_reviewed_by_id`),
  KEY `tool_calibrations_recorded_by_id_foreign` (`recorded_by_id`),
  KEY `tool_calibrations_tool_id_performed_at_index` (`tool_id`,`performed_at`),
  CONSTRAINT `tool_calibrations_gap_reviewed_by_id_foreign` FOREIGN KEY (`gap_reviewed_by_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `tool_calibrations_recorded_by_id_foreign` FOREIGN KEY (`recorded_by_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `tool_calibrations_tool_id_foreign` FOREIGN KEY (`tool_id`) REFERENCES `tools` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `tool_issues`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `tool_issues` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `tool_id` bigint(20) unsigned NOT NULL,
  `issued_to_id` bigint(20) unsigned DEFAULT NULL,
  `issued_to_name` varchar(160) DEFAULT NULL,
  `issued_at` timestamp NOT NULL,
  `issued_by_id` bigint(20) unsigned DEFAULT NULL,
  `due_back_at` date DEFAULT NULL,
  `work_order_reference` varchar(64) DEFAULT NULL,
  `returned_at` timestamp NULL DEFAULT NULL,
  `returned_by_id` bigint(20) unsigned DEFAULT NULL,
  `note` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `tool_issues_issued_to_id_foreign` (`issued_to_id`),
  KEY `tool_issues_issued_by_id_foreign` (`issued_by_id`),
  KEY `tool_issues_returned_by_id_foreign` (`returned_by_id`),
  KEY `tool_issues_tool_id_returned_at_index` (`tool_id`,`returned_at`),
  KEY `tool_issues_returned_at_issued_at_index` (`returned_at`,`issued_at`),
  CONSTRAINT `tool_issues_issued_by_id_foreign` FOREIGN KEY (`issued_by_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `tool_issues_issued_to_id_foreign` FOREIGN KEY (`issued_to_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `tool_issues_returned_by_id_foreign` FOREIGN KEY (`returned_by_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `tool_issues_tool_id_foreign` FOREIGN KEY (`tool_id`) REFERENCES `tools` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `tools`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `tools` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `inventory_number` varchar(64) NOT NULL,
  `name` varchar(255) NOT NULL,
  `manufacturer` varchar(255) DEFAULT NULL,
  `model` varchar(255) DEFAULT NULL,
  `serial_number` varchar(64) DEFAULT NULL,
  `location` varchar(255) DEFAULT NULL,
  `state` varchar(24) NOT NULL DEFAULT 'in_service',
  `calibration_required` tinyint(1) NOT NULL DEFAULT 0,
  `calibration_interval_months` smallint(5) unsigned DEFAULT NULL,
  `calibration_basis` varchar(255) DEFAULT NULL,
  `calibration_due_at` date DEFAULT NULL,
  `note` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `tools_inventory_number_unique` (`inventory_number`),
  KEY `tools_state_calibration_due_at_index` (`state`,`calibration_due_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `users` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `email` varchar(255) NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `locked_at` timestamp NULL DEFAULT NULL,
  `lock_reason` varchar(255) DEFAULT NULL,
  `locked_by_id` bigint(20) unsigned DEFAULT NULL,
  `deactivated_at` timestamp NULL DEFAULT NULL,
  `email_verified_at` timestamp NULL DEFAULT NULL,
  `password` varchar(255) DEFAULT NULL,
  `remember_token` varchar(100) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `app_authentication_secret` text DEFAULT NULL,
  `app_authentication_recovery_codes` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `users_email_unique` (`email`),
  KEY `users_locked_by_id_foreign` (`locked_by_id`),
  CONSTRAINT `users_locked_by_id_foreign` FOREIGN KEY (`locked_by_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `vereinsflieger_aircraft_links`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `vereinsflieger_aircraft_links` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `connection_id` bigint(20) unsigned NOT NULL,
  `aircraft_id` bigint(20) unsigned NOT NULL,
  `callsign` varchar(32) NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `last_read_at` timestamp NULL DEFAULT NULL,
  `last_error` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `vereinsflieger_aircraft_links_aircraft_id_unique` (`aircraft_id`),
  KEY `vereinsflieger_aircraft_links_connection_id_is_active_index` (`connection_id`,`is_active`),
  CONSTRAINT `vereinsflieger_aircraft_links_connection_id_foreign` FOREIGN KEY (`connection_id`) REFERENCES `vereinsflieger_connections` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `vereinsflieger_connections`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `vereinsflieger_connections` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(128) NOT NULL,
  `username` varchar(191) NOT NULL,
  `password` text NOT NULL,
  `app_key` text NOT NULL,
  `auth_secret` text DEFAULT NULL,
  `password_is_hash` tinyint(1) NOT NULL DEFAULT 0,
  `cid` varchar(32) NOT NULL DEFAULT '0',
  `provides_identities` tinyint(1) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `last_run_at` timestamp NULL DEFAULT NULL,
  `last_error` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `vereinsflieger_connections_name_unique` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `vereinsflieger_member_statuses`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `vereinsflieger_member_statuses` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `msid` varchar(32) NOT NULL,
  `label` varchar(191) DEFAULT NULL,
  `member_count` int(10) unsigned DEFAULT NULL,
  `handling` varchar(16) DEFAULT NULL,
  `first_seen_at` timestamp NULL DEFAULT NULL,
  `last_seen_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `vereinsflieger_member_statuses_msid_unique` (`msid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `vereinsflieger_work_hour_categories`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `vereinsflieger_work_hour_categories` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `category` varchar(32) NOT NULL,
  `name` varchar(191) DEFAULT NULL,
  `enabled` tinyint(1) NOT NULL DEFAULT 1,
  `first_seen_at` timestamp NULL DEFAULT NULL,
  `last_seen_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `vereinsflieger_work_hour_categories_category_unique` (`category`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `vereinsflieger_work_hour_transfers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `vereinsflieger_work_hour_transfers` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `task_card_time_id` bigint(20) unsigned NOT NULL,
  `connection_id` bigint(20) unsigned NOT NULL,
  `whid` varchar(32) DEFAULT NULL,
  `job_text` varchar(191) DEFAULT NULL,
  `hours` varchar(8) DEFAULT NULL,
  `category` varchar(32) DEFAULT NULL,
  `status` varchar(8) DEFAULT NULL,
  `attempts` tinyint(3) unsigned NOT NULL DEFAULT 0,
  `transferred_at` timestamp NULL DEFAULT NULL,
  `verified_at` timestamp NULL DEFAULT NULL,
  `last_error` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `vereinsflieger_work_hour_transfers_task_card_time_id_unique` (`task_card_time_id`),
  KEY `vereinsflieger_work_hour_transfers_connection_id_foreign` (`connection_id`),
  CONSTRAINT `vereinsflieger_work_hour_transfers_connection_id_foreign` FOREIGN KEY (`connection_id`) REFERENCES `vereinsflieger_connections` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `weighing_entries`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `weighing_entries` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `weighing_id` bigint(20) unsigned NOT NULL,
  `section` varchar(16) NOT NULL,
  `label` varchar(160) NOT NULL,
  `position` smallint(5) unsigned NOT NULL DEFAULT 0,
  `mass_kg` decimal(10,2) DEFAULT NULL,
  `non_lifting_kg` decimal(10,2) DEFAULT NULL,
  `gross_kg` decimal(10,2) DEFAULT NULL,
  `tare_kg` decimal(10,2) DEFAULT NULL,
  `arm_mm` decimal(10,2) DEFAULT NULL,
  `max_mass_kg` decimal(10,2) DEFAULT NULL,
  `useful_load_kg` decimal(10,2) DEFAULT NULL,
  `cg_from_mm` decimal(10,2) DEFAULT NULL,
  `cg_to_mm` decimal(10,2) DEFAULT NULL,
  `volume_litres` decimal(10,2) DEFAULT NULL,
  `density_kg_per_litre` decimal(6,3) DEFAULT NULL,
  `note` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `weighing_entries_weighing_id_foreign` (`weighing_id`),
  KEY `weighing_entries_section_index` (`section`),
  CONSTRAINT `weighing_entries_weighing_id_foreign` FOREIGN KEY (`weighing_id`) REFERENCES `weighings` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `weighings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `weighings` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `aircraft_id` bigint(20) unsigned NOT NULL,
  `kind` varchar(16) NOT NULL,
  `sheet_variant` varchar(16) DEFAULT NULL,
  `undercarriage` varchar(24) DEFAULT NULL,
  `weighed_at` date NOT NULL,
  `place` varchar(120) DEFAULT NULL,
  `order_reference` varchar(64) DEFAULT NULL,
  `valid_until` date DEFAULT NULL,
  `datum_reference` varchar(160) DEFAULT NULL,
  `reference_line` varchar(160) DEFAULT NULL,
  `datum_plane` varchar(160) DEFAULT NULL,
  `fuselage_reference_plane` varchar(160) DEFAULT NULL,
  `front_support_arm_mm` int(11) DEFAULT NULL,
  `support_distance_mm` int(11) DEFAULT NULL,
  `empty_mass_kg` decimal(10,2) DEFAULT NULL,
  `empty_cg_mm` decimal(10,2) DEFAULT NULL,
  `non_lifting_mass_kg` decimal(10,2) DEFAULT NULL,
  `useful_load_kg` decimal(10,2) DEFAULT NULL,
  `cg_range_from_mm` decimal(10,2) DEFAULT NULL,
  `cg_range_to_mm` decimal(10,2) DEFAULT NULL,
  `cg_range_at_mass_kg` decimal(10,2) DEFAULT NULL,
  `flight_cg_from_mm` decimal(10,2) DEFAULT NULL,
  `flight_cg_to_mm` decimal(10,2) DEFAULT NULL,
  `max_mass_kg` decimal(10,2) DEFAULT NULL,
  `max_mass_water_kg` decimal(10,2) DEFAULT NULL,
  `max_non_lifting_kg` decimal(10,2) DEFAULT NULL,
  `cockpit_load_min_kg` decimal(10,2) DEFAULT NULL,
  `cockpit_load_max_kg` decimal(10,2) DEFAULT NULL,
  `user_id` bigint(20) unsigned DEFAULT NULL,
  `signed_by_name` varchar(160) DEFAULT NULL,
  `signed_by_approval` varchar(64) DEFAULT NULL,
  `equipment_list_dated` date DEFAULT NULL,
  `remarks` text DEFAULT NULL,
  `signed_off_at` timestamp NULL DEFAULT NULL,
  `signed_off_by` bigint(20) unsigned DEFAULT NULL,
  `signed_off_by_name` varchar(160) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `weighings_aircraft_id_foreign` (`aircraft_id`),
  KEY `weighings_user_id_foreign` (`user_id`),
  KEY `weighings_kind_index` (`kind`),
  KEY `weighings_weighed_at_index` (`weighed_at`),
  KEY `weighings_valid_until_index` (`valid_until`),
  KEY `weighings_signed_off_by_foreign` (`signed_off_by`),
  KEY `weighings_signed_off_at_index` (`signed_off_at`),
  CONSTRAINT `weighings_aircraft_id_foreign` FOREIGN KEY (`aircraft_id`) REFERENCES `aircraft` (`id`) ON DELETE CASCADE,
  CONSTRAINT `weighings_signed_off_by_foreign` FOREIGN KEY (`signed_off_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `weighings_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `work_orders`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `work_orders` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `aircraft_id` bigint(20) unsigned NOT NULL,
  `number` varchar(32) NOT NULL,
  `title` varchar(160) NOT NULL,
  `description` text DEFAULT NULL,
  `opened_at` date NOT NULL,
  `closed_at` date DEFAULT NULL,
  `released_at` timestamp NULL DEFAULT NULL,
  `foreign_object_check_at` timestamp NULL DEFAULT NULL,
  `foreign_object_check_by` bigint(20) unsigned DEFAULT NULL,
  `foreign_object_check_by_name` varchar(160) DEFAULT NULL,
  `opened_by` bigint(20) unsigned DEFAULT NULL,
  `state` varchar(16) NOT NULL DEFAULT 'open',
  `counters_at_open` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`counters_at_open`)),
  `counters_at_close` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`counters_at_close`)),
  `external_work_order_id` bigint(20) unsigned DEFAULT NULL,
  `note` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `work_orders_number_unique` (`number`),
  KEY `work_orders_aircraft_id_foreign` (`aircraft_id`),
  KEY `work_orders_opened_by_foreign` (`opened_by`),
  KEY `work_orders_opened_at_index` (`opened_at`),
  KEY `work_orders_closed_at_index` (`closed_at`),
  KEY `work_orders_state_index` (`state`),
  KEY `work_orders_external_work_order_id_index` (`external_work_order_id`),
  KEY `work_orders_released_at_index` (`released_at`),
  CONSTRAINT `work_orders_aircraft_id_foreign` FOREIGN KEY (`aircraft_id`) REFERENCES `aircraft` (`id`) ON DELETE CASCADE,
  CONSTRAINT `work_orders_opened_by_foreign` FOREIGN KEY (`opened_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*M!100616 SET NOTE_VERBOSITY=@OLD_NOTE_VERBOSITY */;

/*M!999999\- enable the sandbox mode */ 
SET @OLD_AUTOCOMMIT=@@AUTOCOMMIT, @@AUTOCOMMIT=0;
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (1,'0001_01_01_000000_create_users_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (2,'0001_01_01_000001_create_cache_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (3,'0001_01_01_000002_create_jobs_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (4,'2026_07_28_120000_create_modules_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (5,'2026_07_28_202916_create_activity_log_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (6,'2026_07_28_202916_create_media_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (7,'2026_07_28_202916_create_permission_tables',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (8,'2026_07_28_210000_add_core_columns_to_users_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (9,'2026_07_28_210100_create_qualifications_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (10,'2026_07_28_220000_create_break_glass_records_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (11,'2026_07_28_230000_create_warehouse_master_data_tables',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (12,'2026_07_28_230100_create_stock_tables',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (13,'2026_07_28_230200_create_lot_state_changes_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (14,'2026_07_29_090000_add_removal_context_to_lot_state_changes',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (15,'2026_07_29_100000_add_removal_origin_to_stock',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (16,'2026_07_29_140000_create_repair_dispatches_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (17,'2026_07_29_160000_create_fleet_tables',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (18,'2026_07_29_160100_create_installation_tables',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (19,'2026_07_29_170000_split_since_new_and_since_overhaul',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (20,'2026_07_29_180000_add_tolerance_to_component_limits',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (21,'2026_07_29_190000_add_equipment_list_fields',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (22,'2026_07_29_200000_create_weighings_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (23,'2026_07_29_210000_add_loading_plan_to_weighings',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (24,'2026_07_29_220000_add_sign_off_to_weighings',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (25,'2026_07_29_230000_create_aircraft_documents_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (26,'2026_07_30_100000_add_onboarding_to_installations',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (27,'2026_07_30_110000_create_external_work_orders_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (28,'2026_07_30_120000_create_task_card_tables',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (29,'2026_07_30_130000_create_releases_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (30,'2026_07_30_150000_harden_release_chain',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (31,'2026_07_30_170000_create_directive_tables',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (32,'2026_07_30_180000_add_bindingness_to_directives',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (33,'2026_07_30_190000_widen_directive_title',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (34,'2026_07_30_200000_create_aircraft_types_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (35,'2026_07_30_210000_add_aircraft_type_to_directives',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (36,'2026_07_30_220000_create_component_types_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (37,'2026_07_31_090000_add_general_flag_to_directives',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (38,'2026_07_31_090000_add_part66_grading_to_qualifications',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (39,'2026_07_31_091000_add_construction_to_aircraft',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (40,'2026_07_31_092000_add_pilot_owner_scope_to_cards',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (41,'2026_07_31_120000_create_directive_credentials',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (42,'2026_07_31_130000_add_type_support_to_aircraft_types',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (43,'2026_08_01_100000_drop_general_flag_from_directives',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (44,'2026_08_02_090000_create_aircraft_type_certificates_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (45,'2026_08_02_120000_add_app_authentication_to_users_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (46,'2026_08_03_120000_create_settings_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (47,'2026_08_03_140000_create_identity_seam_tables',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (48,'2026_08_03_160000_create_external_groups_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (49,'2026_08_04_120000_create_vereinsflieger_member_statuses_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (50,'2026_08_04_130000_create_vereinsflieger_connection_tables',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (51,'2026_08_04_140000_add_attempts_to_work_hour_transfers',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (52,'2026_08_04_150000_add_category_to_work_hour_transfers',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (53,'2026_08_05_100000_make_password_nullable',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (54,'2026_08_05_120000_add_access_lock_to_users_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (55,'2026_08_06_090000_create_purchase_orders_tables',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (56,'2026_08_06_140000_create_incoming_inspection_tables',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (57,'2026_08_06_150000_create_tooling_tables',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (58,'2026_08_06_160000_add_calibration_finding_to_tooling',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (59,'2026_08_06_170000_add_independent_inspection_to_cards',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (60,'2026_08_06_180000_add_approval_to_suppliers',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (61,'2026_08_06_190000_create_tool_issues_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (62,'2026_08_06_200000_create_maintenance_manuals_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (63,'2026_08_06_210000_add_manual_reference_to_cards',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (64,'2026_08_07_100000_add_certificate_fields_to_qualifications',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (65,'2026_08_07_190000_harden_reversal_chain',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (66,'2026_08_09_120000_grant_module_permissions_to_admin_role',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (67,'2026_08_09_130000_create_vereinsflieger_work_hour_categories_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (68,'2026_08_12_140000_link_component_types_to_part_types',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (69,'2026_08_12_180000_add_link_to_aircraft_documents',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (70,'2026_08_13_190000_sync_access_on_update',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (71,'2026_08_13_210000_add_report_signature_to_findings',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (72,'2026_08_13_230000_widen_directive_subject_columns',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (73,'2026_08_14_120000_add_external_signatory_to_releases',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (74,'2026_08_15_120000_add_sheet_variant_and_undercarriage_to_weighings',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (75,'2026_08_16_090000_add_powered_sheet_fields',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (76,'2026_08_17_100000_add_sheet_defaults_to_aircraft_types',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (77,'2026_08_17_140000_add_finding_report_to_work_orders',1);
COMMIT;
SET AUTOCOMMIT=@OLD_AUTOCOMMIT;
