<?php
/**
 * Loan Checkout — Approve + Hand Out
 * /var/www/lab/it-asset-manager/public/loans/checkout.php?id=N
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
        h.serial_number AS hw_serial, h.category,
        m.asset_id AS mob_code, m.brand AS mob_brand, m.model AS mob_model,
        m.serial_number AS mob_serial, m.device_type,
        n.asset_id AS net_code, n.brand AS net_brand, n.model AS net_model,
        n.device_type AS net_device_type,
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
if (!in_array($loan['status'], ['Pending','Approved'], true)) {
    $_SESSION['flash'] = ['type'=>'warning','message'=>'รายการนี้ไม่อยู่ในสถานะที่ checkout ได้'];
    header('Location: /it-asset-manager/loans/index.php'); exit;
}

/* Peripherals ของ HW asset */
$peripherals = [];
if ($loan['asset_id']) {
    $periStmt = $pdo->prepare("
        SELECT type, slot, serial_number FROM asset_peripherals
        WHERE asset_id = :aid ORDER BY type, slot
    ");
    $periStmt->execute([':aid' => $loan['asset_id']]);
    $peripherals = $periStmt->fetchAll();
}

$errors = [];
$currentUser = iam_user();
$handedOutBy = $currentUser['fullname'] ?? $currentUser['username']; // ดึงจาก session เสมอ ไม่ให้พิมพ์เอง — ต้องอยู่นอก if (POST) เพราะฟอร์มตอนโหลดหน้าแรก (GET) ก็ต้องโชว์ค่านี้ด้วย

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    $approvedBy    = trim($_POST['approved_by']  ?? '');
    $conditionOut  = trim($_POST['condition_out'] ?? '');
    $accessoriesOut= trim($_POST['accessories_out']?? '');

    if ($approvedBy === '')  $errors['approved_by']  = 'จำเป็นต้องระบุผู้อนุมัติ';

    if (!$errors) {
        try {
            $pdo->beginTransaction();

            /* อัปเดต loan */
            $pdo->prepare("
                UPDATE asset_loans SET
                    status = 'OnLoan',
                    approved_by = :approved_by,
                    approved_at = NOW(),
                    handed_out_by = :handed_out_by,
                    condition_out = :condition_out,
                    accessories_out = :accessories_out
                WHERE id = :id
            ")->execute([
                ':approved_by'     => $approvedBy,
                ':handed_out_by'   => $handedOutBy,
                ':condition_out'   => $conditionOut  ?: null,
                ':accessories_out' => $accessoriesOut?: null,
                ':id'              => $id,
            ]);

            /* อัปเดต asset status → On Loan */
            if ($loan['asset_id']) {
                $pdo->prepare("UPDATE hardware_assets SET status='On Loan' WHERE id=:id")
                    ->execute([':id' => $loan['asset_id']]);
            }
            if ($loan['mobile_id']) {
                $pdo->prepare("UPDATE mobile_assets SET status='On Loan' WHERE id=:id")
                    ->execute([':id' => $loan['mobile_id']]);
            }
            if ($loan['network_asset_id']) {
                $pdo->prepare("UPDATE network_assets SET status='On Loan' WHERE id=:id")
                    ->execute([':id' => $loan['network_asset_id']]);
            }

            $pdo->commit();

            // ── History log — เฉพาะตอนมี borrower_employee_id (ไม่ใช่บุคคลภายนอก) ──
            if ($loan['borrower_employee_id']) {
                $checkoutLabel = $loan['hw_code']
                    ? "{$loan['hw_code']} · {$loan['hw_brand']} {$loan['hw_model']}"
                    : ($loan['mob_code']
                        ? "{$loan['mob_code']} · {$loan['mob_brand']} {$loan['mob_model']}"
                        : "{$loan['net_code']} · {$loan['net_brand']} {$loan['net_model']}");
                logEmployeeTransaction(
                    $pdo,
                    (int)$loan['borrower_employee_id'],
                    'Asset Assign',
                    'asset_loans',
                    $id,
                    'Loan checkout ' . $checkoutLabel . ' (' . $loan['loan_code'] . ')',
                    $currentUser['username'] ?? null
                );
            }

            $_SESSION['flash'] = [
                'type'    => 'success',
                'message' => "ปล่อยของ {$loan['loan_code']} เรียบร้อย — สถานะ OnLoan",
            ];
            header('Location: /it-asset-manager/loans/detail.php?id=' . $id);
            exit;

        } catch (PDOException $e) {
            $pdo->rollBack();
            error_log('[LOAN CHECKOUT] ' . $e->getMessage());
            $errors['_general'] = 'บันทึกไม่สำเร็จ: ' . $e->getMessage();
        }
    }
}

