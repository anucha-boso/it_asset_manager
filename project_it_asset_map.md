# โครงสร้างโปรเจกต์ Pacific Cold Storage — IT Asset Management System

เอกสารนี้รวบรวมไดอะแกรมสถาปัตยกรรมระบบ, โครงสร้างฐานข้อมูล (ERD), Data & Request Flow, และระบบสิทธิ์การใช้งาน (RBAC) ของโปรเจกต์ **IT Asset Manager** (`/var/www/lab/it-asset-manager`) ในรูปแบบ **Mermaid.js**

---

## 1. โครงสร้างโมดูลและสถาปัตยกรรมระบบ (Project Architecture Diagram)

ไดอะแกรมแสดงการจัดวางไฟล์, โครงสร้างโฟลเดอร์ และความสัมพันธ์ระหว่าง Presentation Layer, Middleware/Config Layer และ Database

```mermaid
graph TD
    subgraph Client [" Client / Browser "]
        UI["Web Browser"]
    end

    subgraph EntryPoint [" Web Server Entrance "]
        NGINX["Nginx Server (config/nginx.conf)"]
        PUBLIC["public/ (Document Root)"]
    end

    subgraph Auth_Security [" Security & Auth Middleware "]
        AUTH["config/auth.php (RBAC & Central Auth)"]
        CSRF["includes/csrf.php (CSRF Token Guard)"]
        SESSION["PHP Session Management"]
    end

    subgraph Presentation_Layout [" Layout & Common Includes "]
        HEADER["includes/header.php"]
        FOOTER["includes/footer.php"]
        STYLE["public/css/style.css"]
        JS["public/js/app.js"]
    end

    subgraph Functional_Modules [" Functional Controllers & Pages (public/) "]
        subgraph Auth_Module [" Authentication "]
            LOGIN["login.php"]
            LOGOUT["logout.php"]
        end

        DASHBOARD["index.php (Dashboard Overview)"]

        subgraph Assets_Module [" Hardware Assets (assets/) "]
            AST_LIST["index.php (List)"]
            AST_FORM["form.php (Create/Edit)"]
            AST_DEL["delete.php (Delete)"]
            AST_IMP["import.php (CSV Import)"]
            AST_MOB["mobile.php (Mobile View & Actions)"]
        end

        subgraph Software_Module [" Software Licenses (software/) "]
            SW_LIST["index.php (List Licenses)"]
            SW_FORM["form.php (Create/Edit License)"]
            SW_DEL["delete.php (Delete License)"]
            SW_ALLOC["allocate.php (Allocate to Asset/User)"]
            SW_RENEW["renew.php (Renew License)"]
        end

        subgraph Loans_Module [" Asset Loans (loans/) "]
            LN_LIST["index.php (Loan Log)"]
            LN_OUT["checkout.php (Borrow Asset)"]
            LN_RET["return.php (Return Asset)"]
            LN_DTL["detail.php / form.php / print.php"]
        end

        subgraph Maint_Module [" Maintenance & PM (maintenance/) "]
            MT_LIST["index.php (Log History)"]
            MT_FORM["form.php (Log Form)"]
            MT_SCH["pm_schedule.php (Schedules)"]
            MT_TPL["pm_templates.php / pm_template_form.php"]
            MT_EXEC["pm_execute.php (Run PM Task)"]
        end

        subgraph Network_Module [" Network Devices (network/) "]
            NW_LIST["index.php"]
            NW_FORM["form.php"]
            NW_DEL["delete.php"]
        end

        subgraph Sites_Module [" Site Locations (sites/) "]
            ST_LIST["index.php"]
            ST_FORM["form.php"]
            ST_DEL["delete.php"]
        end
    end

    subgraph Data_Layer [" Data Access & Storage Layer "]
        DBCONN["config/db_connect.php (Database Singleton / PDO)"]
        DB_APP[("MySQL: it_asset_mgmt")]
        DB_AUTH[("MySQL: cc_central_auth_db")]
    end

    %% Connections
    UI --> NGINX --> PUBLIC
    PUBLIC --> AUTH
    AUTH --> LOGIN & DASHBOARD & Functional_Modules
    Functional_Modules --> CSRF
    Functional_Modules --> HEADER & FOOTER
    Functional_Modules --> DBCONN
    DBCONN --> DB_APP
    AUTH --> DB_AUTH
```

---

## 2. แผนผังความสัมพันธ์ของฐานข้อมูล (Database ER Diagram)

อ้างอิงจาก schema ใน `sql/database.sql` แสดงความสัมพันธ์แบบ Foreign Key ระหว่างตารางต่างๆ

