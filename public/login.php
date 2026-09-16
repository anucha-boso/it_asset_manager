<?php
/**
 * IT Asset Manager — Login
 * /var/www/lab/it-asset-manager/login.php
 *
 * Authenticate กับ cc_central_auth_db
 * ตรวจ permission ของ app 'it_asset_mgmt'
 */
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../config/auth.php';

/* ถ้า login แล้ว → dashboard */
if (!empty($_SESSION['iam_user_id'])) {
    header('Location: /it-asset-manager/index.php');
    exit;
}

$error   = '';
$backUrl = $_GET['back'] ?? '/it-asset-manager/index.php';
if (!str_starts_with($backUrl, '/it-asset-manager/')) {
    $backUrl = '/it-asset-manager/index.php';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim((string)($_POST['username'] ?? ''));
    $password = (string)($_POST['password'] ?? '');

    if ($username === '' || $password === '') {
        $error = 'กรุณากรอก Username และ Password';
    } else {
        try {
            $pdo = iam_pdo_auth();

            /* 1. ดึง user */
            $stmt = $pdo->prepare("
                SELECT user_id, username, full_name,
                       password_hash, is_active, primary_site_id
                FROM users
                WHERE username = :uname
                LIMIT 1
            ");
            $stmt->execute([':uname' => $username]);
            $user = $stmt->fetch();

            if (!$user || !(bool)$user['is_active']) {
                $error = 'Username ไม่ถูกต้อง หรือบัญชีถูกระงับ';

            } elseif (!password_verify($password, $user['password_hash'])) {
                $error = 'Password ไม่ถูกต้อง';

            } else {
                /* 2. ตรวจ permission สำหรับ it_asset_mgmt */
                $permStmt = $pdo->prepare("
                    SELECT p.role, p.site_id
                    FROM user_permissions p
                    INNER JOIN applications a ON a.app_id = p.app_id
                    WHERE p.user_id   = :uid
                      AND a.app_code  = :app_code
                      AND a.is_active = 1
                    ORDER BY
                        FIELD(p.role,'it_admin','it_staff','it_viewer','it_borrower') DESC,
                        p.permission_id ASC
                    LIMIT 1
                ");
                $permStmt->execute([
                    ':uid'      => $user['user_id'],
                    ':app_code' => IAM_APP_CODE,
                ]);
                $perm = $permStmt->fetch();

                if (!$perm) {
                    $error = 'คุณไม่มีสิทธิ์เข้าใช้งาน IT Asset Manager<br>
                              <small class="text-muted">ติดต่อ IT Admin เพื่อขอสิทธิ์</small>';
                } else {
                    /* 3. เซ็ต session */
                    session_regenerate_id(true);
                    $_SESSION['iam_user_id']  = (int)$user['user_id'];
                    $_SESSION['iam_username'] = $user['username'];
                    $_SESSION['iam_fullname'] = $user['full_name'];
                    $_SESSION['iam_role']     = $perm['role'];
                    $_SESSION['iam_site_id']  = $perm['site_id'] ?? $user['primary_site_id'];

                    header('Location: ' . $backUrl);
                    exit;
                }
            }

        } catch (PDOException $e) {
            error_log('[IAM LOGIN FAIL] ' . $e->getMessage());
            $error = 'เกิดข้อผิดพลาดในการเชื่อมต่อ กรุณาลองใหม่';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>เข้าสู่ระบบ · IT Asset Manager</title>
    <link rel="icon" type="image/svg+xml" href="/it-asset-manager/assets/img/Pacific-svg.svg">
    <link rel="alternate icon" type="image/png" href="/it-asset-manager/assets/img/PACIFIC_Cold_Chain_Logo.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@400;500;600&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
    <style>
    * { box-sizing: border-box; }
    body {
        margin: 0; min-height: 100vh;
        display: flex; align-items: center; justify-content: center;
        background: linear-gradient(135deg, #0f172a 0%, #1e3a5f 100%);
        font-family: "Inter","Sarabun",system-ui,sans-serif;
    }
    .login-card {
        width: 100%; max-width: 420px;
        background: #fff;
        border-radius: 16px;
        padding: 40px 36px 36px;
        box-shadow: 0 20px 60px rgba(0,0,0,.35);
    }
    .brand-mark {
        width: 80px; height: 80px;
        background: transparent;
        display: flex; align-items: center; justify-content: center;
        margin: 0 auto 20px;
    }
    .brand-mark img {
        width: 200px; height: 150px;
        object-fit: contain;
    }
    .login-title {
        text-align: center; font-size: 20px; font-weight: 600;
        color: #0f172a; margin-bottom: 4px;
    }
    .login-sub {
        text-align: center; font-size: 13px; color: #64748b;
        margin-bottom: 28px;
    }
    .form-label { font-size: 13px; font-weight: 500; color: #374151; }
    .form-control {
        border: 1px solid #e5e7eb; border-radius: 8px;
        padding: 10px 14px; font-size: 14px;
    }
    .form-control:focus {
        border-color: #38bdf8;
        box-shadow: 0 0 0 3px rgba(56,189,248,.15);
    }
    .btn-login {
        width: 100%; padding: 11px;
        background: #1e3a5f; color: #fff;
        border: none; border-radius: 8px;
        font-size: 15px; font-weight: 600;
        cursor: pointer; transition: background .15s;
    }
    .btn-login:hover { background: #185FA5; color: #fff; }
    .role-hint {
        background: #f8fafc; border: 1px solid #e2e8f0;
        border-radius: 8px; padding: 12px 14px;
        font-size: 12px; color: #64748b; margin-top: 20px;
    }
    .role-hint strong { color: #374151; }
    </style>
</head>
<body>
<div class="login-card">
    <div class="brand-mark">
        <img src="/it-asset-manager/assets/img/PACIFIC_Cold_Chain_Logo2.png"
             alt="Pacific Cold Storage">
    </div>
    <div class="login-title">IT Asset Manager</div>
    <div class="login-sub">Pacific Cold Storage · เข้าสู่ระบบด้วย Central Auth</div>

    <?php if ($error !== ''): ?>
        <div class="alert alert-danger py-2 small mb-3">
            <i class="bi bi-exclamation-triangle me-1"></i>
            <?= $error /* error string จาก server — ไม่มี user input */ ?>
        </div>
    <?php endif; ?>

    <form method="post" novalidate>
        <div class="mb-3">
            <label class="form-label">Username</label>
            <input type="text" name="username" class="form-control"
                   placeholder="ชื่อผู้ใช้" autocomplete="username"
                   value="<?= htmlspecialchars((string)($_POST['username'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                   required autofocus>
        </div>
        <div class="mb-4">
            <label class="form-label">Password</label>
            <div class="input-group">
                <input type="password" name="password" id="pwd"
                       class="form-control" placeholder="รหัสผ่าน"
                       autocomplete="current-password" required>
                <button type="button" class="btn btn-outline-secondary"
                        onclick="togglePwd()" tabindex="-1">
                    <i class="bi bi-eye" id="eye-icon"></i>
                </button>
            </div>
        </div>
        <button type="submit" class="btn-login">
            <i class="bi bi-box-arrow-in-right me-1"></i> เข้าสู่ระบบ
        </button>
    </form>

    <div class="role-hint">
        <strong>สิทธิ์การเข้าใช้งาน</strong><br>
        ต้องได้รับสิทธิ์ <code>it_admin</code> / <code>it_staff</code> / <code>it_viewer</code>
        จาก IT Admin ก่อนเข้าใช้งานได้
    </div>
</div>

<script>
function togglePwd() {
    const pwd = document.getElementById('pwd');
    const icon = document.getElementById('eye-icon');
    if (pwd.type === 'password') {
        pwd.type = 'text';
        icon.className = 'bi bi-eye-slash';
    } else {
        pwd.type = 'password';
        icon.className = 'bi bi-eye';
    }
}
</script>
</body>
</html>
