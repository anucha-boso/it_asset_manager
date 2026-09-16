<?php
/**
 * CSRF helper — ใช้กับทุกฟอร์มที่มีการเปลี่ยนข้อมูล (POST/DELETE)
 *
 * วิธีใช้ในฟอร์ม:
 *     <?php csrf_field(); ?>
 *
 * วิธีใช้ฝั่งรับ:
 *     csrf_verify();   // จะ exit เองถ้า token ไม่ตรง
 */
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function csrf_token(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field(): void {
    $t = csrf_token();
    echo '<input type="hidden" name="_csrf" value="' . htmlspecialchars($t, ENT_QUOTES) . '">';
}

function csrf_verify(): void {
    $sent = $_POST['_csrf'] ?? '';
    $real = $_SESSION['csrf_token'] ?? '';
    if (!is_string($sent) || !is_string($real) || !hash_equals($real, $sent)) {
        http_response_code(419);
        exit('CSRF token mismatch. กรุณารีโหลดหน้าและลองใหม่');
    }
}
