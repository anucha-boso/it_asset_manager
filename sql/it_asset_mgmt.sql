-- MySQL dump 10.13  Distrib 8.0.19, for Win64 (x86_64)
--
-- Host: 127.0.0.1    Database: it_asset_mgmt
-- ------------------------------------------------------
-- Server version	5.5.5-10.11.14-MariaDB-0ubuntu0.24.04.1

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!50503 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

--
-- Table structure for table `access_application_levels`
--

DROP TABLE IF EXISTS `access_application_levels`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `access_application_levels` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `application_id` int(11) NOT NULL,
  `level_name` varchar(50) NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_app_level` (`application_id`,`level_name`),
  CONSTRAINT `fk_level_app` FOREIGN KEY (`application_id`) REFERENCES `access_applications` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `access_application_levels`
--

LOCK TABLES `access_application_levels` WRITE;
/*!40000 ALTER TABLE `access_application_levels` DISABLE KEYS */;
INSERT INTO `access_application_levels` VALUES (1,3,'Checker',1),(2,3,'User',1),(3,3,'Admin',1),(4,6,'View-only',1),(5,6,'Edit',1);
/*!40000 ALTER TABLE `access_application_levels` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `access_applications`
--

DROP TABLE IF EXISTS `access_applications`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `access_applications` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `app_code` varchar(30) NOT NULL,
  `app_name` varchar(150) NOT NULL,
  `description` text DEFAULT NULL,
  `default_approver1_employee_id` int(11) DEFAULT NULL,
  `default_approver2_employee_id` int(11) DEFAULT NULL,
  `requires_approver2` tinyint(1) NOT NULL DEFAULT 0,
  `it_action_note` varchar(150) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_app_code` (`app_code`)
) ENGINE=InnoDB AUTO_INCREMENT=9 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `access_applications`
--

