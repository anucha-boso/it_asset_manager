-- =============================================================================
--  Pacific Cold Storage — IT Asset Management System
--  Database Schema
--  MySQL 8.0+ / MariaDB 10.5+
-- =============================================================================
--  หมายเหตุการออกแบบ:
--   - ใช้ InnoDB เพื่อรองรับ Foreign Key และ Transaction
--   - charset = utf8mb4 รองรับภาษาไทย/อีโมจิ
--   - ตั้ง ON DELETE SET NULL กับ site_id และ asset_id ใน software_allocation_map
--     เพื่อไม่ให้ข้อมูลหลัก (license/maintenance) หายตามสินทรัพย์ที่ถูกลบ
--   - ตั้ง ON DELETE CASCADE กับ maintenance_logs เพราะประวัติบำรุงรักษา
--     ผูกติดกับ asset โดยตรง ถ้า asset ถูกลบควรลบประวัติตามไปด้วย
-- =============================================================================

CREATE DATABASE IF NOT EXISTS it_asset_mgmt
    DEFAULT CHARACTER SET utf8mb4
    DEFAULT COLLATE utf8mb4_unicode_ci;

USE it_asset_mgmt;

-- ลบตารางเดิม (ถ้ามี) ตามลำดับ FK ที่ถูกต้อง — ใช้ตอน reset dev เท่านั้น
SET FOREIGN_KEY_CHECKS = 0;
DROP TABLE IF EXISTS maintenance_logs;
DROP TABLE IF EXISTS software_allocation_map;
DROP TABLE IF EXISTS software_licenses;
DROP TABLE IF EXISTS hardware_assets;
DROP TABLE IF EXISTS sites;
SET FOREIGN_KEY_CHECKS = 1;

