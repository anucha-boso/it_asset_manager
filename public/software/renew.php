<?php
/**
 * =============================================================================
 *  Software License — Renewal History
 *  public/software/renew.php?id=<software_id>
 * -----------------------------------------------------------------------------
 *  หน้านี้ทำ 2 อย่าง:
 *   1. ฟอร์มบันทึกการต่ออายุใหม่ (renewal_date, new_expiry, cost, notes)
 *      -> INSERT ลง software_license_renewals
 *      -> UPDATE software_licenses.expiry_date ให้ตรงกับค่าล่าสุด
 *      ใช้ PDO Transaction เพราะเขียน 2 ตารางพร้อมกัน ต้องสำเร็จทั้งคู่หรือไม่สำเร็จเลย
 *   2. ตารางประวัติการต่ออายุทั้งหมดของ license นี้
 * =============================================================================
 */
declare(strict_types=1);
require_once __DIR__ . '/../../config/db_connect.php';
require_once __DIR__ . '/../../config/auth.php';

require_once __DIR__ . '/../../includes/module_access.php';
require_role(['it_admin','it_staff']);
require_module_access('SOFTWARE');


require_once __DIR__ . '/../../includes/csrf.php';
$pdo = db();

$swId = isset($_GET['id']) && ctype_digit((string)$_GET['id']) ? (int)$_GET['id'] : 0;
if ($swId <= 0) { http_response_code(400); exit('Missing software id'); }

// load license
$licStmt = $pdo->prepare("SELECT * FROM software_licenses WHERE id = :id");
$licStmt->execute([':id' => $swId]);
$license = $licStmt->fetch();
if (!$license) { http_response_code(404); exit('License not found'); }

$errors = [];

