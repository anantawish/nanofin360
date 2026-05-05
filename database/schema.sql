-- MariaDB dump 10.19  Distrib 10.4.32-MariaDB, for Win64 (AMD64)
--
-- Host: 127.0.0.1    Database: nanfinance
-- ------------------------------------------------------
-- Server version	10.4.32-MariaDB

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

--
-- Table structure for table `action_logs`
--

DROP TABLE IF EXISTS `action_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `action_logs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `module_key` varchar(80) NOT NULL,
  `action_type` varchar(40) NOT NULL,
  `record_uid` varchar(80) NOT NULL,
  `version_no` int(10) unsigned NOT NULL DEFAULT 1,
  `reason` varchar(500) DEFAULT '',
  `before_json` longtext DEFAULT NULL,
  `after_json` longtext DEFAULT NULL,
  `user_name` varchar(100) NOT NULL,
  `role_name` varchar(50) NOT NULL,
  `ip_address` varchar(45) DEFAULT '',
  `device_info` varchar(120) DEFAULT '',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_action_module_time` (`module_key`,`created_at`),
  KEY `idx_action_record` (`record_uid`,`version_no`)
) ENGINE=InnoDB AUTO_INCREMENT=27184 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `attitude_assessment_answers`
--

DROP TABLE IF EXISTS `attitude_assessment_answers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `attitude_assessment_answers` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `assessment_uid` varchar(80) NOT NULL,
  `version_no` int(10) unsigned NOT NULL DEFAULT 1,
  `question_set_id` bigint(20) unsigned NOT NULL,
  `question_set_item_id` bigint(20) unsigned NOT NULL,
  `question_code` varchar(20) NOT NULL,
  `question_no` int(10) unsigned NOT NULL,
  `dimension_code` varchar(80) NOT NULL,
  `question_text` text NOT NULL,
  `answer_value` tinyint(3) unsigned NOT NULL,
  `answer_text` text DEFAULT NULL,
  `created_by` varchar(100) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_attitude_answers_assessment` (`assessment_uid`,`version_no`),
  KEY `idx_attitude_answers_question` (`question_code`)
) ENGINE=InnoDB AUTO_INCREMENT=210176 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `attitude_assessment_dimensions`
--

DROP TABLE IF EXISTS `attitude_assessment_dimensions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `attitude_assessment_dimensions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `assessment_uid` varchar(80) NOT NULL,
  `version_no` int(10) unsigned NOT NULL DEFAULT 1,
  `dimension_code` varchar(80) NOT NULL,
  `dimension_label` varchar(200) NOT NULL,
  `raw_score` decimal(6,2) NOT NULL,
  `main_score` decimal(6,2) NOT NULL,
  `spillover_score` decimal(6,2) NOT NULL,
  `adjusted_score` decimal(6,2) NOT NULL,
  `posterior_low` decimal(10,6) NOT NULL,
  `posterior_mid` decimal(10,6) NOT NULL,
  `posterior_high` decimal(10,6) NOT NULL,
  `class_label` varchar(20) NOT NULL,
  `created_by` varchar(100) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_attitude_dimensions_assessment` (`assessment_uid`,`version_no`),
  KEY `idx_attitude_dimensions_code` (`dimension_code`)
) ENGINE=InnoDB AUTO_INCREMENT=42036 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `attitude_assessments`
--

DROP TABLE IF EXISTS `attitude_assessments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `attitude_assessments` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `assessment_uid` varchar(80) NOT NULL,
  `version_no` int(10) unsigned NOT NULL DEFAULT 1,
  `is_latest` tinyint(1) NOT NULL DEFAULT 1,
  `is_deleted` tinyint(1) NOT NULL DEFAULT 0,
  `module_key` varchar(80) NOT NULL,
  `workflow_source_id` bigint(20) unsigned NOT NULL,
  `workflow_record_uid` varchar(80) NOT NULL,
  `workflow_record_version` int(10) unsigned NOT NULL DEFAULT 1,
  `branch_code` varchar(60) DEFAULT '',
  `source_primary_ref` varchar(120) DEFAULT '',
  `source_primary_name` varchar(255) DEFAULT '',
  `contract_no` varchar(120) DEFAULT '',
  `applicant_name` varchar(255) DEFAULT '',
  `applicant_gender` varchar(20) DEFAULT 'unknown',
  `applicant_age` tinyint(3) unsigned DEFAULT NULL,
  `question_set_id` bigint(20) unsigned NOT NULL,
  `question_set_code` varchar(80) NOT NULL,
  `question_set_version` int(10) unsigned NOT NULL DEFAULT 1,
  `question_set_snapshot_json` longtext DEFAULT NULL,
  `answer_set_ref` varchar(120) DEFAULT '',
  `answers_json` longtext DEFAULT NULL,
  `overall_index` decimal(6,2) NOT NULL DEFAULT 0.00,
  `overall_class` varchar(20) NOT NULL DEFAULT 'mid',
  `posterior_low` decimal(10,6) NOT NULL DEFAULT 0.000000,
  `posterior_mid` decimal(10,6) NOT NULL DEFAULT 0.000000,
  `posterior_high` decimal(10,6) NOT NULL DEFAULT 0.000000,
  `result_json` longtext DEFAULT NULL,
  `created_by` varchar(100) NOT NULL,
  `created_role` varchar(50) NOT NULL DEFAULT '',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `created_ip` varchar(45) DEFAULT '',
  `created_device` varchar(120) DEFAULT '',
  `updated_by` varchar(100) DEFAULT '',
  `updated_role` varchar(50) DEFAULT '',
  `updated_at` datetime DEFAULT NULL,
  `updated_ip` varchar(45) DEFAULT '',
  `updated_device` varchar(120) DEFAULT '',
  `deleted_by` varchar(100) DEFAULT NULL,
  `deleted_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_attitude_assessment_version` (`assessment_uid`,`version_no`),
  KEY `idx_attitude_assessment_source` (`module_key`,`workflow_source_id`,`is_latest`,`is_deleted`),
  KEY `idx_attitude_assessment_branch` (`branch_code`,`is_latest`,`is_deleted`),
  KEY `idx_attitude_assessment_contract` (`contract_no`)
) ENGINE=InnoDB AUTO_INCREMENT=6006 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `attitude_question_items`
--

DROP TABLE IF EXISTS `attitude_question_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `attitude_question_items` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `set_id` bigint(20) unsigned NOT NULL,
  `question_code` varchar(20) NOT NULL,
  `question_no` int(10) unsigned NOT NULL,
  `dimension_code` varchar(80) NOT NULL,
  `dimension_label` varchar(200) NOT NULL,
  `question_text` text NOT NULL,
  `choice_map_json` longtext DEFAULT NULL,
  `is_deleted` tinyint(1) NOT NULL DEFAULT 0,
  `created_by` varchar(100) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `deleted_by` varchar(100) DEFAULT NULL,
  `deleted_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_attitude_question_item` (`set_id`,`question_code`),
  KEY `idx_attitude_question_set` (`set_id`,`question_no`)
) ENGINE=InnoDB AUTO_INCREMENT=71 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `attitude_question_sets`
--