LOCK TABLES `access_applications` WRITE;
/*!40000 ALTER TABLE `access_applications` DISABLE KEYS */;
INSERT INTO `access_applications` VALUES (1,'VEHICLE','จองรถ (Vehicle Booking)',NULL,154,NULL,0,NULL,1,'2026-08-29 04:50:10'),(2,'MEETING_ROOM','จองห้องประชุม (Meeting Room)',NULL,154,NULL,0,NULL,1,'2026-08-29 04:50:10'),(3,'CCMS','CCMS',NULL,153,154,1,NULL,1,'2026-08-29 04:50:10'),(4,'TEMPONLINE','TempOnline',NULL,154,NULL,0,NULL,1,'2026-08-29 04:50:10'),(5,'ERP','Ememo',NULL,28,NULL,0,NULL,1,'2026-08-29 04:50:10'),(6,'CCTV','CCTV',NULL,28,NULL,0,NULL,1,'2026-08-29 04:50:10'),(7,'USER_DOMAIN','User AD','ระบบ Login  Computer',154,NULL,0,NULL,1,'2026-09-11 05:09:17'),(8,'EMAIL_MS365','EMAIL MS365','Business Standard',NULL,NULL,0,NULL,1,'2026-09-13 16:51:53');
/*!40000 ALTER TABLE `access_applications` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `access_request_cc`
--

DROP TABLE IF EXISTS `access_request_cc`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `access_request_cc` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `request_id` int(11) NOT NULL,
  `cc_employee_id` int(11) NOT NULL,
  `notified_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `fk_cc_request` (`request_id`),
  CONSTRAINT `fk_cc_request` FOREIGN KEY (`request_id`) REFERENCES `access_requests` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `access_request_cc`
--

LOCK TABLES `access_request_cc` WRITE;
/*!40000 ALTER TABLE `access_request_cc` DISABLE KEYS */;
/*!40000 ALTER TABLE `access_request_cc` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `access_request_steps`
--

DROP TABLE IF EXISTS `access_request_steps`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `access_request_steps` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `request_id` int(11) NOT NULL,
  `step_order` tinyint(4) NOT NULL,
  `step_role` varchar(20) NOT NULL,
  `assignee_employee_id` int(11) DEFAULT NULL,
  `action` enum('Pending','Approved','Rejected','Actioned','Cancelled') NOT NULL DEFAULT 'Pending',
  `comment` text DEFAULT NULL,
  `acted_by_ad` varchar(100) DEFAULT NULL,
  `acted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_request_step` (`request_id`,`step_order`),
  CONSTRAINT `fk_step_request` FOREIGN KEY (`request_id`) REFERENCES `access_requests` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `access_request_steps`
--

LOCK TABLES `access_request_steps` WRITE;
/*!40000 ALTER TABLE `access_request_steps` DISABLE KEYS */;
/*!40000 ALTER TABLE `access_request_steps` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `access_requests`
--

DROP TABLE IF EXISTS `access_requests`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `access_requests` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `request_no` varchar(20) NOT NULL,
  `application_id` int(11) NOT NULL,
  `requestor_employee_id` int(11) NOT NULL,
  `beneficiary_employee_id` int(11) DEFAULT NULL,
  `beneficiary_name` varchar(150) NOT NULL,
  `beneficiary_department` varchar(100) DEFAULT NULL,
  `beneficiary_external_org` varchar(150) DEFAULT NULL,
  `beneficiary_external_contact` varchar(150) DEFAULT NULL,
  `requestor_name` varchar(150) NOT NULL,
  `department` varchar(100) DEFAULT NULL,
  `site_id` int(11) DEFAULT NULL,
  `access_level_id` int(11) DEFAULT NULL,
  `access_level_text` varchar(100) DEFAULT NULL,
  `reason` text NOT NULL,
  `status` enum('Pending Approver1','Pending Approver2','Pending IT Action','Completed','Rejected','Cancelled') NOT NULL DEFAULT 'Pending Approver1',
  `current_step_id` int(11) DEFAULT NULL,
  `requested_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `completed_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_request_no` (`request_no`),
  KEY `idx_requestor` (`requestor_employee_id`),
  KEY `idx_status` (`status`),
  KEY `fk_ar_app` (`application_id`),
  KEY `fk_ar_site` (`site_id`),
  KEY `fk_ar_level` (`access_level_id`),
  KEY `fk_ar_current_step` (`current_step_id`),
  KEY `idx_beneficiary` (`beneficiary_employee_id`),
  CONSTRAINT `fk_ar_app` FOREIGN KEY (`application_id`) REFERENCES `access_applications` (`id`),
  CONSTRAINT `fk_ar_current_step` FOREIGN KEY (`current_step_id`) REFERENCES `access_request_steps` (`id`),
  CONSTRAINT `fk_ar_level` FOREIGN KEY (`access_level_id`) REFERENCES `access_application_levels` (`id`),
  CONSTRAINT `fk_ar_site` FOREIGN KEY (`site_id`) REFERENCES `sites` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `access_requests`
--

LOCK TABLES `access_requests` WRITE;
/*!40000 ALTER TABLE `access_requests` DISABLE KEYS */;
/*!40000 ALTER TABLE `access_requests` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `asset_loans`
--

DROP TABLE IF EXISTS `asset_loans`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `asset_loans` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `loan_code` varchar(20) NOT NULL COMMENT 'รหัส LN-YYYY-NNNN auto-generate ใน PHP',
  `asset_id` int(11) DEFAULT NULL COMMENT 'FK → hardware_assets (null ถ้ายืม mobile)',
  `mobile_id` int(11) DEFAULT NULL COMMENT 'FK → mobile_assets',
  `network_asset_id` int(11) DEFAULT NULL COMMENT 'FK → network_assets (Router SIM ที่ยืม)',
  `borrower_ad` varchar(100) NOT NULL,
  `borrower_employee_id` int(11) DEFAULT NULL,
  `borrower_name` varchar(150) NOT NULL,
  `borrower_dept` varchar(50) DEFAULT NULL,
  `borrower_site_id` int(11) DEFAULT NULL,
  `borrower_phone` varchar(30) DEFAULT NULL,
  `purpose` varchar(255) NOT NULL,
  `purpose_type` enum('Business Trip','Replacement','Project','Training','Other') NOT NULL DEFAULT 'Other',
  `related_log_id` int(11) DEFAULT NULL COMMENT 'ผูกกับ maintenance_logs.id (กรณียืมเพราะส่งซ่อม)',
  `loan_date` date NOT NULL,
  `expected_return` date NOT NULL,
  `actual_return` date DEFAULT NULL,
  `status` enum('Pending','Approved','OnLoan','Returned','Overdue','Lost','Damaged') NOT NULL DEFAULT 'Pending',
  `approved_by` varchar(100) DEFAULT NULL,
  `approved_at` datetime DEFAULT NULL,
  `condition_out` text DEFAULT NULL,
  `condition_in` text DEFAULT NULL,
  `accessories_out` text DEFAULT NULL,
  `accessories_in` text DEFAULT NULL,
  `handed_out_by` varchar(100) DEFAULT NULL,
  `received_by` varchar(100) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `loan_code` (`loan_code`),
  KEY `idx_status` (`status`),
  KEY `idx_borrower` (`borrower_ad`),
  KEY `idx_dates` (`loan_date`,`expected_return`),
  KEY `idx_asset_active` (`asset_id`,`status`),
  KEY `idx_mobile_active` (`mobile_id`,`status`),
  KEY `fk_loan_site` (`borrower_site_id`),
  KEY `fk_loan_network` (`network_asset_id`),
  KEY `idx_loans_borrower_employee` (`borrower_employee_id`),
  CONSTRAINT `fk_loan_asset` FOREIGN KEY (`asset_id`) REFERENCES `hardware_assets` (`id`),
  CONSTRAINT `fk_loan_mobile` FOREIGN KEY (`mobile_id`) REFERENCES `mobile_assets` (`id`),
  CONSTRAINT `fk_loan_network` FOREIGN KEY (`network_asset_id`) REFERENCES `network_assets` (`id`) ON DELETE NO ACTION ON UPDATE CASCADE,
  CONSTRAINT `fk_loan_site` FOREIGN KEY (`borrower_site_id`) REFERENCES `sites` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='ระบบยืม-คืน อุปกรณ์ IT';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `asset_loans`
--

LOCK TABLES `asset_loans` WRITE;
/*!40000 ALTER TABLE `asset_loans` DISABLE KEYS */;
/*!40000 ALTER TABLE `asset_loans` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `asset_peripherals`
--

DROP TABLE IF EXISTS `asset_peripherals`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `asset_peripherals` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `asset_id` int(11) DEFAULT NULL COMMENT 'FK → hardware_assets (null ถ้าเป็น network peripheral)',
  `network_asset_id` int(11) DEFAULT NULL COMMENT 'FK → network_assets (WiFi USB adapter ผูกกับ Router SIM)',
  `type` enum('Monitor','Mouse','Keyboard','Adapter','AdapterCable','Bag','Other') NOT NULL,
  `slot` tinyint(3) unsigned NOT NULL DEFAULT 1 COMMENT 'สำหรับ Monitor1=1, Monitor2=2',
  `serial_number` varchar(150) DEFAULT NULL,
  `note` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_asset` (`asset_id`),
  KEY `fk_periph_network` (`network_asset_id`),
  CONSTRAINT `fk_periph_asset` FOREIGN KEY (`asset_id`) REFERENCES `hardware_assets` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_periph_network` FOREIGN KEY (`network_asset_id`) REFERENCES `network_assets` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=13 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='S/N อุปกรณ์เสริมแยกชิ้น: Monitor, Mouse, KB, Adapter, Bag';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `asset_peripherals`
--

LOCK TABLES `asset_peripherals` WRITE;
/*!40000 ALTER TABLE `asset_peripherals` DISABLE KEYS */;
INSERT INTO `asset_peripherals` VALUES (11,NULL,45,'Other',1,'TP Link WIFI01',NULL,'2026-07-06 09:57:48'),(12,NULL,45,'Other',2,'TP Link WIFI02',NULL,'2026-07-06 09:57:48');
/*!40000 ALTER TABLE `asset_peripherals` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `employee_contacts`
--

DROP TABLE IF EXISTS `employee_contacts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `employee_contacts` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `employee_id` int(11) NOT NULL,
  `email` varchar(150) DEFAULT NULL,
  `line_user_id` varchar(100) DEFAULT NULL,
  `updated_by_ad` varchar(100) DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_employee_contact` (`employee_id`)
) ENGINE=InnoDB AUTO_INCREMENT=14 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `employee_contacts`
--

LOCK TABLES `employee_contacts` WRITE;
/*!40000 ALTER TABLE `employee_contacts` DISABLE KEYS */;
INSERT INTO `employee_contacts` VALUES (1,308,'admin@onecoldchain.com',NULL,'anucha','2026-09-02 07:46:18'),(2,331,'it.pcs@scgjwd.com',NULL,'amonthep','2026-09-14 04:56:55'),(4,154,'designbyjj@gmail.com',NULL,'anucha','2026-09-02 08:38:42'),(8,153,'apiwan.s@scgjwd.com',NULL,'anucha','2026-09-10 08:02:45');
/*!40000 ALTER TABLE `employee_contacts` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `employee_transaction_log`
--

DROP TABLE IF EXISTS `employee_transaction_log`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `employee_transaction_log` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `employee_id` int(11) NOT NULL,
  `txn_type` enum('Asset Assign','Asset Return','Asset Transfer','App Assign','App Revoke','Permission Grant','Permission Revoke','Access Request Submitted','Access Request Approved','Access Request Rejected') NOT NULL,
  `ref_table` varchar(50) DEFAULT NULL,
  `ref_id` int(11) DEFAULT NULL,
  `detail` varchar(255) DEFAULT NULL,
  `performed_by_ad` varchar(100) DEFAULT NULL,
  `occurred_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_emp_time` (`employee_id`,`occurred_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `employee_transaction_log`
--

LOCK TABLES `employee_transaction_log` WRITE;
/*!40000 ALTER TABLE `employee_transaction_log` DISABLE KEYS */;
/*!40000 ALTER TABLE `employee_transaction_log` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `hardware_assets`
--

DROP TABLE IF EXISTS `hardware_assets`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `hardware_assets` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `asset_id` varchar(50) NOT NULL COMMENT 'รหัสภายในขององค์กร เช่น PCS-NB-0001',
  `site_id` int(11) DEFAULT NULL COMMENT 'FK -> sites.id',
  `department` varchar(20) DEFAULT NULL COMMENT 'หน่วยงาน: IT/HR/MK/WH/CS/EN/AC/PLP/QA/PC',
  `category` enum('PC','Notebook','Surface','Server','NAS','Printer','Scanner','UPS','Handheld','Tablet','PrintServer','Other') DEFAULT NULL,
  `brand` varchar(50) DEFAULT NULL,
  `model` varchar(100) DEFAULT NULL,
  `serial_number` varchar(100) DEFAULT NULL,
  `service_tag` varchar(50) DEFAULT NULL COMMENT 'Dell Service Tag / Express Service Code',
  `cpu` varchar(150) DEFAULT NULL COMMENT 'เช่น Intel Core i5-12600 3.3GHz',
  `ram_gb` tinyint(3) unsigned DEFAULT NULL COMMENT 'RAM ในหน่วย GB',
  `storage` varchar(100) DEFAULT NULL COMMENT 'เช่น 256GB SSD / 1TB HDD / 256SSD+1TB HDD',
  `gpu` varchar(100) DEFAULT NULL COMMENT 'ON Board / Nvidia GeForce / AMD Radeon',
  `status` enum('Active','In Repair','In Stock','Retired','On Loan','Reserved') DEFAULT 'Active',
  `location` varchar(100) DEFAULT NULL COMMENT 'ตำแหน่งย่อยภายในสาขา เช่น Rack-04',
  `assigned_to_ad` varchar(100) DEFAULT NULL COMMENT 'AD username ของผู้ใช้ที่ครอบครอง',
  `assigned_employee_id` int(11) DEFAULT NULL,
  `user_display` varchar(150) DEFAULT NULL COMMENT 'ชื่อแสดงผู้ใช้ เช่น อภิวรรณ / พี่เก๋',
  `ip_address` varchar(45) DEFAULT NULL,
  `ip_assignment` enum('Static','DHCP') DEFAULT 'DHCP',
  `mac_address` varchar(17) DEFAULT NULL,
  `mac_wifi` varchar(50) DEFAULT NULL COMMENT 'MAC address สำหรับ WiFi (notebook มี 2 MAC)',
  `specifications` text DEFAULT NULL,
  `os_name` varchar(60) DEFAULT NULL COMMENT 'Windows 10 / Windows 11 / Windows Server 2019 / Linux',
  `os_bit` tinyint(3) unsigned DEFAULT NULL COMMENT '32 หรือ 64',
  `os_product_key` varchar(120) DEFAULT NULL COMMENT 'Windows Product Key — sensitive, เข้าถึงได้เฉพาะ IT admin',
  `office_license` varchar(50) DEFAULT NULL COMMENT 'OEM / VL(OLP) / 365 / Perpetual / N/A',
  `office_product_key` varchar(120) DEFAULT NULL COMMENT 'Office Product Key — sensitive',
  `purchase_date` date DEFAULT NULL,
  `register_date` date DEFAULT NULL COMMENT 'วันที่ลงทะเบียน (ต่างจาก purchase_date)',
  `warranty_expiry` date DEFAULT NULL,
  `is_loanable` tinyint(1) NOT NULL DEFAULT 0 COMMENT '1 = เครื่องส่วนกลาง สำหรับยืม',
  `loan_pool_name` varchar(50) DEFAULT NULL COMMENT 'ชื่อ pool เช่น IT Loan Pool / Site WH-A Pool',
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `asset_id` (`asset_id`),
  KEY `idx_status` (`status`),
  KEY `idx_category` (`category`),
  KEY `idx_site` (`site_id`),
  KEY `idx_warranty` (`warranty_expiry`),
  CONSTRAINT `fk_asset_site` FOREIGN KEY (`site_id`) REFERENCES `sites` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=262 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `hardware_assets`
--

LOCK TABLES `hardware_assets` WRITE;
/*!40000 ALTER TABLE `hardware_assets` DISABLE KEYS */;
INSERT INTO `hardware_assets` VALUES (2,'PCS-NB-0002',1,NULL,'Notebook','Dell','Latitude 5440','SN-L54-018',NULL,NULL,NULL,NULL,NULL,'Active','Floor 5 / Fin',NULL,NULL,NULL,'10.10.5.22','DHCP','AA:BB:CC:11:22:02',NULL,NULL,NULL,NULL,NULL,NULL,NULL,'2024-05-01',NULL,'2027-05-01',0,NULL,'2026-06-19 16:30:29','2026-09-15 02:30:53'),(6,'PCS-NB-0003',4,NULL,'Notebook','Apple','MacBook Pro 14','SN-MBP-003',NULL,NULL,NULL,NULL,NULL,'Active','Office',NULL,NULL,NULL,'10.40.3.15','DHCP','AA:BB:CC:55:66:03',NULL,NULL,NULL,NULL,NULL,NULL,NULL,'2024-08-22',NULL,'2027-08-22',0,NULL,'2026-06-19 16:30:29','2026-09-15 02:30:53'),(100,'BS_Proxy-Ubuntu 62.202',2,'IT','Server',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'Active','Server Room B8',NULL,NULL,NULL,NULL,'Static',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,0,NULL,'2026-07-06 08:38:34','2026-07-13 04:25:06'),(101,'CS-63-006-PCSWH',NULL,NULL,'PC','Dell Inc.','OptiPlex 3070',NULL,'2YBS033','Intel(R) Core(TM) i5-8500 CPU @ 3.00GHz',12,'500 GB',NULL,'Active',NULL,NULL,NULL,NULL,'162.16.58.181','Static','E4:54:E8:D8:51:16',NULL,'storage (full): Name: \"C:\"/ Type: \"Local Disk\"/ Capacity: \"461124599808 (429.5 GiB)\"/ Free: \"162721659904 (160.9 GiB)\"/ Usage %: \"63%\"','Windows 11',64,NULL,'365',NULL,NULL,'2020-02-19','2023-02-21',0,NULL,'2026-07-06 08:38:34','2026-09-15 02:30:53'),(102,'CS-63-007-PCSLP',2,NULL,'PC','Dell Inc.','OptiPlex 3070',NULL,'DWRZV23','Intel(R) Core(TM) i5-8500 CPU @ 3.00GHz',8,NULL,NULL,'Active',NULL,NULL,NULL,NULL,'162.16.58.160','Static','E4:54:E8:D3:F1:5B',NULL,'storage (full): Name: \"C:\"/ Type: \"Local Disk\"/ Capacity: \"254898438144 (237.4 GiB)\"/ Free: \"16266412032 (16.1 GiB)\"/ Usage %: \"94%\"',NULL,64,NULL,NULL,NULL,NULL,'2020-02-12','2023-02-14',0,NULL,'2026-07-06 08:38:34','2026-09-15 04:02:23'),(103,'CS-63-010-PCSLP',2,NULL,'PC','Dell Inc.','OptiPlex 3060',NULL,'8J51WV2','Intel(R) Core(TM) i5-8500 CPU @ 3.00GHz',16,'Name: \"C:\"/ Type: \"Local Disk\"/ Capacity: \"322122543104 (300.0 GiB)\"/ Free: \"144275292160 (134.4 ...',NULL,'Active',NULL,NULL,NULL,NULL,'162.16.58.211','Static','8C:EC:4B:C2:79:42',NULL,'storage (full): Name: \"C:\"/ Type: \"Local Disk\"/ Capacity: \"322122543104 (300.0 GiB)\"/ Free: \"144275292160 (134.4 GiB)\"/ Usage %: \"55%\"',NULL,64,NULL,NULL,NULL,NULL,'2019-05-02','2024-08-05',0,NULL,'2026-07-06 08:38:34','2026-09-15 02:30:53'),(104,'CS-63-013-PCSWH',2,NULL,'PC','Dell Inc.','OptiPlex 3070',NULL,'7CF6J23','Intel(R) Core(TM) i5-9500T CPU @ 2.20GHz',8,'Name: \"C:\"/ Type: \"Local Disk\"/ Capacity: \"535610380288 (498.8 GiB)\"/ Free: \"458509561856 (427.0 ...',NULL,'Active',NULL,NULL,NULL,NULL,'162.16.58.98','Static','5C:80:B6:CF:DC:94',NULL,'storage (full): Name: \"C:\"/ Type: \"Local Disk\"/ Capacity: \"535610380288 (498.8 GiB)\"/ Free: \"458509561856 (427.0 GiB)\"/ Usage %: \"14%\"',NULL,64,NULL,NULL,NULL,NULL,'2020-01-16','2023-01-16',0,NULL,'2026-07-06 08:38:34','2026-09-15 02:30:53'),(105,'CS-63-016-PCSLP',2,NULL,'PC','Dell Inc.','OptiPlex 3070',NULL,'6RX6J23','Intel(R) Core(TM) i5-8500 CPU @ 3.00GHz',8,'Name: \"R:\"/ Type: \"Local Disk\"/ Capacity: \"104857595904 (97.7 GiB)\"/ Free: \"6087438336 (5.7 GiB)\"...',NULL,'Active',NULL,NULL,NULL,NULL,'162.16.57.96','Static','E4:54:E8:C3:70:77',NULL,'storage (full): Name: \"R:\"/ Type: \"Local Disk\"/ Capacity: \"104857595904 (97.7 GiB)\"/ Free: \"6087438336 (5.7 GiB)\"/ Usage %: \"94%\"',NULL,64,NULL,NULL,NULL,NULL,'2020-01-16','2023-01-16',0,NULL,'2026-07-06 08:38:34','2026-09-15 02:30:53'),(106,'CS-63-018-PCSIT',2,NULL,'PC','Dell Inc.','OptiPlex 3060',NULL,'BD8G9V2','Intel(R) Core(TM) i5-8400T CPU @ 1.70GHz',8,'Name: \"C:\"/ Type: \"Local Disk\"/ Capacity: \"126365732864 (116.7 GiB)\"/ Free: \"71200264192 (66.3 Gi...',NULL,'Active',NULL,NULL,NULL,NULL,'162.18.14.111','Static','54:BF:64:91:67:90',NULL,'storage (full): Name: \"C:\"/ Type: \"Local Disk\"/ Capacity: \"126365732864 (116.7 GiB)\"/ Free: \"71200264192 (66.3 GiB)\"/ Usage %: \"44%\"',NULL,64,NULL,NULL,NULL,NULL,'2019-02-14','2022-02-16',0,NULL,'2026-07-06 08:38:34','2026-09-15 02:30:53'),(107,'CS-63-019-PCSWH',2,NULL,'PC','Dell Inc.','OptiPlex 3080',NULL,'1YQJL83','Intel(R) Core(TM) i5-10500 CPU @ 3.10GHz',8,'Name: \"C:\"/ Type: \"Local Disk\"/ Capacity: \"293679919104 (273.5 GiB)\"/ Free: \"40471982080 (37.7 Gi...',NULL,'Active',NULL,NULL,NULL,NULL,'162.16.56.254','Static','70:B5:E8:3D:9A:44',NULL,'storage (full): Name: \"C:\"/ Type: \"Local Disk\"/ Capacity: \"293679919104 (273.5 GiB)\"/ Free: \"40471982080 (37.7 GiB)\"/ Usage %: \"86%\"',NULL,64,NULL,NULL,NULL,NULL,'2020-11-23','2023-11-25',0,NULL,'2026-07-06 08:38:34','2026-09-15 02:30:53'),(108,'CS-63-020-PCSCS',2,NULL,'PC','Dell Inc.','OptiPlex 3070',NULL,'BT25F13','Intel(R) Core(TM) i5-9500T CPU @ 2.20GHz',12,'Name: \"C:\"/ Type: \"Local Disk\"/ Capacity: \"237419622400 (221.1 GiB)\"/ Free: \"55161966592 (51.4 Gi...',NULL,'Active',NULL,NULL,NULL,NULL,'162.16.58.147','Static','E4:54:E8:89:74:1E',NULL,'storage (full): Name: \"C:\"/ Type: \"Local Disk\"/ Capacity: \"237419622400 (221.1 GiB)\"/ Free: \"55161966592 (51.4 GiB)\"/ Usage %: \"77%\"',NULL,64,NULL,NULL,NULL,NULL,'2019-12-19','2022-12-21',0,NULL,'2026-07-06 08:38:34','2026-09-15 02:30:53'),(109,'CS-63-16-PCSHR',2,NULL,'PC','Dell Inc.','OptiPlex 3080',NULL,'3Y27X63','Unknown',16,'Name: \"C:\"/ Type: \"Local Disk\"/ Capacity: \"238575149056 (222.2 GiB)\"/ Free: \"134944960512 (125.7 ...',NULL,'Active',NULL,NULL,NULL,NULL,'162.16.57.180','Static','70:B5:E8:25:C0:5F',NULL,'storage (full): Name: \"C:\"/ Type: \"Local Disk\"/ Capacity: \"238575149056 (222.2 GiB)\"/ Free: \"134944960512 (125.7 GiB)\"/ Usage %: \"43%\"',NULL,64,NULL,NULL,NULL,NULL,'2020-09-28','2023-09-30',0,NULL,'2026-07-06 08:38:34','2026-09-15 02:30:53'),(110,'CS-63-16-PCSLP',2,NULL,'PC','Dell Inc.','OptiPlex 3080',NULL,'19P3H73','Intel(R) Core(TM) i5-10500 CPU @ 3.10GHz',8,'Name: \"C:\"/ Type: \"Local Disk\"/ Capacity: \"255714127872 (238.2 GiB)\"/ Free: \"9737871360 (9.1 GiB)...',NULL,'Active',NULL,NULL,NULL,NULL,'162.16.58.130','Static','70:B5:E8:38:CB:F6',NULL,'storage (full): Name: \"C:\"/ Type: \"Local Disk\"/ Capacity: \"255714127872 (238.2 GiB)\"/ Free: \"9737871360 (9.1 GiB)\"/ Usage %: \"96%\"',NULL,64,NULL,NULL,NULL,NULL,'2020-10-26','2023-10-28',0,NULL,'2026-07-06 08:38:34','2026-09-15 02:30:53'),(111,'CS-63-STEEL',2,NULL,'PC','Dell Inc.','OptiPlex 3070',NULL,'2Y9Z033','Intel(R) Core(TM) i5-8500 CPU @ 3.00GHz',8,'Name: \"C:\"/ Type: \"Local Disk\"/ Capacity: \"181643767808 (169.2 GiB)\"/ Free: \"89521938432 (83.4 Gi...',NULL,'Active',NULL,NULL,NULL,NULL,'162.16.57.213','Static','5C:A6:E6:D1:CC:6F',NULL,'storage (full): Name: \"C:\"/ Type: \"Local Disk\"/ Capacity: \"181643767808 (169.2 GiB)\"/ Free: \"89521938432 (83.4 GiB)\"/ Usage %: \"51%\"',NULL,64,NULL,NULL,NULL,NULL,'2020-02-19','2023-02-21',0,NULL,'2026-07-06 08:38:34','2026-09-15 02:30:53'),(112,'CS-63-THEATER',2,NULL,'PC','Dell Inc.','OptiPlex 3070',NULL,'GYVM6Z2','Intel(R) Core(TM) i5-9500T CPU @ 2.20GHz',8,'Name: \"C:\"/ Type: \"Local Disk\"/ Capacity: \"197404913664 (183.8 GiB)\"/ Free: \"80567734272 (75.0 Gi...',NULL,'Active',NULL,NULL,NULL,NULL,'162.16.60.50','Static','E4:54:E8:64:99:94',NULL,'storage (full): Name: \"C:\"/ Type: \"Local Disk\"/ Capacity: \"197404913664 (183.8 GiB)\"/ Free: \"80567734272 (75.0 GiB)\"/ Usage %: \"59%\"',NULL,64,NULL,NULL,NULL,NULL,'2019-09-18','2022-09-20',0,NULL,'2026-07-06 08:38:34','2026-09-15 02:30:53'),(113,'CS-64-002-PCSWH',2,NULL,'PC','Dell Inc.','OptiPlex 3080',NULL,'1YSGL83','Unknown',8,'Name: \"C:\"/ Type: \"Local Disk\"/ Capacity: \"213000384512 (198.4 GiB)\"/ Free: \"12778512384 (11.9 Gi...',NULL,'Active',NULL,NULL,NULL,NULL,'162.16.57.165','Static','70:B5:E8:3D:99:F3',NULL,'storage (full): Name: \"C:\"/ Type: \"Local Disk\"/ Capacity: \"213000384512 (198.4 GiB)\"/ Free: \"12778512384 (11.9 GiB)\"/ Usage %: \"94%\"',NULL,64,NULL,NULL,NULL,NULL,'2020-11-23','2023-11-25',0,NULL,'2026-07-06 08:38:34','2026-09-15 02:30:53'),(114,'CS-64-004-PCSWH',2,NULL,'PC','Dell Inc.','OptiPlex 3080',NULL,'2L33LB3','Intel(R) Core(TM) i5-10500 CPU @ 3.10GHz',8,'Name: \"C:\"/ Type: \"Local Disk\"/ Capacity: \"393252696064 (366.2 GiB)\"/ Free: \"162845859840 (161.7 ...',NULL,'Active',NULL,NULL,NULL,NULL,'162.16.57.97','Static','70:B5:E8:48:11:4F',NULL,'storage (full): Name: \"C:\"/ Type: \"Local Disk\"/ Capacity: \"393252696064 (366.2 GiB)\"/ Free: \"162845859840 (161.7 GiB)\"/ Usage %: \"59%\"',NULL,64,NULL,NULL,NULL,NULL,'2021-02-09','2024-02-11',0,NULL,'2026-07-06 08:38:34','2026-09-15 02:30:53'),(115,'CS64-005-PCSWH',2,NULL,'PC','Dell Inc.','OptiPlex 3080',NULL,'1L33LB3','Intel(R) Core(TM) i5-10500 CPU @ 3.10GHz',8,'Name: \"C:\"/ Type: \"Local Disk\"/ Capacity: \"295275851676 (275.0 GiB)\"/ Free: \"133949988864 (124.8 ...',NULL,'Active',NULL,NULL,NULL,NULL,'162.16.57.131','Static','70:B5:E8:48:13:F6',NULL,'storage (full): Name: \"C:\"/ Type: \"Local Disk\"/ Capacity: \"295275851676 (275.0 GiB)\"/ Free: \"133949988864 (124.8 GiB)\"/ Usage %: \"55%\"',NULL,64,NULL,NULL,NULL,NULL,'2021-02-09','2024-02-11',0,NULL,'2026-07-06 08:38:34','2026-09-15 02:30:53'),(116,'CS-64-006-PCSWH',2,NULL,'PC','Dell Inc.','OptiPlex 3080',NULL,'8YV0TC3','Intel(R) Core(TM) i5-10500 CPU @ 3.10GHz',8,'Name: \"C:\"/ Type: \"Local Disk\"/ Capacity: \"164898009088 (163.6 GiB)\"/ Free: \"0 (0 B)\"/ Usage %: \"...',NULL,'Active',NULL,NULL,NULL,NULL,'162.16.57.166','Static','70:B5:E8:4D:78:7E',NULL,'storage (full): Name: \"C:\"/ Type: \"Local Disk\"/ Capacity: \"164898009088 (163.6 GiB)\"/ Free: \"0 (0 B)\"/ Usage %: \"100%\"',NULL,64,NULL,NULL,NULL,NULL,'2021-03-16','2024-03-16',0,NULL,'2026-07-06 08:38:34','2026-09-15 02:30:53'),(117,'CS-64-007-PCSWH',2,NULL,'PC','Dell Inc.','OptiPlex 3080',NULL,'BYV0TC3','Intel(R) Core(TM) i5-10500 CPU @ 3.10GHz',8,'Name: \"C:\"/ Type: \"Local Disk\"/ Capacity: \"240379752448 (223.9 GiB)\"/ Free: \"51610337024 (48.2 Gi...',NULL,'Active',NULL,NULL,NULL,NULL,'162.16.58.166','Static','70:B5:E8:4D:78:51',NULL,'storage (full): Name: \"C:\"/ Type: \"Local Disk\"/ Capacity: \"240379752448 (223.9 GiB)\"/ Free: \"51610337024 (48.2 GiB)\"/ Usage %: \"78%\"',NULL,64,NULL,NULL,NULL,NULL,'2021-03-16','2024-03-16',0,NULL,'2026-07-06 08:38:34','2026-09-15 02:30:53'),(118,'CS-64-008-PCSCS',2,NULL,'PC','Dell Inc.','OptiPlex 3080',NULL,'9YV0TC3','Intel(R) Core(TM) i5-10500 CPU @ 3.10GHz',16,'Name: \"C:\"/ Type: \"Local Disk\"/ Capacity: \"214147588096 (199.4 GiB)\"/ Free: \"40901877760 (38.1 Gi...',NULL,'Active',NULL,NULL,NULL,NULL,'162.16.58.122','Static','70:B5:E8:4D:46:90',NULL,'storage (full): Name: \"C:\"/ Type: \"Local Disk\"/ Capacity: \"214147588096 (199.4 GiB)\"/ Free: \"40901877760 (38.1 GiB)\"/ Usage %: \"81%\"',NULL,64,NULL,NULL,NULL,NULL,'2021-03-16','2024-03-16',0,NULL,'2026-07-06 08:38:34','2026-09-15 02:30:53'),(119,'CS-64-009-PCSIT',2,NULL,'PC','Dell Inc.','OptiPlex 3080',NULL,'7B8RVD3','Intel(R) Core(TM) i5-10500 CPU @ 3.10GHz',16,'Name: \"C:\"/ Type: \"Local Disk\"/ Capacity: \"509320286208 (474.3 GiB)\"/ Free: \"195880411136 (182.4 ...',NULL,'Active',NULL,NULL,NULL,NULL,'162.16.59.129','Static','E4:54:E8:C3:6F:16',NULL,'storage (full): Name: \"C:\"/ Type: \"Local Disk\"/ Capacity: \"509320286208 (474.3 GiB)\"/ Free: \"195880411136 (182.4 GiB)\"/ Usage %: \"62%\"',NULL,64,NULL,NULL,NULL,NULL,'2021-05-20','2024-05-22',0,NULL,'2026-07-06 08:38:34','2026-09-15 02:30:53'),(120,'CS-64-010-PCSCS',2,NULL,'PC','Dell Inc.','OptiPlex 3080',NULL,'54D44F3','Intel(R) Core(TM) i5-10500 CPU @ 3.10GHz',8,'Name: \"C:\"/ Type: \"Local Disk\"/ Capacity: \"347427827712 (323.6 GiB)\"/ Free: \"119300300800 (111.1 ...',NULL,'Active',NULL,NULL,NULL,NULL,'162.16.58.247','Static','70:B5:E8:67:08:9C',NULL,'storage (full): Name: \"C:\"/ Type: \"Local Disk\"/ Capacity: \"347427827712 (323.6 GiB)\"/ Free: \"119300300800 (111.1 GiB)\"/ Usage %: \"66%\"',NULL,64,NULL,NULL,NULL,NULL,'2021-05-24','2024-05-26',0,NULL,'2026-07-06 08:38:34','2026-09-15 02:30:53'),(121,'CS-64-016-PCSAC',2,NULL,'PC','Dell Inc.','OptiPlex 3080',NULL,'HS1RRH3','Intel(R) Core(TM) i5-10505 CPU @ 3.20GHz',8,'Name: \"C:\"/ Type: \"Local Disk\"/ Capacity: \"214825955328 (200.1 GiB)\"/ Free: \"45781690016 (42.6 Gi...',NULL,'Active',NULL,NULL,NULL,NULL,'162.16.58.24','Static','C0:25:A5:7B:01:EF',NULL,'storage (full): Name: \"C:\"/ Type: \"Local Disk\"/ Capacity: \"214825955328 (200.1 GiB)\"/ Free: \"45781690016 (42.6 GiB)\"/ Usage %: \"79%\"',NULL,64,NULL,NULL,NULL,NULL,'2021-09-13','2024-09-16',0,NULL,'2026-07-06 08:38:34','2026-09-15 02:30:53'),(122,'CS-64-016-PCSCS',2,NULL,'PC','Dell Inc.','OptiPlex 3080',NULL,'CS1RRH3','Intel(R) Core(TM) i5-10505 CPU @ 3.20GHz',8,'Name: \"C:\"/ Type: \"Local Disk\"/ Capacity: \"354797219840 (330.4 GiB)\"/ Free: \"68979261440 (64.2 Gi...',NULL,'Active',NULL,NULL,NULL,NULL,'162.16.58.228','Static','C0:25:A5:7B:01:ED',NULL,'storage (full): Name: \"C:\"/ Type: \"Local Disk\"/ Capacity: \"354797219840 (330.4 GiB)\"/ Free: \"68979261440 (64.2 GiB)\"/ Usage %: \"81%\"',NULL,64,NULL,NULL,NULL,NULL,'2021-09-13','2024-09-16',0,NULL,'2026-07-06 08:38:34','2026-09-15 02:30:53'),(123,'CS-64-016-PCSEN',2,NULL,'PC','Dell Inc.','OptiPlex 3080',NULL,'4T1RRH3','Intel(R) Core(TM) i5-10505 CPU @ 3.20GHz',16,'Name: \"C:\"/ Type: \"Local Disk\"/ Capacity: \"341620813824 (318.2 GiB)\"/ Free: \"40382267392 (37.6 Gi...',NULL,'Active',NULL,NULL,NULL,NULL,'162.16.56.87','Static','C0:25:A5:7B:01:36',NULL,'storage (full): Name: \"C:\"/ Type: \"Local Disk\"/ Capacity: \"341620813824 (318.2 GiB)\"/ Free: \"40382267392 (37.6 GiB)\"/ Usage %: \"88%\"',NULL,64,NULL,NULL,NULL,NULL,'2021-09-13','2024-09-16',0,NULL,'2026-07-06 08:38:34','2026-09-15 02:30:53'),(124,'CS-64-018-PCSWH',2,NULL,'PC','Dell Inc.','OptiPlex 3080',NULL,'DS1RRH3','Intel(R) Core(TM) i5-10505 CPU @ 3.20GHz',8,'Name: \"C:\"/ Type: \"Local Disk\"/ Capacity: \"293861322752 (273.7 GiB)\"/ Free: \"81881100288 (76.3 Gi...',NULL,'Active',NULL,NULL,NULL,NULL,'162.16.56.212','Static','C0:25:A5:7B:02:5D',NULL,'storage (full): Name: \"C:\"/ Type: \"Local Disk\"/ Capacity: \"293861322752 (273.7 GiB)\"/ Free: \"81881100288 (76.3 GiB)\"/ Usage %: \"72%\"',NULL,64,NULL,NULL,NULL,NULL,'2021-09-13','2024-09-16',0,NULL,'2026-07-06 08:38:34','2026-09-15 02:30:53'),(125,'CS-64-019-PCSQA',2,NULL,'PC','Dell Inc.','OptiPlex 3080',NULL,'5T1RRH3','Intel(R) Core(TM) i5-10505 CPU @ 3.20GHz',8,'Name: \"C:\"/ Type: \"Local Disk\"/ Capacity: \"293861322752 (273.7 GiB)\"/ Free: \"16295355904 (16.1 Gi...',NULL,'Active',NULL,NULL,NULL,NULL,'162.16.56.22','Static','C0:25:A5:7B:01:39',NULL,'storage (full): Name: \"C:\"/ Type: \"Local Disk\"/ Capacity: \"293861322752 (273.7 GiB)\"/ Free: \"16295355904 (16.1 GiB)\"/ Usage %: \"94%\"',NULL,64,NULL,NULL,NULL,NULL,'2021-09-13','2024-09-16',0,NULL,'2026-07-06 08:38:34','2026-09-15 02:30:53'),(126,'CS-64-020-PCSAC',2,NULL,'PC','Dell Inc.','OptiPlex 3080',NULL,'9S1RRH3','Intel(R) Core(TM) i5-10505 CPU @ 3.20GHz',16,'Name: \"C:\"/ Type: \"Local Disk\"/ Capacity: \"239848124416 (223.4 GiB)\"/ Free: \"21898747904 (20.4 Gi...',NULL,'Active',NULL,NULL,NULL,NULL,'162.16.58.187','Static','C0:25:A5:7B:01:66',NULL,'storage (full): Name: \"C:\"/ Type: \"Local Disk\"/ Capacity: \"239848124416 (223.4 GiB)\"/ Free: \"21898747904 (20.4 GiB)\"/ Usage %: \"91%\"',NULL,64,NULL,NULL,NULL,NULL,'2021-09-13','2024-09-16',0,NULL,'2026-07-06 08:38:34','2026-09-15 02:30:53'),(127,'CS-64-021-PCSCS',2,NULL,'PC','Dell Inc.','OptiPlex 3080',NULL,'3T1RRH3','Intel(R) Core(TM) i5-10505 CPU @ 3.20GHz',8,'Name: \"C:\"/ Type: \"Local Disk\"/ Capacity: \"401127501824 (373.6 GiB)\"/ Free: \"28514934784 (26.6 Gi...',NULL,'Active',NULL,NULL,NULL,NULL,'162.16.56.55','Static','C0:25:A5:7B:01:5D',NULL,'storage (full): Name: \"C:\"/ Type: \"Local Disk\"/ Capacity: \"401127501824 (373.6 GiB)\"/ Free: \"28514934784 (26.6 GiB)\"/ Usage %: \"93%\"',NULL,64,NULL,NULL,NULL,NULL,'2021-09-13','2024-09-16',0,NULL,'2026-07-06 08:38:34','2026-09-15 02:30:53'),(128,'CS-64-022-PCSPL',2,NULL,'PC','Dell Inc.','OptiPlex 3080',NULL,'BS1RRH3','Intel(R) Core(TM) i5-10505 CPU @ 3.20GHz',8,'Name: \"C:\"/ Type: \"Local Disk\"/ Capacity: \"293666357248 (273.5 GiB)\"/ Free: \"105338142720 (98.1 G...',NULL,'Active',NULL,NULL,NULL,NULL,'162.16.56.72','Static','C0:25:A5:7B:01:47',NULL,'storage (full): Name: \"C:\"/ Type: \"Local Disk\"/ Capacity: \"293666357248 (273.5 GiB)\"/ Free: \"105338142720 (98.1 GiB)\"/ Usage %: \"64%\"',NULL,64,NULL,NULL,NULL,NULL,'2021-09-13','2024-09-16',0,NULL,'2026-07-06 08:38:34','2026-09-15 02:30:53'),(129,'CS-64-024-PCSAC',2,NULL,'PC','Dell Inc.','OptiPlex 3080',NULL,'FS1RRH3','Intel(R) Core(TM) i5-10505 CPU @ 3.20GHz',8,'Name: \"C:\"/ Type: \"Local Disk\"/ Capacity: \"294429650944 (274.2 GiB)\"/ Free: \"18432237568 (16.2 Gi...',NULL,'Active',NULL,NULL,NULL,NULL,'162.16.58.205','Static','C0:25:A5:7B:02:85',NULL,'storage (full): Name: \"C:\"/ Type: \"Local Disk\"/ Capacity: \"294429650944 (274.2 GiB)\"/ Free: \"18432237568 (16.2 GiB)\"/ Usage %: \"94%\"',NULL,64,NULL,NULL,NULL,NULL,'2021-09-13','2024-09-16',0,NULL,'2026-07-06 08:38:34','2026-09-15 02:30:53'),(130,'CS-64-025-PCSHR',2,NULL,'PC','Dell Inc.','OptiPlex 3080',NULL,'2T1RRH3','Intel(R) Core(TM) i5-10505 CPU @ 3.20GHz',8,'Name: \"C:\"/ Type: \"Local Disk\"/ Capacity: \"294429650944 (274.2 GiB)\"/ Free: \"20382556160 (19.0 Gi...',NULL,'Active',NULL,NULL,NULL,NULL,'162.16.56.119','Static','C0:25:A5:7B:02:5A',NULL,'storage (full): Name: \"C:\"/ Type: \"Local Disk\"/ Capacity: \"294429650944 (274.2 GiB)\"/ Free: \"20382556160 (19.0 GiB)\"/ Usage %: \"93%\"',NULL,64,NULL,NULL,NULL,NULL,'2021-09-13','2024-09-16',0,NULL,'2026-07-06 08:38:34','2026-09-15 02:30:53'),(131,'CS-64-026-PCSCS',2,NULL,'PC','Dell Inc.','OptiPlex 3080',NULL,'7S1RRH3','Intel(R) Core(TM) i5-10505 CPU @ 3.20GHz',8,'Name: \"C:\"/ Type: \"Local Disk\"/ Capacity: \"294429650944 (274.2 GiB)\"/ Free: \"64911608160 (60.5 Gi...',NULL,'Active',NULL,NULL,NULL,NULL,'162.16.58.213','Static','C0:25:A5:7B:01:6A',NULL,'storage (full): Name: \"C:\"/ Type: \"Local Disk\"/ Capacity: \"294429650944 (274.2 GiB)\"/ Free: \"64911608160 (60.5 GiB)\"/ Usage %: \"78%\"',NULL,64,NULL,NULL,NULL,NULL,'2021-09-13','2024-09-16',0,NULL,'2026-07-06 08:38:34','2026-09-15 02:30:53'),(132,'CS-64-027-PCSHR',2,NULL,'PC','Dell Inc.','OptiPlex 3080',NULL,'1T1RRH3','Intel(R) Core(TM) i5-10505 CPU @ 3.20GHz',8,'Name: \"C:\"/ Type: \"Local Disk\"/ Capacity: \"213487833088 (198.8 GiB)\"/ Free: \"82467667968 (76.8 Gi...',NULL,'Active',NULL,NULL,NULL,NULL,'162.16.57.84','Static','C0:25:A5:7B:01:67',NULL,'storage (full): Name: \"C:\"/ Type: \"Local Disk\"/ Capacity: \"213487833088 (198.8 GiB)\"/ Free: \"82467667968 (76.8 GiB)\"/ Usage %: \"61%\"',NULL,64,NULL,NULL,NULL,NULL,'2021-09-13','2024-09-16',0,NULL,'2026-07-06 08:38:34','2026-09-15 02:30:53'),(133,'CS-64-028-PCSHR',2,NULL,'PC','Dell Inc.','OptiPlex 3080',NULL,'3S1RRH3','Intel(R) Core(TM) i5-10505 CPU @ 3.20GHz',16,'Name: \"C:\"/ Type: \"Local Disk\"/ Capacity: \"294429650944 (274.2 GiB)\"/ Free: \"79278014464 (73.8 Gi...',NULL,'Active',NULL,NULL,NULL,NULL,'162.16.58.6','Static','C0:25:A5:7B:67:8B',NULL,'storage (full): Name: \"C:\"/ Type: \"Local Disk\"/ Capacity: \"294429650944 (274.2 GiB)\"/ Free: \"79278014464 (73.8 GiB)\"/ Usage %: \"73%\"',NULL,64,NULL,NULL,NULL,NULL,'2021-09-13','2024-09-16',0,NULL,'2026-07-06 08:38:34','2026-09-15 02:30:53'),(134,'CS-64-029-PCSAC',2,NULL,'PC','Dell Inc.','OptiPlex 3080',NULL,'BFC3NF3','Intel(R) Core(TM) i5-10500 CPU @ 3.10GHz',16,'Name: \"C:\"/ Type: \"Local Disk\"/ Capacity: \"216133188096 (200.4 GiB)\"/ Free: \"6977165072 (6.5 GiB)...',NULL,'Active',NULL,NULL,NULL,NULL,'162.16.58.238','Static','70:B5:E8:75:76:45',NULL,'storage (full): Name: \"C:\"/ Type: \"Local Disk\"/ Capacity: \"216133188096 (200.4 GiB)\"/ Free: \"6977165072 (6.5 GiB)\"/ Usage %: \"97%\"',NULL,64,NULL,NULL,NULL,NULL,'2021-06-30','2024-07-02',0,NULL,'2026-07-06 08:38:34','2026-09-15 02:30:53'),(135,'CS-64-031-PCSEN',2,NULL,'PC','Dell Inc.','OptiPlex 3080',NULL,'9XRBWF3','Intel(R) Core(TM) i5-10505 CPU @ 3.20GHz',8,'Name: \"C:\"/ Type: \"Local Disk\"/ Capacity: \"251409723392 (234.1 GiB)\"/ Free: \"16090963968 (16.0 Gi...',NULL,'Active',NULL,NULL,NULL,NULL,'162.16.56.95','Static','70:B5:E8:76:B4:6C',NULL,'storage (full): Name: \"C:\"/ Type: \"Local Disk\"/ Capacity: \"251409723392 (234.1 GiB)\"/ Free: \"16090963968 (16.0 GiB)\"/ Usage %: \"94%\"',NULL,64,NULL,NULL,NULL,NULL,'2021-07-13','2024-07-16',0,NULL,'2026-07-06 08:38:34','2026-09-15 02:30:53'),(136,'CS-64-032-PCSEN',2,NULL,'PC','Dell Inc.','OptiPlex 3080',NULL,'64D44F3','Intel(R) Core(TM) i5-10500 CPU @ 3.10GHz',8,'Name: \"C:\"/ Type: \"Local Disk\"/ Capacity: \"437828374528 (407.8 GiB)\"/ Free: \"161611011072 (141.2 ...',NULL,'Active',NULL,NULL,NULL,NULL,'162.16.59.98','Static','70:B5:E8:67:08:96',NULL,'storage (full): Name: \"C:\"/ Type: \"Local Disk\"/ Capacity: \"437828374528 (407.8 GiB)\"/ Free: \"161611011072 (141.2 GiB)\"/ Usage %: \"65%\"',NULL,64,NULL,NULL,NULL,NULL,'2021-05-24','2024-05-26',0,NULL,'2026-07-06 08:38:34','2026-09-15 02:30:53'),(137,'CS-64-032-PCSWH',2,NULL,'PC','Dell Inc.','OptiPlex 3080',NULL,'CFC3NF3','Intel(R) Core(TM) i5-10500 CPU @ 3.10GHz',8,'Name: \"C:\"/ Type: \"Local Disk\"/ Capacity: \"214833295360 (200.1 GiB)\"/ Free: \"19167879168 (16.9 Gi...',NULL,'Active',NULL,NULL,NULL,NULL,'162.16.58.194','Static','70:B5:E8:75:75:C2',NULL,'storage (full): Name: \"C:\"/ Type: \"Local Disk\"/ Capacity: \"214833295360 (200.1 GiB)\"/ Free: \"19167879168 (16.9 GiB)\"/ Usage %: \"91%\"',NULL,64,NULL,NULL,NULL,NULL,'2021-06-30','2024-07-02',0,NULL,'2026-07-06 08:38:34','2026-09-15 02:30:53'),(138,'CS-65-001-PCSWH',2,NULL,'PC','Dell Inc.','OptiPlex 3090',NULL,'82GSPP3','Intel(R) Core(TM) i5-10505 CPU @ 3.20GHz',8,'Name: \"C:\"/ Type: \"Local Disk\"/ Capacity: \"294304870400 (274.1 GiB)\"/ Free: \"119108661248 (110.9 ...',NULL,'Active',NULL,NULL,NULL,NULL,'162.16.58.241','Static','C0:25:A5:CD:63:1C',NULL,'storage (full): Name: \"C:\"/ Type: \"Local Disk\"/ Capacity: \"294304870400 (274.1 GiB)\"/ Free: \"119108661248 (110.9 GiB)\"/ Usage %: \"60%\"',NULL,64,NULL,NULL,NULL,NULL,'2022-04-22','2025-05-06',0,NULL,'2026-07-06 08:38:34','2026-09-15 02:30:53'),(139,'CS-65-002-PCSIT',NULL,'IT','PC','Dell','Optiplex 3000',NULL,'7T9FVQ3','i5-12600 3.3GHz',8,'256GB SSD',NULL,'Active','Office B8',NULL,NULL,NULL,'162.16.56.131','Static',NULL,NULL,'Bitdefender: ✓ | SmartStore: ✓ | Redmine: ✗ | Budget: ✗ | Central Store: ✗ | TruckLoad PLP: ✗ | Payroll: ✗ | S/N Mouse: CN-065K5F-xxx | S/N Keyboard: CN-065VYN-xxx','Windows 10',64,'00355-xxxxx','365',NULL,NULL,'2022-01-16','2025-01-16',0,NULL,'2026-07-06 08:38:34','2026-09-15 02:30:53'),(140,'CS-65-003-PCSHR',2,NULL,'PC','Dell Inc.','OptiPlex 3000',NULL,'H19GVQ3','12th Gen Intel(R) Core(TM) i5-12600',16,'Name: \"C:\"/ Type: \"Local Disk\"/ Capacity: \"509259804672 (474.3 GiB)\"/ Free: \"250541621248 (233.3 ...',NULL,'Active',NULL,NULL,NULL,NULL,'162.16.58.168','Static','74:86:E2:2E:C6:F3',NULL,'storage (full): Name: \"C:\"/ Type: \"Local Disk\"/ Capacity: \"509259804672 (474.3 GiB)\"/ Free: \"250541621248 (233.3 GiB)\"/ Usage %: \"51%\"',NULL,64,NULL,NULL,NULL,NULL,'2022-07-03','2025-07-13',0,NULL,'2026-07-06 08:38:34','2026-09-15 02:30:53'),(141,'CS-65-004-PCSAC',2,NULL,'PC','Dell Inc.','OptiPlex 3000',NULL,'GKBDVQ3','12th Gen Intel(R) Core(TM) i5-12600',8,'Name: \"C:\"/ Type: \"Local Disk\"/ Capacity: \"214853218304 (200.1 GiB)\"/ Free: \"33566826496 (31.3 Gi...',NULL,'Active',NULL,NULL,NULL,NULL,'162.16.56.40','Static','74:86:E2:2E:C7:47',NULL,'storage (full): Name: \"C:\"/ Type: \"Local Disk\"/ Capacity: \"214853218304 (200.1 GiB)\"/ Free: \"33566826496 (31.3 GiB)\"/ Usage %: \"84%\"',NULL,64,NULL,NULL,NULL,NULL,'2022-07-03','2025-07-13',0,NULL,'2026-07-06 08:38:34','2026-09-15 02:30:53'),(142,'CS-65-005-PCSPC',2,NULL,'PC','Dell Inc.','OptiPlex 3000',NULL,'J19GVQ3','12th Gen Intel(R) Core(TM) i5-12600',16,'Name: \"C:\"/ Type: \"Local Disk\"/ Capacity: \"214748360704 (200.0 GiB)\"/ Free: \"18806571008 (16.5 Gi...',NULL,'Active',NULL,NULL,NULL,NULL,'162.16.58.246','Static','74:86:E2:2E:C6:EA',NULL,'storage (full): Name: \"C:\"/ Type: \"Local Disk\"/ Capacity: \"214748360704 (200.0 GiB)\"/ Free: \"18806571008 (16.5 GiB)\"/ Usage %: \"91%\"',NULL,64,NULL,NULL,NULL,NULL,'2022-07-03','2025-07-13',0,NULL,'2026-07-06 08:38:34','2026-09-15 02:30:53'),(143,'CS-65-007-PCSCS',2,NULL,'PC','Dell Inc.','OptiPlex 3000',NULL,'FKBDVQ3','12th Gen Intel(R) Core(TM) i5-12600',8,'Name: \"C:\"/ Type: \"Local Disk\"/ Capacity: \"214748360704 (200.0 GiB)\"/ Free: \"32299642880 (30.1 Gi...',NULL,'Active',NULL,NULL,NULL,NULL,'162.16.56.63','Static','74:86:E2:2E:C7:16',NULL,'storage (full): Name: \"C:\"/ Type: \"Local Disk\"/ Capacity: \"214748360704 (200.0 GiB)\"/ Free: \"32299642880 (30.1 GiB)\"/ Usage %: \"85%\"',NULL,64,NULL,NULL,NULL,NULL,'2022-07-03','2025-07-13',0,NULL,'2026-07-06 08:38:34','2026-09-15 02:30:53'),(144,'CS-65-008-PCSWH',2,NULL,'PC','Dell Inc.','OptiPlex 3000',NULL,'8T9FVQ3','12th Gen Intel(R) Core(TM) i5-12600',8,'Name: \"C:\"/ Type: \"Local Disk\"/ Capacity: \"214748360704 (200.0 GiB)\"/ Free: \"97785139200 (91.1 Gi...',NULL,'Active',NULL,NULL,NULL,NULL,'162.16.58.237','Static','74:86:E2:2E:C7:5E',NULL,'storage (full): Name: \"C:\"/ Type: \"Local Disk\"/ Capacity: \"214748360704 (200.0 GiB)\"/ Free: \"97785139200 (91.1 GiB)\"/ Usage %: \"54%\"',NULL,64,NULL,NULL,NULL,NULL,'2022-07-03','2025-07-13',0,NULL,'2026-07-06 08:38:34','2026-09-15 02:30:53'),(145,'CS-65-009-PCSWH',2,NULL,'PC','Dell Inc.','OptiPlex 3000',NULL,'G19GVQ3','12th Gen Intel(R) Core(TM) i5-12600',8,'Name: \"C:\"/ Type: \"Local Disk\"/ Capacity: \"214748360704 (200.0 GiB)\"/ Free: \"45571887104 (42.4 Gi...',NULL,'Active',NULL,NULL,NULL,NULL,'162.16.58.244','Static','74:86:E2:2E:C7:7C',NULL,'storage (full): Name: \"C:\"/ Type: \"Local Disk\"/ Capacity: \"214748360704 (200.0 GiB)\"/ Free: \"45571887104 (42.4 GiB)\"/ Usage %: \"79%\"',NULL,64,NULL,NULL,NULL,NULL,'2022-07-03','2025-07-13',0,NULL,'2026-07-06 08:38:34','2026-09-15 02:30:53'),(146,'CS-65-011-PCSAC',2,NULL,'PC','Dell Inc.','OptiPlex 3090',NULL,'D5CZ6R3','Intel(R) Core(TM) i5-10505 CPU @ 3.20GHz',8,'Name: \"C:\"/ Type: \"Local Disk\"/ Capacity: \"214748360704 (200.0 GiB)\"/ Free: \"14183333888 (13.2 Gi...',NULL,'Active',NULL,NULL,NULL,NULL,'162.16.58.39','Static','00:BE:43:E5:EA:54',NULL,'storage (full): Name: \"C:\"/ Type: \"Local Disk\"/ Capacity: \"214748360704 (200.0 GiB)\"/ Free: \"14183333888 (13.2 GiB)\"/ Usage %: \"93%\"',NULL,64,NULL,NULL,NULL,NULL,'2022-07-20','2025-07-27',0,NULL,'2026-07-06 08:38:34','2026-09-15 02:30:53'),(147,'CS-65-012-PCSHR',2,NULL,'PC','Dell Inc.','OptiPlex 3000',NULL,'C8BGGR3','12th Gen Intel(R) Core(TM) i5-12500',8,'Name: \"C:\"/ Type: \"Local Disk\"/ Capacity: \"253168185344 (235.8 GiB)\"/ Free: \"18214096896 (16.0 Gi...',NULL,'Active',NULL,NULL,NULL,NULL,'162.16.56.112','Static','00:BE:43:F0:21:7D',NULL,'storage (full): Name: \"C:\"/ Type: \"Local Disk\"/ Capacity: \"253168185344 (235.8 GiB)\"/ Free: \"18214096896 (16.0 GiB)\"/ Usage %: \"93%\"',NULL,64,NULL,NULL,NULL,NULL,'2022-08-05','2025-08-16',0,NULL,'2026-07-06 08:38:34','2026-09-15 02:30:53'),(148,'CS-65-013-PCSHR',2,NULL,'PC','Dell Inc.','OptiPlex 3000',NULL,'79BGGR3','12th Gen Intel(R) Core(TM) i5-12500',8,'Name: \"C:\"/ Type: \"Local Disk\"/ Capacity: \"253168185344 (235.8 GiB)\"/ Free: \"72253186048 (67.3 Gi...',NULL,'Active',NULL,NULL,NULL,NULL,'162.16.64.160','Static','00:BE:43:F0:21:4E',NULL,'storage (full): Name: \"C:\"/ Type: \"Local Disk\"/ Capacity: \"253168185344 (235.8 GiB)\"/ Free: \"72253186048 (67.3 GiB)\"/ Usage %: \"71%\"',NULL,64,NULL,NULL,NULL,NULL,'2022-08-05','2025-08-16',0,NULL,'2026-07-06 08:38:34','2026-09-15 02:30:53'),(149,'CS-65-014-PCSIT',2,NULL,'PC','Dell Inc.','OptiPlex 3000',NULL,'D8BGGR3','12th Gen Intel(R) Core(TM) i5-12500',16,'Name: \"C:\"/ Type: \"Local Disk\"/ Capacity: \"253167136768 (235.8 GiB)\"/ Free: \"24803041280 (23.1 Gi...',NULL,'Active',NULL,NULL,NULL,NULL,'162.16.57.89','Static','C0:25:A5:D3:2F:3C',NULL,'storage (full): Name: \"C:\"/ Type: \"Local Disk\"/ Capacity: \"253167136768 (235.8 GiB)\"/ Free: \"24803041280 (23.1 GiB)\"/ Usage %: \"90%\"',NULL,64,NULL,NULL,NULL,NULL,'2022-08-05','2025-08-16',0,NULL,'2026-07-06 08:38:34','2026-09-15 02:30:53'),(150,'CS-65-016-PCSPC',2,NULL,'PC','Dell Inc.','OptiPlex 3000',NULL,'FNL9XR3','12th Gen Intel(R) Core(TM) i5-12500',16,'Name: \"C:\"/ Type: \"Local Disk\"/ Capacity: \"253168185344 (235.8 GiB)\"/ Free: \"50881277952 (47.4 Gi...',NULL,'Active',NULL,NULL,NULL,NULL,'162.16.58.93','Static','00:BE:43:F0:24:79',NULL,'storage (full): Name: \"C:\"/ Type: \"Local Disk\"/ Capacity: \"253168185344 (235.8 GiB)\"/ Free: \"50881277952 (47.4 GiB)\"/ Usage %: \"80%\"',NULL,64,NULL,NULL,NULL,NULL,'2022-08-24','2025-09-27',0,NULL,'2026-07-06 08:38:34','2026-09-15 02:30:53'),(151,'CS-65-016-PCSQA',2,NULL,'PC','Dell Inc.','OptiPlex 3000',NULL,'GPL9XR3','12th Gen Intel(R) Core(TM) i5-12500',8,'Name: \"C:\"/ Type: \"Local Disk\"/ Capacity: \"253168185344 (235.8 GiB)\"/ Free: \"23258701824 (21.7 Gi...',NULL,'Active',NULL,NULL,NULL,NULL,'162.16.58.168','Static','00:BE:43:F0:24:58',NULL,'storage (full): Name: \"C:\"/ Type: \"Local Disk\"/ Capacity: \"253168185344 (235.8 GiB)\"/ Free: \"23258701824 (21.7 GiB)\"/ Usage %: \"91%\"',NULL,64,NULL,NULL,NULL,NULL,'2022-08-24','2025-09-28',0,NULL,'2026-07-06 08:38:34','2026-09-15 02:30:53'),(152,'CS-65-02-PCSIT',2,NULL,'PC','Dell Inc.','OptiPlex 3000',NULL,'7T9FVQ3','12th Gen Intel(R) Core(TM) i5-12600',16,'Name: \"C:\"/ Type: \"Local Disk\"/ Capacity: \"509259804672 (474.3 GiB)\"/ Free: \"336235585536 (313.1 ...',NULL,'Active',NULL,NULL,NULL,NULL,'162.16.56.131','Static','74:86:E2:2E:C7:3B',NULL,'storage (full): Name: \"C:\"/ Type: \"Local Disk\"/ Capacity: \"509259804672 (474.3 GiB)\"/ Free: \"336235585536 (313.1 GiB)\"/ Usage %: \"34%\"',NULL,64,NULL,NULL,NULL,NULL,'2022-07-03','2025-07-13',0,NULL,'2026-07-06 08:38:34','2026-09-15 02:30:53'),(153,'CS-66-001-PCSAC',2,NULL,'PC','Dell Inc.','OptiPlex 3000',NULL,'9P397X3','12th Gen Intel(R) Core(TM) i5-12500',8,'Name: \"C:\"/ Type: \"Local Disk\"/ Capacity: \"253023481856 (235.6 GiB)\"/ Free: \"124127076352 (116.6 ...',NULL,'Active',NULL,NULL,NULL,NULL,'162.16.58.142','Static','6C:3C:8C:4A:F2:9B',NULL,'storage (full): Name: \"C:\"/ Type: \"Local Disk\"/ Capacity: \"253023481856 (235.6 GiB)\"/ Free: \"124127076352 (116.6 GiB)\"/ Usage %: \"51%\"',NULL,64,NULL,NULL,NULL,NULL,'2023-04-27','2026-07-02',0,NULL,'2026-07-06 08:38:34','2026-09-15 02:30:53'),(154,'CS-66-001-PCSWH',2,NULL,'PC','Dell Inc.','OptiPlex Micro 7010',NULL,'DXN37Y3','13th Gen Intel(R) Core(TM) i5-13500T',8,'Name: \"C:\"/ Type: \"Local Disk\"/ Capacity: \"252811669504 (235.4 GiB)\"/ Free: \"13601861632 (12.7 Gi...',NULL,'Active',NULL,NULL,NULL,NULL,'162.16.57.192','Static','6C:3C:8C:56:ED:94',NULL,'storage (full): Name: \"C:\"/ Type: \"Local Disk\"/ Capacity: \"252811669504 (235.4 GiB)\"/ Free: \"13601861632 (12.7 GiB)\"/ Usage %: \"95%\"',NULL,64,NULL,NULL,NULL,NULL,'2023-06-29','2026-08-09',0,NULL,'2026-07-06 08:38:34','2026-09-15 02:30:53'),(155,'CS-66-002-PCSMK',2,NULL,'PC','Dell Inc.','OptiPlex 3000',NULL,'86ZR8X3','12th Gen Intel(R) Core(TM) i5-12500',8,'Name: \"C:\"/ Type: \"Local Disk\"/ Capacity: \"253023481856 (235.6 GiB)\"/ Free: \"54169268224 (50.4 Gi...',NULL,'Active',NULL,NULL,NULL,NULL,'162.16.58.163','Static','6C:3C:8C:3A:A3:8F',NULL,'storage (full): Name: \"C:\"/ Type: \"Local Disk\"/ Capacity: \"253023481856 (235.6 GiB)\"/ Free: \"54169268224 (50.4 GiB)\"/ Usage %: \"79%\"',NULL,64,NULL,NULL,NULL,NULL,'2023-04-28','2026-07-02',0,NULL,'2026-07-06 08:38:34','2026-09-15 02:30:53'),(156,'CS-66-003-PCSCS',2,NULL,'PC','Dell Inc.','OptiPlex Micro 7010',NULL,'4ZN37Y3','13th Gen Intel(R) Core(TM) i5-13500T',16,'Name: \"C:\"/ Type: \"Local Disk\"/ Capacity: \"252811669504 (235.4 GiB)\"/ Free: \"43260137472 (40.3 Gi...',NULL,'Active',NULL,NULL,NULL,NULL,'162.16.57.160','Static','6C:3C:8C:5A:F1:31',NULL,'storage (full): Name: \"C:\"/ Type: \"Local Disk\"/ Capacity: \"252811669504 (235.4 GiB)\"/ Free: \"43260137472 (40.3 GiB)\"/ Usage %: \"83%\"',NULL,64,NULL,NULL,NULL,NULL,'2023-06-29','2026-08-09',0,NULL,'2026-07-06 08:38:34','2026-09-15 02:30:53'),(157,'CS-66-005-PCSCS',2,NULL,'PC','Dell Inc.','OptiPlex Micro 7010',NULL,'3YN37Y3','13th Gen Intel(R) Core(TM) i5-13500T',8,'Name: \"C:\"/ Type: \"Local Disk\"/ Capacity: \"252811669504 (235.4 GiB)\"/ Free: \"83026862080 (77.3 Gi...',NULL,'Active',NULL,NULL,NULL,NULL,'162.16.59.94','Static','6C:3C:8C:56:ED:D6',NULL,'storage (full): Name: \"C:\"/ Type: \"Local Disk\"/ Capacity: \"252811669504 (235.4 GiB)\"/ Free: \"83026862080 (77.3 GiB)\"/ Usage %: \"67%\"',NULL,64,NULL,NULL,NULL,NULL,'2023-06-29','2026-08-09',0,NULL,'2026-07-06 08:38:34','2026-09-15 02:30:53'),(158,'CS-66-007-PCSWH',2,NULL,'PC','Dell Inc.','OptiPlex Micro 7010',NULL,'6XN37Y3','13th Gen Intel(R) Core(TM) i5-13500T',8,'Name: \"C:\"/ Type: \"Local Disk\"/ Capacity: \"252811669504 (235.4 GiB)\"/ Free: \"122226511872 (113.8 ...',NULL,'Active',NULL,NULL,NULL,NULL,'162.16.56.252,162.16.57.166','Static','6C:3C:8C:5A:F3...',NULL,'storage (full): Name: \"C:\"/ Type: \"Local Disk\"/ Capacity: \"252811669504 (235.4 GiB)\"/ Free: \"122226511872 (113.8 GiB)\"/ Usage %: \"52%\" | mac_address (full): 6C:3C:8C:5A:F3:D1,F4:6D:3F:AE:E2:41',NULL,64,NULL,NULL,NULL,NULL,'2023-06-29','2026-08-09',0,NULL,'2026-07-06 08:38:34','2026-09-15 02:30:53'),(159,'CS-66-008-PCSWH',2,NULL,'PC','Dell Inc.','OptiPlex Micro 7010',NULL,'7XN37Y3','13th Gen Intel(R) Core(TM) i5-13500T',8,'Name: \"C:\"/ Type: \"Local Disk\"/ Capacity: \"252811669504 (235.4 GiB)\"/ Free: \"28452007936 (26.5 Gi...',NULL,'Active',NULL,NULL,NULL,NULL,'162.16.57.139','Static','F4:6D:3F:AE:D4:63',NULL,'storage (full): Name: \"C:\"/ Type: \"Local Disk\"/ Capacity: \"252811669504 (235.4 GiB)\"/ Free: \"28452007936 (26.5 GiB)\"/ Usage %: \"89%\"',NULL,64,NULL,NULL,NULL,NULL,'2023-06-29','2026-08-09',0,NULL,'2026-07-06 08:38:34','2026-09-15 02:30:53'),(160,'CS-66-009-PCSPL',2,NULL,'PC','Dell Inc.','OptiPlex Micro 7010',NULL,'JZN37Y3','13th Gen Intel(R) Core(TM) i5-13500T',8,'Name: \"C:\"/ Type: \"Local Disk\"/ Capacity: \"252811669504 (235.4 GiB)\"/ Free: \"50121994240 (46.7 Gi...',NULL,'Active',NULL,NULL,NULL,NULL,'162.16.58.7','Static','6C:3C:8C:56:ED:B0',NULL,'storage (full): Name: \"C:\"/ Type: \"Local Disk\"/ Capacity: \"252811669504 (235.4 GiB)\"/ Free: \"50121994240 (46.7 GiB)\"/ Usage %: \"80%\"',NULL,64,NULL,NULL,NULL,NULL,'2023-06-29','2026-08-09',0,NULL,'2026-07-06 08:38:34','2026-09-15 02:30:53'),(161,'CS-66-010-PCSSA',2,NULL,'PC','Dell Inc.','OptiPlex Micro 7010',NULL,'GXN37Y3','13th Gen Intel(R) Core(TM) i5-13500T',8,'Name: \"C:\"/ Type: \"Local Disk\"/ Capacity: \"254798581660 (237.3 GiB)\"/ Free: \"110259896320 (102.7 ...',NULL,'Active',NULL,NULL,NULL,NULL,'162.16.57.146','Static','6C:3C:8C:56:ED:9E',NULL,'storage (full): Name: \"C:\"/ Type: \"Local Disk\"/ Capacity: \"254798581660 (237.3 GiB)\"/ Free: \"110259896320 (102.7 GiB)\"/ Usage %: \"57%\"',NULL,64,NULL,NULL,NULL,NULL,'2023-06-29','2026-08-09',0,NULL,'2026-07-06 08:38:34','2026-09-15 02:30:53'),(162,'CS-66-010-PCSWH',2,NULL,'PC','Dell Inc.','OptiPlex Micro 7010',NULL,'12P37Y3','13th Gen Intel(R) Core(TM) i5-13500T',8,'Name: \"C:\"/ Type: \"Local Disk\"/ Capacity: \"252811669504 (235.4 GiB)\"/ Free: \"100504059904 (93.6 G...',NULL,'Active',NULL,NULL,NULL,NULL,'162.16.58.131','Static','6C:3C:8C:5A:F1:C2',NULL,'storage (full): Name: \"C:\"/ Type: \"Local Disk\"/ Capacity: \"252811669504 (235.4 GiB)\"/ Free: \"100504059904 (93.6 GiB)\"/ Usage %: \"60%\"',NULL,64,NULL,NULL,NULL,NULL,'2023-06-29','2026-09-04',0,NULL,'2026-07-06 08:38:34','2026-09-15 02:30:53'),(163,'CS-66-011-PCSSA',2,NULL,'PC','Dell Inc.','OptiPlex Micro 7010',NULL,'5RWG6Z3','13th Gen Intel(R) Core(TM) i5-13500T',8,'Name: \"C:\"/ Type: \"Local Disk\"/ Capacity: \"254798581660 (237.3 GiB)\"/ Free: \"162095431680 (161.0 ...',NULL,'Active',NULL,NULL,NULL,NULL,'162.16.57.119','Static','20:88:10:67:45:82',NULL,'storage (full): Name: \"C:\"/ Type: \"Local Disk\"/ Capacity: \"254798581660 (237.3 GiB)\"/ Free: \"162095431680 (161.0 GiB)\"/ Usage %: \"36%\"',NULL,64,NULL,NULL,NULL,NULL,'2023-09-16','2026-10-16',0,NULL,'2026-07-06 08:38:34','2026-09-15 02:30:53'),(164,'CS-66-012-PCSAC',2,NULL,'PC','Dell Inc.','OptiPlex Micro 7010',NULL,'5YN37Y3','13th Gen Intel(R) Core(TM) i5-13500T',12,'Name: \"C:\"/ Type: \"Local Disk\"/ Capacity: \"252811669504 (235.4 GiB)\"/ Free: \"62341144576 (58.1 Gi...',NULL,'Active',NULL,NULL,NULL,NULL,'162.16.57.92','Static','F4:6D:3F:B4:B7:57',NULL,'storage (full): Name: \"C:\"/ Type: \"Local Disk\"/ Capacity: \"252811669504 (235.4 GiB)\"/ Free: \"62341144576 (58.1 GiB)\"/ Usage %: \"75%\"',NULL,64,NULL,NULL,NULL,NULL,'2023-06-29','2026-08-09',0,NULL,'2026-07-06 08:38:34','2026-09-15 02:30:53'),(165,'CS-66-013-PCSWH',2,NULL,'PC','Dell Inc.','OptiPlex Micro 7010',NULL,'FYN37Y3','13th Gen Intel(R) Core(TM) i5-13500T',8,'Name: \"C:\"/ Type: \"Local Disk\"/ Capacity: \"252811669504 (235.4 GiB)\"/ Free: \"30034370560 (28.0 Gi...',NULL,'Active',NULL,NULL,NULL,NULL,'162.16.58.165','Static','6C:3C:8C:56:ED:6A',NULL,'storage (full): Name: \"C:\"/ Type: \"Local Disk\"/ Capacity: \"252811669504 (235.4 GiB)\"/ Free: \"30034370560 (28.0 GiB)\"/ Usage %: \"88%\"',NULL,64,NULL,NULL,NULL,NULL,'2023-06-29','2026-08-09',0,NULL,'2026-07-06 08:38:34','2026-09-15 02:30:53'),(166,'CS-66-06-PCSWH',2,NULL,'PC','Dell Inc.','OptiPlex Micro 7010',NULL,'2XN37Y3','13th Gen Intel(R) Core(TM) i5-13500T',8,'Name: \"C:\"/ Type: \"Local Disk\"/ Capacity: \"252811669504 (235.4 GiB)\"/ Free: \"92161444224 (85.8 Gi...',NULL,'Active',NULL,NULL,NULL,NULL,'162.16.57.148','Static','6C:3C:8C:56:ED:B6',NULL,'storage (full): Name: \"C:\"/ Type: \"Local Disk\"/ Capacity: \"252811669504 (235.4 GiB)\"/ Free: \"92161444224 (85.8 GiB)\"/ Usage %: \"64%\"',NULL,64,NULL,NULL,NULL,NULL,'2023-06-29','2026-08-09',0,NULL,'2026-07-06 08:38:34','2026-09-15 02:30:53'),(167,'CS-68-001-PCSIT',2,NULL,'PC','Dell Inc.','OptiPlex SFF 7020',NULL,'1LK1854','Intel(R) Core(TM) i5-14500',8,'Name: \"C:\"/ Type: \"Local Disk\"/ Capacity: \"296604397568 (276.2 GiB)\"/ Free: \"101885485056 (94.9 G...',NULL,'Active',NULL,NULL,NULL,NULL,'162.16.56.133','Static','5C:B2:6D:F9:96:CE',NULL,'storage (full): Name: \"C:\"/ Type: \"Local Disk\"/ Capacity: \"296604397568 (276.2 GiB)\"/ Free: \"101885485056 (94.9 GiB)\"/ Usage %: \"66%\"',NULL,64,NULL,NULL,NULL,NULL,'2024-09-13','2028-03-16',0,NULL,'2026-07-06 08:38:34','2026-09-15 02:30:53'),(168,'CS-68-003-PCSPC',2,NULL,'PC','HP','HP Pro SFF 400 G9 Desktop PC',NULL,'4CE511BN8Q','Intel(R) Core(TM) i5-14500',16,'Name: \"C:\"/ Type: \"Local Disk\"/ Capacity: \"510976323584 (475.9 GiB)\"/ Free: \"403944321024 (376.2 ...',NULL,'Active',NULL,NULL,NULL,NULL,'162.16.57.116','Static','24:FB:E3:3F:D0:DA',NULL,'storage (full): Name: \"C:\"/ Type: \"Local Disk\"/ Capacity: \"510976323584 (475.9 GiB)\"/ Free: \"403944321024 (376.2 GiB)\"/ Usage %: \"21%\"',NULL,64,NULL,NULL,NULL,NULL,'2025-04-10','2029-04-09',0,NULL,'2026-07-06 08:38:34','2026-09-15 02:30:53'),(169,'CS-68-004-PCSCS',2,NULL,'PC','HP','HP Pro SFF 400 G9 Desktop PC',NULL,'4CE511BNB4','Intel(R) Core(TM) i5-14500',16,'Name: \"C:\"/ Type: \"Local Disk\"/ Capacity: \"510883000320 (475.8 GiB)\"/ Free: \"336694460416 (313.6 ...',NULL,'Active',NULL,NULL,NULL,NULL,'162.16.57.111','Static','24:FB:E3:3F:CF:D8',NULL,'storage (full): Name: \"C:\"/ Type: \"Local Disk\"/ Capacity: \"510883000320 (475.8 GiB)\"/ Free: \"336694460416 (313.6 GiB)\"/ Usage %: \"34%\"',NULL,64,NULL,NULL,NULL,NULL,'2025-07-04','2029-07-03',0,NULL,'2026-07-06 08:38:34','2026-09-15 02:30:53'),(170,'CS-68-005-PCSCS',2,NULL,'PC','HP','HP Pro SFF 400 G9 Desktop PC',NULL,'4CE511BN47','Intel(R) Core(TM) i5-14500',16,'Name: \"C:\"/ Type: \"Local Disk\"/ Capacity: \"510976323584 (475.9 GiB)\"/ Free: \"347438968832 (323.6 ...',NULL,'Active',NULL,NULL,NULL,NULL,'162.16.57.189','Static','24:FB:E3:3F:D4:95',NULL,'storage (full): Name: \"C:\"/ Type: \"Local Disk\"/ Capacity: \"510976323584 (475.9 GiB)\"/ Free: \"347438968832 (323.6 GiB)\"/ Usage %: \"32%\"',NULL,64,NULL,NULL,NULL,NULL,'2025-07-04','2029-07-03',0,NULL,'2026-07-06 08:38:34','2026-09-15 02:30:53'),(171,'CS-68-007-PCSIT',2,NULL,'PC','HP','HP Pro SFF 400 G9 Desktop PC',NULL,'4CE511BN6H','Intel(R) Core(TM) i5-14500',16,'Name: \"C:\"/ Type: \"Local Disk\"/ Capacity: \"510888243200 (475.8 GiB)\"/ Free: \"422514405376 (393.5 ...',NULL,'Active',NULL,NULL,NULL,NULL,'162.16.56.129','Static','24:FB:E3:3F:D0:8D',NULL,'storage (full): Name: \"C:\"/ Type: \"Local Disk\"/ Capacity: \"510888243200 (475.8 GiB)\"/ Free: \"422514405376 (393.5 GiB)\"/ Usage %: \"16%\"',NULL,64,NULL,NULL,NULL,NULL,'2025-04-10','2029-04-09',0,NULL,'2026-07-06 08:38:34','2026-09-15 02:30:53'),(172,'CS-68-008-PCSAC',2,NULL,'PC','HP','HP Pro SFF 400 G9 Desktop PC',NULL,'4CE511BN5G','Intel(R) Core(TM) i5-14500',16,'Name: \"C:\"/ Type: \"Local Disk\"/ Capacity: \"510816988608 (475.7 GiB)\"/ Free: \"355036991488 (330.7 ...',NULL,'Active',NULL,NULL,NULL,NULL,'162.16.57.145','Static','24:FB:E3:3F:D1:6D',NULL,'storage (full): Name: \"C:\"/ Type: \"Local Disk\"/ Capacity: \"510816988608 (475.7 GiB)\"/ Free: \"355036991488 (330.7 GiB)\"/ Usage %: \"30%\"',NULL,64,NULL,NULL,NULL,NULL,'2025-07-18','2029-07-16',0,NULL,'2026-07-06 08:38:34','2026-09-15 02:30:53'),(173,'CS-68-009-PCSMK',2,NULL,'PC','HP','HP Pro SFF 400 G9 Desktop PC',NULL,'4CE511BN9M','Intel(R) Core(TM) i5-14500',16,'Name: \"C:\"/ Type: \"Local Disk\"/ Capacity: \"510889291676 (475.8 GiB)\"/ Free: \"274447536128 (255.6 ...',NULL,'Active',NULL,NULL,NULL,NULL,'162.16.57.88','Static','24:FB:E3:3F:CF:A8',NULL,'storage (full): Name: \"C:\"/ Type: \"Local Disk\"/ Capacity: \"510889291676 (475.8 GiB)\"/ Free: \"274447536128 (255.6 GiB)\"/ Usage %: \"46%\"',NULL,64,NULL,NULL,NULL,NULL,'2025-07-18','2029-07-16',0,NULL,'2026-07-06 08:38:34','2026-09-15 02:30:53'),(174,'CS-68-010-PCSWH',2,NULL,'PC','HP','HP Pro SFF 400 G9 Desktop PC',NULL,'4CE511BN7Y','Intel(R) Core(TM) i5-14500',16,'Name: \"C:\"/ Type: \"Local Disk\"/ Capacity: \"510889291676 (475.8 GiB)\"/ Free: \"363418562560 (338.5 ...',NULL,'Active',NULL,NULL,NULL,NULL,'162.16.56.221','Static','24:FB:E3:3F:D0:62',NULL,'storage (full): Name: \"C:\"/ Type: \"Local Disk\"/ Capacity: \"510889291676 (475.8 GiB)\"/ Free: \"363418562560 (338.5 GiB)\"/ Usage %: \"29%\"',NULL,64,NULL,NULL,NULL,NULL,'2025-07-18','2029-07-16',0,NULL,'2026-07-06 08:38:34','2026-09-15 02:30:53'),(175,'CS-68-011-PCSWH',2,NULL,'PC','HP','HP Pro SFF 400 G9 Desktop PC',NULL,'4CE511BN67','Intel(R) Core(TM) i5-14500',16,'Name: \"C:\"/ Type: \"Local Disk\"/ Capacity: \"510888243200 (475.8 GiB)\"/ Free: \"362252967936 (337.4 ...',NULL,'Active',NULL,NULL,NULL,NULL,'162.16.56.220','Static','24:FB:E3:3F:D0:DE',NULL,'storage (full): Name: \"C:\"/ Type: \"Local Disk\"/ Capacity: \"510888243200 (475.8 GiB)\"/ Free: \"362252967936 (337.4 GiB)\"/ Usage %: \"29%\"',NULL,64,NULL,NULL,NULL,NULL,'2025-07-18','2029-07-16',0,NULL,'2026-07-06 08:38:34','2026-09-15 02:30:53'),(176,'CS-68-012-PCSWH',2,NULL,'PC','HP','HP Pro SFF 400 G9 Desktop PC',NULL,'4CE511BN94','Intel(R) Core(TM) i5-14500',17,'512GB SSD',NULL,'Active',NULL,NULL,NULL,NULL,'162.16.56.236','Static','1C:61:B4:37:01:78',NULL,'storage (full): Name: \"C:\"/ Type: \"Local Disk\"/ Capacity: \"510803308544 (475.7 GiB)\"/ Free: \"375409709056 (349.6 GiB)\"/ Usage %: \"27%\"',NULL,64,NULL,NULL,NULL,NULL,'2025-07-18','2029-07-16',0,NULL,'2026-07-06 08:38:34','2026-09-15 02:30:53'),(177,'CS-68-013-PCSAC',2,NULL,'PC','HP','HP Pro SFF 400 G9 Desktop PC',NULL,'4CE511BN7T','Intel(R) Core(TM) i5-14500',16,'512GB SSD',NULL,'Active',NULL,NULL,NULL,NULL,'162.16.57.91','Static','24:FB:E3:3F:CE:36',NULL,'storage (full): Name: \"C:\"/ Type: \"Local Disk\"/ Capacity: \"510883000320 (475.8 GiB)\"/ Free: \"375770091620 (350.0 GiB)\"/ Usage %: \"26%\"',NULL,64,NULL,NULL,NULL,NULL,'2025-10-06','2029-10-05',0,NULL,'2026-07-06 08:38:34','2026-09-15 02:30:53'),(178,'CS-68-02-PCSAC',2,NULL,'PC','HP','HP Pro SFF 400 G9 Desktop PC',NULL,'4CE511BN9G','Intel(R) Core(TM) i5-14500',16,'512GB SSD',NULL,'Active',NULL,NULL,NULL,NULL,'162.16.57.90','Static','24:FB:E3:3F:CF:CA',NULL,'storage (full): Name: \"C:\"/ Type: \"Local Disk\"/ Capacity: \"510803308544 (475.7 GiB)\"/ Free: \"203082969088 (189.1 GiB)\"/ Usage %: \"60%\"',NULL,64,NULL,NULL,NULL,NULL,'2025-07-04','2029-07-03',0,NULL,'2026-07-06 08:38:34','2026-09-15 02:30:53'),(179,'CS-68-09-PCSBPD',2,NULL,'PC','HP','HP Pro SFF 400 G9 Desktop PC',NULL,'4CE511BN5W','Intel(R) Core(TM) i5-14500',16,'512GB SSD',NULL,'Active',NULL,NULL,NULL,NULL,'162.16.57.95','Static','24:FB:E3:3F:D1:7E',NULL,'storage (full): Name: \"C:\"/ Type: \"Local Disk\"/ Capacity: \"510888243200 (475.8 GiB)\"/ Free: \"396773793792 (369.5 GiB)\"/ Usage %: \"22%\"',NULL,64,NULL,NULL,NULL,NULL,'2025-07-18','2029-07-16',0,NULL,'2026-07-06 08:38:34','2026-09-15 02:30:53'),(180,'CS-69-002-BPDPC',2,NULL,'PC','Dell Inc.','Dell Pro Slim QCS1250',NULL,'8C89LG4','Intel(R) Core(TM) Ultra 5 235',16,'512GB SSD',NULL,'Active',NULL,NULL,NULL,NULL,'162.16.57.144','Static','4C:C5:D9:4C:B5:E5',NULL,'storage (full): Name: \"C:\"/ Type: \"Local Disk\"/ Capacity: \"508852957184 (473.9 GiB)\"/ Free: \"348453888000 (324.5 GiB)\"/ Usage %: \"32%\"',NULL,64,NULL,NULL,NULL,NULL,'2025-12-20','2030-01-07',0,NULL,'2026-07-06 08:38:34','2026-09-15 02:30:53'),(181,'CS-69-002-PCSHR',2,NULL,'PC','Dell Inc.','Dell Pro Slim QCS1250',NULL,'5C89LG4','Intel(R) Core(TM) Ultra 5 235',16,'512GB SSD',NULL,'Active',NULL,NULL,NULL,NULL,'162.16.56.228','Static','4C:C5:D9:4C:B9:A2',NULL,'storage (full): Name: \"C:\"/ Type: \"Local Disk\"/ Capacity: \"508851908608 (473.9 GiB)\"/ Free: \"302526803968 (281.8 GiB)\"/ Usage %: \"41%\"',NULL,64,NULL,NULL,NULL,NULL,'2025-12-20','2030-01-07',0,NULL,'2026-07-06 08:38:34','2026-09-15 02:30:53'),(182,'CS-69-01-PCSHR',2,NULL,'PC','Dell Inc.','OptiPlex SFF 7020',NULL,'BW0Q874','Intel(R) Core(TM) i5-14500',8,'512GB SSD',NULL,'Active',NULL,NULL,NULL,NULL,'162.16.57.163','Static','AC:B4:80:38:F8:4F',NULL,'storage (full): Name: \"C:\"/ Type: \"Local Disk\"/ Capacity: \"252791646560 (235.4 GiB)\"/ Free: \"90973945856 (84.7 GiB)\"/ Usage %: \"64%\"',NULL,64,NULL,NULL,NULL,NULL,'2025-03-14','2028-09-29',0,NULL,'2026-07-06 08:38:34','2026-09-15 02:30:53'),(183,'CS-69-04-PCSAC',2,NULL,'PC','Dell Inc.','Dell Pro Slim QCS1250',NULL,'9B89LG4','Intel(R) Core(TM) Ultra 5 235',16,'512GB SSD',NULL,'Active',NULL,NULL,NULL,NULL,'162.16.56.207','Static','4C:C5:D9:4C:B9:92',NULL,'storage (full): Name: \"C:\"/ Type: \"Local Disk\"/ Capacity: \"508852957184 (473.9 GiB)\"/ Free: \"381842702336 (355.6 GiB)\"/ Usage %: \"25%\"',NULL,64,NULL,NULL,NULL,NULL,'2025-12-20','2030-01-07',0,NULL,'2026-07-06 08:38:34','2026-09-15 02:30:53'),(184,'CS-69-05-PCSHR',2,NULL,'PC','Dell Inc.','Dell Pro Slim QCS1250',NULL,'7SRS3H4','Intel(R) Core(TM) Ultra 5 235',16,'512GB SSD',NULL,'Active',NULL,NULL,NULL,NULL,'162.16.57.194','Static','7C:C2:C6:05:4B:2D',NULL,'storage (full): Name: \"C:\"/ Type: \"Local Disk\"/ Capacity: \"508800528384 (473.9 GiB)\"/ Free: \"387741306880 (361.1 GiB)\"/ Usage %: \"24%\"',NULL,64,NULL,NULL,NULL,NULL,'2025-12-31','2030-09-23',0,NULL,'2026-07-06 08:38:34','2026-09-15 02:30:53'),(185,'CS-69-06-PCSPC',2,NULL,'PC','Dell Inc.','Dell Pro Slim QCS1250',NULL,'48RS3H4','Intel(R) Core(TM) Ultra 5 235',16,'512GB SSD',NULL,'Active',NULL,NULL,NULL,NULL,'162.16.56.250','Static','4C:C5:D9:4D:4D:5D',NULL,'storage (full): Name: \"C:\"/ Type: \"Local Disk\"/ Capacity: \"508800528384 (473.9 GiB)\"/ Free: \"359761956864 (335.1 GiB)\"/ Usage %: \"29%\"',NULL,64,NULL,NULL,NULL,NULL,'2025-12-31','2030-09-23',0,NULL,'2026-07-06 08:38:34','2026-09-15 02:30:53'),(186,'DATA-PCS',2,NULL,'Server','Dell Inc.','PowerEdge R320',NULL,'445DS62','Intel(R) Xeon(R) CPU E5-2407 v2 @ 2.40GHz',16,'Name: \"C:\"/ Type: \"Local Disk\"/ Capacity: \"262143995904 (244.1 GiB)\"/ Free: \"149234040832 (139.0 ...',NULL,'Active',NULL,NULL,NULL,NULL,'162.16.61.18,192.168.40.252','Static','44:A8:42:3D:45...',NULL,'storage (full): Name: \"C:\"/ Type: \"Local Disk\"/ Capacity: \"262143995904 (244.1 GiB)\"/ Free: \"149234040832 (139.0 GiB)\"/ Usage %: \"43%\" | mac_address (full): 44:A8:42:3D:45:45,44:A8:42:3D:45:46',NULL,64,NULL,NULL,NULL,NULL,'2016-08-16','2018-08-18',0,NULL,'2026-07-06 08:38:34','2026-09-15 02:30:53'),(187,'DESKTOP-G67OPPQ',2,NULL,'Notebook','ASUSTeK COMPUTER INC.','ASUS Zenbook S 14 UX5406SA_UX5406SA',NULL,'T1N0KD008338023','Intel(R) Core(TM) Ultra 7 258V',32,'Name: \"C:\"/ Type: \"Local Disk\"/ Capacity: \"1022555918336 (952.3 GiB)\"/ Free: \"878000304128 (816.7...',NULL,'Active',NULL,NULL,NULL,NULL,'162.16.60.190','Static','00:72:EE:AF:68:92',NULL,'storage (full): Name: \"C:\"/ Type: \"Local Disk\"/ Capacity: \"1022555918336 (952.3 GiB)\"/ Free: \"878000304128 (816.7 GiB)\"/ Usage %: \"14%\"',NULL,64,NULL,NULL,NULL,NULL,NULL,NULL,0,NULL,'2026-07-06 08:38:34','2026-09-15 02:30:53'),(188,'DHCP-SVR04',2,NULL,'Server','Dell Inc.','PowerEdge R220',NULL,'DV89R42','Intel(R) Xeon(R) CPU E3-1220 v3 @ 3.10GHz',8,'Name: \"C:\"/ Type: \"Local Disk\"/ Capacity: \"216822102528 (201.0 GiB)\"/ Free: \"167830162672 (147.0 ...',NULL,'Active',NULL,NULL,NULL,NULL,'162.16.61.4','Static','54:9F:35:12:74:26',NULL,'storage (full): Name: \"C:\"/ Type: \"Local Disk\"/ Capacity: \"216822102528 (201.0 GiB)\"/ Free: \"167830162672 (147.0 GiB)\"/ Usage %: \"27%\"',NULL,64,NULL,NULL,NULL,NULL,'2016-02-18','2018-02-20',0,NULL,'2026-07-06 08:38:34','2026-09-15 02:30:53'),(189,'Monitor-Network',NULL,NULL,'Server',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,0,NULL,'2026-07-06 08:38:34','2026-07-06 08:38:34'),(190,'NB-60-001-PCSIT',2,NULL,'Notebook','Dell Inc.','Latitude 3470',NULL,'91HH1F2','Intel(R) Core(TM) i5-6200U CPU @ 2.30GHz',8,'Name: \"C:\"/ Type: \"Local Disk\"/ Capacity: \"238335029248 (222.0 GiB)\"/ Free: \"118361890816 (110.2 ...',NULL,'Active',NULL,NULL,NULL,NULL,'162.16.57.112','Static','34:F3:9A:92:90:28',NULL,'storage (full): Name: \"C:\"/ Type: \"Local Disk\"/ Capacity: \"238335029248 (222.0 GiB)\"/ Free: \"118361890816 (110.2 GiB)\"/ Usage %: \"50%\"',NULL,64,NULL,NULL,NULL,NULL,'2016-02-14','2020-02-16',0,NULL,'2026-07-06 08:38:34','2026-09-15 02:30:53'),(191,'NB-60-002-PCSIT',2,NULL,'Notebook','Dell Inc.','Latitude 3450',NULL,'CP12042','Unknown',8,'256GB SSD',NULL,'Active',NULL,NULL,NULL,NULL,'162.16.112.71','Static','DC:53:60:77:E4:08',NULL,'storage (full): Name: \"C:\"/ Type: \"Local Disk\"/ Capacity: \"239427858432 (223.0 GiB)\"/ Free: \"65864753162 (61.3 GiB)\"/ Usage %: \"72%\"',NULL,64,NULL,NULL,NULL,NULL,'2016-10-16','2018-10-16',0,NULL,'2026-07-06 08:38:34','2026-09-15 02:30:53'),(192,'NB-60-003-PCSIT',2,NULL,'Notebook','Dell Inc.','Latitude 3470',NULL,'DHZL1F2','Unknown',8,'256GB SSD',NULL,'Active',NULL,NULL,NULL,NULL,'162.16.56.218','Static','18:5E:0F:06:EF:73',NULL,'storage (full): Name: \"C:\"/ Type: \"Local Disk\"/ Capacity: \"240001277952 (223.5 GiB)\"/ Free: \"96768200704 (90.1 GiB)\"/ Usage %: \"60%\"',NULL,64,NULL,NULL,NULL,NULL,'2016-02-16','2020-02-19',0,NULL,'2026-07-06 08:38:34','2026-09-15 02:30:53'),(193,'NB-61-001-PCSBPD',2,NULL,'Notebook','Dell Inc.','Latitude 3590',NULL,'BVNXDP2','Intel(R) Core(TM) i7-8550U CPU @ 1.80GHz',8,'256GB SSD',NULL,'Active',NULL,NULL,NULL,NULL,'162.16.57.167','Static','5C:EA:1D:7C:DC:AF',NULL,'storage (full): Name: \"C:\"/ Type: \"Local Disk\"/ Capacity: \"254798581660 (237.3 GiB)\"/ Free: \"34637705216 (32.3 GiB)\"/ Usage %: \"86%\"',NULL,64,NULL,NULL,NULL,NULL,'2018-04-09','2021-04-10',0,NULL,'2026-07-06 08:38:34','2026-09-15 02:30:53'),(194,'NB-61-002-PCSIT',2,NULL,'Notebook','Dell Inc.','Latitude 3590',NULL,'73PDFP2','Intel(R) Core(TM) i7-8550U CPU @ 1.80GHz',8,'256GB SSD',NULL,'Active',NULL,NULL,NULL,NULL,'162.16.57.116','Static','B4:6B:FC:A8:8A:B8',NULL,'storage (full): Name: \"C:\"/ Type: \"Local Disk\"/ Capacity: \"254204168432 (236.7 GiB)\"/ Free: \"23475494912 (21.9 GiB)\"/ Usage %: \"91%\"',NULL,64,NULL,NULL,NULL,NULL,'2018-06-06','2021-06-08',0,NULL,'2026-07-06 08:38:34','2026-09-15 02:30:53'),(195,'NB-64-001-PCSWH',2,NULL,'Notebook','Dell Inc.','Latitude 3410',NULL,'9CMWM93','Intel(R) Core(TM) i5-10310U CPU @ 1.70GHz',8,'Name: \"C:\"/ Type: \"Local Disk\"/ Capacity: \"509337399296 (474.4 GiB)\"/ Free: \"211043553280 (196.5 ...',NULL,'Active',NULL,NULL,NULL,NULL,'162.16.57.101','Static','64:6C:80:96:63:C3',NULL,'storage (full): Name: \"C:\"/ Type: \"Local Disk\"/ Capacity: \"509337399296 (474.4 GiB)\"/ Free: \"211043553280 (196.5 GiB)\"/ Usage %: \"59%\"',NULL,64,NULL,NULL,NULL,NULL,'2021-02-09','2024-02-11',0,NULL,'2026-07-06 08:38:34','2026-09-15 02:30:53'),(196,'NB-64-003-PCSWH',2,NULL,'Notebook','Dell Inc.','Latitude 3410',NULL,'8CMWM93','Intel(R) Core(TM) i5-10310U CPU @ 1.70GHz',8,'Name: \"C:\"/ Type: \"Local Disk\"/ Capacity: \"319605960704 (297.7 GiB)\"/ Free: \"160857437184 (140.5 ...',NULL,'Active',NULL,NULL,NULL,NULL,'162.16.57.130','Static','64:6C:80:96:64:71',NULL,'storage (full): Name: \"C:\"/ Type: \"Local Disk\"/ Capacity: \"319605960704 (297.7 GiB)\"/ Free: \"160857437184 (140.5 GiB)\"/ Usage %: \"53%\"',NULL,64,NULL,NULL,NULL,NULL,'2021-02-09','2024-02-11',0,NULL,'2026-07-06 08:38:34','2026-09-15 02:30:53'),(197,'NB-64-005-PCSWH',2,NULL,'Notebook','Dell Inc.','Latitude 3420',NULL,'JB7W2B3','11th Gen Intel(R) Core(TM) i5-1135G7 @ 2.40GHz',8,'Name: \"C:\"/ Type: \"Local Disk\"/ Capacity: \"251358343168 (234.1 GiB)\"/ Free: \"56923750400 (53.0 Gi...',NULL,'Active',NULL,NULL,NULL,NULL,'169.254.101.219','Static','94:E2:3C:DA:93:87',NULL,'storage (full): Name: \"C:\"/ Type: \"Local Disk\"/ Capacity: \"251358343168 (234.1 GiB)\"/ Free: \"56923750400 (53.0 GiB)\"/ Usage %: \"77%\"',NULL,64,NULL,NULL,NULL,NULL,'2021-08-02','2024-08-03',0,NULL,'2026-07-06 08:38:34','2026-09-15 02:30:53'),(198,'NB-64-008-PCSQA',2,NULL,'Notebook','Dell Inc.','Latitude 3420',NULL,'C8M3RG3','11th Gen Intel(R) Core(TM) i5-1135G7 @ 2.40GHz',16,'256GB SSD',NULL,'Active',NULL,NULL,NULL,NULL,'162.16.57.114','Static','4C:77:CB:F6:AF:CC',NULL,'เครื่อง LowOut',NULL,64,NULL,NULL,NULL,NULL,'2021-10-27','2024-10-29',1,NULL,'2026-07-06 08:38:34','2026-09-15 02:30:53'),(199,'NB-64-009-PCSWH',2,NULL,'Notebook','Dell Inc.','Latitude 3420',NULL,'38M3RG3','11th Gen Intel(R) Core(TM) i5-1135G7 @ 2.40GHz',8,'256GB SSD',NULL,'Active',NULL,NULL,NULL,NULL,'162.16.57.118','Static','4C:77:CB:F6:AF:E5',NULL,'เครื่อง LowOut',NULL,64,NULL,NULL,NULL,NULL,'2021-10-27','2024-10-29',1,NULL,'2026-07-06 08:38:34','2026-09-15 02:30:53'),(200,'NB-64-010-PCSAC',2,NULL,'Notebook','Dell Inc.','Latitude 3420',NULL,'F8M3RG3','11th Gen Intel(R) Core(TM) i5-1135G7 @ 2.40GHz',8,'256GB SSD',NULL,'Active',NULL,NULL,NULL,NULL,'162.16.56.241','Static','00:91:9E:05:61:A8',NULL,'storage (full): Name: \"C:\"/ Type: \"Local Disk\"/ Capacity: \"251699793664 (234.5 GiB)\"/ Free: \"16186966016 (16.1 GiB)\"/ Usage %: \"94%\"',NULL,64,NULL,NULL,NULL,NULL,'2021-10-27','2024-10-29',1,NULL,'2026-07-06 08:38:34','2026-09-15 02:30:53'),(201,'NB-64-011-PCSEN',2,NULL,'Notebook','Dell Inc.','Latitude 3420',NULL,'48M3RG3','11th Gen Intel(R) Core(TM) i5-1135G7 @ 2.40GHz',8,'256GB SSD',NULL,'Active',NULL,NULL,NULL,NULL,'162.16.57.166','Static','4C:77:CB:F6:AF:FE',NULL,'storage (full): Name: \"C:\"/ Type: \"Local Disk\"/ Capacity: \"242936180736 (226.3 GiB)\"/ Free: \"7219523584 (6.7 GiB)\"/ Usage %: \"97%\"',NULL,64,NULL,NULL,NULL,NULL,'2021-10-27','2024-10-29',1,NULL,'2026-07-06 08:38:34','2026-09-15 02:30:53'),(202,'NB-64-013-PCSEN',2,NULL,'Notebook','Dell Inc.','Latitude 3420',NULL,'J7M3RG3','11th Gen Intel(R) Core(TM) i5-1135G7 @ 2.40GHz',8,'256GB SSD',NULL,'Active',NULL,NULL,NULL,NULL,'162.16.57.163','Static','4C:77:CB:F6:B0:44',NULL,'storage (full): Name: \"C:\"/ Type: \"Local Disk\"/ Capacity: \"253148262400 (235.8 GiB)\"/ Free: \"83047550976 (77.3 GiB)\"/ Usage %: \"67%\"',NULL,64,NULL,NULL,NULL,NULL,'2021-10-27','2024-10-29',0,NULL,'2026-07-06 08:38:34','2026-09-15 02:30:53'),(203,'NB-64-016-PCSHR',2,NULL,'Notebook','Dell Inc.','Latitude 3420',NULL,'58M3RG3','11th Gen Intel(R) Core(TM) i5-1135G7 @ 2.40GHz',8,'256GB SSD',NULL,'Active',NULL,NULL,NULL,NULL,'162.16.57.168','Static','00:91:9E:05:62:BB',NULL,'storage (full): Name: \"C:\"/ Type: \"Local Disk\"/ Capacity: \"198166665600 (184.6 GiB)\"/ Free: \"8442830848 (7.9 GiB)\"/ Usage %: \"96%\"',NULL,64,NULL,NULL,NULL,NULL,'2021-10-27','2024-10-29',0,NULL,'2026-07-06 08:38:34','2026-09-15 02:30:53'),(204,'NB-64-016-PCSIT',2,NULL,'Notebook','Dell Inc.','Latitude 3420',NULL,'58M3RG3','11th Gen Intel(R) Core(TM) i5-1135G7 @ 2.40GHz',8,'256GB SSD',NULL,'Active',NULL,NULL,NULL,NULL,'162.16.58.224','Static','F4:EE:08:EA:4B:9E',NULL,'storage (full): Name: \"C:\"/ Type: \"Local Disk\"/ Capacity: \"198166665600 (184.6 GiB)\"/ Free: \"121692766464 (113.2 GiB)\"/ Usage %: \"39%\"',NULL,64,NULL,NULL,NULL,NULL,'2021-10-27','2024-10-29',0,NULL,'2026-07-06 08:38:34','2026-09-15 02:30:53'),(205,'NB-64-016-PCSLP',2,NULL,'Notebook','Dell Inc.','Latitude 3420',NULL,'H7M3RG3','11th Gen Intel(R) Core(TM) i5-1135G7 @ 2.40GHz',16,'256GB SSD',NULL,'Active',NULL,NULL,NULL,NULL,'162.16.57.166','Static','4C:77:CB:F6:B0:71',NULL,'storage (full): Name: \"C:\"/ Type: \"Local Disk\"/ Capacity: \"253148262400 (235.8 GiB)\"/ Free: \"23223828480 (21.6 GiB)\"/ Usage %: \"91%\"',NULL,64,NULL,NULL,NULL,NULL,'2021-10-27','2024-10-29',0,NULL,'2026-07-06 08:38:34','2026-09-15 02:30:53'),(206,'NB-64-016-PCSMK',2,NULL,'Notebook','Dell Inc.','Latitude 3420',NULL,'B8M3RG3','11th Gen Intel(R) Core(TM) i5-1135G7 @ 2.40GHz',8,'256 GB SSD',NULL,'Active',NULL,NULL,NULL,NULL,'162.20.10.2','Static','00:91:9E:05:61:B7',NULL,'storage (full): Name: \"C:\"/ Type: \"Local Disk\"/ Capacity: \"199456976896 (185.8 GiB)\"/ Free: \"34139488256 (31.8 GiB)\"/ Usage %: \"83%\"',NULL,64,NULL,NULL,NULL,NULL,'2021-10-27','2024-10-29',0,NULL,'2026-07-06 08:38:34','2026-09-15 02:30:53'),(207,'NB-64-018-PCSHR',2,NULL,'Notebook','Dell Inc.','Latitude 3420',NULL,'98M3RG3','11th Gen Intel(R) Core(TM) i5-1135G7 @ 2.40GHz',8,'256GB SSD',NULL,'Active',NULL,NULL,NULL,NULL,'162.16.57.195','Static','00:91:9E:05:5F:F5',NULL,'storage (full): Name: \"C:\"/ Type: \"Local Disk\"/ Capacity: \"251037478912 (233.8 GiB)\"/ Free: \"48648161040 (45.3 GiB)\"/ Usage %: \"81%\"',NULL,64,NULL,NULL,NULL,NULL,'2021-10-27','2024-10-29',0,NULL,'2026-07-06 08:38:34','2026-09-15 02:30:53'),(208,'NB-64-019-PCSWH',2,NULL,'Notebook','Dell Inc.','Latitude 3420',NULL,'D8M3RG3','11th Gen Intel(R) Core(TM) i5-1135G7 @ 2.40GHz',8,'256GB SSD',NULL,'Active',NULL,NULL,NULL,NULL,'162.16.57.93','Static','00:91:9E:05:63:6A',NULL,'storage (full): Name: \"C:\"/ Type: \"Local Disk\"/ Capacity: \"167985286144 (165.8 GiB)\"/ Free: \"8566919168 (8.0 GiB)\"/ Usage %: \"95%\"',NULL,64,NULL,NULL,NULL,NULL,'2021-10-27','2024-10-29',0,NULL,'2026-07-06 08:38:34','2026-09-15 02:30:53'),(209,'NB-64-020-PCSHR',2,NULL,'Notebook','Dell Inc.','Latitude 3420',NULL,'88M3RG3','11th Gen Intel(R) Core(TM) i5-1135G7 @ 2.40GHz',8,'256GB SSD',NULL,'Active',NULL,NULL,NULL,NULL,'162.16.57.189','Static','00:91:9E:05:63:65',NULL,'storage (full): Name: \"C:\"/ Type: \"Local Disk\"/ Capacity: \"253148262400 (235.8 GiB)\"/ Free: \"24232189952 (22.6 GiB)\"/ Usage %: \"90%\"',NULL,64,NULL,NULL,NULL,NULL,'2021-10-27','2024-10-29',1,NULL,'2026-07-06 08:38:34','2026-09-15 02:30:53'),(210,'NB-64-021-PCSAC',2,NULL,'Notebook','Dell Inc.','Latitude 3420',NULL,'G7M3RG3','11th Gen Intel(R) Core(TM) i5-1135G7 @ 2.40GHz',8,'256GB SSD',NULL,'Active',NULL,NULL,NULL,NULL,'162.16.57.116','Static','4C:77:CB:F6:B0:58',NULL,'storage (full): Name: \"C:\"/ Type: \"Local Disk\"/ Capacity: \"147016388608 (136.9 GiB)\"/ Free: \"4971933696 (4.6 GiB)\"/ Usage %: \"97%\"',NULL,64,NULL,NULL,NULL,NULL,'2021-10-27','2024-10-29',0,NULL,'2026-07-06 08:38:34','2026-09-15 02:30:53'),(211,'NB-64-022-PCSAC',2,NULL,'Notebook','Dell Inc.','Latitude 3420',NULL,'18M3RG3','11th Gen Intel(R) Core(TM) i5-1135G7 @ 2.40GHz',8,'256GB SSD',NULL,'Active',NULL,NULL,NULL,NULL,'162.16.57.147','Static','4C:77:CB:F6:B0:16',NULL,'storage (full): Name: \"C:\"/ Type: \"Local Disk\"/ Capacity: \"147005902848 (136.9 GiB)\"/ Free: \"6590705664 (6.1 GiB)\"/ Usage %: \"96%\"',NULL,64,NULL,NULL,NULL,NULL,'2021-10-27','2024-10-29',0,NULL,'2026-07-06 08:38:34','2026-09-15 02:30:53'),(212,'NB-64-14-PCSHR',2,NULL,'Notebook','Dell Inc.','Latitude 3420',NULL,'28M3RG3','11th Gen Intel(R) Core(TM) i5-1135G7 @ 2.40GHz',8,'256GB SSD',NULL,'Active',NULL,NULL,NULL,NULL,'162.16.56.240','Static','4C:77:CB:F6:B0:35',NULL,'storage (full): Name: \"C:\"/ Type: \"Local Disk\"/ Capacity: \"198228045824 (184.6 GiB)\"/ Free: \"34083065856 (31.7 GiB)\"/ Usage %: \"83%\"',NULL,64,NULL,NULL,NULL,NULL,'2021-10-27','2024-10-29',0,NULL,'2026-07-06 08:38:34','2026-09-15 02:30:53'),(213,'NB-65-002-PCSPC',2,NULL,'Notebook','Dell Inc.','Latitude 3430',NULL,'35K37S3','12th Gen Intel(R) Core(TM) i5-1235U',8,'256GB SSD',NULL,'Active',NULL,NULL,NULL,NULL,'162.16.57.85','Static','A0:80:69:CB:1E:6B',NULL,'storage (full): Name: \"C:\"/ Type: \"Local Disk\"/ Capacity: \"214748360704 (200.0 GiB)\"/ Free: \"35805704192 (33.3 GiB)\"/ Usage %: \"83%\"',NULL,64,NULL,NULL,NULL,NULL,'2022-10-13','2025-11-07',0,NULL,'2026-07-06 08:38:34','2026-09-15 02:30:53'),(214,'NB-66-001-PCSSA',2,NULL,'Notebook','Dell Inc.','Latitude 3430',NULL,'6TR97S3','12th Gen Intel(R) Core(TM) i5-1235U',8,'256GB SSD','ON Board','Active',NULL,NULL,NULL,NULL,'162.16.57.128','Static','A0:80:69:B3:E2:D2',NULL,'storage (full): Name: \"C:\"/ Type: \"Local Disk\"/ Capacity: \"214748360704 (200.0 GiB)\"/ Free: \"14318080000 (13.3 GiB)\"/ Usage %: \"93%\"',NULL,64,NULL,NULL,NULL,NULL,'2022-11-16','2025-12-23',0,NULL,'2026-07-06 08:38:34','2026-09-15 02:30:53'),(215,'NB-66-002-PCSPC',2,NULL,'Notebook','Dell Inc.','Latitude 3440',NULL,'78YZFS3','13th Gen Intel(R) Core(TM) i5-1335U',8,'256GB SSD','ON Board','Active',NULL,NULL,NULL,NULL,'162.16.57.168','Static','AC:1A:3D:8F:0C:0E',NULL,'storage (full): Name: \"C:\"/ Type: \"Local Disk\"/ Capacity: \"252963713024 (235.6 GiB)\"/ Free: \"69129744384 (64.4 GiB)\"/ Usage %: \"73%\"',NULL,64,NULL,NULL,NULL,NULL,'2023-04-13','2026-05-05',0,NULL,'2026-07-06 08:38:34','2026-09-15 02:30:53'),(216,'NB-66-01-PACTCS',2,NULL,'Notebook','Dell Inc.','Latitude 3430',NULL,'4JQ78S3','12th Gen Intel(R) Core(TM) i5-1235U',8,'500GB SSD',NULL,'Active',NULL,NULL,NULL,NULL,'162.16.112.73','Static','8C:F8:C5:91:C3:55',NULL,'storage (full): Name: \"C:\"/ Type: \"Local Disk\"/ Capacity: \"379894886400 (353.8 GiB)\"/ Free: \"82645618688 (77.0 GiB)\"/ Usage %: \"78%\"',NULL,64,NULL,NULL,NULL,NULL,'2022-12-09','2026-01-20',0,NULL,'2026-07-06 08:38:34','2026-09-15 02:30:53'),(217,'NB-66-04-PCSAC',2,NULL,'Notebook','Dell Inc.','Latitude 3420',NULL,'3W6MJL3','11th Gen Intel(R) Core(TM) i5-1135G7 @ 2.40GHz',8,'256 GB SSD',NULL,'Active',NULL,NULL,NULL,NULL,'162.16.57.123','Static','4C:03:4F:BD:A7:DB',NULL,'storage (full): Name: \"C:\"/ Type: \"Local Disk\"/ Capacity: \"213279305728 (198.6 GiB)\"/ Free: \"49651683328 (46.2 GiB)\"/ Usage %: \"77%\"',NULL,64,NULL,NULL,NULL,NULL,'2022-04-05','2025-06-10',0,NULL,'2026-07-06 08:38:34','2026-09-15 02:30:53'),(218,'NB-68-003-PCSPL',2,NULL,'Notebook','HP','HP ProBook 440 14 inch G11 Notebook PC',NULL,'5CD450FSSQ','Intel(R) Core(TM) Ultra 5 125U',16,'500GB SSD','ON Board','Active',NULL,NULL,NULL,NULL,'10.103.124.9','Static','EC:4C:8C:BD:59:2F',NULL,'storage (full): Name: \"C:\"/ Type: \"Local Disk\"/ Capacity: \"476384849920 (443.7 GiB)\"/ Free: \"350486532096 (326.4 GiB)\"/ Usage %: \"26%\"',NULL,64,NULL,NULL,NULL,NULL,'2025-07-16','2028-07-16',0,NULL,'2026-07-06 08:38:34','2026-09-15 02:30:53'),(219,'NB-68-01-PCSBPD',2,NULL,'Notebook','Dell Inc.','Latitude 5450',NULL,'D4LVK94','Intel(R) Core(TM) Ultra 5 125U',16,'500 GB SSD','ON Board','Active',NULL,NULL,NULL,NULL,'162.16.57.184','Static','80:84:89:9C:26:38',NULL,'storage (full): Name: \"C:\"/ Type: \"Local Disk\"/ Capacity: \"508893851648 (473.9 GiB)\"/ Free: \"337325694976 (314.2 GiB)\"/ Usage %: \"34%\"',NULL,64,NULL,NULL,NULL,NULL,'2025-04-13','2028-06-24',0,NULL,'2026-07-06 08:38:34','2026-09-15 02:30:53'),(220,'NB-68-02-PCSBPD',2,NULL,'Notebook','Dell Inc.','Latitude 5450',NULL,'316TK94','Intel(R) Core(TM) Ultra 5 125U',16,'512GB SSD','ON Board','Active',NULL,NULL,NULL,NULL,'162.16.56.213','Static','80:84:89:9D:67:6E',NULL,'storage (full): Name: \"C:\"/ Type: \"Local Disk\"/ Capacity: \"508893851648 (473.9 GiB)\"/ Free: \"374396862464 (348.7 GiB)\"/ Usage %: \"26%\"',NULL,64,NULL,NULL,NULL,NULL,'2025-04-13','2028-06-24',0,NULL,'2026-07-06 08:38:34','2026-09-15 02:30:53'),(221,'NB-68-04-PCSMK',2,NULL,'Notebook','HP','HP ProBook 440 14 inch G11 Notebook PC',NULL,'5CD450FTLS','Intel(R) Core(TM) Ultra 5 125U',16,'512GB SSD','ON Board','Active',NULL,NULL,NULL,NULL,'162.16.57.120','Static','EC:4C:8C:B5:CC:A5',NULL,'storage (full): Name: \"C:\"/ Type: \"Local Disk\"/ Capacity: \"476383801344 (443.7 GiB)\"/ Free: \"280625950720 (261.4 GiB)\"/ Usage %: \"41%\"','Windows 11',64,NULL,'365',NULL,NULL,'2025-01-14','2028-01-13',0,NULL,'2026-07-06 08:38:34','2026-09-15 02:30:53'),(222,'NB-68-05-PCSQA',2,NULL,'Notebook','HP','HP ProBook 440 14 inch G11 Notebook PC',NULL,'5CD450FT6D','Intel(R) Core(TM) Ultra 5 125U',16,'512GB SSD','ON Board','Active',NULL,NULL,NULL,NULL,'162.16.112.74','Static','EC:4C:8C:42:07:7F',NULL,'storage (full): Name: \"C:\"/ Type: \"Local Disk\"/ Capacity: \"476313546752 (443.6 GiB)\"/ Free: \"274809720832 (255.9 GiB)\"/ Usage %: \"42%\"',NULL,64,NULL,NULL,NULL,NULL,'2025-01-14','2028-01-13',0,NULL,'2026-07-06 08:38:34','2026-09-15 02:30:53'),(223,'NB-68-06-PCSMIS',2,NULL,'Notebook','HP','HP ProBook 440 14 inch G11 Notebook PC',NULL,'5CD450FSST','Intel(R) Core(TM) Ultra 5 125U',16,'512 GB','ON Board','Active',NULL,NULL,NULL,NULL,'10.98.29.99','Static','EC:4C:8C:BD:4A:98',NULL,'storage (full): Name: \"C:\"/ Type: \"Local Disk\"/ Capacity: \"476383801344 (443.7 GiB)\"/ Free: \"338237163760 (316.0 GiB)\"/ Usage %: \"29%\"','Windows 11',64,NULL,'365',NULL,NULL,'2025-07-16','2028-07-16',0,NULL,'2026-07-06 08:38:34','2026-09-15 02:30:53'),(224,'NB-68-07-PCSWH',2,NULL,'Notebook','HP','HP ProBook 440 14 inch G11 Notebook PC',NULL,'5CD450FSKL','Intel(R) Core(TM) Ultra 5 125U',16,'512 GB','ON Board','Active',NULL,NULL,NULL,NULL,'162.16.89.52','Static','EC:4C:8C:B9:E5:06',NULL,'storage (full): Name: \"C:\"/ Type: \"Local Disk\"/ Capacity: \"476313546752 (443.6 GiB)\"/ Free: \"73113911296 (68.1 GiB)\"/ Usage %: \"85%\"','Windows 11',64,NULL,'365',NULL,NULL,'2025-01-14','2028-01-13',0,NULL,'2026-07-06 08:38:34','2026-09-15 02:30:53'),(225,'NB-68-09-PCSBPD',2,NULL,'Notebook','Dell Inc.','Dell Pro 14 PC14250',NULL,'C3NDCB4','Intel(R) Core(TM) Ultra 5 235U',16,'512 GB','ON Board','Active',NULL,NULL,NULL,NULL,'162.16.57.160','Static','4C:0F:3E:A3:80:B3',NULL,'storage (full): Name: \"C:\"/ Type: \"Local Disk\"/ Capacity: \"508936843264 (474.0 GiB)\"/ Free: \"379977965568 (353.9 GiB)\"/ Usage %: \"25%\"','Windows 11',64,NULL,'365',NULL,NULL,'2025-06-16','2028-09-29',0,NULL,'2026-07-06 08:38:34','2026-09-15 02:30:53'),(226,'NB-68-10-PCSAC',2,NULL,'Notebook','HP','HP ProBook 440 14 inch G11 Notebook PC',NULL,'1H85390MJ5','Intel(R) Core(TM) Ultra 5 125U',16,'Name: \"C:\"/ Type: \"Local Disk\"/ Capacity: \"476298866688 (443.6 GiB)\"/ Free: \"340660805632 (316.3 ...',NULL,'Active',NULL,NULL,NULL,NULL,'162.16.56.240','Static','D4:AB:61:74:66:FC',NULL,'storage (full): Name: \"C:\"/ Type: \"Local Disk\"/ Capacity: \"476298866688 (443.6 GiB)\"/ Free: \"340660805632 (316.3 GiB)\"/ Usage %: \"28%\"',NULL,64,NULL,NULL,NULL,NULL,'2025-10-22','2028-10-21',0,NULL,'2026-07-06 08:38:34','2026-09-15 02:30:53'),(227,'NB-68-11-PCSAC',2,NULL,'Notebook','HP','HP ProBook 440 14 inch G11 Notebook PC',NULL,'1H85390MTR','Intel(R) Core(TM) Ultra 5 125U',16,'512GB SSD',NULL,'Active',NULL,NULL,NULL,NULL,'162.16.57.200','Static','D4:AB:61:74:AD:24',NULL,'storage (full): Name: \"C:\"/ Type: \"Local Disk\"/ Capacity: \"476306206720 (443.6 GiB)\"/ Free: \"328350027776 (305.8 GiB)\"/ Usage %: \"31%\"',NULL,64,NULL,NULL,NULL,NULL,'2025-10-22','2028-10-21',0,NULL,'2026-07-06 08:38:34','2026-09-15 02:30:53'),(228,'NB-68-12-PCSAC',2,NULL,'Notebook','HP','HP ProBook 440 14 inch G11 Notebook PC',NULL,'1H85390MFB','Intel(R) Core(TM) Ultra 5 125U',16,'512GB SSD',NULL,'Active',NULL,NULL,NULL,NULL,'162.16.56.255','Static','D4:AB:61:74:33:12',NULL,'storage (full): Name: \"C:\"/ Type: \"Local Disk\"/ Capacity: \"476306206720 (443.6 GiB)\"/ Free: \"302201290752 (281.4 GiB)\"/ Usage %: \"37%\"',NULL,64,NULL,NULL,NULL,NULL,'2025-10-22','2028-10-21',0,NULL,'2026-07-06 08:38:34','2026-09-15 02:30:53'),(229,'NB-68-13-PCSAC',2,NULL,'Notebook','HP','HP ProBook 440 14 inch G11 Notebook PC',NULL,'1H85390MMB','Intel(R) Core(TM) Ultra 5 125U',16,'512GB SSD',NULL,'Active',NULL,NULL,NULL,NULL,'162.16.56.235','Static','D4:AB:61:74:31:B9',NULL,'storage (full): Name: \"C:\"/ Type: \"Local Disk\"/ Capacity: \"476306206720 (443.6 GiB)\"/ Free: \"301618540288 (281.0 GiB)\"/ Usage %: \"37%\"',NULL,64,NULL,NULL,NULL,NULL,'2025-10-22','2028-10-21',0,NULL,'2026-07-06 08:38:34','2026-09-15 02:30:53'),(230,'NB-69-001-PCSBP',2,NULL,'Notebook','Dell Inc.','Dell Pro 14 PC14255',NULL,'7KF29F4','AMD Ryzen 5 PRO 230 w/ Radeon 760M Graphics',16,'512GB SSD',NULL,'Active',NULL,NULL,NULL,NULL,'162.16.57.112','Static','F4:28:9D:AE:B4:FD',NULL,'storage (full): Name: \"C:\"/ Type: \"Local Disk\"/ Capacity: \"508931600384 (474.0 GiB)\"/ Free: \"342757478400 (319.2 GiB)\"/ Usage %: \"33%\"',NULL,64,NULL,NULL,NULL,NULL,'2025-11-29','2028-12-16',0,NULL,'2026-07-06 08:38:34','2026-09-15 02:30:53'),(231,'NB-69-003-PCSBP',2,NULL,'Notebook','Dell Inc.','Dell Pro 14 PC14255',NULL,'28Q8NG4','AMD Ryzen 5 PRO 230 w/ Radeon 760M Graphics',16,'512GB SSD',NULL,'Active',NULL,NULL,NULL,NULL,'162.16.57.102','Static','08:F9:7E:0A:3C:53',NULL,'storage (full): Name: \"C:\"/ Type: \"Local Disk\"/ Capacity: \"508879161684 (473.9 GiB)\"/ Free: \"357793071104 (333.2 GiB)\"/ Usage %: \"30%\"',NULL,64,NULL,NULL,NULL,NULL,'2026-01-03','2029-04-27',0,NULL,'2026-07-06 08:38:34','2026-09-15 02:30:53'),(232,'NB-69-004-PCSBP',2,NULL,'Notebook','Dell Inc.','Dell Pro 14 PC14255',NULL,'FRN8NG4','AMD Ryzen 5 PRO 230 w/ Radeon 760M Graphics',16,'512GB SSD',NULL,'Active',NULL,NULL,NULL,NULL,'162.16.56.211','Static','08:F9:7E:0A:71:75',NULL,'storage (full): Name: \"C:\"/ Type: \"Local Disk\"/ Capacity: \"508879161684 (473.9 GiB)\"/ Free: \"358826278912 (334.2 GiB)\"/ Usage %: \"29%\"',NULL,64,NULL,NULL,NULL,NULL,'2026-01-03','2029-04-27',0,NULL,'2026-07-06 08:38:34','2026-09-15 02:30:53'),(233,'NB-69-02-PCSAC',2,NULL,'Notebook','Dell Inc.','Dell Pro 14 PC14255',NULL,'D4YX4D4','AMD Ryzen 5 PRO 230 w/ Radeon 760M Graphics',16,'512GB SSD',NULL,'Active',NULL,NULL,NULL,NULL,'162.16.57.116','Static','F4:4E:B4:B4:C5:47',NULL,'storage (full): Name: \"C:\"/ Type: \"Local Disk\"/ Capacity: \"508930551808 (474.0 GiB)\"/ Free: \"372723232768 (347.1 GiB)\"/ Usage %: \"27%\"',NULL,64,NULL,NULL,NULL,NULL,'2025-10-07','2028-11-27',0,NULL,'2026-07-06 08:38:34','2026-09-15 02:30:53'),(234,'NB-69-05-PCSAC',2,NULL,'Notebook','Dell Inc.','Dell Pro 14 PC14255',NULL,'JXQ8NG4','AMD Ryzen 5 PRO 230 w/ Radeon 760M Graphics',16,'512GB SSD',NULL,'Active',NULL,NULL,NULL,NULL,'162.20.10.2','Static','08:F9:7E:0A:3B:29',NULL,'storage (full): Name: \"C:\"/ Type: \"Local Disk\"/ Capacity: \"508879161684 (473.9 GiB)\"/ Free: \"374904037376 (349.2 GiB)\"/ Usage %: \"26%\"',NULL,64,NULL,NULL,NULL,NULL,'2026-01-04','2029-04-26',0,NULL,'2026-07-06 08:38:34','2026-09-15 02:30:53'),(235,'NB-69-06-PCS',2,NULL,'Notebook','Dell Inc.','Dell Pro 14 PC14255',NULL,'HSP8NG4','AMD Ryzen 5 PRO 230 w/ Radeon 760M Graphics',16,'512GB SSD',NULL,'Active',NULL,NULL,NULL,NULL,'162.16.112.62','Static','08:F9:7E:0A:16:BB',NULL,'storage (full): Name: \"C:\"/ Type: \"Local Disk\"/ Capacity: \"246735161684 (229.8 GiB)\"/ Free: \"100087808000 (93.2 GiB)\"/ Usage %: \"59%\"',NULL,64,NULL,NULL,NULL,NULL,'2026-01-03','2029-04-26',0,NULL,'2026-07-06 08:38:34','2026-09-15 02:30:53'),(236,'NB-69-07-PCSHR',2,NULL,'Notebook','Dell Inc.','Dell Pro 14 PC14255',NULL,'3SN8NG4','AMD Ryzen 5 PRO 230 w/ Radeon 760M Graphics',16,'512GB SSD',NULL,'Active',NULL,NULL,NULL,NULL,'162.16.56.245','Static','08:F9:7E:0A:71:5B',NULL,'storage (full): Name: \"C:\"/ Type: \"Local Disk\"/ Capacity: \"508879161684 (473.9 GiB)\"/ Free: \"327576403968 (305.1 GiB)\"/ Usage %: \"36%\"',NULL,64,NULL,NULL,NULL,NULL,'2026-01-03','2029-04-27',0,NULL,'2026-07-06 08:38:34','2026-09-15 02:30:53'),(237,'NB-69-08-PCSHR',2,NULL,'Notebook','Dell Inc.','Dell Pro 14 PC14255',NULL,'9MN8NG4','AMD Ryzen 5 PRO 230 w/ Radeon 760M Graphics',16,'512GB SSD',NULL,'Active',NULL,NULL,NULL,NULL,'162.16.56.246','Static','08:F9:7E:0A:C6:29',NULL,'storage (full): Name: \"C:\"/ Type: \"Local Disk\"/ Capacity: \"508879161684 (473.9 GiB)\"/ Free: \"357007622144 (332.5 GiB)\"/ Usage %: \"30%\"',NULL,64,NULL,NULL,NULL,NULL,'2026-01-03','2029-04-27',0,NULL,'2026-07-06 08:38:34','2026-09-15 02:30:53'),(238,'NB-69-09-PCSHR',2,NULL,'Notebook','Dell Inc.','Dell Pro 14 PC14255',NULL,'47X8NG4','AMD Ryzen 5 PRO 230 w/ Radeon 760M Graphics',16,'512GB SSD',NULL,'Active',NULL,NULL,NULL,NULL,'162.16.112.86','Static','08:F9:7E:0A:58:85',NULL,'storage (full): Name: \"C:\"/ Type: \"Local Disk\"/ Capacity: \"508879161684 (473.9 GiB)\"/ Free: \"353925021696 (329.6 GiB)\"/ Usage %: \"30%\"',NULL,64,NULL,NULL,NULL,NULL,'2026-01-03','2029-04-27',0,NULL,'2026-07-06 08:38:34','2026-09-15 02:30:53'),(239,'NB-69-10-PCSHR',2,NULL,'Notebook','Dell Inc.','Dell Pro 14 PC14255',NULL,'1MN8NG4','AMD Ryzen 5 PRO 230 w/ Radeon 760M Graphics',16,'512GB SSD',NULL,'Active',NULL,NULL,NULL,NULL,'162.16.57.82','Static','08:F9:7E:0A:C5:2D',NULL,'storage (full): Name: \"C:\"/ Type: \"Local Disk\"/ Capacity: \"508879161684 (473.9 GiB)\"/ Free: \"358344169712 (333.7 GiB)\"/ Usage %: \"30%\"',NULL,64,NULL,NULL,NULL,NULL,'2026-01-03','2029-04-27',0,NULL,'2026-07-06 08:38:34','2026-09-15 02:30:53'),(240,'NB-69-11-PCSAC',2,NULL,'Notebook','Dell Inc.','Dell Pro 14 PC14255',NULL,'GLN8NG4','AMD Ryzen 5 PRO 230 w/ Radeon 760M Graphics',16,'512GB SSD',NULL,'Active',NULL,NULL,NULL,NULL,'162.16.57.201','Static','08:F9:7E:0A:D6:33',NULL,'storage (full): Name: \"C:\"/ Type: \"Local Disk\"/ Capacity: \"508879161684 (473.9 GiB)\"/ Free: \"404314324992 (376.5 GiB)\"/ Usage %: \"21%\"',NULL,64,NULL,NULL,NULL,NULL,'2026-01-03','2029-04-27',0,NULL,'2026-07-06 08:38:34','2026-09-15 02:30:53'),(241,'NB-69-12-PCSAC',2,NULL,'Notebook','Dell Inc.','Dell Pro 14 PC14255',NULL,'7LN8NG4','AMD Ryzen 5 PRO 230 w/ Radeon 760M Graphics',16,'512GB SSD',NULL,'Active',NULL,NULL,NULL,NULL,'162.16.57.120','Static','08:F9:7E:0A:24:57',NULL,'storage (full): Name: \"C:\"/ Type: \"Local Disk\"/ Capacity: \"508879161684 (473.9 GiB)\"/ Free: \"420547911680 (391.7 GiB)\"/ Usage %: \"16%\"',NULL,64,NULL,NULL,NULL,NULL,'2026-01-03','2029-04-27',0,NULL,'2026-07-06 08:38:34','2026-09-15 02:30:53'),(242,'NB-69-13-PCSAC',2,NULL,'Notebook','Dell Inc.','Dell Pro 14 PC14255',NULL,'BRW8NG4','AMD Ryzen 5 PRO 230 w/ Radeon 760M Graphics',16,'512GB SSD',NULL,'Active',NULL,NULL,NULL,NULL,'162.16.56.214','Static','08:F9:7E:0A:64:C9',NULL,'storage (full): Name: \"C:\"/ Type: \"Local Disk\"/ Capacity: \"508879161684 (473.9 GiB)\"/ Free: \"336894914560 (313.8 GiB)\"/ Usage %: \"34%\"',NULL,64,NULL,NULL,NULL,NULL,'2026-01-03','2029-04-27',0,NULL,'2026-07-06 08:38:34','2026-09-15 02:30:53'),(243,'NB69-14-PCSIT',2,'IT','Notebook','Dell Inc.','Dell Pro 14 PC14255',NULL,'JLN8NG4','AMD Ryzen 5 PRO 230 w/ Radeon 760M Graphics',16,'512GB SSD',NULL,'Active',NULL,NULL,NULL,NULL,'162.16.60.168,162.30.240.1','Static','00:16:5D:39:CB...',NULL,NULL,NULL,64,NULL,NULL,NULL,NULL,'2026-01-03','2029-04-27',0,NULL,'2026-07-06 08:38:34','2026-09-15 02:30:53'),(244,'NB-69-16-PCSQA',2,NULL,'Notebook','Dell Inc.','Dell Pro 14 PC14255',NULL,'7QW8NG4','AMD Ryzen 5 PRO 230 w/ Radeon 760M Graphics',16,'512GB SSD',NULL,'Active',NULL,NULL,NULL,NULL,'162.16.112.79','Static','08:F9:7E:0A:5E:16',NULL,'storage (full): Name: \"C:\"/ Type: \"Local Disk\"/ Capacity: \"508879161684 (473.9 GiB)\"/ Free: \"372667191296 (347.1 GiB)\"/ Usage %: \"27%\"',NULL,64,NULL,NULL,NULL,NULL,'2026-01-03','2029-04-27',0,NULL,'2026-07-06 08:38:34','2026-09-15 02:30:53'),(245,'NB-69-18-PCSWH',2,NULL,'Notebook','Dell Inc.','Dell Pro 14 PC14255',NULL,'6DS8NG4','AMD Ryzen 5 PRO 230 w/ Radeon 760M Graphics',16,'512GB SSD',NULL,'Active',NULL,NULL,NULL,NULL,'162.16.57.168','Static','08:F9:7E:0A:49:AF',NULL,'storage (full): Name: \"C:\"/ Type: \"Local Disk\"/ Capacity: \"508879161684 (473.9 GiB)\"/ Free: \"401906982912 (374.3 GiB)\"/ Usage %: \"21%\"',NULL,64,NULL,NULL,NULL,NULL,'2026-01-03','2029-04-27',0,NULL,'2026-07-06 08:38:34','2026-09-15 02:30:53'),(246,'NB-69-19-PCSWH',2,NULL,'Notebook','Dell Inc.','Dell Pro 14 PC14255',NULL,'4QV8NG4','AMD Ryzen 5 PRO 230 w/ Radeon 760M Graphics',16,'512GB SSD',NULL,'Active',NULL,NULL,NULL,NULL,'162.16.57.202','Static','08:F9:7E:0A:64:C7',NULL,'storage (full): Name: \"C:\"/ Type: \"Local Disk\"/ Capacity: \"508879161684 (473.9 GiB)\"/ Free: \"360695689216 (335.9 GiB)\"/ Usage %: \"29%\"',NULL,64,NULL,NULL,NULL,NULL,'2026-01-03','2029-04-27',0,NULL,'2026-07-06 08:38:34','2026-09-15 02:30:53'),(247,'NB-69-21-PCSMK',2,NULL,'Notebook','Dell Inc.','Dell Pro 14 PC14255',NULL,'F6X8NG4','AMD Ryzen 5 PRO 230 w/ Radeon 760M Graphics',16,'512GB SSD',NULL,'Active',NULL,NULL,NULL,NULL,'162.16.57.108','Static','08:F9:7E:0A:4A:47',NULL,'storage (full): Name: \"C:\"/ Type: \"Local Disk\"/ Capacity: \"508879161684 (473.9 GiB)\"/ Free: \"342670569472 (319.1 GiB)\"/ Usage %: \"33%\"',NULL,64,NULL,NULL,NULL,NULL,'2026-01-03','2029-04-27',0,NULL,'2026-07-06 08:38:34','2026-09-15 02:30:53'),(248,'PACA-TruckQ',NULL,NULL,'Server',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,0,NULL,'2026-07-06 08:38:34','2026-07-06 08:38:34'),(249,'PC-IT-LAPTEST_B',2,NULL,'PC','Dell Inc.','OptiPlex 3080',NULL,'1YSKL83','Intel(R) Core(TM) i5-10500 CPU @ 3.10GHz',16,'Name: \"C:\"/ Type: \"Local Disk\"/ Capacity: \"326225620992 (303.8 GiB)\"/ Free: \"8593399808 (8.0 GiB)...',NULL,'Active',NULL,NULL,NULL,NULL,'162.16.1.199,162.16.58.88,162.19.128.1','Static','00:16:5D:02:45...',NULL,'storage (full): Name: \"C:\"/ Type: \"Local Disk\"/ Capacity: \"326225620992 (303.8 GiB)\"/ Free: \"8593399808 (8.0 GiB)\"/ Usage %: \"97%\" | mac_address (full): 00:16:5D:02:45:6E,58:D5:6E:7B:01:7B,70:B5:E8:3D:99:E4',NULL,64,NULL,NULL,NULL,NULL,'2020-11-23','2023-11-25',0,NULL,'2026-07-06 08:38:34','2026-09-15 02:30:53'),(250,'PCS-SVR-DB01',NULL,NULL,'Server','Microsoft Corporation','Virtual Machine',NULL,'6142-3144-4701-8228-9565-0731-45','Intel(R) Xeon(R) Silver 4314 CPU @ 2.40GHz',4,'Name: \"C:\"/ Type: \"Local Disk\"/ Capacity: \"251133947904 (233.9 GiB)\"/ Free: \"16951064064 (16.7 Gi...',NULL,NULL,NULL,NULL,NULL,NULL,'162.16.61.22',NULL,'00:16:5D:CD:98:04',NULL,'storage (full): Name: \"C:\"/ Type: \"Local Disk\"/ Capacity: \"251133947904 (233.9 GiB)\"/ Free: \"16951064064 (16.7 GiB)\"/ Usage %: \"93%\"','Windows Server 2016 Standard Edition',64,NULL,NULL,NULL,NULL,NULL,NULL,0,NULL,'2026-07-06 08:38:34','2026-09-15 02:30:53'),(251,'PCS-TERMINAL',NULL,NULL,'Server','Dell Inc.','PowerEdge R330',NULL,'7VK7MP2','Intel(R) Xeon(R) CPU E3-1225 v6 @ 3.30GHz',64,'Name: \"C:\"/ Type: \"Local Disk\"/ Capacity: \"195303567360 (181.9 GiB)\"/ Free: \"96972939264 (90.3 Gi...',NULL,NULL,NULL,NULL,NULL,NULL,'162.16.61.11',NULL,'50:9A:4C:8A:1F:61',NULL,'storage (full): Name: \"C:\"/ Type: \"Local Disk\"/ Capacity: \"195303567360 (181.9 GiB)\"/ Free: \"96972939264 (90.3 GiB)\"/ Usage %: \"50%\"','Windows Server 2016 Standard Edition',64,NULL,NULL,NULL,NULL,'2018-05-16','2024-05-16',0,NULL,'2026-07-06 08:38:34','2026-09-15 02:30:53'),(252,'SAPCONNECT-PCS',NULL,NULL,'PC','Microsoft Corporation','Virtual Machine',NULL,'9901-6035-5389-1640-7031-6007-19','Intel(R) Xeon(R) Silver 4314 CPU @ 2.40GHz',8,'Name: \"C:\"/ Type: \"Local Disk\"/ Capacity: \"106762375168 (99.4 GiB)\"/ Free: \"46654992384 (43.5 GiB...',NULL,NULL,NULL,NULL,NULL,NULL,'162.16.62.132',NULL,'00:16:5D:55:24:10',NULL,'storage (full): Name: \"C:\"/ Type: \"Local Disk\"/ Capacity: \"106762375168 (99.4 GiB)\"/ Free: \"46654992384 (43.5 GiB)\"/ Usage %: \"56%\"','Windows 10 Professional Edition',64,NULL,NULL,NULL,NULL,NULL,NULL,0,NULL,'2026-07-06 08:38:34','2026-09-15 02:30:53'),(253,'SRV22-AD',NULL,NULL,'Server','Microsoft Corporation','Virtual Machine',NULL,'8406-1620-0001-3747-6248-8900-73','Intel(R) Xeon(R) Silver 4314 CPU @ 2.40GHz',9,'Name: \"C:\"/ Type: \"Local Disk\"/ Capacity: \"114687995904 (106.8 GiB)\"/ Free: \"86142705664 (80.2 Gi...',NULL,NULL,NULL,NULL,NULL,NULL,'162.16.62.245',NULL,'00:16:5D:CD:98:00',NULL,'storage (full): Name: \"C:\"/ Type: \"Local Disk\"/ Capacity: \"114687995904 (106.8 GiB)\"/ Free: \"86142705664 (80.2 GiB)\"/ Usage %: \"25%\"','Windows Server 2022 Standard Edition',64,NULL,NULL,NULL,NULL,NULL,NULL,0,NULL,'2026-07-06 08:38:34','2026-09-15 02:30:53'),(254,'SV-60-001-PCSPC',NULL,'IT','Server','Dell','PowerEdge R640',NULL,'D59VB33','Xeon Silver',64,'480GB SSD + 8TB',NULL,'Active','ห้อง Server B8',NULL,NULL,NULL,'162.16.61.31','Static','AA:BB:CC:DD:EE:FF',NULL,'Bitdefender: ✓ | SmartStore: ✗ | Redmine: ✗ | Budget: ✗ | Central Store: ✗ | TruckLoad PLP: ✗ | Payroll: ✗ | หมายเหตุ: AD Server','Windows Server 2019',64,'00429-xxxxx','N/A',NULL,NULL,'2018-10-09','2023-10-09',0,NULL,'2026-07-06 08:38:34','2026-09-15 02:30:53'),(255,'SVR2012-DHCP3-Backup',NULL,NULL,'Server',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,0,NULL,'2026-07-06 08:38:34','2026-07-06 08:38:34'),(256,'SVR2016-TigerSoft',NULL,NULL,'Server',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,0,NULL,'2026-07-06 08:38:34','2026-07-06 08:38:34'),(257,'SVR2022-AD245',NULL,NULL,'Server',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,0,NULL,'2026-07-06 08:38:34','2026-07-06 08:38:34'),(258,'TEMPERATUREONLI',NULL,NULL,'Server','Dell Inc.','PowerEdge R230',NULL,'636K7C2','Intel(R) Xeon(R) CPU E3-1240 v5 @ 3.50GHz',8,'Name: \"C:\"/ Type: \"Local Disk\"/ Capacity: \"643877040128 (599.7 GiB)\"/ Free: \"584163969408 (544.1 ...',NULL,NULL,NULL,NULL,NULL,NULL,'162.16.61.28,162.18.14.47',NULL,'34:16:EB:ED:5C...',NULL,'storage (full): Name: \"C:\"/ Type: \"Local Disk\"/ Capacity: \"643877040128 (599.7 GiB)\"/ Free: \"584163969408 (544.1 GiB)\"/ Usage %: \"9%\" | mac_address (full): 34:16:EB:ED:5C:16,34:16:EB:ED:5C:18','Windows Server 2012 Standard Edition',64,NULL,NULL,NULL,NULL,'2016-06-16','2019-06-19',0,NULL,'2026-07-06 08:38:34','2026-09-15 02:30:53'),(259,'WEIGHTEXPERT1-P',2,NULL,'PC','Dell Inc.','OptiPlex 3070',NULL,'139DX53','Intel(R) Core(TM) i5-8500 CPU @ 3.00GHz',16,'Name: \"C:\"/ Type: \"Local Disk\"/ Capacity: \"82398142464 (76.7 GiB)\"/ Free: \"35245223936 (32.8 GiB)...',NULL,'Active',NULL,NULL,NULL,NULL,'162.16.56.127','Static','70:B5:E8:30:13:A3',NULL,'storage (full): Name: \"C:\"/ Type: \"Local Disk\"/ Capacity: \"82398142464 (76.7 GiB)\"/ Free: \"35245223936 (32.8 GiB)\"/ Usage %: \"57%\"',NULL,64,NULL,NULL,NULL,NULL,'2020-08-11','2023-08-13',0,NULL,'2026-07-06 08:38:34','2026-09-15 02:30:53'),(260,'WEIGTH-SCALE-CA',NULL,NULL,'PC','Dell Inc.','OptiPlex 3080',NULL,'2B7PRH3','Intel(R) Core(TM) i5-10505 CPU @ 3.20GHz',16,'256GB SSD',NULL,'Active',NULL,NULL,NULL,NULL,'162.16.56.139','Static','C0:25:A5:7B:01:46',NULL,'storage (full): Name: \"C:\"/ Type: \"Local Disk\"/ Capacity: \"212955291648 (198.3 GiB)\"/ Free: \"73235259392 (68.2 GiB)\"/ Usage %: \"66%\"',NULL,64,NULL,NULL,NULL,NULL,'2021-09-13','2024-09-16',0,NULL,'2026-07-06 08:38:34','2026-09-15 02:30:53'),(261,'NB-64-018-PCSIT',2,'IT','PC',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'Active',NULL,NULL,NULL,NULL,NULL,'DHCP',NULL,NULL,NULL,NULL,64,NULL,NULL,NULL,NULL,'2026-07-09',NULL,1,NULL,'2026-07-09 07:53:31','2026-07-14 07:13:35');
/*!40000 ALTER TABLE `hardware_assets` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `loan_extensions`
--

DROP TABLE IF EXISTS `loan_extensions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `loan_extensions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `loan_id` int(11) NOT NULL,
  `old_return_date` date NOT NULL,
  `new_return_date` date NOT NULL,
  `reason` varchar(255) DEFAULT NULL,
  `requested_by` varchar(100) DEFAULT NULL,
  `approved_by` varchar(100) DEFAULT NULL,
  `approved_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_loan` (`loan_id`),
  CONSTRAINT `fk_ext_loan` FOREIGN KEY (`loan_id`) REFERENCES `asset_loans` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='ประวัติการขอต่ออายุการยืม';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `loan_extensions`
--

LOCK TABLES `loan_extensions` WRITE;
/*!40000 ALTER TABLE `loan_extensions` DISABLE KEYS */;
/*!40000 ALTER TABLE `loan_extensions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `maintenance_logs`
--

DROP TABLE IF EXISTS `maintenance_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `maintenance_logs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `asset_id` int(11) DEFAULT NULL COMMENT 'FK -> hardware_assets.id (nullable ถ้าเป็น mobile/network)',
  `mobile_id` int(11) DEFAULT NULL COMMENT 'FK -> mobile_assets.id',
  `network_id` int(11) DEFAULT NULL COMMENT 'FK -> network_assets.id',
  `schedule_id` int(11) DEFAULT NULL COMMENT 'FK → pm_schedules (null ถ้าเป็น ad-hoc)',
  `pm_date` date NOT NULL,
  `type` enum('PM','Repair') NOT NULL,
  `description` text DEFAULT NULL,
  `performed_by` varchar(100) DEFAULT NULL,
  `signed_by_tech` varchar(100) DEFAULT NULL COMMENT 'ลายเซ็น IT ผู้ปฏิบัติงาน',
  `signed_by_user` varchar(100) DEFAULT NULL COMMENT 'ลายเซ็น ผู้ใช้งาน',
  `signed_date` date DEFAULT NULL,
  `cost` decimal(10,2) DEFAULT 0.00,
  `next_pm_date` date DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_next_pm` (`next_pm_date`),
  KEY `idx_pm_date` (`pm_date`),
  KEY `fk_maint_asset` (`asset_id`),
  KEY `fk_mlog_schedule` (`schedule_id`),
  KEY `fk_maint_mobile` (`mobile_id`),
  KEY `fk_maint_network` (`network_id`),
  CONSTRAINT `fk_maint_asset` FOREIGN KEY (`asset_id`) REFERENCES `hardware_assets` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_maint_mobile` FOREIGN KEY (`mobile_id`) REFERENCES `mobile_assets` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_maint_network` FOREIGN KEY (`network_id`) REFERENCES `network_assets` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_mlog_schedule` FOREIGN KEY (`schedule_id`) REFERENCES `pm_schedules` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `maintenance_logs`
--

LOCK TABLES `maintenance_logs` WRITE;
/*!40000 ALTER TABLE `maintenance_logs` DISABLE KEYS */;
INSERT INTO `maintenance_logs` VALUES (1,190,NULL,NULL,144,'2026-09-15','PM',NULL,'test',NULL,NULL,'2026-09-15',0.00,'2026-11-01','2026-09-15 08:22:57'),(2,191,NULL,NULL,145,'2026-09-15','PM',NULL,'6401117','6401117',NULL,'2026-09-15',0.00,NULL,'2026-09-15 08:37:24'),(3,191,NULL,NULL,145,'2026-09-15','PM',NULL,'anucha','anucha',NULL,'2026-09-15',0.00,NULL,'2026-09-15 08:46:10'),(4,191,NULL,NULL,145,'2026-09-15','PM',NULL,'anucha','anucha',NULL,'2026-09-15',0.00,NULL,'2026-09-15 08:48:37');
/*!40000 ALTER TABLE `maintenance_logs` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `mobile_assets`
--

DROP TABLE IF EXISTS `mobile_assets`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `mobile_assets` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `asset_id` varchar(30) NOT NULL COMMENT 'รหัส เช่น MOB-A13-001 / HH-64-001-PCSWH',
  `device_type` enum('Smartphone','Handheld','Tablet','Barcode Reader','Barcode Scanner','Rugged PDA','Feature Phone','Other') NOT NULL,
  `company` varchar(50) DEFAULT NULL COMMENT 'PCS / JPK / JPAC / PACS / ส่วนกลาง',
  `department` varchar(20) DEFAULT NULL COMMENT 'IT / HR / MK / WH / CS / EN / AC / PLP / QA',
  `site_id` int(11) DEFAULT NULL,
  `user_name` varchar(150) DEFAULT NULL COMMENT 'ชื่อผู้รับผิดชอบ',
  `assigned_employee_id` int(11) DEFAULT NULL,
  `location_detail` varchar(200) DEFAULT NULL,
  `register_date` date DEFAULT NULL,
  `brand` varchar(60) DEFAULT NULL,
  `model` varchar(100) DEFAULT NULL,
  `serial_number` varchar(60) DEFAULT NULL COMMENT 'S/N หรือ IMEI',
  `imei` varchar(20) DEFAULT NULL,
  `ram` varchar(20) DEFAULT NULL COMMENT 'เช่น 4GB / 16GB',
  `storage` varchar(30) DEFAULT NULL COMMENT 'เช่น 64GB / 128GB',
  `mac_wifi` varchar(50) DEFAULT NULL,
  `anydesk_id` varchar(20) DEFAULT NULL,
  `os_version` varchar(50) DEFAULT NULL COMMENT 'Android 12 / iOS 16 / etc.',
  `main_app` varchar(100) DEFAULT NULL COMMENT 'CCMS / WMS / SmartStore',
  `other_apps` varchar(255) DEFAULT NULL,
  `sim_provider` varchar(30) DEFAULT NULL COMMENT 'DTAC / True / AIS / ซิมโรงงาน / ไม่มี',
  `phone_number` varchar(20) DEFAULT NULL,
  `sim_owner` enum('บริษัท','ส่วนตัว') DEFAULT NULL,
  `org_email` varchar(150) DEFAULT NULL,
  `org_password_hint` varchar(100) DEFAULT NULL COMMENT 'hint เท่านั้น ไม่เก็บ plain text จริง',
  `sn_charger` varchar(60) DEFAULT NULL,
  `sn_cradle_case` varchar(60) DEFAULT NULL,
  `accessories_note` varchar(255) DEFAULT NULL,
  `is_loanable` tinyint(1) NOT NULL DEFAULT 0,
  `status` enum('Active','In Repair','In Stock','Retired','On Loan','ชำรุด','คืนแล้ว','สูญหาย','รอ Reset') NOT NULL DEFAULT 'Active',
  `notes` text DEFAULT NULL,
  `updated_at` date DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_ts` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `asset_id` (`asset_id`),
  KEY `idx_device_type` (`device_type`),
  KEY `idx_department` (`department`),
  KEY `idx_status` (`status`),
  KEY `idx_company` (`company`),
  KEY `fk_mobile_site` (`site_id`),
  KEY `idx_mobile_assigned_employee` (`assigned_employee_id`),
  CONSTRAINT `fk_mobile_site` FOREIGN KEY (`site_id`) REFERENCES `sites` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=115 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Smartphone / Handheld / Tablet — แยกจาก hardware_assets';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `mobile_assets`
--

LOCK TABLES `mobile_assets` WRITE;
/*!40000 ALTER TABLE `mobile_assets` DISABLE KEYS */;
INSERT INTO `mobile_assets` VALUES (1,'HH.63.001.PCSWH','Handheld',NULL,'WH',2,NULL,NULL,'WH B.6',NULL,'Honeywell','CN80','19137D80C6',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,0,'Retired',NULL,'2026-07-13','2026-07-06 08:38:34','2026-09-15 02:30:53'),(2,'HH.63.002.PCSWH','Handheld',NULL,'WH',NULL,NULL,NULL,'WH B.6',NULL,'Honeywell','CN80','19137D828A',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,0,'Retired',NULL,NULL,'2026-07-06 08:38:34','2026-09-15 02:30:53'),(3,'HH.63.003.PCSWH','Handheld',NULL,'WH',NULL,NULL,NULL,'WH B.6',NULL,'Honeywell','CN80','19074D820D',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,0,'Retired',NULL,NULL,'2026-07-06 08:38:34','2026-09-15 02:30:53'),(4,'HH.63.004.PCSWH','Handheld',NULL,'WH',NULL,NULL,NULL,'WH B.6',NULL,'Honeywell','CN80','19138D8132',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,0,'Retired',NULL,NULL,'2026-07-06 08:38:34','2026-09-15 02:30:53'),(5,'HH.63.005.PCSWH','Handheld',NULL,'WH',NULL,NULL,NULL,'WH B.6',NULL,'Honeywell','CN80','19074D8293',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,0,'Retired',NULL,NULL,'2026-07-06 08:38:34','2026-09-15 02:30:53'),(6,'HH.63.006.PCSWH','Handheld',NULL,'WH',NULL,NULL,NULL,'WH B.6',NULL,'Honeywell','CN80',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,0,'Retired',NULL,NULL,'2026-07-06 08:38:34','2026-09-15 02:30:53'),(7,'HH.63.007.PCSWH','Handheld',NULL,'WH',NULL,NULL,NULL,'WH B.6',NULL,'Honeywell','CN80',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,0,'Retired',NULL,NULL,'2026-07-06 08:38:34','2026-09-15 02:30:53'),(8,'HH.65.008.PCSWH','Handheld',NULL,'WH',NULL,NULL,NULL,'WH B.8',NULL,'Keyence','BT-A500','6A0M000286',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,0,'Active',NULL,NULL,'2026-07-06 08:38:34','2026-09-15 02:30:53'),(9,'HH.65.004.PCSWH','Handheld',NULL,'WH',NULL,NULL,NULL,'WH B.9',NULL,'Keyence','BT-A500','136A5006A0M000203',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,0,'Active',NULL,NULL,'2026-07-06 08:38:34','2026-09-15 02:30:53'),(10,'HH.65.005.PCSWH','Handheld',NULL,'WH',NULL,NULL,NULL,'WH B.9',NULL,'Keyence','BT-A500','136A5006A0M000199',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,0,'Active',NULL,NULL,'2026-07-06 08:38:34','2026-09-15 02:30:53'),(11,'HH.65.006.PCSWH','Handheld',NULL,'WH',NULL,NULL,NULL,'WH B.2',NULL,'Keyence','BT-A500','6A0M000200',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,0,'Active',NULL,NULL,'2026-07-06 08:38:34','2026-09-15 02:30:53'),(12,'HH.66.001.PCSWH','Handheld',NULL,'WH',NULL,NULL,NULL,'WH B.1',NULL,'Keyence','BT-A500','136A500C70N004286',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,0,'Active',NULL,NULL,'2026-07-06 08:38:34','2026-09-15 02:30:53'),(13,'HH.66.002.PCSWH','Handheld',NULL,'WH',NULL,NULL,NULL,'WH B.3',NULL,'Keyence','BT-A500','136A500C70N004282',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,0,'Active',NULL,NULL,'2026-07-06 08:38:34','2026-09-15 02:30:53'),(14,'HH.66.003.PCSWH','Handheld',NULL,'WH',NULL,NULL,NULL,'WH B.4',NULL,'Keyence','BT-A700G','13A700GCR220703',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,0,'Active',NULL,NULL,'2026-07-06 08:38:34','2026-09-15 02:30:53'),(15,'HH.66.004.PCSWH','Handheld',NULL,'WH',NULL,NULL,NULL,'WH B.4',NULL,'Keyence','BT-A700G','13A700GCR220317',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,0,'Active',NULL,NULL,'2026-07-06 08:38:34','2026-09-15 02:30:53'),(16,'HH.66.005.PCSWH','Handheld',NULL,'WH',NULL,NULL,NULL,'WH B.4',NULL,'Keyence','BT-A700G','13A700GCR220402',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,0,'Active',NULL,NULL,'2026-07-06 08:38:34','2026-09-15 02:30:53'),(17,'HH.66.006.PCSWH','Handheld',NULL,'WH',NULL,NULL,NULL,'WH B.4',NULL,'Keyence','BT-A700G','13A700GCR220795',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,0,'Active',NULL,NULL,'2026-07-06 08:38:34','2026-09-15 02:30:53'),(18,'HH.66.007.PCSWH','Handheld',NULL,'WH',NULL,NULL,NULL,'WH.B8',NULL,'Keyence','BT-A700G','13A700GCR220791',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,0,'Active',NULL,NULL,'2026-07-06 08:38:34','2026-09-15 02:30:53'),(19,'HH.66.008.PCSWH','Handheld',NULL,'WH',NULL,NULL,NULL,'WH.B8',NULL,'Keyence','BT-A700G','13A700G2R422762',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,0,'Active',NULL,NULL,'2026-07-06 08:38:34','2026-09-15 02:30:53'),(20,'HH.66.009.PCSWH','Handheld',NULL,'WH',NULL,NULL,NULL,'WH.B8',NULL,'Keyence','BT-A700G','13A700GCR220701',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,0,'Active',NULL,NULL,'2026-07-06 08:38:34','2026-09-15 02:30:53'),(21,'HH.66.010.PCSWH','Handheld',NULL,'WH',NULL,NULL,NULL,'WH.B8',NULL,'Keyence','BT-A700G','13A700GCR223328',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,0,'Active',NULL,NULL,'2026-07-06 08:38:34','2026-09-15 02:30:53'),(22,'HH.69.01','Handheld',NULL,'WH',NULL,NULL,NULL,'WH B.1',NULL,'CIPHERLAB','RS38WO','GH125B0013777',NULL,NULL,NULL,'00:d0:17:d9:23:d1',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,0,'Active',NULL,NULL,'2026-07-06 08:38:34','2026-09-15 02:30:53'),(23,'HH.69.02','Handheld',NULL,'WH',NULL,NULL,NULL,'WH B.1',NULL,'CIPHERLAB','RS38WO','GH125C0016708',NULL,NULL,NULL,'00:d0:17:d9:2e:e2',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,0,'Active',NULL,NULL,'2026-07-06 08:38:34','2026-09-15 02:30:53'),(24,'HH.69.03','Handheld',NULL,'WH',NULL,NULL,NULL,'WH B.2',NULL,'CIPHERLAB','RS38WO','GH125B0012537',NULL,NULL,NULL,'00:d0:17:d9:1e:f9',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,0,'Active',NULL,NULL,'2026-07-06 08:38:34','2026-09-15 02:30:53'),(25,'HH.69.04','Handheld',NULL,'WH',NULL,NULL,NULL,'WH B.6',NULL,'CIPHERLAB','RS38WO','GH125B0012561',NULL,NULL,NULL,'00:d0:17:d9:1f:11',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,0,'Active',NULL,NULL,'2026-07-06 08:38:34','2026-09-15 02:30:53'),(26,'HH.69.05','Handheld',NULL,'WH',NULL,NULL,NULL,'WH B.6',NULL,'CIPHERLAB','RS38WO','GH125B0013808',NULL,NULL,NULL,'00:d0:17:d9:23:f0',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,0,'Active',NULL,NULL,'2026-07-06 08:38:34','2026-09-15 02:30:53'),(27,'HH.69.06','Handheld',NULL,'WH',NULL,NULL,NULL,'WH B.6',NULL,'CIPHERLAB','RS38WO','GH125B0012529',NULL,NULL,NULL,'00:d0:17:d9:1e:f1',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,0,'Active',NULL,NULL,'2026-07-06 08:38:34','2026-09-15 02:30:53'),(28,'HH.69.07','Handheld',NULL,'WH',NULL,NULL,NULL,'WH B.6',NULL,'CIPHERLAB','RS38WO','GH125B0013764',NULL,NULL,NULL,'00:d0:17:d9:23:c4',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,0,'Active',NULL,NULL,'2026-07-06 08:38:34','2026-09-15 02:30:53'),(29,'HH.69.08','Handheld',NULL,'WH',NULL,NULL,NULL,'WH B.6',NULL,'CIPHERLAB','RS38WO','GH125B0013781',NULL,NULL,NULL,'00:d0:17:d9:23:d5',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,0,'Active',NULL,NULL,'2026-07-06 08:38:34','2026-09-15 02:30:53'),(30,'HH.69.09','Handheld',NULL,'WH',NULL,NULL,NULL,'WH B.6',NULL,'CIPHERLAB','RS38WO','GH125B0012514',NULL,NULL,NULL,'00:d0:17:d9:1e:e2',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,0,'Active',NULL,NULL,'2026-07-06 08:38:34','2026-09-15 02:30:53'),(31,'HH.69.10','Handheld',NULL,'WH',NULL,NULL,NULL,'WH B.6',NULL,'CIPHERLAB','RS38WO','GH125B0012508',NULL,NULL,NULL,'00:d0:17:d9:1e:dc',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,0,'Active',NULL,NULL,'2026-07-06 08:38:34','2026-09-15 02:30:53'),(32,'HH.69.11','Handheld',NULL,'WH',NULL,NULL,NULL,'WH B.8',NULL,'CIPHERLAB','RS38WO','GH125B0013817',NULL,NULL,NULL,'00:d0:17:d9:23:f9',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,0,'Active',NULL,NULL,'2026-07-06 08:38:34','2026-09-15 02:30:53'),(33,'HH.69.12','Handheld',NULL,'WH',NULL,NULL,NULL,'WH B.8',NULL,'CIPHERLAB','RS38WO','GH125B0012766',NULL,NULL,NULL,'00:d0:17:d9:1f:de',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,0,'Active',NULL,NULL,'2026-07-06 08:38:34','2026-09-15 02:30:53'),(34,'HH.69.13','Handheld',NULL,'WH',NULL,NULL,NULL,'WH B.8',NULL,'CIPHERLAB','RS38WO','GH125B0012542',NULL,NULL,NULL,'00:d0:17:d9:1e:fe',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,0,'Active',NULL,NULL,'2026-07-06 08:38:34','2026-09-15 02:30:53'),(35,'TB.62.004','Tablet',NULL,'WH',NULL,NULL,NULL,'WH.6',NULL,'Algizrt7','118207','9I000029',NULL,'2',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,0,'Retired',NULL,NULL,'2026-07-06 08:38:34','2026-09-15 02:30:53'),(36,'TB.62.008','Tablet',NULL,'WH',NULL,NULL,NULL,'WH.8',NULL,'Algizrt7','118207','9I000377',NULL,'2',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,0,'Retired',NULL,NULL,'2026-07-06 08:38:34','2026-09-15 02:30:53'),(37,'TB.62.009','Tablet',NULL,'WH',NULL,NULL,NULL,'WH.8',NULL,'Algizrt7','118207','9I001000',NULL,'2',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,0,'Retired',NULL,NULL,'2026-07-06 08:38:34','2026-09-15 02:30:53'),(38,'TB.62.010','Tablet',NULL,'WH',NULL,NULL,NULL,'WH.8',NULL,'Algizrt7','118207','9I000857',NULL,'2',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,0,'Retired',NULL,NULL,'2026-07-06 08:38:34','2026-09-15 02:30:53'),(39,'TB.67.01','Tablet',NULL,'WH',NULL,NULL,NULL,'wh.1',NULL,'iDataP1','iDataP1','4179797',NULL,'4',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,0,'Active',NULL,NULL,'2026-07-06 08:38:34','2026-09-15 02:30:53'),(40,'TB.67.02','Tablet',NULL,'WH',NULL,NULL,NULL,'wh.1',NULL,'iDataP1','iDataP1','4179832',NULL,'4',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,0,'Active',NULL,NULL,'2026-07-06 08:38:34','2026-09-15 02:30:53'),(41,'TB.67.03','Tablet',NULL,'WH',NULL,NULL,NULL,'wh.1',NULL,'iDataP1','iDataP1','4179766',NULL,'4',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,0,'Active',NULL,NULL,'2026-07-06 08:38:34','2026-09-15 02:30:53'),(42,'TB.67.04','Tablet',NULL,'WH',NULL,NULL,NULL,'wh.1',NULL,'iDataP1','iDataP1','4179830',NULL,'4',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,0,'Active',NULL,NULL,'2026-07-06 08:38:34','2026-09-15 02:30:53'),(43,'TB.67.05','Tablet',NULL,'WH',NULL,NULL,NULL,'wH.4',NULL,'iDataP1','iDataP1','4179828',NULL,'4',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,0,'Active',NULL,NULL,'2026-07-06 08:38:34','2026-09-15 02:30:53'),(44,'TB.67.06','Tablet',NULL,'WH',NULL,NULL,NULL,'wH.4',NULL,'iDataP1','iDataP1','4179859',NULL,'4',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,0,'Active',NULL,NULL,'2026-07-06 08:38:34','2026-09-15 02:30:53'),(45,'TB.67.07','Tablet',NULL,'WH',NULL,NULL,NULL,'WH.6',NULL,'iDataP1','iDataP1','4179783',NULL,'4',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,0,'Active',NULL,NULL,'2026-07-06 08:38:34','2026-09-15 02:30:53'),(46,'TB.67.08','Tablet',NULL,'WH',NULL,NULL,NULL,'WH.7',NULL,'iDataP1','iDataP1','4179865',NULL,'4',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,0,'Active',NULL,NULL,'2026-07-06 08:38:34','2026-09-15 02:30:53'),(47,'PCS-GALAXYA34-001','Smartphone','PCS',NULL,2,NULL,NULL,NULL,'2023-08-10','Samsung Galaxy A34',NULL,'RFCW50CJ58F','RFCW50CJ58F','8GB','128GB','24:11:53:9B:CD:45',NULL,'Android',NULL,NULL,NULL,'088-994-4945',NULL,NULL,NULL,NULL,NULL,NULL,0,'Active',NULL,'2026-07-13','2026-07-06 08:56:32','2026-09-15 02:30:53'),(48,'PCS-GALAXYA34-002','Smartphone','PCS',NULL,1,NULL,NULL,NULL,'2023-08-10','Samsung Galaxy A34',NULL,'RFCW40TWXRL','RFCW40TWXRL','8GB','128GB','24:11:53:05:49:FB',NULL,'Android',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,0,'Active',NULL,'2026-07-13','2026-07-06 08:56:32','2026-09-15 02:30:53'),(49,'PCS-GALAXYA34-003','Smartphone','PCS','บริหาร',NULL,NULL,NULL,NULL,'2023-08-10','Samsung Galaxy A34',NULL,'RFCW40TX7XR','RFCW40TX7XR','8GB','128GB','24:11:53:05:4D:44',NULL,'Android',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,0,'Active',NULL,NULL,'2026-07-06 08:56:32','2026-07-06 08:56:32'),(50,'PCS-GALAXYA34-004','Smartphone','PCS','HR',2,NULL,NULL,NULL,'2023-08-10','Samsung Galaxy A34',NULL,'RFCW40TX93L','RFCW40TX93L','8GB','128GB','24:11:53:05:4C:E7',NULL,'Android',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,0,'Active',NULL,'2026-07-13','2026-07-06 08:56:32','2026-09-15 02:30:53'),(51,'PCS-GALAXYA34-005','Smartphone','PCS','IT',2,NULL,NULL,NULL,'2023-08-10','Samsung Galaxy A34',NULL,'RFCW50CHEFJ','RFCW50CHEFJ','8GB','128GB','24:11:53:9B:C7:23',NULL,'Android',NULL,NULL,'DTAC','086-324-3212','บริษัท',NULL,NULL,NULL,NULL,NULL,0,'Active',NULL,'2026-09-14','2026-07-06 08:56:32','2026-09-15 02:30:53'),(52,'PCS-GALAXYA34-006','Smartphone','PCS',NULL,2,NULL,NULL,NULL,'2023-08-10','Samsung Galaxy A34',NULL,'RFCW40TWXPX','RFCW40TWXPX','8GB','128GB','24:11:53:05:49:F7',NULL,'Android',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,0,'Active',NULL,'2026-07-13','2026-07-06 08:56:32','2026-09-15 02:30:53'),(53,'PCS-GALAXYA34-007','Smartphone','PCS','WH',2,NULL,NULL,NULL,'2023-08-10','Samsung Galaxy A34',NULL,'RFCW40TX9XB','RFCW40TX9XB','8GB','128GB','24:11:53:05:4D:1C',NULL,'Android',NULL,NULL,NULL,'081-354-0765',NULL,NULL,NULL,NULL,NULL,NULL,0,'Active',NULL,'2026-07-13','2026-07-06 08:56:32','2026-09-15 02:30:53'),(54,'PCS-GALAXYA34-008','Smartphone','PCS','PC',2,NULL,NULL,NULL,'2023-08-10','Samsung Galaxy A34',NULL,'RFCW40TXA2V','RFCW40TXA2V','8GB','128GB','24:11:53:05:4D:27',NULL,'Android',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,0,'Active',NULL,'2026-07-13','2026-07-06 08:56:32','2026-09-15 02:30:53'),(55,'PCS-GALAXYA34-009','Smartphone','PCS','บริหาร',NULL,NULL,NULL,NULL,'2023-08-10','Samsung Galaxy A34',NULL,'RFCW40TXA3Z','RFCW40TXA3Z','8GB','128GB','24:11:53:05:4D:28',NULL,'Android',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,0,'Active',NULL,NULL,'2026-07-06 08:56:32','2026-07-06 08:56:32'),(56,'PCS-GALAXYA34-010','Smartphone','PCS','AC',2,NULL,NULL,NULL,'2023-08-10','Samsung Galaxy A34',NULL,'RFCW50F8P5J','RFCW50F8P5J','8GB','128GB','24:11:53:A3:4E:DC',NULL,'Android',NULL,NULL,NULL,'091-889-8544',NULL,NULL,NULL,NULL,NULL,NULL,0,'Active',NULL,'2026-07-08','2026-07-06 08:56:32','2026-09-15 02:30:53'),(57,'PCS-GALAXYA34-011','Smartphone','PCS','AC',2,NULL,NULL,NULL,'2023-08-10','Samsung Galaxy A34',NULL,'RFCW50CJ09W','RFCW50CJ09W','8GB','128GB','24:11:53:9B:CB:FC',NULL,'Android',NULL,NULL,NULL,'091-889-9336','บริษัท',NULL,NULL,NULL,NULL,NULL,0,'Active',NULL,'2026-07-08','2026-07-06 08:56:32','2026-09-15 02:30:53'),(58,'PCS-GALAXYA34-012','Smartphone','PCS','MK',2,NULL,NULL,NULL,'2023-08-10','Samsung Galaxy A34',NULL,'RFCW40TWGWB','RFCW40TWGWB','8GB','128GB','24:11:53:05:46:66',NULL,'Android',NULL,NULL,NULL,'091-889-8546',NULL,NULL,NULL,NULL,NULL,NULL,0,'Active',NULL,'2026-07-13','2026-07-06 08:56:32','2026-09-15 02:30:53'),(59,'PCS-GALAXYA34-013','Smartphone','PCS','HR',2,NULL,NULL,NULL,'2023-08-10','Samsung Galaxy A34',NULL,'RFCW40TWXSY','RFCW40TWXSY','8GB','128GB','24:11:53:05:49:FD',NULL,'Android',NULL,NULL,NULL,'091-887-0918',NULL,NULL,NULL,NULL,NULL,NULL,0,'Active',NULL,'2026-07-13','2026-07-06 08:56:32','2026-09-15 02:30:53'),(60,'PCS-GALAXYA34-014','Smartphone','PCS','QA',2,NULL,NULL,NULL,'2023-08-10','Samsung Galaxy A34',NULL,'RFCW50CH5DY','RFCW50CH5DY','8GB','128GB','24:11:53:9B:C4:CC',NULL,'Android',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,0,'Active',NULL,'2026-07-13','2026-07-06 08:56:32','2026-09-15 02:30:53'),(61,'PCS-GALAXYA34-015','Smartphone','PCS','AC',2,NULL,NULL,NULL,'2023-08-10','Samsung Galaxy A34',NULL,'RFCW50F8NDK','RFCW50F8NDK','8GB','128GB','24:11:53:A3:4E:AB',NULL,'Android',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,0,'Active',NULL,'2026-07-13','2026-07-06 08:56:32','2026-09-15 02:30:53'),(62,'PCS-GALAXYA34-016','Smartphone','PCS','WH',2,NULL,NULL,NULL,'2023-08-10','Samsung Galaxy A34',NULL,'RFCW50CJ2RR','RFCW50CJ2RR','8GB','128GB','24:11:53:9B:CC:A0',NULL,'Android',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,0,'Active',NULL,'2026-07-13','2026-07-06 08:56:32','2026-09-15 02:30:53'),(63,'PCS-GALAXYA34-017','Smartphone','PCS',NULL,2,NULL,NULL,NULL,'2023-08-10','Samsung Galaxy A34',NULL,'RFCW40TX8LY','RFCW40TX8LY','8GB','128GB','24:11:53:05:4C:C7',NULL,'Android',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,0,'Active',NULL,'2026-07-13','2026-07-06 08:56:32','2026-09-15 02:30:53'),(64,'PCS-GALAXYA34-018','Smartphone','PCS','EN',2,NULL,NULL,NULL,'2023-08-10','Samsung Galaxy A34',NULL,'RFCW40TXAGF','RFCW40TXAGF','8GB','128GB','24:11:53:05:4D:28',NULL,'Android',NULL,NULL,'ซิมโรงงาน','095-204-2298','บริษัท',NULL,NULL,NULL,NULL,NULL,0,'Active',NULL,'2026-07-08','2026-07-06 08:56:32','2026-09-15 02:30:53'),(65,'PCS-GALAXYA34-019','Smartphone','PCS','PC',2,NULL,NULL,NULL,'2023-08-10','Samsung Galaxy A34',NULL,'RFCW40TWGFD','RFCW40TWGFD','8GB','128GB','24:11:53:05:46:4B',NULL,'Android',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,0,'Active',NULL,'2026-07-13','2026-07-06 08:56:32','2026-09-15 02:30:53'),(66,'PCS-GALAXYA34-020','Smartphone','PCS','CS',2,NULL,NULL,NULL,'2023-08-10','Samsung Galaxy A34',NULL,'RFCW40TWYCJ','RFCW40TWYCJ','8GB','128GB','24:11:53:05:4A:23',NULL,'Android',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,0,'Active',NULL,'2026-07-13','2026-07-06 08:56:32','2026-07-13 04:30:35'),(67,'PCS-GALAXYA13-001','Smartphone',NULL,'WH',5,NULL,NULL,NULL,'2022-06-10','Samsung Galaxy A13',NULL,'R58T51M4K0B','R58T51M4K0B','4GB','128GB','4C:2E5E:73:E0:15',NULL,'Android',NULL,NULL,'ไม่มี SIM',NULL,NULL,NULL,NULL,NULL,NULL,NULL,0,'Active',NULL,'2026-07-13','2026-07-06 08:56:32','2026-09-15 02:30:53'),(68,'PCS-GALAXYA13-002','Smartphone','PCS','WH',2,NULL,NULL,NULL,'2022-06-10','Samsung Galaxy A13',NULL,'R58T51M5ALE','R58T51M5ALE','4GB','128GB','4C:2E:5E:73:E6:6D',NULL,'Android',NULL,NULL,'ไม่มี SIM',NULL,NULL,NULL,NULL,NULL,NULL,NULL,0,'Active',NULL,'2026-07-13','2026-07-06 08:56:32','2026-09-15 02:30:53'),(69,'PCS-GALAXYA13-003','Smartphone','PCS','WH',2,NULL,NULL,NULL,'2022-06-10','Samsung Galaxy A13',NULL,'R58T51M4T6R','R58T51M4T6R','4GB','128GB','4C:2E:5E:73:E2:31',NULL,'Android',NULL,NULL,'ไม่มี SIM',NULL,NULL,NULL,NULL,NULL,NULL,NULL,0,'Active',NULL,'2026-07-13','2026-07-06 08:56:32','2026-09-15 02:30:53'),(70,'PCS-GALAXYA13-004','Smartphone','PCS','WH',2,NULL,NULL,NULL,'2022-06-10','Samsung Galaxy A13',NULL,'R58T51M4QLL','R58T51M4QLL','4GB','128GB','4C:2E:5E:73:E1:87',NULL,'Android',NULL,NULL,'ไม่มี SIM',NULL,NULL,NULL,NULL,NULL,NULL,NULL,0,'Active',NULL,'2026-07-13','2026-07-06 08:56:32','2026-09-15 02:30:53'),(71,'PCS-GALAXYA13-005','Smartphone','PCS','WH',2,NULL,NULL,NULL,'2022-06-10','Samsung Galaxy A13',NULL,'R58T43333VLM','R58T43333VLM','4GB','128GB','B4:70:64:68:FA:30',NULL,'Android',NULL,NULL,'ไม่มี SIM',NULL,NULL,NULL,NULL,NULL,NULL,NULL,0,'Active',NULL,'2026-07-13','2026-07-06 08:56:32','2026-09-15 02:30:53'),(72,'PCS-GALAXYA13-006','Smartphone','PCS','WH',2,NULL,NULL,NULL,'2022-06-10','Samsung Galaxy A13',NULL,'R58T51M4EPB','R58T51M4EPB','4GB','128GB','4C:2E5E:73:DE:F9',NULL,'Android',NULL,NULL,'ไม่มี SIM',NULL,NULL,NULL,NULL,NULL,NULL,NULL,0,'Active',NULL,'2026-07-13','2026-07-06 08:56:32','2026-09-15 02:30:53'),(73,'PCS-GALAXYA13-007','Smartphone','PCS','WH',2,NULL,NULL,NULL,'2022-06-10','Samsung Galaxy A13',NULL,'R58T51M5B0Y','R58T51M5B0Y','4GB','128GB','4C:2E5E:73:E6:87',NULL,'Android',NULL,NULL,'ไม่มี SIM',NULL,NULL,NULL,NULL,NULL,NULL,NULL,0,'Active',NULL,'2026-07-13','2026-07-06 08:56:32','2026-09-15 02:30:53'),(74,'PCS-GALAXYA13-008','Smartphone','PCS','WH',2,NULL,NULL,NULL,'2022-06-10','Samsung Galaxy A13',NULL,'R58T51M4K1R','R58T51M4K1R','4GB','128GB','4C:2E:5E:73:E0:17',NULL,'Android',NULL,NULL,'ไม่มี SIM',NULL,NULL,NULL,NULL,NULL,NULL,NULL,0,'Active',NULL,'2026-07-13','2026-07-06 08:56:32','2026-09-15 02:30:53'),(75,'PCS-GALAXYA13-009','Smartphone','PCS','WH',2,NULL,NULL,NULL,'2022-06-10','Samsung Galaxy A13',NULL,'R58T51M4EYH','R58T51M4EYH','4GB','128GB','4C:2E:5E:73:DF:09',NULL,'Android',NULL,NULL,'ไม่มี SIM',NULL,NULL,NULL,NULL,NULL,NULL,NULL,0,'Active',NULL,'2026-07-13','2026-07-06 08:56:32','2026-09-15 02:30:53'),(76,'PCS-GALAXYA13-010','Smartphone',NULL,'WH',9,NULL,NULL,NULL,'2022-06-10','Samsung Galaxy A13',NULL,'R58T51M4L1V','R58T51M4L1V','4GB','128GB','4C:2E:5E:73:E0:59',NULL,'Android',NULL,NULL,'ไม่มี SIM',NULL,NULL,NULL,NULL,NULL,NULL,NULL,0,'Active',NULL,'2026-07-13','2026-07-06 08:56:32','2026-09-15 02:30:53'),(77,'PCS-GALAXYA13-011','Smartphone',NULL,'WH',3,NULL,NULL,NULL,'2022-06-10','Samsung Galaxy A13',NULL,'R58T51M4M9M','R58T51M4M9M','4GB','128GB','4C:2E:5E:73:E0:AB',NULL,'Android',NULL,NULL,'ไม่มี SIM',NULL,NULL,NULL,NULL,NULL,NULL,NULL,0,'Active',NULL,'2026-07-13','2026-07-06 08:56:32','2026-09-15 02:30:53'),(78,'PCS-GALAXYA13-012','Smartphone',NULL,'WH',4,NULL,NULL,NULL,'2022-06-10','Samsung Galaxy A13',NULL,'R58T51M4R2M','R58T51M4R2M','4GB','128GB','4C:2E:5E:73:E1:51',NULL,'Android',NULL,NULL,'ไม่มี SIM',NULL,NULL,NULL,NULL,NULL,NULL,NULL,0,'Active',NULL,'2026-07-13','2026-07-06 08:56:32','2026-09-15 02:30:53'),(79,'PCS-GALAXYA13-013','Smartphone','PCS','WH',2,NULL,NULL,NULL,'2022-06-10','Samsung Galaxy A13',NULL,'R58T51M4NYM','R58T51M4NYM','4GB','128GB','4C:2E:5E:73:E1:19',NULL,'Android',NULL,NULL,'ไม่มี SIM',NULL,NULL,NULL,NULL,NULL,NULL,NULL,0,'Active',NULL,'2026-07-13','2026-07-06 08:56:32','2026-09-15 02:30:53'),(80,'PCS-GALAXYA13-014','Smartphone',NULL,'WH',5,NULL,NULL,NULL,'2022-06-10','Samsung Galaxy A13',NULL,'R58T51M4E4B','R58T51M4E4B','4GB','128GB','4C:2E:5E:73:DE:D3',NULL,'Android',NULL,NULL,'ไม่มี SIM',NULL,NULL,NULL,NULL,NULL,NULL,NULL,0,'Active',NULL,'2026-07-13','2026-07-06 08:56:32','2026-09-15 02:30:53'),(81,'PCS-GALAXYA13-015','Smartphone','PCS','WH',2,NULL,NULL,NULL,'2022-06-10','Samsung Galaxy A13',NULL,'R58T51M4WQF','R58T51M4WQF','4GB','128GB','4C:2E:5E:73:E2:D9',NULL,'Android',NULL,NULL,'ไม่มี SIM',NULL,NULL,NULL,NULL,NULL,NULL,NULL,0,'Active',NULL,'2026-07-13','2026-07-06 08:56:32','2026-09-15 02:30:53'),(82,'PCS-UNKNOWN-001','Smartphone',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,0,'Active',NULL,NULL,'2026-07-06 08:56:32','2026-07-06 08:56:32'),(83,'TB-66-02-PCSEN','Tablet','PCS','IT',2,NULL,NULL,NULL,NULL,'Samsung Tablet',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,0,'Active',NULL,'2026-07-14','2026-07-14 04:40:10','2026-07-14 04:42:47'),(84,'HH-69-01-PACAWH','Handheld','PCS','IT',4,NULL,NULL,NULL,'2026-07-15','Cipher Lab','Cipher Lab','GH125C0016545',NULL,'8GB',NULL,'00:d0:17:d9:09:df',NULL,'Android 14','CCMS',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,0,'Active',NULL,'2026-07-15','2026-07-15 10:17:27','2026-09-15 02:30:53'),(85,'HH-69-02-PACAWH','Handheld','PCS','IT',4,NULL,NULL,NULL,'2026-07-21','Cipher Lab','Cipher Lab','GH125C0016535',NULL,'8GB',NULL,'00:d0:17:d9:2e:35',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,0,'Active',NULL,'2026-07-21','2026-07-21 01:38:13','2026-07-21 01:39:29'),(86,'HH-69-03-PACAWH','Handheld','PCS','IT',4,NULL,NULL,NULL,NULL,'Cipher Lab','Cipher Lab','GH125C0016268',NULL,'8GB',NULL,'00:d0:17:d9:2d:f2',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,0,'Active',NULL,'2026-07-21','2026-07-21 01:40:46','2026-07-21 01:40:46'),(87,'HH-69-04-PACAWH','Handheld','PCS','IT',4,NULL,NULL,NULL,NULL,'Cipher Lab','Cipher Lab','GH125C0016466',NULL,'8GB',NULL,'00:d0:17:d9:2d:f0',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,0,'Active',NULL,'2026-07-21','2026-07-21 01:42:04','2026-07-21 01:42:04'),(88,'HH-69-05-PACAWH','Handheld','PCS','IT',4,NULL,NULL,NULL,NULL,'Cipher Lab','Cipher Lab','GH125C0016461',NULL,NULL,NULL,'00:d0:17:d9:2d:eb',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,0,'Active',NULL,'2026-07-21','2026-07-21 01:46:35','2026-07-21 01:46:35'),(89,'HH-69-06-PACAWH','Handheld','PCS','IT',4,NULL,NULL,NULL,NULL,'Cipher Lab','Cipher Lab','GH125C0016462',NULL,'8GB',NULL,'00:d0:17:d9:2d:ec',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,0,'Active',NULL,'2026-07-21','2026-07-21 03:15:37','2026-07-21 03:15:37'),(90,'HH-66-001-PACAWH','Handheld','PCS','IT',4,NULL,NULL,NULL,NULL,'KEYENCE','BTA-500','#C70N003795',NULL,NULL,NULL,NULL,NULL,NULL,'CCMS',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,0,'Active',NULL,'2026-07-23','2026-07-23 07:04:53','2026-09-15 02:30:53'),(91,'HH-69-07-PACAWH','Handheld','PCS','IT',4,NULL,NULL,NULL,NULL,'Cipher Lab',NULL,'GH125C0016463',NULL,NULL,NULL,'00:d0:17:d9:2d:ed',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,0,'Active',NULL,'2026-07-27','2026-07-27 06:34:46','2026-07-27 06:34:46'),(92,'HH-69-08-PACAWH','Handheld','PCS','IT',4,NULL,NULL,NULL,NULL,'Cipher Lab',NULL,'GH125C0016551',NULL,NULL,NULL,'00:d0:17:d9:2e:45',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,0,'Active',NULL,'2026-07-27','2026-07-27 06:36:00','2026-07-27 06:36:00'),(93,'HH-69-09-PACAWH','Handheld','PCS','IT',4,NULL,NULL,NULL,NULL,'Cipher Lab',NULL,'GH125C0016534',NULL,NULL,NULL,'00:d0:17:d9:2e:34',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,0,'Active',NULL,'2026-07-27','2026-07-27 06:36:53','2026-07-27 06:36:53'),(94,'HH-69-10-PACAWH','Handheld','PCS','IT',4,NULL,NULL,NULL,NULL,'Cipher Lab',NULL,'GH125C0016541',NULL,NULL,NULL,'00:d0:17:d9:2e:db',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,0,'Active',NULL,'2026-07-27','2026-07-27 06:37:27','2026-07-27 06:37:27'),(95,'HH-69-11-PACAWH','Handheld','PCS','IT',4,NULL,NULL,NULL,NULL,'Cipher Lab',NULL,'GH125C0016559',NULL,NULL,NULL,'00:d0:17:d9:2e:4d',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,0,'Active',NULL,'2026-07-27','2026-07-27 06:38:09','2026-07-27 06:38:09'),(96,'SP-69-01-PCSMK','Smartphone','PCS','MK',2,NULL,NULL,'HW8','2026-08-24','Samsung','Galaxy A06 5G','R7AY4033DJJ','355659324438328','6GB','64GB',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,0,'Active',NULL,'2026-08-24','2026-08-24 08:23:47','2026-08-24 08:23:47'),(97,'SP-69-02-PCSCS','Smartphone','PCS','CS',2,NULL,NULL,NULL,'2026-08-25','Samsung','galaxy A06 5g','R7AY4033NTT','355659324405772','6','128','cc:e6:89:5c:82:69',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,0,'Active',NULL,'2026-08-25','2026-08-25 01:46:50','2026-08-25 01:46:50'),(98,'SP-69-03-PCSCS','Smartphone','PCS','CS',2,NULL,NULL,NULL,'2026-08-26','Samsung','galaxy a06 5g','R7AY40318CH','355659324441140','8GB','128','cc:e6:86:5c:83:1b',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,0,'Active',NULL,'2026-08-26','2026-08-26 03:52:13','2026-08-26 03:52:13'),(99,'SP-69-04-PCSAC','Smartphone','PCS','AC',2,NULL,NULL,'office.pcs','2026-08-26','Samsung','galaxy a06 5g','R7AY4032F3T','355659324423932','8','128','cc:e6:86:5c:77:5f',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,0,'Active',NULL,'2026-08-26','2026-08-26 04:19:13','2026-09-15 02:30:53'),(100,'SP-69-05-PCSAC','Smartphone','PCS','AC',2,NULL,NULL,'office.pcs','2026-08-26','Samsung','galaxy a06 5g','R7AY40354WZ','355659324440225','8','128','cc:e6:86:5c:89:2b',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,0,'Active',NULL,'2026-08-26','2026-08-26 04:43:48','2026-09-15 02:30:53'),(101,'SP-69-06-PCSCS','Smartphone','PCS','CS',2,NULL,NULL,'CS','2026-08-26','Samsung','galaxy a60 5g','R7AY4030G0T','3555659324432305','8','128','cc:6e:86:56:83:03',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,0,'Active',NULL,'2026-08-26','2026-08-26 07:16:13','2026-08-26 07:16:13'),(102,'SP-69-07-PCSHR','Smartphone','PCS','HR',2,NULL,NULL,'HR','2026-08-26','Samsung','galaxy a06 5g','R7AY40318EY','355659324441165','8','128','cc:e6:86:5c:71:88',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,0,'Active',NULL,'2026-08-28','2026-08-26 07:59:40','2026-09-15 02:30:53'),(103,'SP-69-08-PCSHR','Smartphone','PCS','HR',2,NULL,NULL,'HR','2026-08-26','Samsung','Galaxy A06 5G','R7AY403117V','355659324419500','8','128',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,0,'Active',NULL,'2026-08-26','2026-08-26 09:01:16','2026-09-15 02:30:53'),(104,'SP-69-09-PCSCS','Smartphone','PCS','CS',2,NULL,NULL,'CS','2026-08-26','Samsung','Galaxy-A06 5G','R7AY40318JA','355659324441207','8','128','76:ed:31:e5:2a:a0',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,0,'Active',NULL,'2026-08-28','2026-08-26 09:13:01','2026-09-15 02:30:53'),(105,'SP-69-10-PCSAC','Smartphone','PCS','AC',2,NULL,NULL,'AC','2026-08-27','Samsung','galaxy A06 5G','R7AY40311ZN','355659324419757','8','128','cc:e6:86:5c:86:3a',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,0,'Active',NULL,'2026-08-27','2026-08-27 01:33:07','2026-09-15 02:30:53'),(106,'SP-69-11-PCSEN','Smartphone','PCS','EN',2,NULL,NULL,'EN','2026-08-27','Samsung','galaxy A06 5G','R7AY4032VMF','355659324401326','8','128','cc:e6:86:5c:86:f4',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,0,'Active',NULL,'2026-08-27','2026-08-27 02:08:30','2026-09-15 02:30:53'),(107,'SP-69-12-PLP-MKT','Smartphone','PCS','PLP',7,NULL,NULL,'PLP','2026-08-31','Samsung','Galaxy A06 5G','R7AY4034GPW','355659324415326','8','128','cc:e6:86:5c:7e:ef',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,0,'Active',NULL,'2026-08-31','2026-08-31 02:27:32','2026-09-15 02:30:53'),(108,'SP-69-13-PLP','Smartphone','PCS','PLP',7,NULL,NULL,'BPD','2026-08-31','Samsung','galaxy A06 5G','R7AY4034J8D','355659324418734','8','128','cc:e6:86:5c:77:0f',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,0,'Active',NULL,'2026-08-31','2026-08-31 02:30:17','2026-09-15 02:30:53'),(109,'SP-69-14-PLP','Smartphone','PCS','PLP',7,NULL,NULL,'PLP','2026-08-31','Samsung','galaxy a06 5g','R7AY40336VV','355659324424435','8','128','cc:e6:86:5c:7b:a2',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,0,'Active',NULL,'2026-08-31','2026-08-31 03:17:48','2026-09-15 02:30:53'),(110,'SP-69-15-PCSQA','Smartphone','PCS','QA',2,NULL,NULL,'QA','2026-08-31','Samsung','galaxy a06 5g','R7AY40310QZ','355659324419344','8','128','cc:e6:86:5c:82:5d',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,0,'Active',NULL,'2026-08-31','2026-08-31 03:22:01','2026-09-15 02:30:53'),(111,'SP-69-16-PCSAC','Smartphone','PCS','AC',2,NULL,NULL,'AC','2026-08-31','Samsung','galaxy a06 5g','R7AY4032QFB','355659324446305','8','128','cc:e6:86:5c:86:07',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,0,'Active',NULL,'2026-08-31','2026-08-31 03:24:44','2026-09-15 02:30:53'),(112,'SP-69-17-PLPAC','Smartphone','PCS','AC',2,NULL,NULL,'PLP-AC','2026-08-31','Samsung','galaxy a06 5g','R7AY4033EEV','355659324438617','8','128','cc:e6:86:5c:74:cf',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,0,'Active',NULL,'2026-08-31','2026-08-31 03:28:08','2026-09-15 02:30:53'),(113,'SP-69-18-PCSBPD','Smartphone','PCS','ส่วนกลาง',7,NULL,NULL,'BPD','2026-08-31','Samsung','galaxy a06 5g','R7AY40338TB','355659324428568','8','128','cc:e6:86:5c:78:98',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,0,'Active',NULL,'2026-08-31','2026-08-31 03:32:05','2026-09-15 02:30:53'),(114,'SP-69-19-PCSCS','Smartphone','PCS','CS',2,NULL,NULL,'CS','2026-08-31','Samsung','galaxy a06 5g','R7AY4033WKD','355659324418684','8','128','cc:e6:86:5c:7e:a0',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,0,'Active',NULL,'2026-08-31','2026-08-31 03:37:13','2026-09-15 02:30:53');
/*!40000 ALTER TABLE `mobile_assets` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `network_assets`
--

DROP TABLE IF EXISTS `network_assets`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `network_assets` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `asset_id` varchar(30) NOT NULL COMMENT 'รหัส เช่น NET-SW-001 / NET-RT-001',
  `device_type` enum('Switch','Router','Firewall','Access Point','Load Balancer','Patch Panel','Media Converter','Router SIM','Other') NOT NULL DEFAULT 'Switch',
  `site_id` int(11) DEFAULT NULL,
  `location` varchar(200) DEFAULT NULL COMMENT 'Building 8 / CS Office / Guardhouse',
  `rack_position` varchar(50) DEFAULT NULL COMMENT 'PACK3 #1 / Rack A2',
  `brand` varchar(60) DEFAULT NULL,
  `model` varchar(100) DEFAULT NULL,
  `hostname` varchar(150) DEFAULT NULL COMMENT 'ชื่อ hostname เช่น B8-SVR-CoreSWR3-CBS350-24T',
  `serial_number` varchar(60) DEFAULT NULL,
  `mac_address` varchar(50) DEFAULT NULL,
  `ip_mgmt` varchar(45) DEFAULT NULL COMMENT 'Management IP (vlan40): 192.168.40.x',
  `ip_production` varchar(45) DEFAULT NULL COMMENT 'Production IP (vlan20): 172.16.x.x',
  `vlan_info` varchar(100) DEFAULT NULL COMMENT 'Port/VLAN info เช่น 1-46 v20',
  `firmware_version` varchar(50) DEFAULT NULL,
  `firmware_updated` date DEFAULT NULL,
  `sim_provider` varchar(30) DEFAULT NULL COMMENT 'AIS / DTAC / True / NT',
  `sim_number` varchar(20) DEFAULT NULL,
  `sim_owner` enum('บริษัท','ส่วนตัว') DEFAULT NULL,
  `mgmt_username` varchar(50) DEFAULT NULL,
  `mgmt_password_hint` varchar(100) DEFAULT NULL,
  `is_loanable` tinyint(1) NOT NULL DEFAULT 0,
  `loan_pool_name` varchar(50) DEFAULT NULL,
  `status` enum('Active','Inactive','In Repair','Retired','On Loan') NOT NULL DEFAULT 'Active',
  `purchase_date` date DEFAULT NULL,
  `warranty_expiry` date DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `asset_id` (`asset_id`),
  KEY `idx_device_type` (`device_type`),
  KEY `idx_ip_mgmt` (`ip_mgmt`),
  KEY `idx_status` (`status`),
  KEY `idx_location` (`location`(50)),
  KEY `fk_net_site` (`site_id`),
  CONSTRAINT `fk_net_site` FOREIGN KEY (`site_id`) REFERENCES `sites` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=48 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Network Devices: Switch, Router, AP, Firewall, Router SIM';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `network_assets`
--

LOCK TABLES `network_assets` WRITE;
/*!40000 ALTER TABLE `network_assets` DISABLE KEYS */;
INSERT INTO `network_assets` VALUES (1,'NET-SW-001','Switch',2,'Building 8',NULL,'Cisco','CBS350-24T-4G 24-Port G','B8-SVR-CoreSWR3-CBS350-24T','FOC2643YL8T','44:64:3c:1e:6a:f5','192.168.40.100','172.16.60.30','port 1','3.3.0.16',NULL,NULL,NULL,NULL,'admin',NULL,0,NULL,'Active',NULL,NULL,NULL,'2026-06-22 06:59:08','2026-07-13 08:45:48'),(2,'NET-SW-002','Switch',2,'Building 8',NULL,'Cisco','SG350-52MP-K9 V03','B8-SW1R1-SVR-SG350-52P','DNI24120B9D','14:16:9d:8b:a8:00','192.168.40.101','172.16.60.26','1-46 v20',NULL,NULL,NULL,NULL,NULL,'admin',NULL,0,NULL,'Active',NULL,NULL,NULL,'2026-06-22 06:59:08','2026-07-13 08:45:20'),(3,'NET-SW-003','Switch',2,'Building 8',NULL,'Cisco','SG350-52MP-K9 V02','B8-SW2R1-SVR-SG350-52P','DNI23140012','00:72:78:8f:29:1f','192.168.40.102','172.16.60.25','1-46 v20','2.5.9.16',NULL,NULL,NULL,NULL,'admin',NULL,0,NULL,'Active',NULL,NULL,NULL,'2026-06-22 06:59:08','2026-07-13 08:45:55'),(4,'NET-SW-004','Switch',2,'Building 8',NULL,'Cisco','SG250-26P-K9 V06','B8-SW3R1-SVR-SG250-26P','DNI252804EK','1c:d1:e0:9e:be:16','192.168.40.103','172.16.60.27','1-22 v20','2.5.5.47',NULL,NULL,NULL,NULL,'admin',NULL,0,NULL,'Active',NULL,NULL,NULL,'2026-06-22 06:59:08','2026-07-13 08:46:06'),(5,'NET-SW-005','Switch',2,'Building 8',NULL,'Cisco','CBS350-24S-4G SFP','B8-SW4R1-SFP-CBS350-24S','PSZ25161CK4','84:f1:47:22:6b:85','192.168.40.104','172.16.60.24',NULL,'3.3.0.16',NULL,NULL,NULL,NULL,'admin',NULL,0,NULL,'Active',NULL,NULL,NULL,'2026-06-22 06:59:08','2026-07-13 08:45:12'),(6,'NET-SW-006','Switch',2,'Building 8',NULL,'Cisco','CBS220-24T-4G','B8-SW8-SVR-R2-CBS220-24T','DNI2631026U','74:11:B2:91:8D:2B','192.168.40.105','172.16.60.1',NULL,NULL,NULL,NULL,NULL,NULL,'admin',NULL,0,NULL,'Active',NULL,NULL,NULL,'2026-06-22 06:59:08','2026-07-13 08:46:24'),(7,'NET-SW-007','Switch',2,'Building 8',NULL,'Cisco','C1300-24P-4G V01','B8-SW5-SVR-R3C1300-24P-4G','DNI274200E6','84:5a:3e:89:b3:43','192.168.40.106','172.16.61.130',NULL,'4.0.0.93',NULL,NULL,NULL,NULL,'admin',NULL,0,NULL,'Active',NULL,NULL,NULL,'2026-06-22 06:59:08','2026-07-13 08:46:32'),(8,'NET-SW-008','Switch',2,'Building 8',NULL,'Cisco','CBS350-48P-4G V03','B8-SW4-FL2-R1-CBS350-48P','PSZ253419KM','a4:9b:cd:7f:3f:be','192.168.40.107','172.16.60.28',NULL,'3.3.0.16',NULL,NULL,NULL,NULL,'admin',NULL,0,NULL,'Active',NULL,NULL,NULL,'2026-06-22 06:59:08','2026-09-02 01:44:50'),(9,'NET-SW-009','Switch',NULL,'Building 8',NULL,'Cisco','CBS250-24FP-4G V01','B8-SW1R2-CBS250-24FP','FOC2518L3YU','f8:7a:41:f0:95:6e','192.168.40.160','172.16.61.160',NULL,'3.0.0.69',NULL,NULL,NULL,NULL,'admin',NULL,0,NULL,'Active',NULL,NULL,NULL,'2026-06-22 06:59:08','2026-06-22 06:59:08'),(10,'NET-SW-010','Switch',NULL,'Building 8',NULL,'Cisco','CBS250-24FP-4G V01','B8-SW2R2-CBS250-24FP','FOC2518L3S7','f8:7a:41:f0:e1:25','192.168.40.161','172.16.61.161',NULL,'3.0.0.69',NULL,NULL,NULL,NULL,'admin',NULL,0,NULL,'Active',NULL,NULL,NULL,'2026-06-22 06:59:08','2026-06-22 06:59:08'),(11,'NET-SW-011','Switch',NULL,'Building 8',NULL,'Cisco','CBS250-24FP-4G V01','B8-SW3R2-CBS250-24FP','FOC2518L3SL','f8:7a:41:f0:de:14','192.168.40.162','172.16.61.162',NULL,'3.0.0.69',NULL,NULL,NULL,NULL,'admin',NULL,0,NULL,'Active',NULL,NULL,NULL,'2026-06-22 06:59:08','2026-06-22 06:59:08'),(12,'NET-SW-012','Switch',2,'Building 8',NULL,'Cisco','CBS250-24FP-4G V01','B8-SW4R2-CBS250-24FP','FOC2518L3XS','f8:7a:41:f1:8d:65','192.168.40.163','172.16.61.163',NULL,'3.0.0.69',NULL,NULL,NULL,NULL,'admin',NULL,0,NULL,'Active',NULL,NULL,NULL,'2026-06-22 06:59:08','2026-07-13 08:45:02'),(13,'NET-SW-013','Switch',2,'Building 9',NULL,'Cisco','CBS350-48P-4G V03','B9-SW1-CBS350-48P',NULL,NULL,'192.168.40.109','172.16.61.30',NULL,'3.3.0.16',NULL,NULL,NULL,NULL,'admin',NULL,0,NULL,'Active',NULL,NULL,NULL,'2026-06-22 06:59:08','2026-07-13 08:38:56'),(14,'NET-SW-014','Switch',NULL,'Building 9',NULL,'Cisco','CBS250-24FP-4G V01','B9-SW1R1-CBS250-24FP',NULL,NULL,'192.168.40.165','172.16.61.165',NULL,'3.0.0.69',NULL,NULL,NULL,NULL,'admin',NULL,0,NULL,'Active',NULL,NULL,NULL,'2026-06-22 06:59:08','2026-06-22 06:59:08'),(15,'NET-SW-015','Switch',NULL,'Building 9',NULL,'Cisco','CBS250-24FP-4G V01','B9-SW2R1-CBS250-24FP',NULL,NULL,'192.168.40.166','172.16.61.166',NULL,'3.0.0.69',NULL,NULL,NULL,NULL,'admin',NULL,0,NULL,'Active',NULL,NULL,NULL,'2026-06-22 06:59:08','2026-06-22 06:59:08'),(16,'NET-SW-016','Switch',NULL,'Building 9',NULL,'Cisco','CBS250-24FP-4G V01','B9-SW3R1-CBS250-24FP',NULL,NULL,'192.168.40.167','172.16.61.167',NULL,'3.0.0.69',NULL,NULL,NULL,NULL,'admin',NULL,0,NULL,'Active',NULL,NULL,NULL,'2026-06-22 06:59:08','2026-06-22 06:59:08'),(17,'NET-SW-017','Switch',2,'Building 6',NULL,'Cisco','CBS350-48P-4G V03','B6-SW1-CBS350-48P',NULL,NULL,'192.168.40.108','172.16.61.20',NULL,'3.3.0.16',NULL,NULL,NULL,NULL,'admin',NULL,0,NULL,'Active',NULL,NULL,NULL,'2026-06-22 06:59:08','2026-07-13 08:44:00'),(18,'NET-SW-018','Switch',2,'Building 6',NULL,'Cisco','CBS250-24FP-4G V01','B6-SW1R1-CBS250-24FP',NULL,NULL,'192.168.40.170','172.16.61.170',NULL,'3.0.0.69',NULL,NULL,NULL,NULL,'admin',NULL,0,NULL,'Active',NULL,NULL,NULL,'2026-06-22 06:59:08','2026-07-13 08:44:08'),(19,'NET-SW-019','Switch',2,'Building 6',NULL,'Cisco','CBS250-24FP-4G V01','B6-SW2R1-CBS250-24FP',NULL,NULL,'192.168.40.171','172.16.61.171',NULL,'3.0.0.69',NULL,NULL,NULL,NULL,'admin',NULL,0,NULL,'Active',NULL,NULL,NULL,'2026-06-22 06:59:08','2026-07-13 08:44:16'),(20,'NET-SW-020','Switch',2,'Building 6',NULL,'Cisco','CBS250-24FP-4G V01','B6-SW3R1-CBS250-24FP',NULL,NULL,'192.168.40.172','172.16.61.172',NULL,'3.0.0.69',NULL,NULL,NULL,NULL,'admin',NULL,0,NULL,'Active',NULL,NULL,NULL,'2026-06-22 06:59:08','2026-07-13 08:44:24'),(21,'NET-SW-021','Switch',2,'Building 7',NULL,'Cisco','CBS350-48P-4G V03','B7-SW1-CBS350-48P',NULL,NULL,'192.168.40.110','172.16.61.40',NULL,'3.3.0.16',NULL,NULL,NULL,NULL,'admin',NULL,0,NULL,'Active',NULL,NULL,NULL,'2026-06-22 06:59:08','2026-07-13 08:44:34'),(22,'NET-SW-022','Switch',2,'Building 7',NULL,'Cisco','CBS250-24FP-4G V01','B7-SW1R1-CBS250-24FP',NULL,NULL,'192.168.40.175','172.16.61.175',NULL,'3.0.0.69',NULL,NULL,NULL,NULL,'admin',NULL,0,NULL,'Active',NULL,NULL,NULL,'2026-06-22 06:59:08','2026-07-13 08:44:48'),(23,'NET-SW-023','Switch',2,'Building 7',NULL,'Cisco','CBS250-24FP-4G V01','B7-SW2R1-CBS250-24FP',NULL,NULL,'192.168.40.176','172.16.61.176',NULL,'3.0.0.69',NULL,NULL,NULL,NULL,'admin',NULL,0,NULL,'Active',NULL,NULL,NULL,'2026-06-22 06:59:08','2026-07-13 08:44:55'),(24,'NET-SW-024','Switch',2,'Building 4',NULL,'Cisco','CBS350-48P-4G V03','B4-SW1-CBS350-48P',NULL,NULL,'192.168.40.111','172.16.61.50',NULL,'3.3.0.16',NULL,NULL,NULL,NULL,'admin',NULL,0,NULL,'Active',NULL,NULL,NULL,'2026-06-22 06:59:08','2026-07-13 08:43:29'),(25,'NET-SW-025','Switch',2,'Building 4',NULL,'Cisco','CBS250-24FP-4G V01','B4-SW1R1-CBS250-24FP',NULL,NULL,'192.168.40.180','172.16.61.180',NULL,'3.0.0.69',NULL,NULL,NULL,NULL,'admin',NULL,0,NULL,'Active',NULL,NULL,NULL,'2026-06-22 06:59:08','2026-07-13 08:43:39'),(26,'NET-SW-026','Switch',2,'Building 4',NULL,'Cisco','CBS250-24FP-4G V01','B4-SW2R1-CBS250-24FP',NULL,NULL,'192.168.40.181','172.16.61.181',NULL,'3.0.0.69',NULL,NULL,NULL,NULL,'admin',NULL,0,NULL,'Active',NULL,NULL,NULL,'2026-06-22 06:59:08','2026-07-13 08:43:03'),(27,'NET-SW-027','Switch',2,'Building 3',NULL,'Cisco','CBS350-48P-4G V03','B3-SW1-CBS350-48P',NULL,NULL,'192.168.40.112','172.16.61.60',NULL,'3.3.0.16',NULL,NULL,NULL,NULL,'admin',NULL,0,NULL,'Active',NULL,NULL,NULL,'2026-06-22 06:59:08','2026-07-13 08:42:55'),(28,'NET-SW-028','Switch',2,'Building 3',NULL,'Cisco','CBS250-24FP-4G V01','B3-SW1R1-CBS250-24FP',NULL,NULL,'192.168.40.185','172.16.61.185',NULL,'3.0.0.69',NULL,NULL,NULL,NULL,'admin',NULL,0,NULL,'Active',NULL,NULL,NULL,'2026-06-22 06:59:08','2026-07-13 08:43:13'),(29,'NET-SW-029','Switch',2,'Building 3',NULL,'Cisco','CBS250-24FP-4G V01','B3-SW2R1-CBS250-24FP',NULL,NULL,'192.168.40.186','172.16.61.186',NULL,'3.0.0.69',NULL,NULL,NULL,NULL,'admin',NULL,0,NULL,'Active',NULL,NULL,NULL,'2026-06-22 06:59:08','2026-07-13 08:43:22'),(30,'NET-SW-030','Switch',2,'Building 2',NULL,'Cisco','CBS350-48P-4G V03','B2-SW1-CBS350-48P',NULL,NULL,'192.168.40.113','172.16.61.70',NULL,'3.3.0.16',NULL,NULL,NULL,NULL,'admin',NULL,0,NULL,'Active',NULL,NULL,NULL,'2026-06-22 06:59:08','2026-07-13 08:42:29'),(31,'NET-SW-031','Switch',2,'Building 2',NULL,'Cisco','CBS250-24FP-4G V01','B2-SW1R1-CBS250-24FP',NULL,NULL,'192.168.40.190','172.16.61.190',NULL,'3.0.0.69',NULL,NULL,NULL,NULL,'admin',NULL,0,NULL,'Active',NULL,NULL,NULL,'2026-06-22 06:59:08','2026-07-13 08:42:41'),(32,'NET-SW-032','Switch',2,'Building 2',NULL,'Cisco','CBS250-24FP-4G V01','B2-SW2R1-CBS250-24FP',NULL,NULL,'192.168.40.191','172.16.61.191',NULL,'3.0.0.69',NULL,NULL,NULL,NULL,'admin',NULL,0,NULL,'Active',NULL,NULL,NULL,'2026-06-22 06:59:08','2026-07-13 08:42:49'),(33,'NET-SW-033','Switch',2,'Building 1',NULL,'Cisco','CBS350-48P-4G V03','B1-SW1-CBS350-48P',NULL,NULL,'192.168.40.114','172.16.61.80',NULL,'3.3.0.16',NULL,NULL,NULL,NULL,'admin',NULL,0,NULL,'Active',NULL,NULL,NULL,'2026-06-22 06:59:08','2026-07-13 08:40:06'),(34,'NET-SW-034','Switch',2,'Building 1',NULL,'Cisco','CBS250-24FP-4G V01','B1-SW1R1-CBS250-24FP',NULL,NULL,'192.168.40.195','172.16.61.195',NULL,'3.0.0.69',NULL,NULL,NULL,NULL,'admin',NULL,0,NULL,'Active',NULL,NULL,NULL,'2026-06-22 06:59:08','2026-07-13 08:41:34'),(35,'NET-SW-035','Switch',2,'Building 1',NULL,'Cisco','CBS250-24FP-4G V01','B1-SW2R1-CBS250-24FP',NULL,NULL,'192.168.40.196','172.16.61.196',NULL,'3.0.0.69',NULL,NULL,NULL,NULL,'admin',NULL,0,NULL,'Active',NULL,NULL,NULL,'2026-06-22 06:59:08','2026-07-13 08:41:43'),(36,'NET-SW-036','Switch',2,'Building 0',NULL,'Cisco','CBS350-48P-4G V03','B0-SW1-CBS350-48P',NULL,NULL,'192.168.40.115','172.16.61.90',NULL,'3.3.0.16',NULL,NULL,NULL,NULL,'admin',NULL,0,NULL,'Active',NULL,NULL,NULL,'2026-06-22 06:59:08','2026-07-13 08:39:07'),(37,'NET-SW-037','Switch',2,'Building 0',NULL,'Cisco','CBS250-24FP-4G V01','B0-SW1R1-CBS250-24FP',NULL,NULL,'192.168.40.200','172.16.61.200',NULL,'3.0.0.69',NULL,NULL,NULL,NULL,'admin',NULL,0,NULL,'Active',NULL,NULL,NULL,'2026-06-22 06:59:08','2026-07-13 08:39:15'),(38,'NET-SW-038','Switch',2,'Building 0',NULL,'Cisco','CBS250-24FP-4G V01','B0-SW2R1-CBS250-24FP',NULL,NULL,'192.168.40.201','172.16.61.201',NULL,'3.0.0.69',NULL,NULL,NULL,NULL,'admin',NULL,0,NULL,'Active',NULL,NULL,NULL,'2026-06-22 06:59:08','2026-07-13 08:39:47'),(39,'NET-SW-039','Switch',NULL,'CS Office',NULL,'Cisco','CBS250-24FP-4G V01','CS-SW1-CBS250-24FP',NULL,NULL,'192.168.40.120','172.16.61.120',NULL,'3.0.0.69',NULL,NULL,NULL,NULL,'admin',NULL,0,NULL,'Active',NULL,NULL,NULL,'2026-06-22 06:59:08','2026-06-22 06:59:08'),(40,'NET-SW-040','Switch',2,'Guardhouse',NULL,'Cisco','CBS250-8FP-E-2G V01','GH-SW1-CBS250-8FP',NULL,NULL,'192.168.40.121','172.16.61.121',NULL,'3.0.0.69',NULL,NULL,NULL,NULL,'admin',NULL,0,NULL,'Active',NULL,NULL,NULL,'2026-06-22 06:59:08','2026-07-13 08:41:57'),(41,'NET-SW-041','Switch',NULL,'Scale B8',NULL,'Cisco','CBS250-8FP-E-2G V01','ScaleB8-SW1',NULL,NULL,'192.168.40.122',NULL,NULL,NULL,NULL,NULL,NULL,NULL,'admin',NULL,0,NULL,'Active',NULL,NULL,NULL,'2026-06-22 06:59:08','2026-06-22 06:59:08'),(42,'NET-SW-042','Switch',NULL,'Scale B0',NULL,'Cisco','CBS250-8FP-E-2G V01','ScaleB0-SW1',NULL,NULL,'192.168.40.123',NULL,NULL,NULL,NULL,NULL,NULL,NULL,'admin',NULL,0,NULL,'Active',NULL,NULL,NULL,'2026-06-22 06:59:08','2026-06-22 06:59:08'),(43,'NET-SW-043','Switch',NULL,'Building 8',NULL,'Cisco','CBS350-24T-4G','B8-CoreSW-CBS350-24T',NULL,NULL,'192.168.40.124','172.16.60.31',NULL,'3.3.0.16',NULL,NULL,NULL,NULL,'admin',NULL,0,NULL,'Active',NULL,NULL,NULL,'2026-06-22 06:59:08','2026-06-22 06:59:08'),(44,'NET-SW-044','Switch',NULL,'Building 8',NULL,'Cisco','SG250-26P-K9 V04','B8-SW6-SG250-26P',NULL,NULL,'192.168.40.125','172.16.60.29',NULL,'2.5.5.47',NULL,NULL,NULL,NULL,'admin',NULL,0,NULL,'Active',NULL,NULL,NULL,'2026-06-22 06:59:08','2026-06-22 06:59:08'),(45,'Router01','Router SIM',2,NULL,NULL,'TP Link',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'AIS','0885669554','บริษัท',NULL,NULL,1,NULL,'Active',NULL,NULL,NULL,'2026-06-22 09:10:41','2026-07-06 09:57:48'),(46,'PCS-SIM3','Router SIM',2,'อาคาร 8',NULL,'D-Link','DWR-953V2',NULL,'TL2F121000326',NULL,NULL,NULL,NULL,NULL,'2026-07-14','AIS','0631364904','บริษัท','admin','Pcs@1234',1,NULL,'Active','2026-07-14',NULL,NULL,'2026-07-14 08:32:15','2026-07-14 08:33:01'),(47,'NET-SW-045','Switch',2,'Building 8',NULL,'Ubiquiti',NULL,'US 8 PoE 150W',NULL,'24:5a:4c:97:6a:95',NULL,'172.16.60.41',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,0,NULL,'Active',NULL,NULL,'Unifi Control','2026-09-02 01:40:05','2026-09-02 01:44:08');
/*!40000 ALTER TABLE `network_assets` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `pm_check_results`
--

DROP TABLE IF EXISTS `pm_check_results`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `pm_check_results` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `maintenance_log_id` int(11) NOT NULL,
  `template_item_id` int(11) NOT NULL,
  `result` enum('Pass','Fail','NA') NOT NULL DEFAULT 'Pass',
  `remark` varchar(255) DEFAULT NULL,
  `is_auto_na` tinyint(1) NOT NULL DEFAULT 0 COMMENT '1 = ระบบ auto NA ให้ตาม frequency ไม่ตรงรอบ, 0 = ช่างเลือกผลเอง',
  PRIMARY KEY (`id`),
  KEY `idx_log` (`maintenance_log_id`),
  KEY `idx_item` (`template_item_id`),
  CONSTRAINT `fk_result_item` FOREIGN KEY (`template_item_id`) REFERENCES `pm_template_items` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=93 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='ผลตรวจเช็ครายข้อต่อ maintenance log';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `pm_check_results`
--

LOCK TABLES `pm_check_results` WRITE;
/*!40000 ALTER TABLE `pm_check_results` DISABLE KEYS */;
INSERT INTO `pm_check_results` VALUES (1,6,101,'Pass',NULL,0),(2,6,102,'Pass',NULL,0),(3,6,103,'Pass',NULL,0),(4,6,104,'Pass',NULL,0),(5,6,105,'Pass',NULL,0),(6,6,106,'Pass',NULL,0),(7,6,107,'NA',NULL,0),(8,6,108,'NA',NULL,0),(9,6,109,'NA',NULL,0),(10,6,110,'NA',NULL,0),(11,6,111,'NA',NULL,0),(12,6,112,'NA',NULL,0),(13,6,113,'Pass',NULL,0),(14,6,114,'Pass','ยกเลิก',0),(15,6,115,'Pass',NULL,0),(16,7,101,'Pass',NULL,0),(17,7,102,'Pass',NULL,0),(18,7,103,'Pass',NULL,0),(19,7,104,'Pass',NULL,0),(20,7,105,'Pass',NULL,0),(21,7,106,'Pass',NULL,0),(22,7,107,'Pass',NULL,0),(23,7,108,'Pass',NULL,0),(24,7,109,'Pass',NULL,0),(25,7,110,'Pass',NULL,0),(26,7,111,'Pass',NULL,0),(27,7,112,'Pass',NULL,0),(28,7,113,'Pass',NULL,0),(29,7,114,'Pass',NULL,0),(30,7,115,'Pass',NULL,0),(31,8,414,'Pass',NULL,0),(32,8,415,'Pass',NULL,0),(33,1,101,'Pass',NULL,0),(34,1,102,'Pass',NULL,0),(35,1,103,'Pass',NULL,0),(36,1,104,'Pass',NULL,0),(37,1,105,'Pass',NULL,0),(38,1,106,'Pass',NULL,0),(39,1,107,'Pass',NULL,0),(40,1,108,'Pass',NULL,0),(41,1,109,'Pass',NULL,0),(42,1,110,'Pass',NULL,0),(43,1,111,'Pass',NULL,0),(44,1,112,'Pass',NULL,0),(45,1,113,'Pass',NULL,0),(46,1,114,'Pass',NULL,0),(47,1,115,'Pass',NULL,0),(48,2,101,'NA',NULL,0),(49,2,102,'NA',NULL,0),(50,2,103,'NA',NULL,0),(51,2,104,'NA',NULL,0),(52,2,105,'NA',NULL,0),(53,2,106,'NA',NULL,0),(54,2,107,'NA',NULL,0),(55,2,108,'NA',NULL,0),(56,2,109,'NA',NULL,0),(57,2,110,'NA',NULL,0),(58,2,111,'NA',NULL,0),(59,2,112,'NA',NULL,0),(60,2,113,'NA',NULL,0),(61,2,114,'NA',NULL,0),(62,2,115,'NA',NULL,0),(63,3,101,'NA',NULL,0),(64,3,102,'NA',NULL,0),(65,3,103,'NA',NULL,0),(66,3,104,'NA',NULL,0),(67,3,105,'NA',NULL,0),(68,3,106,'NA',NULL,0),(69,3,107,'NA',NULL,0),(70,3,108,'NA',NULL,0),(71,3,109,'NA',NULL,0),(72,3,110,'NA',NULL,0),(73,3,111,'NA',NULL,0),(74,3,112,'NA',NULL,0),(75,3,113,'NA',NULL,0),(76,3,114,'NA',NULL,0),(77,3,115,'NA',NULL,0),(78,4,101,'Pass',NULL,0),(79,4,102,'Pass',NULL,0),(80,4,103,'Pass',NULL,0),(81,4,104,'Pass',NULL,0),(82,4,105,'Pass',NULL,0),(83,4,106,'Pass',NULL,0),(84,4,107,'Pass',NULL,0),(85,4,108,'Pass',NULL,0),(86,4,109,'Pass',NULL,0),(87,4,110,'Pass',NULL,0),(88,4,111,'Pass',NULL,0),(89,4,112,'Pass',NULL,0),(90,4,113,'Pass','onedrive',0),(91,4,114,'NA','onedrive',0),(92,4,115,'Pass',NULL,0);
/*!40000 ALTER TABLE `pm_check_results` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `pm_reschedule_log`
--

DROP TABLE IF EXISTS `pm_reschedule_log`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `pm_reschedule_log` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `schedule_id` int(11) NOT NULL,
  `old_planned_date` date NOT NULL,
  `new_planned_date` date NOT NULL,
  `reason` varchar(255) DEFAULT NULL,
  `changed_by` varchar(100) DEFAULT NULL,
  `changed_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `schedule_id` (`schedule_id`),
  CONSTRAINT `pm_reschedule_log_ibfk_1` FOREIGN KEY (`schedule_id`) REFERENCES `pm_schedules` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `pm_reschedule_log`
--

LOCK TABLES `pm_reschedule_log` WRITE;
/*!40000 ALTER TABLE `pm_reschedule_log` DISABLE KEYS */;
/*!40000 ALTER TABLE `pm_reschedule_log` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `pm_schedules`
--

DROP TABLE IF EXISTS `pm_schedules`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `pm_schedules` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `asset_id` int(11) DEFAULT NULL COMMENT 'FK → hardware_assets (PC/NB/Server/Printer/UPS)',
  `mobile_id` int(11) DEFAULT NULL COMMENT 'FK → mobile_assets (Handheld)',
  `network_id` int(11) DEFAULT NULL COMMENT 'FK → network_assets (Router/Switch/Firewall)',
  `template_id` int(11) NOT NULL,
  `year` smallint(6) NOT NULL,
  `quarter` tinyint(4) NOT NULL COMMENT '1-4',
  `planned_date` date DEFAULT NULL,
  `status` enum('Planned','InProgress','Done','Skipped') NOT NULL DEFAULT 'Planned',
  `skip_reason` varchar(255) DEFAULT NULL,
  `skipped_by` varchar(50) DEFAULT NULL,
  `skipped_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_pc_date` (`asset_id`,`template_id`,`planned_date`),
  UNIQUE KEY `uq_mob_date` (`mobile_id`,`template_id`,`planned_date`),
  UNIQUE KEY `uq_net_date` (`network_id`,`template_id`,`planned_date`),
  KEY `idx_status_date` (`status`,`planned_date`),
  KEY `idx_year_quarter` (`year`,`quarter`),
  KEY `fk_sched_template` (`template_id`),
  CONSTRAINT `fk_sched_asset` FOREIGN KEY (`asset_id`) REFERENCES `hardware_assets` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_sched_mobile` FOREIGN KEY (`mobile_id`) REFERENCES `mobile_assets` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_sched_network` FOREIGN KEY (`network_id`) REFERENCES `network_assets` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_sched_template` FOREIGN KEY (`template_id`) REFERENCES `pm_templates` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=212 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='แผน PM รายไตรมาส ต่ออุปกรณ์';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `pm_schedules`
--

LOCK TABLES `pm_schedules` WRITE;
/*!40000 ALTER TABLE `pm_schedules` DISABLE KEYS */;
INSERT INTO `pm_schedules` VALUES (22,NULL,NULL,45,8,2026,3,'2026-08-01','Done',NULL,NULL,NULL,'2026-07-06 01:48:00','2026-07-06 01:55:49'),(144,190,NULL,NULL,1,2026,3,'2026-09-22','Done',NULL,NULL,NULL,'2026-09-15 08:18:37','2026-09-15 08:22:57'),(145,191,NULL,NULL,1,2026,3,'2026-09-22','Done',NULL,NULL,NULL,'2026-09-15 08:18:37','2026-09-15 08:48:37');
/*!40000 ALTER TABLE `pm_schedules` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `pm_template_items`
--

DROP TABLE IF EXISTS `pm_template_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `pm_template_items` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `template_id` int(11) NOT NULL,
  `section` varchar(50) NOT NULL COMMENT 'หมวด: Hardware / Software / Data & Security / UPS Battery',
  `item_text` varchar(255) NOT NULL COMMENT 'รายการตรวจสอบ',
  `is_critical` tinyint(1) NOT NULL DEFAULT 0 COMMENT '1 = ต้องผ่านก่อนปิด PM',
  `frequency` varchar(30) NOT NULL DEFAULT 'ทุกรอบ' COMMENT 'ทุกรอบ / Q เว้น Q / ปีละครั้ง',
  `sort_order` smallint(6) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_template` (`template_id`),
  CONSTRAINT `fk_item_template` FOREIGN KEY (`template_id`) REFERENCES `pm_templates` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=416 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='รายการตรวจเช็คในแต่ละ PM template';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `pm_template_items`
--

LOCK TABLES `pm_template_items` WRITE;
/*!40000 ALTER TABLE `pm_template_items` DISABLE KEYS */;
INSERT INTO `pm_template_items` VALUES (101,1,'Hardware','ทำความสะอาดตัวเครื่อง จอ แป้นพิมพ์ เมาส์',0,'ทุกรอบ',1),(102,1,'Hardware','ตรวจสภาพสายไฟ สายเชื่อมต่อ และหัวต่อ',0,'ทุกรอบ',2),(103,1,'Hardware','เป่าฝุ่นพัดลม CPU และช่องระบายอากาศ',0,'ทุกรอบ',3),(104,1,'Hardware','ตรวจ Event Viewer (Windows Logs → Error / Critical)',0,'ทุกรอบ',4),(105,1,'Hardware','ตรวจ Disk Health ด้วย CrystalDiskInfo หรือ SMART Status',1,'ทุกรอบ',5),(106,1,'Hardware','Defragment HDD เฉพาะ HDD เท่านั้น ห้ามทำกับ SSD',0,'Q เว้น Q',6),(107,1,'Software','ตรวจ Bitdefender ว่า Active และ Definition อัปเดตล่าสุด',1,'ทุกรอบ',7),(108,1,'Software','ตรวจ Windows Update ไม่มี Critical update ค้าง',0,'ทุกรอบ',8),(109,1,'Software','ตรวจ Microsoft Office ว่า Activate ปกติ',0,'ทุกรอบ',9),(110,1,'Software','ทดสอบ TightVNC / RustDesk เชื่อมต่อได้ปกติ',0,'ทุกรอบ',10),(111,1,'Software','ทดสอบ Network: Ping Gateway + Internet Speed',0,'ทุกรอบ',11),(112,1,'Software','ตรวจ CPU และ Storage Temperature ด้วย HWiNFO หรือ Speccy',0,'ทุกรอบ',12),(113,1,'Data & Security','ยืนยัน Backup ล่าสุดทำงานสำเร็จ (ถ้ามี policy)',0,'ทุกรอบ',13),(114,1,'Data & Security','ตรวจ Shared Folder / Drive Mapping ใช้งานได้',0,'ทุกรอบ',14),(115,1,'Data & Security','แนะนำผู้ใช้เปลี่ยน Password ถ้าเกิน 90 วัน',0,'ปีละครั้ง',15),(201,2,'Hardware','ทำความสะอาดภายนอก และช่องป้อนกระดาษ',0,'ทุกรอบ',1),(202,2,'Hardware','ตรวจช่องป้อนกระดาษ ไม่มีกระดาษค้างหรือติด',0,'ทุกรอบ',2),(203,2,'Hardware','ตรวจสายไฟ สาย USB/LAN และหัวต่อ',0,'ทุกรอบ',3),(204,2,'Hardware','ตรวจสภาพ Drum / Roller ถ้าเข้าถึงได้',0,'ทุกรอบ',4),(205,2,'Consumables','ตรวจระดับ Toner / Ink แจ้งถ้าเหลือน้อยกว่า 20%',0,'ทุกรอบ',5),(206,2,'Consumables','พิมพ์ Test Page ตรวจคุณภาพงานพิมพ์',0,'ทุกรอบ',6),(207,2,'Network','ตรวจ IP Address สั่งพิมพ์จาก Client ปกติ',0,'ทุกรอบ',7),(208,2,'Network','ตรวจ Driver Version อัปเดตถ้ามี version ใหม่',0,'ปีละครั้ง',8),(301,3,'Hardware','ทำความสะอาดตัวเครื่อง และช่องระบายอากาศ',0,'ทุกรอบ',1),(302,3,'Hardware','ตรวจสายไฟ Input/Output และ Ground',1,'ทุกรอบ',2),(303,3,'Hardware','ตรวจ LED Status ไม่มี Error หรือ Alarm',1,'ทุกรอบ',3),(304,3,'Battery','ทดสอบสำรองไฟ: ถอด Input แล้วจับเวลา Runtime',1,'ทุกรอบ',4),(305,3,'Battery','ตรวจอายุแบตเตอรี่ แนะนำเปลี่ยนทุก 3 ปี',0,'ทุกรอบ',5),(306,3,'Battery','ตรวจ Capacity % จาก Software ของ UPS',0,'ทุกรอบ',6),(307,3,'Load','ตรวจ Load % ควรไม่เกิน 80% ของ Capacity',0,'ทุกรอบ',7),(308,3,'Load','ยืนยัน Runtime ≥ ที่กำหนดตาม SLA ของ site',0,'ทุกรอบ',8),(401,4,'Hardware','ทำความสะอาดตัวเครื่อง หน้าจอ และ Barcode Window',0,'ทุกรอบ',1),(402,4,'Hardware','ตรวจสภาพตัวเครื่อง ไม่มีรอยร้าว จอไม่แตก',0,'ทุกรอบ',2),(403,4,'Hardware','ตรวจแบตเตอรี่ ชาร์จได้ปกติ ไม่บวม',0,'ทุกรอบ',3),(404,4,'Hardware','ทดสอบ Barcode Scanner อ่านได้ถูกต้อง ≥ 95%',1,'ทุกรอบ',4),(405,4,'Software','ทดสอบเปิดโปรแกรม CCMS / WMS ได้ปกติ',1,'ทุกรอบ',5),(406,4,'Software','ตรวจ WiFi เชื่อมต่อ AP ที่ถูกต้อง Signal แรงพอ',0,'ทุกรอบ',6),(407,4,'Software','ตรวจ Data Sync ข้อมูล sync กับ Server ได้',0,'ทุกรอบ',7),(408,4,'Software','ตรวจ App Version อัปเดตถ้ามี version ใหม่',0,'ทุกรอบ',8),(414,8,'อุปกรณ์','ตรวจสอบตัวเครื่อง',0,'ทุกรอบ',1),(415,8,'Net SIM','ควรเร็วคงเหลือ พร้อมใช้งาน',0,'ทุกรอบ',2);
/*!40000 ALTER TABLE `pm_template_items` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `pm_templates`
--

DROP TABLE IF EXISTS `pm_templates`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `pm_templates` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL COMMENT 'เช่น PM PC/Notebook รายไตรมาส',
  `applies_to` varchar(50) NOT NULL COMMENT 'category ที่ใช้: PC,Notebook,Printer,UPS,Handheld',
  `interval_days` smallint(5) unsigned NOT NULL DEFAULT 30 COMMENT 'จำนวนวันระหว่างรอบ PM ถัดไป นับจากวันที่ทำ PM ล่าสุดจริง',
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_applies` (`applies_to`)
) ENGINE=InnoDB AUTO_INCREMENT=10 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Template checklist PM แต่ละประเภทอุปกรณ์';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `pm_templates`
--

LOCK TABLES `pm_templates` WRITE;
/*!40000 ALTER TABLE `pm_templates` DISABLE KEYS */;
INSERT INTO `pm_templates` VALUES (1,'PM PC / Notebook รายไตรมาส','PC,Notebook,Surface',30,1,'2026-06-21 04:50:33'),(2,'PM Printer รายไตรมาส','Printer,Scanner',30,1,'2026-06-21 04:50:33'),(3,'PM UPS รายไตรมาส','UPS',30,1,'2026-06-21 04:50:33'),(4,'PM Handheld / Tablet รายไตรมาส','Handheld,Tablet,Barcode Reader,Barcode Scanner',30,1,'2026-06-21 04:50:33'),(8,'Router SIM','Router SIM',30,1,'2026-07-04 08:40:17'),(9,'60D','Mobile',30,1,'2026-09-15 08:19:56');
/*!40000 ALTER TABLE `pm_templates` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `sites`
--

DROP TABLE IF EXISTS `sites`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `sites` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `site_name` varchar(100) NOT NULL,
  `site_type` enum('Warehouse','Office','Data Center','Logistics Hub') DEFAULT 'Warehouse',
  `address` text DEFAULT NULL,
  `contact_person` varchar(100) DEFAULT NULL,
  `contact_phone` varchar(50) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_site_name` (`site_name`)
) ENGINE=InnoDB AUTO_INCREMENT=10 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `sites`
--

LOCK TABLES `sites` WRITE;
/*!40000 ALTER TABLE `sites` DISABLE KEYS */;
INSERT INTO `sites` VALUES (1,'PACR','Warehouse','Rangsit',NULL,NULL,'2026-06-19 16:30:29'),(2,'PCS','Warehouse','Mahachai','Anucha','02-000-0002','2026-06-19 16:30:29'),(3,'PACS','Warehouse','Saraburi',NULL,NULL,'2026-06-19 16:30:29'),(4,'PACA','Warehouse','Bangna KM.22',NULL,NULL,'2026-06-19 16:30:29'),(5,'PACM','Warehouse',NULL,NULL,NULL,'2026-07-13 04:33:19'),(6,'PACJ','Warehouse',NULL,NULL,NULL,'2026-07-13 04:33:38'),(7,'PLP | BPD','Logistics Hub',NULL,NULL,NULL,'2026-07-13 04:34:13'),(8,'PACK','Warehouse',NULL,NULL,NULL,'2026-07-13 04:34:20'),(9,'PACT','Warehouse','Mahachai',NULL,NULL,'2026-07-13 04:34:46');
/*!40000 ALTER TABLE `sites` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `software_allocation_map`
--

DROP TABLE IF EXISTS `software_allocation_map`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `software_allocation_map` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `software_id` int(11) NOT NULL COMMENT 'FK -> software_licenses.id',
  `asset_id` int(11) DEFAULT NULL COMMENT 'FK -> hardware_assets.id (อาจ NULL ถ้าให้ user ตรงๆ)',
  `assigned_user_ad` varchar(100) DEFAULT NULL COMMENT 'AD username ของผู้รับการ allocate',
  `assigned_employee_id` int(11) DEFAULT NULL,
  `install_date` date DEFAULT NULL,
  `uninstall_date` date DEFAULT NULL,
  `status` enum('Installed','Uninstalled') DEFAULT 'Installed',
  `notes` text DEFAULT NULL,
  `created_by` varchar(100) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_alloc_status` (`status`),
  KEY `fk_alloc_software` (`software_id`),
  KEY `fk_alloc_asset` (`asset_id`),
  CONSTRAINT `fk_alloc_asset` FOREIGN KEY (`asset_id`) REFERENCES `hardware_assets` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_alloc_software` FOREIGN KEY (`software_id`) REFERENCES `software_licenses` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `software_allocation_map`
--

LOCK TABLES `software_allocation_map` WRITE;
/*!40000 ALTER TABLE `software_allocation_map` DISABLE KEYS */;
/*!40000 ALTER TABLE `software_allocation_map` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `software_license_renewals`
--

DROP TABLE IF EXISTS `software_license_renewals`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `software_license_renewals` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `software_id` int(11) NOT NULL,
  `renewal_date` date NOT NULL,
  `previous_expiry` date DEFAULT NULL,
  `new_expiry` date NOT NULL,
  `cost` decimal(12,2) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `software_id` (`software_id`),
  CONSTRAINT `software_license_renewals_ibfk_1` FOREIGN KEY (`software_id`) REFERENCES `software_licenses` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `software_license_renewals`
--

LOCK TABLES `software_license_renewals` WRITE;
/*!40000 ALTER TABLE `software_license_renewals` DISABLE KEYS */;
/*!40000 ALTER TABLE `software_license_renewals` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `software_licenses`
--

DROP TABLE IF EXISTS `software_licenses`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `software_licenses` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `software_id` varchar(50) NOT NULL COMMENT 'รหัสภายใน เช่น SW-MS365-001',
  `software_name` varchar(150) NOT NULL,
  `publisher` varchar(100) DEFAULT NULL,
  `license_type` varchar(50) DEFAULT NULL COMMENT 'Perpetual / Subscription / OEM ...',
  `license_key` text DEFAULT NULL COMMENT 'ควรเก็บแบบเข้ารหัสในระดับ application',
  `total_seats` int(11) DEFAULT 1 COMMENT 'จำนวน seat ที่ซื้อมา',
  `cost` decimal(12,2) DEFAULT NULL,
  `purchase_date` date DEFAULT NULL,
  `expiry_date` date DEFAULT NULL,
  `renewal_reminder_days` int(11) DEFAULT 30,
  `vendor` varchar(100) DEFAULT NULL,
  `site_id` int(11) DEFAULT NULL COMMENT 'Site ที่ license นี้ผูกอยู่ (NULL = ส่วนกลาง/ไม่ระบุ)',
  `notes` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `software_id` (`software_id`),
  KEY `idx_expiry` (`expiry_date`),
  KEY `idx_site` (`site_id`),
  CONSTRAINT `fk_software_licenses_site` FOREIGN KEY (`site_id`) REFERENCES `sites` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `software_licenses`
--

LOCK TABLES `software_licenses` WRITE;
/*!40000 ALTER TABLE `software_licenses` DISABLE KEYS */;
INSERT INTO `software_licenses` VALUES (1,'SW-M365-001','Microsoft 365 Business Standard','Microsoft','Subscription','XXXX-XXXX-XXXX-AAAA',50,NULL,'2024-01-01','2026-12-31',30,'Ingram Micro',NULL,NULL,'2026-06-19 16:30:29','2026-06-19 16:30:29'),(3,'SW-WIN-001','Windows 11 Pro','Microsoft','OEM','XXXX-XXXX-XXXX-CCCC',30,NULL,'2024-02-01',NULL,30,'Lenovo Bundle',NULL,NULL,'2026-06-19 16:30:29','2026-06-19 16:30:29'),(6,'sw-google-01','Google WorkSpace Standrad','Google','Subscription',NULL,5,18900.00,'2026-06-30','2027-06-30',30,NULL,2,NULL,'2026-07-03 09:50:22','2026-07-13 07:11:01'),(7,'SW-WINSVR-001','Windows Server 2022',NULL,NULL,NULL,1,NULL,NULL,NULL,30,NULL,2,NULL,'2026-07-13 07:20:54','2026-07-13 07:20:54');
/*!40000 ALTER TABLE `software_licenses` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `user_employee_map`
--

DROP TABLE IF EXISTS `user_employee_map`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `user_employee_map` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `auth_user_id` int(11) NOT NULL,
  `employee_id` int(11) NOT NULL,
  `link_method` enum('Auto-Matched','Self-Linked','Manual') NOT NULL DEFAULT 'Auto-Matched',
  `linked_by_ad` varchar(100) DEFAULT NULL,
  `linked_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_auth_user` (`auth_user_id`),
  UNIQUE KEY `uq_employee` (`employee_id`)
) ENGINE=InnoDB AUTO_INCREMENT=132 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `user_employee_map`
--

LOCK TABLES `user_employee_map` WRITE;
/*!40000 ALTER TABLE `user_employee_map` DISABLE KEYS */;
INSERT INTO `user_employee_map` VALUES (1,124,4,'Auto-Matched',NULL,'2026-08-29 07:59:56'),(2,29,7,'Auto-Matched',NULL,'2026-08-29 07:59:56'),(3,119,9,'Auto-Matched',NULL,'2026-08-29 07:59:56'),(4,120,10,'Auto-Matched',NULL,'2026-08-29 07:59:56'),(5,30,12,'Auto-Matched',NULL,'2026-08-29 07:59:56'),(6,31,13,'Auto-Matched',NULL,'2026-08-29 07:59:56'),(7,32,15,'Auto-Matched',NULL,'2026-08-29 07:59:56'),(8,33,20,'Auto-Matched',NULL,'2026-08-29 07:59:56'),(9,34,24,'Auto-Matched',NULL,'2026-08-29 07:59:56'),(10,109,27,'Auto-Matched',NULL,'2026-08-29 07:59:56'),(11,35,30,'Auto-Matched',NULL,'2026-08-29 07:59:56'),(12,36,32,'Auto-Matched',NULL,'2026-08-29 07:59:56'),(13,37,37,'Auto-Matched',NULL,'2026-08-29 07:59:56'),(14,116,40,'Auto-Matched',NULL,'2026-08-29 07:59:56'),(15,38,42,'Auto-Matched',NULL,'2026-08-29 07:59:56'),(16,39,46,'Auto-Matched',NULL,'2026-08-29 07:59:56'),(17,128,51,'Auto-Matched',NULL,'2026-08-29 07:59:56'),(18,134,53,'Auto-Matched',NULL,'2026-08-29 07:59:56'),(19,40,57,'Auto-Matched',NULL,'2026-08-29 07:59:56'),(20,41,59,'Auto-Matched',NULL,'2026-08-29 07:59:56'),(21,114,72,'Auto-Matched',NULL,'2026-08-29 07:59:56'),(22,122,78,'Auto-Matched',NULL,'2026-08-29 07:59:56'),(23,42,79,'Auto-Matched',NULL,'2026-08-29 07:59:56'),(24,118,81,'Auto-Matched',NULL,'2026-08-29 07:59:56'),(25,43,84,'Auto-Matched',NULL,'2026-08-29 07:59:56'),(26,44,85,'Auto-Matched',NULL,'2026-08-29 07:59:56'),(27,111,94,'Auto-Matched',NULL,'2026-08-29 07:59:56'),(28,110,97,'Auto-Matched',NULL,'2026-08-29 07:59:56'),(29,136,100,'Auto-Matched',NULL,'2026-08-29 07:59:56'),(30,131,102,'Auto-Matched',NULL,'2026-08-29 07:59:56'),(31,45,114,'Auto-Matched',NULL,'2026-08-29 07:59:56'),(32,46,115,'Auto-Matched',NULL,'2026-08-29 07:59:56'),(33,127,116,'Auto-Matched',NULL,'2026-08-29 07:59:56'),(34,129,119,'Auto-Matched',NULL,'2026-08-29 07:59:56'),(35,93,121,'Auto-Matched',NULL,'2026-08-29 07:59:56'),(36,125,122,'Auto-Matched',NULL,'2026-08-29 07:59:56'),(37,47,128,'Auto-Matched',NULL,'2026-08-29 07:59:56'),(38,95,133,'Auto-Matched',NULL,'2026-08-29 07:59:56'),(39,48,135,'Auto-Matched',NULL,'2026-08-29 07:59:56'),(40,115,137,'Auto-Matched',NULL,'2026-08-29 07:59:56'),(41,51,140,'Auto-Matched',NULL,'2026-08-29 07:59:56'),(42,52,141,'Auto-Matched',NULL,'2026-08-29 07:59:56'),(43,53,151,'Auto-Matched',NULL,'2026-08-29 07:59:56'),(44,54,152,'Auto-Matched',NULL,'2026-08-29 07:59:56'),(45,117,155,'Auto-Matched',NULL,'2026-08-29 07:59:56'),(46,121,156,'Auto-Matched',NULL,'2026-08-29 07:59:56'),(47,132,158,'Auto-Matched',NULL,'2026-08-29 07:59:56'),(48,55,159,'Auto-Matched',NULL,'2026-08-29 07:59:56'),(49,56,168,'Auto-Matched',NULL,'2026-08-29 07:59:56'),(50,57,170,'Auto-Matched',NULL,'2026-08-29 07:59:56'),(51,130,175,'Auto-Matched',NULL,'2026-08-29 07:59:56'),(52,58,197,'Auto-Matched',NULL,'2026-08-29 07:59:56'),(53,59,212,'Auto-Matched',NULL,'2026-08-29 07:59:56'),(54,60,216,'Auto-Matched',NULL,'2026-08-29 07:59:56'),(55,61,227,'Auto-Matched',NULL,'2026-08-29 07:59:56'),(56,62,229,'Auto-Matched',NULL,'2026-08-29 07:59:56'),(57,63,230,'Auto-Matched',NULL,'2026-08-29 07:59:56'),(58,64,233,'Auto-Matched',NULL,'2026-08-29 07:59:56'),(59,65,238,'Auto-Matched',NULL,'2026-08-29 07:59:56'),(60,67,260,'Auto-Matched',NULL,'2026-08-29 07:59:56'),(61,68,264,'Auto-Matched',NULL,'2026-08-29 07:59:56'),(62,123,265,'Auto-Matched',NULL,'2026-08-29 07:59:56'),(63,70,270,'Auto-Matched',NULL,'2026-08-29 07:59:56'),(64,72,272,'Auto-Matched',NULL,'2026-08-29 07:59:56'),(65,73,273,'Auto-Matched',NULL,'2026-08-29 07:59:56'),(66,75,282,'Auto-Matched',NULL,'2026-08-29 07:59:56'),(67,76,286,'Auto-Matched',NULL,'2026-08-29 07:59:56'),(68,77,296,'Auto-Matched',NULL,'2026-08-29 07:59:56'),(69,78,313,'Auto-Matched',NULL,'2026-08-29 07:59:56'),(70,79,316,'Auto-Matched',NULL,'2026-08-29 07:59:56'),(71,81,412,'Auto-Matched',NULL,'2026-08-29 07:59:56'),(72,94,451,'Auto-Matched',NULL,'2026-08-29 07:59:56'),(128,4,154,'Self-Linked',NULL,'2026-08-29 08:37:05'),(129,22,153,'Self-Linked',NULL,'2026-08-31 04:21:12'),(130,20,331,'Self-Linked',NULL,'2026-08-31 04:59:17'),(131,160,308,'Self-Linked',NULL,'2026-08-31 06:21:28');
/*!40000 ALTER TABLE `user_employee_map` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `user_module_access`
--

DROP TABLE IF EXISTS `user_module_access`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `user_module_access` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `auth_user_id` int(11) NOT NULL,
  `module_code` varchar(30) NOT NULL,
  `granted_by_ad` varchar(100) DEFAULT NULL,
  `granted_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_user_module` (`auth_user_id`,`module_code`)
) ENGINE=InnoDB AUTO_INCREMENT=53 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `user_module_access`
--

LOCK TABLES `user_module_access` WRITE;
/*!40000 ALTER TABLE `user_module_access` DISABLE KEYS */;
INSERT INTO `user_module_access` VALUES (27,20,'DASHBOARD','anucha','2026-09-02 08:25:15'),(28,20,'ASSETS','anucha','2026-09-02 08:25:15'),(29,20,'MOBILE','anucha','2026-09-02 08:25:15'),(30,20,'SOFTWARE','anucha','2026-09-02 08:25:15'),(31,20,'LOANS','anucha','2026-09-02 08:25:15'),(32,20,'MAINTENANCE','anucha','2026-09-02 08:25:15'),(33,20,'NETWORK','anucha','2026-09-02 08:25:15'),(34,20,'ACCESS_REQUESTS','anucha','2026-09-02 08:25:15'),(35,22,'DASHBOARD','anucha','2026-09-03 04:16:22'),(36,22,'ASSETS','anucha','2026-09-03 04:16:22'),(37,22,'MOBILE','anucha','2026-09-03 04:16:22'),(38,22,'SOFTWARE','anucha','2026-09-03 04:16:22'),(39,22,'LOANS','anucha','2026-09-03 04:16:22'),(40,22,'MAINTENANCE','anucha','2026-09-03 04:16:22'),(41,22,'NETWORK','anucha','2026-09-03 04:16:22'),(42,22,'ACCESS_REQUESTS','anucha','2026-09-03 04:16:22'),(48,160,'ASSETS','anucha','2026-09-10 09:33:03'),(49,160,'MOBILE','anucha','2026-09-10 09:33:03'),(50,160,'LOANS','anucha','2026-09-10 09:33:03'),(51,160,'SITES','anucha','2026-09-10 09:33:03'),(52,160,'ACCESS_REQUESTS','anucha','2026-09-10 09:33:03');
/*!40000 ALTER TABLE `user_module_access` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Temporary view structure for view `v_active_loans`
--

DROP TABLE IF EXISTS `v_active_loans`;
/*!50001 DROP VIEW IF EXISTS `v_active_loans`*/;
SET @saved_cs_client     = @@character_set_client;
/*!50503 SET character_set_client = utf8mb4 */;
/*!50001 CREATE VIEW `v_active_loans` AS SELECT 
 1 AS `id`,
 1 AS `loan_code`,
 1 AS `borrower_ad`,
 1 AS `borrower_name`,
 1 AS `borrower_dept`,
 1 AS `loan_date`,
 1 AS `expected_return`,
 1 AS `days_left`,
 1 AS `urgency`,
 1 AS `hw_asset_code`,
 1 AS `hw_category`,
 1 AS `hw_brand`,
 1 AS `hw_model`,
 1 AS `mob_asset_code`,
 1 AS `device_type`,
 1 AS `mob_brand`,
 1 AS `mob_model`,
 1 AS `net_asset_code`,
 1 AS `net_device_type`,
 1 AS `net_brand`,
 1 AS `net_model`,
 1 AS `borrower_site`*/;
