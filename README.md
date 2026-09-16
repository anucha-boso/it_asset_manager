# Pacific Cold Storage — IT Asset Management System

ระบบจัดการสินทรัพย์ไอที / ใบอนุญาตซอฟต์แวร์ / การบำรุงรักษา
สำหรับ **Pacific Cold Storage**

**Stack:** PHP 8.1+ (Native OOP, PDO) · MySQL 8 · Bootstrap 5 · Nginx บน Ubuntu

---

## โครงสร้างโปรเจกต์

```
it-asset-manager/
├── config/
│   ├── db_connect.php       # PDO connection (singleton, อ่าน env var)
│   └── nginx.conf           # server block ตัวอย่าง
├── sql/
│   └── database.sql         # schema + sample data + view
├── includes/
│   ├── header.php           # Bootstrap 5 shell + sidebar
│   └── footer.php
├── public/                  # web root (Nginx ชี้มาที่นี่)
│   ├── index.php            # Dashboard
│   ├── assets/              # Hardware CRUD     (สร้างเพิ่มได้จาก pattern เดียวกัน)
│   ├── software/            # Software CRUD + allocation
│   ├── maintenance/         # PM / Repair logs
│   ├── sites/               # Site management
│   ├── css/style.css
│   └── js/app.js
└── uploads/                 # writable, Nginx ปิดการ exec PHP
```

---

## ติดตั้ง

### 1. Database

```bash
mysql -u root -p < sql/database.sql
```

### 2. ตั้ง credentials

แนะนำให้ใช้ environment variable (อ่านโดย `db_connect.php`):

```bash
export DB_HOST=127.0.0.1
export DB_NAME=pacific_it_assets
export DB_USER=pcs_app
export DB_PASS='change-me'
```

ตัวอย่างใน `/etc/php/8.1/fpm/pool.d/www.conf`:

```ini
env[DB_HOST] = 127.0.0.1
env[DB_NAME] = pacific_it_assets
env[DB_USER] = pcs_app
env[DB_PASS] = change-me
```

### 3. Nginx

```bash
sudo cp config/nginx.conf /etc/nginx/sites-available/it-asset-manager
sudo ln -s /etc/nginx/sites-available/it-asset-manager /etc/nginx/sites-enabled/
sudo nginx -t && sudo systemctl reload nginx
```

อย่าลืมแก้ `root` ใน `nginx.conf` ให้ตรงกับ path จริง และตั้ง
`upload_max_filesize` / `post_max_size` ใน `php.ini` ให้ ≥ `client_max_body_size`

### 4. Permission

```bash
sudo chown -R www-data:www-data uploads/
sudo chmod -R 750 uploads/
```

---

## หลักการเขียนโค้ดที่ยึดในโปรเจกต์นี้

1. **ทุก query รับ input ใช้ prepared statement เสมอ**
   ```php
   $stmt = $pdo->prepare("SELECT * FROM hardware_assets WHERE id = :id");
   $stmt->execute([':id' => $id]);
   ```
2. **ทุก output ผ่าน `e()` (htmlspecialchars)** — กัน XSS
3. **`PDO::ATTR_EMULATE_PREPARES = false`** — ใช้ native prepared statement
4. **CSRF** ในแบบฟอร์มที่เปลี่ยนข้อมูล (POST/PUT/DELETE) — เพิ่ม token เมื่อทำ CRUD จริง
5. ห้ามต่อสตริง SQL กับตัวแปรจาก user ทุกกรณี

---

## ต่อยอด

ไฟล์ที่สร้างไว้ในชุดนี้คือ "starter ที่รันได้จริง" ของ:
- โครงสร้างโฟลเดอร์
- ฐานข้อมูล + sample data
- Dashboard
- Layout / Navigation

ขั้นถัดไป (CRUD ของแต่ละโมดูล) ใช้ pattern เดียวกัน:
- `public/assets/index.php` → list + filter
- `public/assets/form.php`  → add/edit (POST → prepared statement)
- `public/assets/delete.php` → POST + CSRF token + `WHERE id = :id`