DROP TABLE IF EXISTS `attitude_question_sets`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `attitude_question_sets` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `set_code` varchar(80) NOT NULL,
  `set_version` int(10) unsigned NOT NULL DEFAULT 1,
  `set_name` varchar(255) NOT NULL,
  `model_version` varchar(80) NOT NULL,
  `question_count` int(10) unsigned NOT NULL DEFAULT 0,
  `dimension_count` int(10) unsigned NOT NULL DEFAULT 0,
  `payload_json` longtext DEFAULT NULL,
  `is_latest` tinyint(1) NOT NULL DEFAULT 1,
  `is_deleted` tinyint(1) NOT NULL DEFAULT 0,
  `created_by` varchar(100) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_by` varchar(100) DEFAULT '',
  `updated_at` datetime DEFAULT NULL,
  `deleted_by` varchar(100) DEFAULT NULL,
  `deleted_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_attitude_set_version` (`set_code`,`set_version`),
  KEY `idx_attitude_set_latest` (`set_code`,`is_latest`,`is_deleted`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `customer_statement_ocr`
--

DROP TABLE IF EXISTS `customer_statement_ocr`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `customer_statement_ocr` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `module_key` varchar(80) NOT NULL DEFAULT 'customer_360',
  `workflow_record_id` bigint(20) unsigned NOT NULL,
  `record_uid` varchar(80) NOT NULL,
  `customer_code` varchar(80) DEFAULT '',
  `customer_name` varchar(255) DEFAULT '',
  `source_field` varchar(80) NOT NULL DEFAULT 'bank_statement_files',
  `source_file_url` text NOT NULL,
  `source_file_path` text DEFAULT NULL,
  `source_file_hash` char(64) NOT NULL,
  `source_file_mime` varchar(100) DEFAULT '',
  `scan_status` varchar(20) NOT NULL DEFAULT 'SUCCESS',
  `page_count` int(10) unsigned NOT NULL DEFAULT 0,
  `ocr_text` longtext DEFAULT NULL,
  `ocr_meta_json` longtext DEFAULT NULL,
  `error_message` text DEFAULT NULL,
  `created_by` varchar(100) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_customer_statement_file` (`module_key`,`record_uid`,`source_field`,`source_file_hash`),
  KEY `idx_customer_statement_ocr_customer` (`customer_code`,`created_at`),
  KEY `idx_customer_statement_ocr_record` (`module_key`,`workflow_record_id`),
  KEY `idx_customer_statement_ocr_status` (`scan_status`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `event_ledger`
--

DROP TABLE IF EXISTS `event_ledger`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `event_ledger` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `event_type` varchar(40) NOT NULL,
  `module_key` varchar(80) NOT NULL,
  `record_uid` varchar(80) NOT NULL,
  `version_no` int(10) unsigned NOT NULL DEFAULT 1,
  `event_payload` longtext DEFAULT NULL,
  `actor_name` varchar(100) NOT NULL,
  `actor_role` varchar(50) NOT NULL,
  `ip_address` varchar(45) DEFAULT '',
  `device_info` varchar(120) DEFAULT '',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `event_hash` varchar(128) GENERATED ALWAYS AS (sha2(concat(`event_type`,'|',`module_key`,'|',`record_uid`,'|',`version_no`,'|',`actor_name`,'|',`created_at`),256)) STORED,
  PRIMARY KEY (`id`),
  KEY `idx_event_module_time` (`module_key`,`created_at`),
  KEY `idx_event_actor` (`actor_name`,`created_at`),
  KEY `idx_event_hash` (`event_hash`)
) ENGINE=InnoDB AUTO_INCREMENT=27184 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `fresher_affordability`
--