```mermaid
erDiagram
    sites ||--o{ hardware_assets : "contains (1:N)"
    hardware_assets ||--o{ maintenance_logs : "has history (1:N)"
    hardware_assets ||--o{ software_allocation_map : "assigned to (0..1:N)"
    software_licenses ||--o{ software_allocation_map : "allocated via (1:N)"
    software_licenses ||..o{ v_software_usage : "calculated in View"

    sites {
        int id PK
        string site_name UK
        enum site_type
        text address
        string contact_person
        string contact_phone
        timestamp created_at
    }

    hardware_assets {
        int id PK
        string asset_id UK "PCS-NB-0001"
        int site_id FK
        string category "Notebook/Server/Printer"
        string brand
        string model
        string serial_number
        enum status "Active/In Repair/Retired/In Stock"
        string location
        string assigned_to_ad
        string ip_address
        string mac_address
        text specifications
        date purchase_date
        date warranty_expiry
    }

    software_licenses {
        int id PK
        string software_id UK "SW-M365-001"
        string software_name
        string publisher
        string license_type
        text license_key
        int total_seats
        date purchase_date
        date expiry_date
        string vendor
    }

    software_allocation_map {
        int id PK
        int software_id FK
        int asset_id FK
        string assigned_user_ad
        date install_date
        enum status "Installed/Uninstalled"
        text notes
    }

    maintenance_logs {
        int id PK
        int asset_id FK
        date pm_date
        enum type "PM/Repair"
        text description
        string performed_by
        decimal cost
        date next_pm_date
    }

    v_software_usage {
        int id
        string software_id
        string software_name
        int total_seats
        int seats_used
        int seats_available
        date expiry_date
    }
```

---

## 3. ลำดับการประมวลผลคำขอ (Request & Authentication Flow)

แสดงลำดับขั้นตอนเมื่อผู้ใช้ส่งคำขอ (HTTP Request) เข้าสู่ระบบ ตั้งแต่ผ่าน Auth Middleware, ตรวจสอบ CSRF จนถึงการประมวลผล PDO SQL

```mermaid
sequenceDiagram
    autonumber
    actor User as User Browser
    participant Nginx as Web Server (Nginx)
    participant Page as Page/Controller (PHP)
    participant Auth as Auth Middleware (auth.php)
    participant Csrf as CSRF Guard (csrf.php)
    participant DB as PDO Database (db_connect.php)
    participant MySQL as MySQL Database

    User->>Nginx: HTTP Request (e.g., POST /assets/form.php)
    Nginx->>Page: Dispatch Request
    Page->>Auth: require_role(['it_admin', 'it_staff'])
    
    alt Not Logged In
        Auth-->>User: Redirect 302 -> /login.php
    else Logged In but Unauthorized Role
        Auth-->>User: Render 403 Forbidden Page
    else Authorized
        Auth-->>Page: Proceed Execution
    end

    alt POST / DELETE Request
        Page->>Csrf: csrf_verify()
        alt Invalid CSRF Token
            Csrf-->>User: HTTP 419 Token Mismatch
        else Valid Token
            Csrf-->>Page: Token Validated
        end
    end

    Page->>DB: db()->prepare(...) / execute()
    DB->>MySQL: SQL Native Prepared Statement
    MySQL-->>DB: Query Result Set / Affected Rows
    DB-->>Page: Return Data / Status
    Page-->>User: Render HTML with Header/Footer
```

---

## 4. ผังการจัดสิทธิ์ใช้งาน (Role-Based Access Control - RBAC Matrix)

สิทธิ์การใช้งานตามบทบาทใน `config/auth.php` ผ่านฟังก์ชัน `can($action)`

```mermaid
graph LR
    subgraph Roles [" User Roles "]
        ADMIN["it_admin (Administrator)"]
        STAFF["it_staff (IT Staff)"]
        VIEWER["it_viewer (Read-Only)"]
        BORROWER["it_borrower (Asset Borrower)"]
    end

    subgraph Actions [" System Actions "]
        ACT_DEL["delete / approve_loan / generate_pm"]
        ACT_WRITE["create / edit / do_pm / checkout_loan / return_loan"]
        ACT_VIEW["view (All Modules Overview & List)"]
        ACT_OWN["request_loan / view_own_loan"]
    end

    ADMIN --> ACT_DEL
    ADMIN --> ACT_WRITE
    ADMIN --> ACT_VIEW
    ADMIN --> ACT_OWN

    STAFF --> ACT_WRITE
    STAFF --> ACT_VIEW
    STAFF --> ACT_OWN

    VIEWER --> ACT_VIEW
    VIEWER --> ACT_OWN

    BORROWER --> ACT_OWN
```

---

## สรุปส่วนประกอบหลักของโค้ดในโปรเจกต์

1. **การเชื่อมต่อฐานข้อมูล**: `config/db_connect.php` ใช้รูปแบบ Singleton PDO Pattern ป้องกัน SQL Injection ด้วย Native Prepared Statement
2. **ระบบสิทธิ์และการยืนยันตัวตน**: `config/auth.php` รองรับ 4 ระดับสิทธิ์ (`it_admin`, `it_staff`, `it_viewer`, `it_borrower`) เชื่อมต่อกับระบบล็อกอินส่วนกลาง `cc_central_auth_db`
3. **การป้องกันความปลอดภัย**: `includes/csrf.php` มี `csrf_field()` และ `csrf_verify()` ป้องกัน CSRF Attack ในทุกฟอร์ม
4. **โมดูลหลักของระบบ**:
   - **Assets**: จัดการอุปกรณ์ฮาร์ดแวร์, นำเข้าข้อมูล CSV และมีหน้าจอสำหรับอุปกรณ์เคลื่อนที่ (Mobile view)
   - **Software**: บันทึกและจัดสรรไลเซนส์ซอฟต์แวร์ (Software Allocation Map)
   - **Loans**: ระบบยืม-คืนอุปกรณ์ ไอที
   - **Maintenance & PM**: บันทึกการซ่อมบำรุง และวางแผนงาน Preventive Maintenance (PM)
   - **Sites & Network**: จัดการข้อมูลสาขาและอุปกรณ์เครือข่าย