SET character_set_client = @saved_cs_client;

--
-- Temporary view structure for view `v_software_usage`
--

DROP TABLE IF EXISTS `v_software_usage`;
/*!50001 DROP VIEW IF EXISTS `v_software_usage`*/;
SET @saved_cs_client     = @@character_set_client;
/*!50503 SET character_set_client = utf8mb4 */;
/*!50001 CREATE VIEW `v_software_usage` AS SELECT 
 1 AS `id`,
 1 AS `software_id`,
 1 AS `software_name`,
 1 AS `publisher`,
 1 AS `total_seats`,
 1 AS `seats_used`,
 1 AS `seats_available`,
 1 AS `expiry_date`,
 1 AS `site_id`,
 1 AS `site_name`*/;
SET character_set_client = @saved_cs_client;

--
-- Dumping routines for database 'it_asset_mgmt'
--

--
-- Final view structure for view `v_active_loans`
--

/*!50001 DROP VIEW IF EXISTS `v_active_loans`*/;
/*!50001 SET @saved_cs_client          = @@character_set_client */;
/*!50001 SET @saved_cs_results         = @@character_set_results */;
/*!50001 SET @saved_col_connection     = @@collation_connection */;
/*!50001 SET character_set_client      = utf8mb4 */;
/*!50001 SET character_set_results     = utf8mb4 */;
/*!50001 SET collation_connection      = utf8mb4_unicode_ci */;
/*!50001 CREATE ALGORITHM=UNDEFINED */
/*!50013 DEFINER=`anucha`@`localhost` SQL SECURITY DEFINER */
/*!50001 VIEW `v_active_loans` AS select `l`.`id` AS `id`,`l`.`loan_code` AS `loan_code`,`l`.`borrower_ad` AS `borrower_ad`,`l`.`borrower_name` AS `borrower_name`,`l`.`borrower_dept` AS `borrower_dept`,`l`.`loan_date` AS `loan_date`,`l`.`expected_return` AS `expected_return`,to_days(`l`.`expected_return`) - to_days(curdate()) AS `days_left`,case when `l`.`expected_return` < curdate() then 'Overdue' when to_days(`l`.`expected_return`) - to_days(curdate()) <= 3 then 'Due Soon' else 'OK' end AS `urgency`,`h`.`asset_id` AS `hw_asset_code`,`h`.`category` AS `hw_category`,`h`.`brand` AS `hw_brand`,`h`.`model` AS `hw_model`,`m`.`asset_id` AS `mob_asset_code`,`m`.`device_type` AS `device_type`,`m`.`brand` AS `mob_brand`,`m`.`model` AS `mob_model`,`n`.`asset_id` AS `net_asset_code`,`n`.`device_type` AS `net_device_type`,`n`.`brand` AS `net_brand`,`n`.`model` AS `net_model`,`s`.`site_name` AS `borrower_site` from ((((`asset_loans` `l` left join `hardware_assets` `h` on(`h`.`id` = `l`.`asset_id`)) left join `mobile_assets` `m` on(`m`.`id` = `l`.`mobile_id`)) left join `network_assets` `n` on(`n`.`id` = `l`.`network_asset_id`)) left join `sites` `s` on(`s`.`id` = `l`.`borrower_site_id`)) where `l`.`status` in ('OnLoan','Overdue') order by `l`.`expected_return` */;
/*!50001 SET character_set_client      = @saved_cs_client */;
/*!50001 SET character_set_results     = @saved_cs_results */;
/*!50001 SET collation_connection      = @saved_col_connection */;