-- -----------------------------------------------------------------------------
--  ตาราง: sites  (สาขา / คลังสินค้า / สำนักงาน)
-- -----------------------------------------------------------------------------
CREATE TABLE sites (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    site_name       VARCHAR(100) NOT NULL,
    site_type       ENUM('Warehouse', 'Office', 'Data Center', 'Logistics Hub')
                        DEFAULT 'Warehouse',
    address         TEXT,
    contact_person  VARCHAR(100),
    contact_phone   VARCHAR(50),
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_site_name (site_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
--  ตาราง: hardware_assets  (อุปกรณ์ฮาร์ดแวร์)
-- -----------------------------------------------------------------------------
CREATE TABLE hardware_assets (
    id                INT AUTO_INCREMENT PRIMARY KEY,
    asset_id          VARCHAR(50) UNIQUE NOT NULL COMMENT 'รหัสภายในขององค์กร เช่น PCS-NB-0001',
    site_id           INT NULL                    COMMENT 'FK -> sites.id',
    category          VARCHAR(50)                 COMMENT 'Notebook / Desktop / Printer / Server ...',
    brand             VARCHAR(50),
    model             VARCHAR(100),
    serial_number     VARCHAR(100),
    status            ENUM('Active', 'In Repair', 'Retired', 'In Stock')
                          DEFAULT 'Active',
    location          VARCHAR(100)                COMMENT 'ตำแหน่งย่อยภายในสาขา เช่น Rack-04',
    assigned_to_ad    VARCHAR(100)                COMMENT 'AD username ของผู้ใช้ที่ครอบครอง',
    ip_address        VARCHAR(45),
    mac_address       VARCHAR(17),
    specifications    TEXT,
    purchase_date     DATE,
    warranty_expiry   DATE,
    created_at        TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at        TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    INDEX idx_status   (status),
    INDEX idx_category (category),
    INDEX idx_site     (site_id),
    INDEX idx_warranty (warranty_expiry),

    CONSTRAINT fk_asset_site
        FOREIGN KEY (site_id) REFERENCES sites(id)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
--  ตาราง: software_licenses  (ใบอนุญาตซอฟต์แวร์)
-- -----------------------------------------------------------------------------
CREATE TABLE software_licenses (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    software_id     VARCHAR(50) UNIQUE NOT NULL COMMENT 'รหัสภายใน เช่น SW-MS365-001',
    software_name   VARCHAR(150) NOT NULL,
    publisher       VARCHAR(100),
    license_type    VARCHAR(50)                 COMMENT 'Perpetual / Subscription / OEM ...',
    license_key     TEXT                        COMMENT 'ควรเก็บแบบเข้ารหัสในระดับ application',
    total_seats     INT DEFAULT 1               COMMENT 'จำนวน seat ที่ซื้อมา',
    purchase_date   DATE,
    expiry_date     DATE,
    vendor          VARCHAR(100),
    notes           TEXT,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    INDEX idx_expiry (expiry_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
--  ตาราง: software_allocation_map  (แมป license -> asset/user)
-- -----------------------------------------------------------------------------
CREATE TABLE software_allocation_map (
    id                 INT AUTO_INCREMENT PRIMARY KEY,
    software_id        INT NOT NULL              COMMENT 'FK -> software_licenses.id',
    asset_id           INT DEFAULT NULL          COMMENT 'FK -> hardware_assets.id (อาจ NULL ถ้าให้ user ตรงๆ)',
    assigned_user_ad   VARCHAR(100)              COMMENT 'AD username ของผู้รับการ allocate',
    install_date       DATE,
    status             ENUM('Installed', 'Uninstalled') DEFAULT 'Installed',
    notes              TEXT,
    created_at         TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_alloc_status (status),

    CONSTRAINT fk_alloc_software
        FOREIGN KEY (software_id) REFERENCES software_licenses(id)
        ON DELETE CASCADE ON UPDATE CASCADE,

    CONSTRAINT fk_alloc_asset
        FOREIGN KEY (asset_id) REFERENCES hardware_assets(id)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
--  ตาราง: maintenance_logs  (บันทึก PM / Repair)
-- -----------------------------------------------------------------------------
CREATE TABLE maintenance_logs (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    asset_id        INT NOT NULL                COMMENT 'FK -> hardware_assets.id',
    pm_date         DATE NOT NULL,
    type            ENUM('PM', 'Repair') NOT NULL,
    description     TEXT,
    performed_by    VARCHAR(100),
    cost            DECIMAL(10, 2) DEFAULT 0.00,
    next_pm_date    DATE,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_next_pm (next_pm_date),
    INDEX idx_pm_date (pm_date),

    CONSTRAINT fk_maint_asset
        FOREIGN KEY (asset_id) REFERENCES hardware_assets(id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
--  Sample Data — ใช้สำหรับทดสอบ Dashboard
-- =============================================================================

INSERT INTO sites (site_name, site_type, address, contact_person, contact_phone) VALUES
('PCS HQ Bangkok',          'Office',        '123 Sukhumvit Rd, Bangkok',  'Somchai P.',  '02-000-0001'),
('PCS Bangna Cold Hub',     'Warehouse',     '88 Bangna-Trad Km.12',       'Wichai T.',   '02-000-0002'),
('PCS Laem Chabang DC',     'Data Center',   'Laem Chabang Port, Chonburi','Anan K.',     '038-000-001'),
('PCS Chiang Mai Logistics','Logistics Hub', 'Mae Hia, Chiang Mai',        'Nattaya R.',  '053-000-001');

INSERT INTO hardware_assets
(asset_id, site_id, category, brand, model, serial_number, status, location, assigned_to_ad, ip_address, mac_address, purchase_date, warranty_expiry) VALUES
('PCS-NB-0001', 1, 'Notebook', 'Lenovo',  'ThinkPad T14', 'SN-T14-001', 'Active',    'Floor 5 / IT',  'somchai.p',  '10.10.5.21', 'AA:BB:CC:11:22:01', '2024-02-15', '2027-02-15'),
('PCS-NB-0002', 1, 'Notebook', 'Dell',    'Latitude 5440','SN-L54-018', 'Active',    'Floor 5 / Fin', 'malee.s',    '10.10.5.22', 'AA:BB:CC:11:22:02', '2024-05-01', '2027-05-01'),
('PCS-SV-0010', 3, 'Server',   'HPE',     'DL380 Gen11',  'SN-DL380-3', 'Active',    'Rack-04',       NULL,         '10.20.1.10', 'AA:BB:CC:33:44:10', '2023-09-10', '2026-09-10'),
('PCS-PR-0005', 2, 'Printer',  'Brother', 'HL-L8360CDW',  'SN-BR-0005', 'In Repair', 'Warehouse Ofc', NULL,         NULL,         NULL,                '2022-11-20', '2025-11-20'),
('PCS-DT-0030', 2, 'Desktop',  'HP',      'EliteDesk 800','SN-ED800-30','In Stock',  'IT Storeroom',  NULL,         NULL,         NULL,                '2024-01-10', '2027-01-10'),
('PCS-NB-0003', 4, 'Notebook', 'Apple',   'MacBook Pro 14','SN-MBP-003','Active',    'Office',        'nattaya.r',  '10.40.3.15', 'AA:BB:CC:55:66:03', '2024-08-22', '2027-08-22'),
('PCS-SC-0007', 3, 'Scanner',  'Zebra',   'DS3608',       'SN-ZB-0007', 'Retired',   'Decommission',  NULL,         NULL,         NULL,                '2020-03-01', '2023-03-01');

INSERT INTO software_licenses
(software_id, software_name, publisher, license_type, license_key, total_seats, purchase_date, expiry_date, vendor) VALUES
('SW-M365-001', 'Microsoft 365 Business Standard', 'Microsoft', 'Subscription', 'XXXX-XXXX-XXXX-AAAA', 50, '2024-01-01', '2026-12-31', 'Ingram Micro'),
('SW-ADB-001',  'Adobe Acrobat Pro',               'Adobe',     'Subscription', 'XXXX-XXXX-XXXX-BBBB', 10, '2024-03-15', '2026-03-15', 'SiS Distribution'),
('SW-WIN-001',  'Windows 11 Pro',                  'Microsoft', 'OEM',          'XXXX-XXXX-XXXX-CCCC', 30, '2024-02-01', NULL,         'Lenovo Bundle'),
('SW-AV-001',   'Trend Micro Apex One',            'Trend Micro','Subscription','XXXX-XXXX-XXXX-DDDD',100, '2024-04-10', '2026-07-30', 'NForce Secure'),
('SW-AUT-001',  'AutoCAD LT',                      'Autodesk',  'Subscription', 'XXXX-XXXX-XXXX-EEEE',  5, '2024-06-01', '2025-12-15', 'Application Service');

INSERT INTO software_allocation_map (software_id, asset_id, assigned_user_ad, install_date, status, notes) VALUES
(1, 1, 'somchai.p', '2024-02-20', 'Installed', 'Default office bundle'),
(1, 2, 'malee.s',   '2024-05-05', 'Installed', NULL),
(1, 6, 'nattaya.r', '2024-08-25', 'Installed', NULL),
(2, 1, 'somchai.p', '2024-03-20', 'Installed', NULL),
(3, 1, 'somchai.p', '2024-02-15', 'Installed', NULL),
(3, 2, 'malee.s',   '2024-05-01', 'Installed', NULL),
(4, 1, 'somchai.p', '2024-04-15', 'Installed', 'Endpoint protection'),
(4, 2, 'malee.s',   '2024-04-15', 'Installed', 'Endpoint protection'),
(4, 3, NULL,        '2024-04-15', 'Installed', 'Server agent'),
(5, NULL, 'arch.team', '2024-06-10', 'Installed', 'Floating license');

INSERT INTO maintenance_logs (asset_id, pm_date, type, description, performed_by, cost, next_pm_date) VALUES
(3, '2025-03-15', 'PM',     'Quarterly server preventive maintenance — fan, firmware, log review', 'IT-Vendor A', 3500.00, '2026-07-10'),
(4, '2025-10-02', 'Repair', 'Replace fuser unit — printer paper jam',                              'On-site Tech', 4200.00, NULL),
(1, '2025-12-01', 'PM',     'Notebook cleaning + thermal paste',                                   'IT-Internal',   500.00, '2026-06-30'),
(2, '2025-11-20', 'PM',     'Notebook cleaning + battery health check',                            'IT-Internal',   500.00, '2026-06-25'),
(6, '2025-09-15', 'PM',     'MacBook annual checkup',                                              'IT-Internal',   500.00, '2026-07-05');

-- =============================================================================
--  Views — ใช้ใน Dashboard เพื่อลด JOIN ซ้ำๆ ใน application code
-- =============================================================================

DROP VIEW IF EXISTS v_software_usage;
CREATE VIEW v_software_usage AS
SELECT
    s.id,
    s.software_id,
    s.software_name,
    s.publisher,
    s.total_seats,
    COALESCE(SUM(CASE WHEN m.status = 'Installed' THEN 1 ELSE 0 END), 0) AS seats_used,
    s.total_seats - COALESCE(SUM(CASE WHEN m.status = 'Installed' THEN 1 ELSE 0 END), 0) AS seats_available,
    s.expiry_date
FROM software_licenses s
LEFT JOIN software_allocation_map m ON m.software_id = s.id
GROUP BY s.id;
