<?php
/**
 * Loan Return
 * /var/www/lab/it-asset-manager/public/loans/return.php?id=N
 */
declare(strict_types=1);
require_once __DIR__ . '/../../config/db_connect.php';
require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../includes/module_access.php';
require_role(['it_admin','it_staff']);
require_module_access('LOANS');



require_once __DIR__ . '/../../includes/csrf.php';
require_once __DIR__ . '/../../includes/employee_log.php';
$pdo = db();

$id = isset($_GET['id']) && ctype_digit((string)$_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) { http_response_code(400); exit('Missing id'); }

$loan = $pdo->prepare("
    SELECT l.*,
        h.asset_id AS hw_code, h.brand AS hw_brand, h.model AS hw_model,
        m.asset_id AS mob_code, m.brand AS mob_brand, m.model AS mob_model,
        n.asset_id AS net_code, n.brand AS net_brand, n.model AS net_model,
        s.site_name AS borrower_site
    FROM asset_loans l
    LEFT JOIN hardware_assets h ON h.id = l.asset_id
    LEFT JOIN mobile_assets   m ON m.id = l.mobile_id
    LEFT JOIN network_assets  n ON n.id = l.network_asset_id
    LEFT JOIN sites           s ON s.id = l.borrower_site_id
    WHERE l.id = :id
");
$loan->execute([':id' => $id]);
$loan = $loan->fetch();
if (!$loan) { http_response_code(404); exit('Loan not found'); }
if (!in_array($loan['status'], ['OnLoan','Overdue'], true)) {
    $_SESSION['flash'] = ['type'=>'warning','message'=>'รายการนี้ไม่อยู่ในสถานะที่รับคืนได้'];
    header('Location: /it-asset-manager/loans/index.php'); exit;
}

/* Extensions */
$extensions = $pdo->prepare("
    SELECT * FROM loan_extensions WHERE loan_id = :lid ORDER BY created_at ASC
");
$extensions->execute([':lid' => $id]);
$extensions = $extensions->fetchAll();

$errors = [];
$currentUser = iam_user(); // ใช้ทั้งโหมด GET (แสดงชื่อในฟอร์ม) และ POST (บันทึกจริง)
$receivedByDisplay = $currentUser['fullname'] ?? $currentUser['username'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? 'return';

    if ($action === 'extend') {
        /* ── ต่ออายุ ── */
        $newDate  = trim($_POST['new_return_date'] ?? '');
        $reason   = trim($_POST['extend_reason']   ?? '');
        $reqBy    = trim($_POST['extend_req_by']   ?? '');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $newDate))
            $errors['new_return_date'] = 'รูปแบบวันที่ไม่ถูกต้อง';
        if (!$errors && $newDate <= $loan['expected_return'])
            $errors['new_return_date'] = 'วันใหม่ต้องหลังกำหนดคืนเดิม';
        if (!$errors) {
            try {
                $pdo->beginTransaction();
                $pdo->prepare("
                    INSERT INTO loan_extensions
                        (loan_id, old_return_date, new_return_date, reason, requested_by)
                    VALUES (:lid, :old, :new, :reason, :req_by)
                ")->execute([
                    ':lid'    => $id,
                    ':old'    => $loan['expected_return'],
                    ':new'    => $newDate,
                    ':reason' => $reason ?: null,
                    ':req_by' => $reqBy  ?: null,
                ]);
                $pdo->prepare("
                    UPDATE asset_loans
                    SET expected_return = :new,
                        status = CASE WHEN status='Overdue' THEN 'OnLoan' ELSE status END
                    WHERE id = :id
                ")->execute([':new' => $newDate, ':id' => $id]);
                $pdo->commit();
                $_SESSION['flash'] = ['type'=>'success','message'=>'ต่ออายุการยืมเรียบร้อย'];
                header('Location: /it-asset-manager/loans/return.php?id=' . $id); exit;
            } catch (PDOException $e) {
                $pdo->rollBack();
                $errors['_general'] = 'บันทึกไม่สำเร็จ: ' . $e->getMessage();
            }
        }

    } else {
        /* ── รับคืน ── */
        $returnDate   = trim($_POST['actual_return']  ?? date('Y-m-d'));
        $conditionIn  = trim($_POST['condition_in']   ?? '');
        $accessoriesIn= trim($_POST['accessories_in'] ?? '');
        $receivedBy   = $receivedByDisplay; // จาก session เสมอ
        $returnStatus = $_POST['return_status'] ?? 'Returned';

        if (!in_array($returnStatus, ['Returned','Damaged','Lost'], true))
            $returnStatus = 'Returned';

        if (!$errors) {
            try {
                $pdo->beginTransaction();
                $pdo->prepare("
                    UPDATE asset_loans SET
                        status         = :status,
                        actual_return  = :ret_date,
                        condition_in   = :cond_in,
                        accessories_in = :acc_in,
                        received_by    = :recv_by
                    WHERE id = :id
                ")->execute([
                    ':status'   => $returnStatus,
                    ':ret_date' => $returnDate,
                    ':cond_in'  => $conditionIn   ?: null,
                    ':acc_in'   => $accessoriesIn ?: null,
                    ':recv_by'  => $receivedBy,
                    ':id'       => $id,
                ]);

                /* คืน asset status */
                $newAssetStatus = $returnStatus === 'Damaged' ? 'In Repair' : 'Active';
                if ($loan['asset_id']) {
                    $pdo->prepare("UPDATE hardware_assets SET status=:st WHERE id=:id")
                        ->execute([':st' => $newAssetStatus, ':id' => $loan['asset_id']]);
                }
                if ($loan['mobile_id']) {
                    $pdo->prepare("UPDATE mobile_assets SET status=:st WHERE id=:id")
                        ->execute([':st' => $newAssetStatus === 'In Repair' ? 'ชำรุด' : 'Active',
                                   ':id' => $loan['mobile_id']]);
                }
                if ($loan['network_asset_id']) {
                    $pdo->prepare("UPDATE network_assets SET status=:st WHERE id=:id")
                        ->execute([':st' => $newAssetStatus, ':id' => $loan['network_asset_id']]);
                }

                $pdo->commit();

                // ── History log ──────────────────────────────────────────
                if ($loan['borrower_employee_id']) {
                    $returnLabel = $loan['hw_code']
                        ? "{$loan['hw_code']} · {$loan['hw_brand']} {$loan['hw_model']}"
                        : ($loan['mob_code']
                            ? "{$loan['mob_code']} · {$loan['mob_brand']} {$loan['mob_model']}"
                            : "{$loan['net_code']} · {$loan['net_brand']} {$loan['net_model']}");
                    logEmployeeTransaction(
                        $pdo,
                        (int)$loan['borrower_employee_id'],
                        'Asset Return',
                        'asset_loans',
                        $id,
                        'Loan return ' . $returnLabel . ' (' . $loan['loan_code'] . ', ' . $returnStatus . ')',
                        $currentUser['username'] ?? null
                    );
                }

                $msg = match($returnStatus) {
                    'Damaged' => "รับคืน {$loan['loan_code']} — สภาพชำรุด อุปกรณ์ถูกเปลี่ยนเป็น In Repair",
                    'Lost'    => "บันทึก {$loan['loan_code']} — อุปกรณ์สูญหาย",
                    default   => "รับคืน {$loan['loan_code']} เรียบร้อย",
                };
                $_SESSION['flash'] = ['type'=>'success','message'=>$msg];
                header('Location: /it-asset-manager/loans/index.php'); exit;

            } catch (PDOException $e) {
                $pdo->rollBack();
                $errors['_general'] = 'บันทึกไม่สำเร็จ: ' . $e->getMessage();
            }
        }
    }
}

