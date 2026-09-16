<?php
/**
 * Reset Transaction Data — สำหรับล้างข้อมูลทดสอบก่อนขึ้นระบบจริง
 * public/administration/reset-transactions.php
 *
 * ล้างเฉพาะตาราง "transaction" (คำขอ/การจับคู่/ประวัติ) — ไม่แตะ master data
 * (hardware_assets, mobile_assets, network_assets, software_licenses, sites,
 * employees, user accounts) ยกเว้นคอลัมน์ status/assigned_employee_id บนตาราง
 * asset ที่เป็น "ผลลัพธ์ปัจจุบัน" ของ transaction จะถูกรีเซ็ตกลับด้วย
 * ไม่งั้นจะเกิด dangling state (เช่น status='On Loan' ค้างทั้งที่ asset_loans
 * ถูกลบไปแล้ว)
 *
 * เฉพาะ it_admin เท่านั้น + ต้องพิมพ์ "RESET" ยืนยันก่อนถึงจะกดได้จริง
 */
declare(strict_types=1);

require_once __DIR__ . '/../../config/db_connect.php';
require_once __DIR__ . '/../../config/auth.php';
require_role(['it_admin']);

require_once __DIR__ . '/../../includes/csrf.php';
$pdo = db();

/* ── ตารางที่จะ TRUNCATE (transaction data) — เรียงลำดับ child ก่อน parent
      เผื่อกรณีต้องเปิด FK_CHECKS กลับมาระหว่างทาง ────────────────────── */
$truncateTables = [
    'loan_extensions',
    'asset_loans',
    'access_request_cc',
    'access_request_steps',
    'access_requests',
    'software_allocation_map',
    'maintenance_logs',
    'employee_transaction_log',
];

$errors = [];
$done   = false;
$summaryBefore = [];

/* นับจำนวนจริงในแต่ละตาราง ไว้โชว์ก่อนกดยืนยัน */
foreach ($truncateTables as $t) {
    try {
        $summaryBefore[$t] = (int) $pdo->query("SELECT COUNT(*) FROM {$t}")->fetchColumn();
    } catch (PDOException $e) {
        $summaryBefore[$t] = null; // ตารางอาจไม่มีอยู่จริงในบางสภาพแวดล้อม
    }
}
$hwOnLoanCount  = (int) $pdo->query("SELECT COUNT(*) FROM hardware_assets WHERE status='On Loan'")->fetchColumn();
$mobOnLoanCount = (int) $pdo->query("SELECT COUNT(*) FROM mobile_assets WHERE status='On Loan'")->fetchColumn();
$netOnLoanCount = (int) $pdo->query("SELECT COUNT(*) FROM network_assets WHERE status='On Loan'")->fetchColumn();
$hwAssignedCount  = (int) $pdo->query("SELECT COUNT(*) FROM hardware_assets WHERE assigned_employee_id IS NOT NULL OR assigned_to_ad IS NOT NULL")->fetchColumn();
$mobAssignedCount = (int) $pdo->query("SELECT COUNT(*) FROM mobile_assets WHERE assigned_employee_id IS NOT NULL OR (user_name IS NOT NULL AND user_name <> '')")->fetchColumn();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $confirmText = trim((string)($_POST['confirm_text'] ?? ''));

    if ($confirmText !== 'RESET') {
        $errors['confirm'] = 'กรุณาพิมพ์คำว่า RESET (ตัวพิมพ์ใหญ่ทั้งหมด) ให้ตรงเป๊ะเพื่อยืนยัน';
    }

    if (!$errors) {
        try {
            $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');

            foreach ($truncateTables as $t) {
                if ($summaryBefore[$t] === null) continue; // ข้ามตารางที่ไม่มีอยู่จริง
                $pdo->exec("TRUNCATE TABLE {$t}");
            }

            /* รีเซ็ต derived status/employee_id บนตาราง master asset —
               เฉพาะ On Loan เท่านั้นที่ปรับ status (ไม่แตะ Retired/In Repair
               เพราะอาจเป็นข้อมูลจริงที่กรอกไว้ก่อนหน้า ไม่ใช่ผลจากการทดสอบ) */
            $pdo->exec("UPDATE hardware_assets SET status = 'Active' WHERE status = 'On Loan'");
            $pdo->exec("UPDATE hardware_assets SET assigned_employee_id = NULL, assigned_to_ad = NULL, user_display = NULL");

            $pdo->exec("UPDATE mobile_assets SET status = 'Active' WHERE status = 'On Loan'");
            $pdo->exec("UPDATE mobile_assets SET assigned_employee_id = NULL, user_name = NULL");

            $pdo->exec("UPDATE network_assets SET status = 'Active' WHERE status = 'On Loan'");

            $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');

            $currentUser = iam_user();
            error_log('[TRANSACTION RESET] executed by ' . ($currentUser['username'] ?? 'unknown') . ' at ' . date('Y-m-d H:i:s'));

            $done = true;
        } catch (PDOException $e) {
            $pdo->exec('SET FOREIGN_KEY_CHECKS = 1'); // กันค้างไว้แบบปิดตลอดไป ถ้า error กลางทาง
            error_log('[TRANSACTION RESET FAIL] ' . $e->getMessage());
            $errors['_general'] = 'รีเซ็ตไม่สำเร็จ: ' . $e->getMessage();
        }
    }
}

$page_title  = 'Reset Transaction Data';
$active_menu = 'reset_transactions';
require __DIR__ . '/../../includes/header.php';
?>

<div class="mb-3">
    <h2 class="h5 mb-0"><i class="bi bi-exclamation-octagon text-danger"></i> Reset Transaction Data</h2>
    <div class="text-muted small">สำหรับล้างข้อมูลทดสอบก่อนขึ้นระบบจริง — ลบถาวร กู้คืนไม่ได้</div>