DROP TABLE IF EXISTS `fresher_affordability`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `fresher_affordability` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `assessment_code` varchar(80) NOT NULL,
  `customer_code` varchar(80) NOT NULL,
  `customer_name` varchar(255) DEFAULT '',
  `branch_code` varchar(60) DEFAULT '',
  `monthly_income` decimal(18,2) NOT NULL DEFAULT 0.00,
  `occupation_expense` decimal(18,2) NOT NULL DEFAULT 0.00,
  `family_expense` decimal(18,2) NOT NULL DEFAULT 0.00,
  `existing_debt` decimal(18,2) NOT NULL DEFAULT 0.00,
  `attitude_score` decimal(6,2) NOT NULL DEFAULT 0.00,
  `net_capacity` decimal(18,2) NOT NULL DEFAULT 0.00,
  `recommended_installment` decimal(18,2) NOT NULL DEFAULT 0.00,
  `recommended_limit` decimal(18,2) NOT NULL DEFAULT 0.00,
  `result_status` varchar(40) NOT NULL DEFAULT 'REVIEW',
  `note_text` text DEFAULT NULL,
  `assessment_date` date DEFAULT NULL,
  `is_deleted` tinyint(1) NOT NULL DEFAULT 0,
  `created_by` varchar(100) NOT NULL DEFAULT '',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_by` varchar(100) DEFAULT '',
  `updated_at` datetime DEFAULT NULL,
  `deleted_by` varchar(100) DEFAULT NULL,
  `deleted_at` datetime DEFAULT NULL,
  `document_score` decimal(6,2) NOT NULL DEFAULT 0.00,
  `collateral_factor` decimal(8,4) NOT NULL DEFAULT 0.7500,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_fresher_assessment_code` (`assessment_code`),
  KEY `idx_fresher_assessment_scope` (`is_deleted`,`branch_code`,`customer_code`)
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `fresher_branches`
--