$assetLabel = $loan['hw_code']
    ? "{$loan['hw_code']} · {$loan['hw_brand']} {$loan['hw_model']}"
    : ($loan['mob_code']
        ? "{$loan['mob_code']} · {$loan['mob_brand']} {$loan['mob_model']}"
        : "{$loan['net_code']} · {$loan['net_brand']} {$loan['net_model']}");

$page_title  = 'Return · ' . $loan['loan_code'];
$active_menu = 'loans';
require __DIR__ . '/../../includes/header.php';
?>

<div class="mb-3">
    <a href="/it-asset-manager/loans/index.php"
       class="text-decoration-none small text-muted">
        <i class="bi bi-arrow-left"></i> กลับรายการ
    </a>
    <h2 class="h5 mb-0 mt-1">
        รับคืน — <?= e($loan['loan_code']) ?>
        <?php if ($loan['status'] === 'Overdue'): ?>
            <span class="badge text-bg-danger ms-1">Overdue</span>
        <?php else: ?>
            <span class="badge text-bg-primary ms-1">OnLoan</span>
        <?php endif; ?>
    </h2>
</div>

<?php if (!empty($errors['_general'])): ?>
    <div class="alert alert-danger"><?= e($errors['_general']) ?></div>
<?php endif; ?>

<?php
$flash = $_SESSION['flash'] ?? null; unset($_SESSION['flash']);
if ($flash): ?>
<div class="alert alert-<?= e($flash['type']) ?> alert-dismissible fade show">
    <?= e($flash['message']) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<!-- ── สรุปข้อมูล ─────────────────────────────────────────────── -->