</div>

<?php if ($done): ?>
    <div class="alert alert-success">
        <i class="bi bi-check-circle me-1"></i>
        รีเซ็ตข้อมูล transaction เรียบร้อยแล้ว — ตารางกิจกรรมทั้งหมดว่างเปล่า
        และสถานะอุปกรณ์ที่เคยเป็น On Loan ถูกปรับกลับเป็น Active แล้ว
    </div>
    <a href="/it-asset-manager/index.php" class="btn btn-primary">
        <i class="bi bi-house"></i> กลับหน้า Dashboard
    </a>
<?php else: ?>

    <div class="alert alert-warning">
        <i class="bi bi-shield-exclamation me-1"></i>
        <strong>สำรองฐานข้อมูลก่อนกดยืนยัน</strong> — เช่น
        <code>mysqldump -u &lt;user&gt; -p it_asset_mgmt &gt; backup_ก่อนรีเซ็ต.sql</code>
        การกระทำนี้ลบข้อมูลถาวร ไม่มีทางย้อนกลับผ่านหน้าเว็บได้
    </div>

    <?php if (!empty($errors['_general'])): ?>
        <div class="alert alert-danger"><?= e($errors['_general']) ?></div>
    <?php endif; ?>

    <div class="row g-3 mb-3">
        <div class="col-md-6">
            <div class="card h-100">
                <div class="card-header bg-danger-subtle"><strong>🗑️ จะถูกลบทั้งหมด</strong></div>
                <div class="card-body">
                    <table class="table table-sm mb-0">
                        <tbody>
                        <?php
                        $labels = [
                            'loan_extensions'          => 'ประวัติต่ออายุการยืม',
                            'asset_loans'               => 'คำขอยืม/รายการยืมทั้งหมด',
                            'access_request_cc'         => 'รายชื่อ CC ในคำขอสิทธิ์',
                            'access_request_steps'      => 'ขั้นตอนอนุมัติคำขอสิทธิ์',
                            'access_requests'           => 'คำขอสิทธิ์เข้าใช้งานโปรแกรม',
                            'software_allocation_map'   => 'การจับคู่ Software License กับอุปกรณ์/พนักงาน',
                            'maintenance_logs'          => 'บันทึกการซ่อม/PM',
                            'employee_transaction_log'  => 'ประวัติ (History) ของพนักงานทั้งหมด',
                        ];
                        foreach ($labels as $t => $label):
                            $c = $summaryBefore[$t] ?? null;
                        ?>
                            <tr>
                                <td><?= e($label) ?></td>
                                <td class="text-end">
                                    <?php if ($c === null): ?>
                                        <span class="text-muted">— (ไม่พบตาราง)</span>
                                    <?php else: ?>
                                        <strong class="text-danger"><?= number_format($c) ?></strong> แถว
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="card h-100">
                <div class="card-header bg-success-subtle"><strong>✅ จะถูกเก็บไว้ (master data)</strong></div>
                <div class="card-body small">
                    <ul class="mb-3">
                        <li>Hardware / Mobile / Network Assets (รายละเอียดอุปกรณ์)</li>
                        <li>Software Licenses (แคตตาล็อก license)</li>
                        <li>Sites, Employees (sync จาก HR), User accounts, สิทธิ์การเข้าถึง module</li>
                    </ul>
                    <div class="text-muted">
                        แต่จะ<strong>รีเซ็ตค่าที่เป็นผลจากการทดสอบ</strong> บนตาราง asset:
                    </div>
                    <ul class="mb-0">
                        <li>Hardware ที่สถานะ On Loan (<?= $hwOnLoanCount ?> เครื่อง) → กลับเป็น Active</li>
                        <li>Mobile ที่สถานะ On Loan (<?= $mobOnLoanCount ?> เครื่อง) → กลับเป็น Active</li>
                        <li>Network ที่สถานะ On Loan (<?= $netOnLoanCount ?> เครื่อง) → กลับเป็น Active</li>
                        <li>Hardware ที่ผูกพนักงาน/AD (<?= $hwAssignedCount ?> เครื่อง) → ล้างเป็นว่าง</li>
                        <li>Mobile ที่ผูกพนักงาน/AD (<?= $mobAssignedCount ?> เครื่อง) → ล้างเป็นว่าง</li>
                    </ul>
                </div>
            </div>
        </div>
    </div>

    <div class="card border-danger">
        <div class="card-header bg-white"><strong class="text-danger">ยืนยันการรีเซ็ต</strong></div>
        <div class="card-body">
            <form method="post" onsubmit="return confirm('ยืนยันอีกครั้ง — ลบข้อมูล transaction ทั้งหมดถาวร ไม่สามารถย้อนกลับได้ ทำ backup แล้วใช่ไหม?');">
                <?php csrf_field(); ?>
                <label class="form-label">พิมพ์ <code>RESET</code> เพื่อยืนยัน</label>
                <input type="text" name="confirm_text" class="form-control mb-2 <?= isset($errors['confirm'])?'is-invalid':'' ?>"
                       style="max-width:300px;" autocomplete="off" placeholder="RESET">
                <?php if (isset($errors['confirm'])): ?>
                    <div class="invalid-feedback d-block mb-2"><?= e($errors['confirm']) ?></div>
                <?php endif; ?>
                <button type="submit" class="btn btn-danger">
                    <i class="bi bi-trash3"></i> ยืนยันการรีเซ็ต — ลบถาวร
                </button>
            </form>
        </div>
    </div>

<?php endif; ?>

<?php require __DIR__ . '/../../includes/footer.php'; ?>