DROP TABLE IF EXISTS `fresher_branches`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `fresher_branches` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `branch_code` varchar(60) NOT NULL,
  `branch_name` varchar(200) NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `is_deleted` tinyint(1) NOT NULL DEFAULT 0,
  `created_by` varchar(100) NOT NULL DEFAULT '',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_by` varchar(100) DEFAULT '',
  `updated_at` datetime DEFAULT NULL,
  `deleted_by` varchar(100) DEFAULT NULL,
  `deleted_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_fresher_branch_code` (`branch_code`),
  KEY `idx_fresher_branch_active` (`is_deleted`,`is_active`,`branch_code`)
) ENGINE=InnoDB AUTO_INCREMENT=31 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `fresher_collections`
--

DROP TABLE IF EXISTS `fresher_collections`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `fresher_collections` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `followup_code` varchar(80) NOT NULL,
  `contract_code` varchar(80) NOT NULL,
  `customer_code` varchar(80) DEFAULT '',
  `customer_name` varchar(255) DEFAULT '',
  `branch_code` varchar(60) DEFAULT '',
  `dpd_days` int(11) NOT NULL DEFAULT 0,
  `followup_date` date DEFAULT NULL,
  `channel` varchar(40) DEFAULT '',
  `outcome` text DEFAULT NULL,
  `promise_date` date DEFAULT NULL,
  `promise_amount` decimal(18,2) NOT NULL DEFAULT 0.00,
  `next_action_date` date DEFAULT NULL,
  `collector_code` varchar(80) DEFAULT '',
  `collector_name` varchar(255) DEFAULT '',
  `collection_status` varchar(40) NOT NULL DEFAULT 'OPEN',
  `note_text` text DEFAULT NULL,
  `is_deleted` tinyint(1) NOT NULL DEFAULT 0,
  `created_by` varchar(100) NOT NULL DEFAULT '',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_by` varchar(100) DEFAULT '',
  `updated_at` datetime DEFAULT NULL,
  `deleted_by` varchar(100) DEFAULT NULL,
  `deleted_at` datetime DEFAULT NULL,
  `overdue_installments` int(11) NOT NULL DEFAULT 0,
  `overdue_principal_amount` decimal(18,2) NOT NULL DEFAULT 0.00,
  `overdue_due_amount` decimal(18,2) NOT NULL DEFAULT 0.00,
  `requested_collection_fee` decimal(18,2) NOT NULL DEFAULT 0.00,
  `collection_fee_amount` decimal(18,2) NOT NULL DEFAULT 0.00,
  `collection_fee_note` varchar(255) DEFAULT '',
  `late_penalty_rate` decimal(8,4) NOT NULL DEFAULT 0.0000,
  `late_penalty_amount` decimal(18,2) NOT NULL DEFAULT 0.00,
  `contract_interest_rate` decimal(8,4) NOT NULL DEFAULT 0.0000,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_fresher_followup_code` (`followup_code`),
  KEY `idx_fresher_collection_scope` (`is_deleted`,`branch_code`,`contract_code`,`collection_status`),
  KEY `idx_fresher_collection_next` (`next_action_date`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `fresher_collectors`
--

DROP TABLE IF EXISTS `fresher_collectors`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `fresher_collectors` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `collector_code` varchar(60) NOT NULL,
  `collector_name` varchar(200) NOT NULL,
  `phone_number` varchar(30) DEFAULT '',
  `branch_code` varchar(60) DEFAULT '',
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `is_deleted` tinyint(1) NOT NULL DEFAULT 0,
  `created_by` varchar(100) NOT NULL DEFAULT '',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_by` varchar(100) DEFAULT '',
  `updated_at` datetime DEFAULT NULL,
  `deleted_by` varchar(100) DEFAULT NULL,
  `deleted_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_fresher_collector_code` (`collector_code`),
  KEY `idx_fresher_collector_scope` (`is_deleted`,`is_active`,`branch_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `fresher_customers`
--

DROP TABLE IF EXISTS `fresher_customers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `fresher_customers` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `customer_code` varchar(80) NOT NULL,
  `first_name` varchar(120) NOT NULL,
  `last_name` varchar(120) NOT NULL,
  `phone_number` varchar(30) DEFAULT '',
  `cid_tax_id` varchar(40) DEFAULT '',
  `address_line` varchar(255) DEFAULT '',
  `subdistrict` varchar(120) DEFAULT '',
  `district` varchar(120) DEFAULT '',
  `province` varchar(120) DEFAULT '',
  `occupation` varchar(200) DEFAULT '',
  `monthly_income` decimal(18,2) NOT NULL DEFAULT 0.00,
  `family_dependents` int(11) NOT NULL DEFAULT 0,
  `attitude_score` decimal(6,2) NOT NULL DEFAULT 0.00,
  `branch_code` varchar(60) DEFAULT '',
  `customer_photo_path` varchar(255) DEFAULT '',
  `note_text` text DEFAULT NULL,
  `customer_status` varchar(40) NOT NULL DEFAULT 'ACTIVE',
  `is_deleted` tinyint(1) NOT NULL DEFAULT 0,
  `created_by` varchar(100) NOT NULL DEFAULT '',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_by` varchar(100) DEFAULT '',
  `updated_at` datetime DEFAULT NULL,
  `deleted_by` varchar(100) DEFAULT NULL,
  `deleted_at` datetime DEFAULT NULL,
  `doc_id_card_path` varchar(255) DEFAULT '',
  `doc_house_reg_path` varchar(255) DEFAULT '',
  `doc_vehicle_ownership_path` varchar(255) DEFAULT '',
  `doc_land_ownership_path` varchar(255) DEFAULT '',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_fresher_customer_code` (`customer_code`),
  KEY `idx_fresher_customer_scope` (`is_deleted`,`branch_code`,`customer_status`),
  KEY `idx_fresher_customer_name` (`first_name`,`last_name`)
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `fresher_documents`
--

DROP TABLE IF EXISTS `fresher_documents`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `fresher_documents` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `document_code` varchar(80) NOT NULL,
  `contract_code` varchar(80) DEFAULT '',
  `customer_code` varchar(80) DEFAULT '',
  `customer_name` varchar(255) DEFAULT '',
  `branch_code` varchar(60) DEFAULT '',
  `document_type` varchar(120) DEFAULT '',
  `file_name` varchar(255) DEFAULT '',
  `file_path` varchar(255) DEFAULT '',
  `note_text` text DEFAULT NULL,
  `is_deleted` tinyint(1) NOT NULL DEFAULT 0,
  `created_by` varchar(100) NOT NULL DEFAULT '',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_by` varchar(100) DEFAULT '',
  `updated_at` datetime DEFAULT NULL,
  `deleted_by` varchar(100) DEFAULT NULL,
  `deleted_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_fresher_document_code` (`document_code`),
  KEY `idx_fresher_doc_scope` (`is_deleted`,`branch_code`,`contract_code`,`customer_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `fresher_hire_purchase`
--

DROP TABLE IF EXISTS `fresher_hire_purchase`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `fresher_hire_purchase` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `contract_code` varchar(80) NOT NULL,
  `customer_code` varchar(80) NOT NULL,
  `customer_name` varchar(255) DEFAULT '',
  `branch_code` varchar(60) DEFAULT '',
  `product_code` varchar(60) DEFAULT '',
  `product_name` varchar(255) DEFAULT '',
  `model_name` varchar(255) DEFAULT '',
  `serial_number` varchar(120) DEFAULT '',
  `product_image_path` varchar(255) DEFAULT '',
  `contract_amount` decimal(18,2) NOT NULL DEFAULT 0.00,
  `down_payment` decimal(18,2) NOT NULL DEFAULT 0.00,
  `financed_amount` decimal(18,2) NOT NULL DEFAULT 0.00,
  `annual_interest_rate` decimal(8,4) NOT NULL DEFAULT 0.0000,
  `installment_amount` decimal(18,2) NOT NULL DEFAULT 0.00,
  `installment_count` int(11) NOT NULL DEFAULT 0,
  `start_date` date DEFAULT NULL,
  `due_day` int(11) NOT NULL DEFAULT 1,
  `contract_status` varchar(40) NOT NULL DEFAULT 'ACTIVE',
  `note_text` text DEFAULT NULL,
  `is_deleted` tinyint(1) NOT NULL DEFAULT 0,
  `created_by` varchar(100) NOT NULL DEFAULT '',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_by` varchar(100) DEFAULT '',
  `updated_at` datetime DEFAULT NULL,
  `deleted_by` varchar(100) DEFAULT NULL,
  `deleted_at` datetime DEFAULT NULL,
  `flat_interest_rate` decimal(8,4) NOT NULL DEFAULT 0.0000,
  `eir_interest_rate` decimal(8,4) NOT NULL DEFAULT 0.0000,
  `calculation_method` varchar(40) NOT NULL DEFAULT 'FLAT_TO_EIR',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_fresher_contract_code` (`contract_code`),
  KEY `idx_fresher_contract_scope` (`is_deleted`,`branch_code`,`customer_code`,`contract_status`),
  KEY `idx_fresher_contract_serial` (`serial_number`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `fresher_hire_purchase_items`
--

DROP TABLE IF EXISTS `fresher_hire_purchase_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `fresher_hire_purchase_items` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `contract_code` varchar(80) NOT NULL,
  `branch_code` varchar(60) DEFAULT '',
  `product_code` varchar(60) NOT NULL,
  `product_name` varchar(200) DEFAULT '',
  `model_name` varchar(200) DEFAULT '',
  `serial_number` varchar(120) DEFAULT '',
  `quantity` int(11) NOT NULL DEFAULT 1,
  `unit_price` decimal(18,2) NOT NULL DEFAULT 0.00,
  `line_amount` decimal(18,2) NOT NULL DEFAULT 0.00,
  `is_deleted` tinyint(1) NOT NULL DEFAULT 0,
  `created_by` varchar(100) NOT NULL DEFAULT '',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_by` varchar(100) DEFAULT '',
  `updated_at` datetime DEFAULT NULL,
  `deleted_by` varchar(100) DEFAULT NULL,
  `deleted_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_fresher_hp_item_scope` (`is_deleted`,`contract_code`,`branch_code`),
  KEY `idx_fresher_hp_item_product` (`product_code`,`model_name`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `fresher_installments`
--

DROP TABLE IF EXISTS `fresher_installments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `fresher_installments` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `contract_code` varchar(80) NOT NULL,
  `customer_code` varchar(80) NOT NULL,
  `customer_name` varchar(255) DEFAULT '',
  `branch_code` varchar(60) DEFAULT '',
  `installment_no` int(11) NOT NULL DEFAULT 1,
  `due_date` date DEFAULT NULL,
  `installment_amount` decimal(18,2) NOT NULL DEFAULT 0.00,
  `principal_amount` decimal(18,2) NOT NULL DEFAULT 0.00,
  `interest_amount` decimal(18,2) NOT NULL DEFAULT 0.00,
  `paid_amount` decimal(18,2) NOT NULL DEFAULT 0.00,
  `paid_date` date DEFAULT NULL,
  `payment_status` varchar(30) NOT NULL DEFAULT 'UNPAID',
  `receipt_no` varchar(120) DEFAULT '',
  `note_text` text DEFAULT NULL,
  `is_deleted` tinyint(1) NOT NULL DEFAULT 0,
  `created_by` varchar(100) NOT NULL DEFAULT '',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_by` varchar(100) DEFAULT '',
  `updated_at` datetime DEFAULT NULL,
  `deleted_by` varchar(100) DEFAULT NULL,
  `deleted_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_fresher_installment` (`contract_code`,`installment_no`),
  KEY `idx_fresher_installment_scope` (`is_deleted`,`branch_code`,`contract_code`,`payment_status`),
  KEY `idx_fresher_installment_due` (`due_date`,`payment_status`)
) ENGINE=InnoDB AUTO_INCREMENT=25 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `fresher_legal_cases`
--

DROP TABLE IF EXISTS `fresher_legal_cases`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `fresher_legal_cases` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `case_code` varchar(80) NOT NULL,
  `contract_code` varchar(80) NOT NULL,
  `customer_code` varchar(80) DEFAULT '',
  `customer_name` varchar(255) DEFAULT '',
  `branch_code` varchar(60) DEFAULT '',
  `filing_date` date DEFAULT NULL,
  `court_name` varchar(255) DEFAULT '',
  `case_no` varchar(120) DEFAULT '',
  `claim_amount` decimal(18,2) NOT NULL DEFAULT 0.00,
  `paid_amount` decimal(18,2) NOT NULL DEFAULT 0.00,
  `paid_date` date DEFAULT NULL,
  `case_status` varchar(40) NOT NULL DEFAULT 'OPEN',
  `note_text` text DEFAULT NULL,
  `is_deleted` tinyint(1) NOT NULL DEFAULT 0,
  `created_by` varchar(100) NOT NULL DEFAULT '',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_by` varchar(100) DEFAULT '',
  `updated_at` datetime DEFAULT NULL,
  `deleted_by` varchar(100) DEFAULT NULL,
  `deleted_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_fresher_case_code` (`case_code`),
  KEY `idx_fresher_case_scope` (`is_deleted`,`branch_code`,`contract_code`,`case_status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `fresher_payoff_settlements`
--

DROP TABLE IF EXISTS `fresher_payoff_settlements`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `fresher_payoff_settlements` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `settlement_code` varchar(80) NOT NULL,
  `contract_code` varchar(80) NOT NULL,
  `customer_code` varchar(80) DEFAULT '',
  `customer_name` varchar(255) DEFAULT '',
  `branch_code` varchar(60) DEFAULT '',
  `quote_date` date DEFAULT NULL,
  `paid_ratio` decimal(8,4) NOT NULL DEFAULT 0.0000,
  `discount_tier` varchar(40) DEFAULT '',
  `discount_rate` decimal(8,4) NOT NULL DEFAULT 0.0000,
  `remaining_principal` decimal(18,2) NOT NULL DEFAULT 0.00,
  `remaining_interest` decimal(18,2) NOT NULL DEFAULT 0.00,
  `discount_interest` decimal(18,2) NOT NULL DEFAULT 0.00,
  `payable_interest` decimal(18,2) NOT NULL DEFAULT 0.00,
  `payoff_amount` decimal(18,2) NOT NULL DEFAULT 0.00,
  `receipt_no` varchar(120) DEFAULT '',
  `note_text` text DEFAULT NULL,
  `is_deleted` tinyint(1) NOT NULL DEFAULT 0,
  `created_by` varchar(100) NOT NULL DEFAULT '',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_by` varchar(100) DEFAULT '',
  `updated_at` datetime DEFAULT NULL,
  `deleted_by` varchar(100) DEFAULT NULL,
  `deleted_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_fresher_settlement_code` (`settlement_code`),
  KEY `idx_fresher_settlement_scope` (`is_deleted`,`branch_code`,`contract_code`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `fresher_products`
--

DROP TABLE IF EXISTS `fresher_products`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `fresher_products` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `product_code` varchar(60) NOT NULL,
  `product_name` varchar(200) NOT NULL,
  `model_name` varchar(200) NOT NULL,
  `category_name` varchar(120) DEFAULT '',
  `default_price` decimal(18,2) NOT NULL DEFAULT 0.00,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `is_deleted` tinyint(1) NOT NULL DEFAULT 0,
  `created_by` varchar(100) NOT NULL DEFAULT '',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_by` varchar(100) DEFAULT '',
  `updated_at` datetime DEFAULT NULL,
  `deleted_by` varchar(100) DEFAULT NULL,
  `deleted_at` datetime DEFAULT NULL,
  `sale_price` decimal(18,2) NOT NULL DEFAULT 0.00,
  `stock_quantity` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_fresher_product_code` (`product_code`),
  KEY `idx_fresher_product_active` (`is_deleted`,`is_active`,`product_name`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `fresher_receipt_items`
--

DROP TABLE IF EXISTS `fresher_receipt_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `fresher_receipt_items` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `receipt_code` varchar(80) NOT NULL,
  `contract_code` varchar(80) NOT NULL,
  `installment_id` bigint(20) unsigned NOT NULL DEFAULT 0,
  `installment_no` int(11) NOT NULL DEFAULT 0,
  `due_date` date DEFAULT NULL,
  `installment_amount` decimal(18,2) NOT NULL DEFAULT 0.00,
  `paid_before` decimal(18,2) NOT NULL DEFAULT 0.00,
  `pay_amount` decimal(18,2) NOT NULL DEFAULT 0.00,
  `paid_after` decimal(18,2) NOT NULL DEFAULT 0.00,
  `principal_paid` decimal(18,2) NOT NULL DEFAULT 0.00,
  `interest_paid` decimal(18,2) NOT NULL DEFAULT 0.00,
  `payment_status_after` varchar(30) NOT NULL DEFAULT 'PARTIAL',
  `note_text` text DEFAULT NULL,
  `is_deleted` tinyint(1) NOT NULL DEFAULT 0,
  `created_by` varchar(100) NOT NULL DEFAULT '',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_by` varchar(100) DEFAULT '',
  `updated_at` datetime DEFAULT NULL,
  `deleted_by` varchar(100) DEFAULT NULL,
  `deleted_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_fresher_receipt_item_scope` (`is_deleted`,`receipt_code`,`contract_code`),
  KEY `idx_fresher_receipt_item_installment` (`installment_id`,`installment_no`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `fresher_receipts`
--

DROP TABLE IF EXISTS `fresher_receipts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `fresher_receipts` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `receipt_code` varchar(80) NOT NULL,
  `contract_code` varchar(80) NOT NULL,
  `customer_code` varchar(80) DEFAULT '',
  `customer_name` varchar(255) DEFAULT '',
  `branch_code` varchar(60) DEFAULT '',
  `payment_date` date DEFAULT NULL,
  `payment_method` varchar(40) DEFAULT '',
  `reference_no` varchar(120) DEFAULT '',
  `total_paid_amount` decimal(18,2) NOT NULL DEFAULT 0.00,
  `total_principal_paid` decimal(18,2) NOT NULL DEFAULT 0.00,
  `total_interest_paid` decimal(18,2) NOT NULL DEFAULT 0.00,
  `total_late_penalty` decimal(18,2) NOT NULL DEFAULT 0.00,
  `total_collection_fee` decimal(18,2) NOT NULL DEFAULT 0.00,
  `grand_total` decimal(18,2) NOT NULL DEFAULT 0.00,
  `note_text` text DEFAULT NULL,
  `receipt_status` varchar(30) NOT NULL DEFAULT 'POSTED',
  `is_deleted` tinyint(1) NOT NULL DEFAULT 0,
  `created_by` varchar(100) NOT NULL DEFAULT '',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_by` varchar(100) DEFAULT '',
  `updated_at` datetime DEFAULT NULL,
  `deleted_by` varchar(100) DEFAULT NULL,
  `deleted_at` datetime DEFAULT NULL,
  `payment_attachment_path` varchar(255) DEFAULT '',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_fresher_receipt_code` (`receipt_code`),
  KEY `idx_fresher_receipt_scope` (`is_deleted`,`branch_code`,`contract_code`,`payment_date`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `fresher_repossessions`
--

DROP TABLE IF EXISTS `fresher_repossessions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `fresher_repossessions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `repo_code` varchar(80) NOT NULL,
  `contract_code` varchar(80) NOT NULL,
  `customer_code` varchar(80) DEFAULT '',
  `customer_name` varchar(255) DEFAULT '',
  `branch_code` varchar(60) DEFAULT '',
  `repossession_date` date DEFAULT NULL,
  `asset_condition` varchar(200) DEFAULT '',
  `storage_location` varchar(255) DEFAULT '',
  `appraised_value` decimal(18,2) NOT NULL DEFAULT 0.00,
  `sale_value` decimal(18,2) NOT NULL DEFAULT 0.00,
  `repo_status` varchar(40) NOT NULL DEFAULT 'PENDING',
  `note_text` text DEFAULT NULL,
  `is_deleted` tinyint(1) NOT NULL DEFAULT 0,
  `created_by` varchar(100) NOT NULL DEFAULT '',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_by` varchar(100) DEFAULT '',
  `updated_at` datetime DEFAULT NULL,
  `deleted_by` varchar(100) DEFAULT NULL,
  `deleted_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_fresher_repo_code` (`repo_code`),
  KEY `idx_fresher_repo_scope` (`is_deleted`,`branch_code`,`contract_code`,`repo_status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `master_branch`
--

DROP TABLE IF EXISTS `master_branch`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `master_branch` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `record_uid` varchar(80) NOT NULL,
  `version_no` int(10) unsigned NOT NULL DEFAULT 1,
  `is_latest` tinyint(1) NOT NULL DEFAULT 1,
  `is_deleted` tinyint(1) NOT NULL DEFAULT 0,
  `branch_code` varchar(60) NOT NULL,
  `branch_name` varchar(200) NOT NULL,
  `region_name` varchar(120) DEFAULT '',
  `data_json` longtext DEFAULT NULL,
  `created_by` varchar(100) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_by` varchar(100) DEFAULT '',
  `updated_at` datetime DEFAULT NULL,
  `deleted_by` varchar(100) DEFAULT NULL,
  `deleted_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_master_branch_version` (`record_uid`,`version_no`),
  KEY `idx_master_branch_latest` (`is_latest`,`is_deleted`,`branch_code`)
) ENGINE=InnoDB AUTO_INCREMENT=36 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `master_car_model`
--

DROP TABLE IF EXISTS `master_car_model`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `master_car_model` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `record_uid` varchar(80) NOT NULL,
  `version_no` int(10) unsigned NOT NULL DEFAULT 1,
  `is_latest` tinyint(1) NOT NULL DEFAULT 1,
  `is_deleted` tinyint(1) NOT NULL DEFAULT 0,
  `brand_name` varchar(120) NOT NULL,
  `model_name` varchar(160) NOT NULL,
  `data_json` longtext DEFAULT NULL,
  `created_by` varchar(100) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_by` varchar(100) DEFAULT '',
  `updated_at` datetime DEFAULT NULL,
  `deleted_by` varchar(100) DEFAULT NULL,
  `deleted_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_master_car_model_version` (`record_uid`,`version_no`),
  KEY `idx_master_car_model_latest` (`is_latest`,`is_deleted`,`brand_name`,`model_name`),
  KEY `idx_master_car_model_brand` (`brand_name`,`model_name`)
) ENGINE=InnoDB AUTO_INCREMENT=37 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `master_collateral`
--

DROP TABLE IF EXISTS `master_collateral`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `master_collateral` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `record_uid` varchar(80) NOT NULL,
  `version_no` int(10) unsigned NOT NULL DEFAULT 1,
  `is_latest` tinyint(1) NOT NULL DEFAULT 1,
  `is_deleted` tinyint(1) NOT NULL DEFAULT 0,
  `collateral_code` varchar(80) NOT NULL,
  `collateral_type` varchar(80) NOT NULL,
  `owner_name` varchar(255) DEFAULT '',
  `appraised_value` decimal(18,2) DEFAULT NULL,
  `data_json` longtext DEFAULT NULL,
  `created_by` varchar(100) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_by` varchar(100) DEFAULT '',
  `updated_at` datetime DEFAULT NULL,
  `deleted_by` varchar(100) DEFAULT NULL,
  `deleted_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_master_collateral_version` (`record_uid`,`version_no`),
  KEY `idx_master_collateral_latest` (`is_latest`,`is_deleted`,`collateral_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `master_contract`
--

DROP TABLE IF EXISTS `master_contract`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `master_contract` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `record_uid` varchar(80) NOT NULL,
  `version_no` int(10) unsigned NOT NULL DEFAULT 1,
  `is_latest` tinyint(1) NOT NULL DEFAULT 1,
  `is_deleted` tinyint(1) NOT NULL DEFAULT 0,
  `contract_no` varchar(80) NOT NULL,
  `customer_code` varchar(80) DEFAULT '',
  `branch_code` varchar(60) DEFAULT '',
  `product_code` varchar(60) DEFAULT '',
  `principal_amount` decimal(18,2) DEFAULT NULL,
  `data_json` longtext DEFAULT NULL,
  `created_by` varchar(100) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_by` varchar(100) DEFAULT '',
  `updated_at` datetime DEFAULT NULL,
  `deleted_by` varchar(100) DEFAULT NULL,
  `deleted_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_master_contract_version` (`record_uid`,`version_no`),
  KEY `idx_master_contract_latest` (`is_latest`,`is_deleted`,`contract_no`)
) ENGINE=InnoDB AUTO_INCREMENT=6133 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `master_customer`
--

DROP TABLE IF EXISTS `master_customer`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `master_customer` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `record_uid` varchar(80) NOT NULL,
  `version_no` int(10) unsigned NOT NULL DEFAULT 1,
  `is_latest` tinyint(1) NOT NULL DEFAULT 1,
  `is_deleted` tinyint(1) NOT NULL DEFAULT 0,
  `customer_code` varchar(80) NOT NULL,
  `customer_name` varchar(255) NOT NULL,
  `cid_tax_id` varchar(40) DEFAULT '',
  `phone_number` varchar(30) DEFAULT '',
  `data_json` longtext DEFAULT NULL,
  `created_by` varchar(100) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_by` varchar(100) DEFAULT '',
  `updated_at` datetime DEFAULT NULL,
  `deleted_by` varchar(100) DEFAULT NULL,
  `deleted_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_master_customer_version` (`record_uid`,`version_no`),
  KEY `idx_master_customer_latest` (`is_latest`,`is_deleted`,`customer_code`)
) ENGINE=InnoDB AUTO_INCREMENT=6001 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `master_occupation`
--

DROP TABLE IF EXISTS `master_occupation`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `master_occupation` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `record_uid` varchar(80) NOT NULL,
  `version_no` int(10) unsigned NOT NULL DEFAULT 1,
  `is_latest` tinyint(1) NOT NULL DEFAULT 1,
  `is_deleted` tinyint(1) NOT NULL DEFAULT 0,
  `occupation_code` varchar(60) NOT NULL,
  `occupation_name` varchar(200) NOT NULL,
  `employment_type` varchar(30) NOT NULL,
  `province_name` varchar(120) NOT NULL,
  `avg_income_min` decimal(12,2) NOT NULL DEFAULT 0.00,
  `avg_income_max` decimal(12,2) NOT NULL DEFAULT 0.00,
  `avg_income_default` decimal(12,2) NOT NULL DEFAULT 0.00,
  `agriculture_detail` text DEFAULT NULL,
  `data_json` longtext DEFAULT NULL,
  `created_by` varchar(100) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_by` varchar(100) DEFAULT '',
  `updated_at` datetime DEFAULT NULL,
  `deleted_by` varchar(100) DEFAULT NULL,
  `deleted_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_master_occupation_version` (`record_uid`,`version_no`),
  KEY `idx_master_occupation_latest` (`is_latest`,`is_deleted`,`occupation_code`,`province_name`),
  KEY `idx_master_occupation_type` (`employment_type`,`province_name`)
) ENGINE=InnoDB AUTO_INCREMENT=64 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `master_product`
--

DROP TABLE IF EXISTS `master_product`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `master_product` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `record_uid` varchar(80) NOT NULL,
  `version_no` int(10) unsigned NOT NULL DEFAULT 1,
  `is_latest` tinyint(1) NOT NULL DEFAULT 1,
  `is_deleted` tinyint(1) NOT NULL DEFAULT 0,
  `product_code` varchar(60) NOT NULL,
  `product_name` varchar(200) NOT NULL,
  `rate_cap_pct` decimal(10,2) DEFAULT NULL,
  `tenor_max_month` int(11) DEFAULT NULL,
  `data_json` longtext DEFAULT NULL,
  `created_by` varchar(100) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_by` varchar(100) DEFAULT '',
  `updated_at` datetime DEFAULT NULL,
  `deleted_by` varchar(100) DEFAULT NULL,
  `deleted_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_master_product_version` (`record_uid`,`version_no`),
  KEY `idx_master_product_latest` (`is_latest`,`is_deleted`,`product_code`)
) ENGINE=InnoDB AUTO_INCREMENT=33 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `notification_logs`
--