<div class="card mb-3">
    <div class="card-header bg-white"><strong>สรุปข้อมูลการยืม</strong></div>
    <div class="card-body">
        <div class="row g-2 small">
            <div class="col-md-4"><span class="text-muted">อุปกรณ์:</span>
                <strong class="ms-1"><?= e($assetLabel) ?></strong></div>
            <div class="col-md-4"><span class="text-muted">ผู้ยืม:</span>
                <span class="ms-1"><?= e($loan['borrower_name']) ?></span></div>
            <div class="col-md-4"><span class="text-muted">วันยืม:</span>
                <span class="ms-1"><?= e($loan['loan_date']) ?></span></div>
            <div class="col-md-4"><span class="text-muted">กำหนดคืนเดิม:</span>
                <strong class="ms-1 text-danger"><?= e($loan['expected_return']) ?></strong></div>
            <div class="col-md-8"><span class="text-muted">สภาพตอนยืม:</span>
                <span class="ms-1"><?= e($loan['condition_out'] ?: '—') ?></span></div>
            <?php if ($loan['accessories_out']): ?>
            <div class="col-12"><span class="text-muted">อุปกรณ์เสริมที่ส่งมอบ:</span>
                <span class="ms-1"><?= e($loan['accessories_out']) ?></span></div>
            <?php endif; ?>
        </div>
        <?php if ($extensions): ?>
        <div class="mt-2 pt-2 border-top small text-muted">
            ประวัติต่ออายุ:
            <?php foreach ($extensions as $ext): ?>
                <span class="badge text-bg-light border ms-1">
                    <?= e($ext['old_return_date']) ?> → <?= e($ext['new_return_date']) ?>
                </span>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- ── Return Form ────────────────────────────────────────────── -->