$assetLabel = $loan['hw_code']
    ? "{$loan['hw_code']} · {$loan['hw_brand']} {$loan['hw_model']}"
    : ($loan['mob_code']
        ? "{$loan['mob_code']} · {$loan['mob_brand']} {$loan['mob_model']}"
        : "{$loan['net_code']} · {$loan['net_brand']} {$loan['net_model']}");

$page_title  = 'Checkout · ' . $loan['loan_code'];
$active_menu = 'loans';
require __DIR__ . '/../../includes/header.php';
?>

<div class="mb-3">
    <a href="/it-asset-manager/loans/index.php"
       class="text-decoration-none small text-muted">
        <i class="bi bi-arrow-left"></i> กลับรายการ
    </a>
    <h2 class="h5 mb-0 mt-1">
        ปล่อยของ — <?= e($loan['loan_code']) ?>
        <span class="badge text-bg-warning ms-1">Checkout</span>
    </h2>
</div>

<?php if (!empty($errors['_general'])): ?>
    <div class="alert alert-danger"><?= e($errors['_general']) ?></div>
<?php endif; ?>

<!-- ── สรุปข้อมูล Loan ────────────────────────────────────────── -->
<div class="card mb-3">
    <div class="card-header bg-white"><strong>สรุปข้อมูลการยืม</strong></div>
    <div class="card-body">
        <div class="row g-2 small">
            <div class="col-md-3"><span class="text-muted">อุปกรณ์:</span>
                <strong class="ms-1"><?= e($assetLabel) ?></strong></div>
            <div class="col-md-3"><span class="text-muted">ผู้ยืม:</span>
                <span class="ms-1"><?= e($loan['borrower_name']) ?> (<?= e($loan['borrower_ad']) ?>)</span></div>
            <div class="col-md-2"><span class="text-muted">วันยืม:</span>
                <span class="ms-1"><?= e($loan['loan_date']) ?></span></div>
            <div class="col-md-2"><span class="text-muted">กำหนดคืน:</span>
                <span class="ms-1"><?= e($loan['expected_return']) ?></span></div>
            <div class="col-md-2"><span class="text-muted">วัตถุประสงค์:</span>
                <span class="ms-1"><?= e($loan['purpose_type']) ?></span></div>
            <div class="col-12"><span class="text-muted">รายละเอียด:</span>
                <span class="ms-1"><?= e($loan['purpose']) ?></span></div>
        </div>
    </div>
</div>

<form method="post" novalidate style="max-width:860px;">
<?php csrf_field(); ?>

<!-- ── อุปกรณ์เสริม ──────────────────────────────────────────── -->
<?php if ($peripherals): ?>
<div class="card mb-3">
    <div class="card-header bg-white"><strong>อุปกรณ์เสริมที่ส่งมอบ</strong></div>
    <div class="card-body">
        <div class="row g-2">
            <?php foreach ($peripherals as $p): ?>
            <div class="col-md-6 small">
                <i class="bi bi-check-circle text-success me-1"></i>
                <strong><?= e($p['type']) ?> <?= $p['slot'] > 1 ? $p['slot'] : '' ?></strong>
                — <span class="font-monospace"><?= e($p['serial_number'] ?: '(ไม่มี S/N)') ?></span>
            </div>
            <?php endforeach; ?>
        </div>
        <div class="mt-3">
            <label class="form-label small text-muted">บันทึกอุปกรณ์เสริมที่ส่งมอบ</label>
            <textarea name="accessories_out" rows="2" class="form-control"
                      placeholder="Adapter, กระเป๋า, Mouse, Keyboard ฯลฯ"></textarea>
        </div>
    </div>
</div>
<?php else: ?>
<div class="card mb-3">
    <div class="card-body">
        <label class="form-label"><strong>อุปกรณ์เสริมที่ส่งมอบ</strong></label>
        <textarea name="accessories_out" rows="2" class="form-control"
                  placeholder="Adapter, กระเป๋า, Mouse, Keyboard ฯลฯ (ถ้ามี)"></textarea>
    </div>