DROP TABLE IF EXISTS `notification_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `notification_logs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `module_key` varchar(80) NOT NULL,
  `record_uid` varchar(80) DEFAULT '',
  `level_name` varchar(20) NOT NULL DEFAULT 'INFO',
  `message_text` varchar(500) NOT NULL,
  `user_name` varchar(100) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_notify_module_time` (`module_key`,`created_at`),
  KEY `idx_notify_user_time` (`user_name`,`created_at`)
) ENGINE=InnoDB AUTO_INCREMENT=21179 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `system_users`
--

DROP TABLE IF EXISTS `system_users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `system_users` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_name` varchar(100) NOT NULL,
  `display_name` varchar(150) NOT NULL,
  `role_name` varchar(50) NOT NULL,
  `is_latest` tinyint(1) NOT NULL DEFAULT 1,
  `is_deleted` tinyint(1) NOT NULL DEFAULT 0,
  `created_by` varchar(100) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_by` varchar(100) DEFAULT '',
  `updated_at` datetime DEFAULT NULL,
  `deleted_by` varchar(100) DEFAULT NULL,
  `deleted_at` datetime DEFAULT NULL,
  `profile_json` longtext DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `user_name` (`user_name`),
  KEY `idx_user_role` (`role_name`)
) ENGINE=InnoDB AUTO_INCREMENT=178 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `workflow_records`
--