<div class="row g-3">
    <div class="col-md-7">
        <div class="card">
            <div class="card-header bg-white"><strong>รับคืนอุปกรณ์</strong></div>
            <div class="card-body">
                <form method="post" novalidate>
                <?php csrf_field(); ?>
                <input type="hidden" name="action" value="return">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label">วันที่รับคืนจริง</label>
                        <input type="date" name="actual_return" class="form-control"
                               value="<?= date('Y-m-d') ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">ผู้รับคืน (IT Staff)</label>
                        <input type="text" class="form-control" value="<?= e($receivedByDisplay) ?>" disabled>
                        <div class="form-text">ดึงจากบัญชีที่ login อยู่อัตโนมัติ (<?= e($currentUser['username'] ?? '') ?>)</div>
                    </div>
                    <div class="col-12">
                        <label class="form-label">สภาพเครื่องตอนรับคืน</label>
                        <textarea name="condition_in" rows="2" class="form-control"
                                  placeholder="สภาพปกติ / มีรอยขีดข่วนเพิ่มที่... / จอแตก"></textarea>
                    </div>
                    <div class="col-12">
                        <label class="form-label">อุปกรณ์เสริมที่รับคืน</label>
                        <textarea name="accessories_in" rows="2" class="form-control"
                                  placeholder="ระบุอุปกรณ์ที่รับคืน / อุปกรณ์ที่ขาด"></textarea>
                    </div>
                    <div class="col-12">
                        <label class="form-label">ผลการตรวจรับ</label>
                        <div class="btn-group w-100">
                            <input type="radio" class="btn-check" name="return_status"
                                   id="rs_ok" value="Returned" checked>
                            <label class="btn btn-outline-success" for="rs_ok">
                                <i class="bi bi-check-circle"></i> ปกติ
                            </label>
                            <input type="radio" class="btn-check" name="return_status"
                                   id="rs_dmg" value="Damaged">
                            <label class="btn btn-outline-warning" for="rs_dmg">
                                <i class="bi bi-exclamation-triangle"></i> ชำรุด
                            </label>
                            <input type="radio" class="btn-check" name="return_status"
                                   id="rs_lost" value="Lost">
                            <label class="btn btn-outline-danger" for="rs_lost">
                                <i class="bi bi-x-circle"></i> สูญหาย
                            </label>
                        </div>
                    </div>
                </div>
                <div class="mt-3">
                    <button type="submit" class="btn btn-primary w-100">
                        <i class="bi bi-box-arrow-in-left"></i> ยืนยันรับคืน
                    </button>
                </div>
                </form>
            </div>
        </div>
    </div>

    <!-- ── ต่ออายุ ────────────────────────────────────────────── -->
    <div class="col-md-5">
        <div class="card">
            <div class="card-header bg-white"><strong>ขอต่ออายุการยืม</strong></div>
            <div class="card-body">
                <form method="post" novalidate>
                <?php csrf_field(); ?>
                <input type="hidden" name="action" value="extend">
                <div class="mb-3">
                    <label class="form-label">วันกำหนดคืนใหม่ <span class="text-danger">*</span></label>
                    <input type="date" name="new_return_date"
                           class="form-control <?= isset($errors['new_return_date'])?'is-invalid':'' ?>"
                           min="<?= date('Y-m-d', strtotime($loan['expected_return'] . ' +1 day')) ?>">
                    <?php if (isset($errors['new_return_date'])): ?>
                        <div class="invalid-feedback"><?= e($errors['new_return_date']) ?></div>
                    <?php endif; ?>
                </div>
                <div class="mb-3">
                    <label class="form-label">เหตุผล</label>
                    <input type="text" name="extend_reason" maxlength="255"
                           class="form-control" placeholder="งานยังไม่เสร็จ / รออนุมัติงบ">
                </div>
                <div class="mb-3">
                    <label class="form-label">ผู้ขอต่ออายุ</label>
                    <input type="text" name="extend_req_by" maxlength="100"
                           class="form-control" placeholder="ชื่อผู้ยืมหรือหัวหน้า">
                </div>
                <button type="submit" class="btn btn-outline-secondary w-100">
                    <i class="bi bi-calendar-plus"></i> ต่ออายุการยืม
                </button>
                </form>
            </div>
        </div>
    </div>
</div>

<div class="mb-5"></div>
<?php require __DIR__ . '/../../includes/footer.php'; ?>