// -----------------------------------------------------------------------------
//  POST — บันทึกการต่ออายุใหม่
// -----------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    $renewalDate = trim((string)($_POST['renewal_date'] ?? ''));
    $newExpiry   = trim((string)($_POST['new_expiry']   ?? ''));
    $cost        = trim((string)($_POST['cost']         ?? ''));
    $notes       = trim((string)($_POST['notes']        ?? '')) ?: null;

    if ($renewalDate === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $renewalDate)) {
        $errors['renewal_date'] = 'กรุณาระบุวันที่ต่ออายุให้ถูกต้อง';
    }
    if ($newExpiry === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $newExpiry)) {
        $errors['new_expiry'] = 'กรุณาระบุวันหมดอายุใหม่ให้ถูกต้อง';
    }
    if ($cost !== '' && !is_numeric($cost)) {
        $errors['cost'] = 'ค่าใช้จ่ายต้องเป็นตัวเลข';
    }
    // วันหมดอายุใหม่ควรมากกว่าวันเดิม (กันกรอกผิดสลับก่อน-หลัง)
    if (!$errors && $license['expiry_date'] && $newExpiry <= $license['expiry_date']) {
        $errors['new_expiry'] = 'วันหมดอายุใหม่ต้องมากกว่าวันหมดอายุเดิม (' . $license['expiry_date'] . ')';
    }

    if (!$errors) {
        try {
            $pdo->beginTransaction();

            $insStmt = $pdo->prepare("
                INSERT INTO software_license_renewals
                    (software_id, renewal_date, previous_expiry, new_expiry, cost, notes)
                VALUES
                    (:sid, :rdate, :prev_exp, :new_exp, :cost, :notes)
            ");
            $insStmt->execute([
                ':sid'      => $swId,
                ':rdate'    => $renewalDate,
                ':prev_exp' => $license['expiry_date'],
                ':new_exp'  => $newExpiry,
                ':cost'     => $cost !== '' ? (float)$cost : null,
                ':notes'    => $notes,
            ]);

            $updStmt = $pdo->prepare("
                UPDATE software_licenses SET expiry_date = :new_exp WHERE id = :id
            ");
            $updStmt->execute([
                ':new_exp' => $newExpiry,
                ':id'      => $swId,
            ]);

            $pdo->commit();

            $_SESSION['flash'] = ['type'=>'success','message'=>'บันทึกการต่ออายุเรียบร้อย'];
            header("Location: /it-asset-manager/software/renew.php?id={$swId}");
            exit;
        } catch (PDOException $e) {
            $pdo->rollBack();
            error_log('[RENEWAL SAVE FAIL] ' . $e->getMessage());
            $errors['_general'] = 'บันทึกไม่สำเร็จ: ' . $e->getMessage();
        }
    }
}

// -----------------------------------------------------------------------------
//  Read — ประวัติการต่ออายุทั้งหมด
// -----------------------------------------------------------------------------
$renewals = $pdo->prepare("
    SELECT * FROM software_license_renewals
    WHERE software_id = :id
    ORDER BY renewal_date DESC, id DESC
");
$renewals->execute([':id' => $swId]);
$renewals = $renewals->fetchAll();

$flash = $_SESSION['flash'] ?? null; unset($_SESSION['flash']);
$page_title  = 'Renewal History · ' . $license['software_name'];
$active_menu = 'software';
require __DIR__ . '/../../includes/header.php';
?>

<div class="mb-3">
    <a href="/it-asset-manager/software/index.php" class="text-decoration-none small text-muted">
        <i class="bi bi-arrow-left"></i> กลับไปหน้า Software
    </a>
    <h2 class="h5 mb-0 mt-1"><?= e($license['software_name']) ?></h2>
    <div class="text-muted small">
        <?= e($license['software_id']) ?> · <?= e($license['publisher']) ?>
        · หมดอายุปัจจุบัน: <strong><?= e($license['expiry_date'] ?: 'ไม่มีกำหนด') ?></strong>
    </div>
</div>

<?php if ($flash): ?>
    <div class="alert alert-<?= e($flash['type']) ?> alert-dismissible fade show">
        <?= e($flash['message']) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<?php if (!empty($errors['_general'])): ?>
    <div class="alert alert-danger"><?= e($errors['_general']) ?></div>
<?php endif; ?>

<!-- ========== Renew form ========== -->
<div class="card mb-4">
    <div class="card-header bg-white"><strong>บันทึกการต่ออายุ</strong></div>
    <div class="card-body">
        <form method="post" class="row g-2 align-items-end">
            <?php csrf_field(); ?>
            <div class="col-md-3">
                <label class="form-label small text-muted mb-1">วันที่ต่ออายุ</label>
                <input type="date" name="renewal_date" value="<?= e(date('Y-m-d')) ?>"
                       class="form-control form-control-sm <?= isset($errors['renewal_date'])?'is-invalid':'' ?>">
                <?php if (isset($errors['renewal_date'])): ?>
                    <div class="invalid-feedback"><?= e($errors['renewal_date']) ?></div>
                <?php endif; ?>
            </div>
            <div class="col-md-3">
                <label class="form-label small text-muted mb-1">วันหมดอายุใหม่ <span class="text-danger">*</span></label>
                <input type="date" name="new_expiry"
                       class="form-control form-control-sm <?= isset($errors['new_expiry'])?'is-invalid':'' ?>">
                <?php if (isset($errors['new_expiry'])): ?>
                    <div class="invalid-feedback"><?= e($errors['new_expiry']) ?></div>
                <?php endif; ?>
            </div>
            <div class="col-md-2">
                <label class="form-label small text-muted mb-1">ค่าใช้จ่าย (THB)</label>
                <input type="number" step="0.01" min="0" name="cost"
                       class="form-control form-control-sm <?= isset($errors['cost'])?'is-invalid':'' ?>">
                <?php if (isset($errors['cost'])): ?>
                    <div class="invalid-feedback"><?= e($errors['cost']) ?></div>
                <?php endif; ?>
            </div>
            <div class="col-md-3">
                <label class="form-label small text-muted mb-1">Notes</label>
                <input type="text" name="notes" maxlength="200" class="form-control form-control-sm">
            </div>
            <div class="col-md-1">
                <button class="btn btn-sm btn-primary w-100">
                    <i class="bi bi-plus-lg"></i>
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ========== Renewal history ========== -->
<div class="card">
    <div class="card-header bg-white"><strong>ประวัติการต่ออายุ</strong></div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th>วันที่ต่อ</th>
                    <th>หมดอายุเดิม</th>
                    <th>หมดอายุใหม่</th>
                    <th class="text-end">ค่าใช้จ่าย</th>
                    <th>Notes</th>
                </tr>
            </thead>
            <tbody>
            <?php if (!$renewals): ?>
                <tr><td colspan="5" class="text-center text-muted py-4">ยังไม่มีประวัติการต่ออายุ</td></tr>
            <?php else: foreach ($renewals as $r): ?>
                <tr>
                    <td><?= e($r['renewal_date']) ?></td>
                    <td class="text-muted small"><?= e($r['previous_expiry'] ?: '—') ?></td>
                    <td class="fw-semibold"><?= e($r['new_expiry']) ?></td>
                    <td class="text-end"><?= $r['cost'] !== null ? e(number_format((float)$r['cost'], 2)) : '—' ?></td>
                    <td class="text-muted small"><?= e($r['notes'] ?: '') ?></td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require __DIR__ . '/../../includes/footer.php'; ?>