DROP TABLE IF EXISTS `workflow_records`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `workflow_records` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `module_key` varchar(80) NOT NULL,
  `record_uid` varchar(80) NOT NULL,
  `version_no` int(10) unsigned NOT NULL DEFAULT 1,
  `is_latest` tinyint(1) NOT NULL DEFAULT 1,
  `is_deleted` tinyint(1) NOT NULL DEFAULT 0,
  `record_status` varchar(30) NOT NULL DEFAULT 'PENDING_CHECKER',
  `primary_name` varchar(255) DEFAULT '',
  `primary_ref` varchar(120) DEFAULT '',
  `customer_ref` varchar(120) DEFAULT '',
  `branch_code` varchar(60) DEFAULT '',
  `risk_level` varchar(80) DEFAULT '',
  `amount` decimal(18,2) DEFAULT NULL,
  `event_date` date DEFAULT NULL,
  `data_json` longtext DEFAULT NULL,
  `consent_flag` tinyint(1) NOT NULL DEFAULT 0,
  `risk_flags` varchar(255) DEFAULT '',
  `note_text` text DEFAULT NULL,
  `created_by` varchar(100) NOT NULL,
  `created_role` varchar(50) NOT NULL DEFAULT 'maker',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `created_ip` varchar(45) DEFAULT '',
  `created_device` varchar(120) DEFAULT '',
  `updated_by` varchar(100) DEFAULT '',
  `updated_role` varchar(50) DEFAULT '',
  `updated_at` datetime DEFAULT NULL,
  `updated_ip` varchar(45) DEFAULT '',
  `updated_device` varchar(120) DEFAULT '',
  `checker_by` varchar(100) DEFAULT NULL,
  `checker_at` datetime DEFAULT NULL,
  `deleted_by` varchar(100) DEFAULT NULL,
  `deleted_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_record_version` (`module_key`,`record_uid`,`version_no`),
  KEY `idx_module_latest` (`module_key`,`is_latest`,`is_deleted`),
  KEY `idx_module_primary_ref` (`module_key`,`primary_ref`),
  KEY `idx_status` (`record_status`),
  KEY `idx_updated_at` (`updated_at`),
  KEY `idx_module_latest_branch_id` (`module_key`,`is_latest`,`branch_code`,`id`),
  KEY `idx_module_latest_branch_status` (`module_key`,`is_latest`,`branch_code`,`record_status`,`is_deleted`),
  KEY `idx_module_latest_record_uid` (`module_key`,`is_latest`,`record_uid`,`id`),
  KEY `idx_module_latest_primary_ref` (`module_key`,`is_latest`,`primary_ref`,`id`),
  KEY `idx_module_latest_customer_ref` (`module_key`,`is_latest`,`customer_ref`,`id`),
  KEY `idx_module_latest_primary_name` (`module_key`,`is_latest`,`primary_name`,`id`)
) ENGINE=InnoDB AUTO_INCREMENT=22007 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping events for database 'nanfinance'
--

--
-- Dumping routines for database 'nanfinance'
--
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2026-05-06  0:44:16