--
-- Final view structure for view `v_software_usage`
--

/*!50001 DROP VIEW IF EXISTS `v_software_usage`*/;
/*!50001 SET @saved_cs_client          = @@character_set_client */;
/*!50001 SET @saved_cs_results         = @@character_set_results */;
/*!50001 SET @saved_col_connection     = @@collation_connection */;
/*!50001 SET character_set_client      = utf8mb4 */;
/*!50001 SET character_set_results     = utf8mb4 */;
/*!50001 SET collation_connection      = utf8mb4_unicode_ci */;
/*!50001 CREATE ALGORITHM=UNDEFINED */
/*!50013 DEFINER=`it_asset_user`@`localhost` SQL SECURITY DEFINER */
/*!50001 VIEW `v_software_usage` AS select `s`.`id` AS `id`,`s`.`software_id` AS `software_id`,`s`.`software_name` AS `software_name`,`s`.`publisher` AS `publisher`,`s`.`total_seats` AS `total_seats`,coalesce(sum(case when `m`.`status` = 'Installed' then 1 else 0 end),0) AS `seats_used`,`s`.`total_seats` - coalesce(sum(case when `m`.`status` = 'Installed' then 1 else 0 end),0) AS `seats_available`,`s`.`expiry_date` AS `expiry_date`,`s`.`site_id` AS `site_id`,`st`.`site_name` AS `site_name` from ((`software_licenses` `s` left join `software_allocation_map` `m` on(`m`.`software_id` = `s`.`id`)) left join `sites` `st` on(`st`.`id` = `s`.`site_id`)) group by `s`.`id` */;
/*!50001 SET character_set_client      = @saved_cs_client */;
/*!50001 SET character_set_results     = @saved_cs_results */;
/*!50001 SET collation_connection      = @saved_col_connection */;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2026-09-16  9:32:34