</div>
<?php endif; ?>

<!-- ── Approval + สภาพ ────────────────────────────────────────── -->
<div class="card mb-3">
    <div class="card-header bg-white"><strong>การอนุมัติและสภาพเครื่อง</strong></div>
    <div class="card-body">
        <div class="row g-3">
            <div class="col-md-6">
                <label class="form-label">ผู้อนุมัติ <span class="text-danger">*</span></label>
                <input type="text" name="approved_by" id="approvedByInput" maxlength="100"
                       class="form-control <?= isset($errors['approved_by'])?'is-invalid':'' ?>"
                       placeholder="พิมพ์ค้นหาชื่อ นามสกุล หรือรหัสพนักงาน" autocomplete="off">
                <div id="approvedByResults" class="list-group mt-1"></div>
                <?php if (isset($errors['approved_by'])): ?>
                    <div class="invalid-feedback d-block"><?= e($errors['approved_by']) ?></div>
                <?php endif; ?>
                <div class="form-text">ค้นหาจากระบบ HR หรือพิมพ์ชื่อเองก็ได้ (เช่น หัวหน้าที่ไม่มีในระบบ)</div>
            </div>
            <div class="col-md-6">
                <label class="form-label">ผู้ปล่อยของ (IT Staff)</label>
                <input type="text" class="form-control" value="<?= e($handedOutBy) ?>" disabled>
                <div class="form-text">ดึงจากบัญชีที่ login อยู่อัตโนมัติ (<?= e($currentUser['username'] ?? '') ?>)</div>
            </div>
            <div class="col-12">
                <label class="form-label">สภาพเครื่องตอนส่งมอบ</label>
                <textarea name="condition_out" rows="2" class="form-control"
                          placeholder="สภาพปกติ / มีรอยขีดข่วนที่... / แบตเตอรี่ xx%"></textarea>
            </div>
        </div>
    </div>
</div>

<div class="alert alert-warning small">
    <i class="bi bi-exclamation-triangle me-1"></i>
    เมื่อกด <strong>ยืนยันการปล่อยของ</strong> ระบบจะเปลี่ยนสถานะอุปกรณ์เป็น
    <strong>On Loan</strong> ทันที และไม่สามารถยืมอุปกรณ์นี้ซ้ำได้จนกว่าจะคืน
</div>

<div class="d-flex justify-content-end gap-2 mb-5">
    <a href="/it-asset-manager/loans/index.php" class="btn btn-outline-secondary">
        <i class="bi bi-x-lg"></i> ยกเลิก
    </a>
    <button type="submit" class="btn btn-success">
        <i class="bi bi-box-arrow-right"></i> ยืนยันการปล่อยของ
    </button>
</div>

</form>
<script>
// ------ Approver live search — fill ชื่อลงช่อง text ตรงๆ (approved_by เป็น
//        VARCHAR ธรรมดา ไม่ผูก employee_id จึงไม่ต้องมี hidden field) ------
const apprInput  = document.getElementById('approvedByInput');
const apprResult = document.getElementById('approvedByResults');

let apprTimer = null;
apprInput.addEventListener('input', () => {
    clearTimeout(apprTimer);
    const q = apprInput.value.trim();
    apprResult.innerHTML = '';
    if (q.length < 2) return;
    apprTimer = setTimeout(() => {
        fetch('/it-asset-manager/access-requests/employee_search_ajax.php?q=' + encodeURIComponent(q))
            .then(r => r.json())
            .then(list => {
                apprResult.innerHTML = '';
                list.forEach(item => {
                    const btn = document.createElement('button');
                    btn.type = 'button';
                    btn.className = 'list-group-item list-group-item-action';
                    btn.innerHTML = '<div class="fw-semibold">' + item.label + '</div>'
                        + '<div class="text-muted small">' + item.sub + '</div>';
                    btn.addEventListener('click', () => {
                        apprInput.value = item.label;
                        apprResult.innerHTML = '';
                    });
                    apprResult.appendChild(btn);
                });
            });
    }, 300);
});
</script>

<?php require __DIR__ . '/../../includes/footer.php'; ?>