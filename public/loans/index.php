<?php
/**
 * Loan System — List / Dashboard
 * /var/www/lab/it-asset-manager/public/loans/index.php
 *
 * it_borrower: เห็นเฉพาะ loan ของตัวเอง + ปุ่ม New Loan เท่านั้น
 */
declare(strict_types=1);

require_once __DIR__ . '/../../config/db_connect.php';
require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../includes/module_access.php';
require_role(['it_admin','it_staff','it_viewer','it_borrower']);
require_module_access('LOANS');

$pdo  = db();
$user = iam_user();
$isBorrowerOnly = is_borrower_only();

/* ── Auto-sweep overdue ──────────────────────────────────────── */
if (!$isBorrowerOnly) {
    $pdo->exec("
        UPDATE asset_loans
        SET status = 'Overdue'
        WHERE status = 'OnLoan'
          AND expected_return < CURRENT_DATE
    ");
}

/* ── Filters ─────────────────────────────────────────────────── */
$tab    = in_array($_GET['tab'] ?? '', ['active','pending','history','all'], true)
        ? $_GET['tab'] : 'active';
$search = trim((string)($_GET['q'] ?? ''));

$where  = [];
$params = [];

/* it_borrower เห็นเฉพาะ loan ของตัวเอง */
if ($isBorrowerOnly) {
    $where[]              = "l.borrower_ad = :my_ad";
    $params[':my_ad']     = $user['username'];
    /* borrower ไม่มี tab pending/history — แสดงทั้งหมดของตัวเอง */
    $tab = 'all';
} else {
    switch ($tab) {
        case 'active':
            $where[] = "l.status IN ('OnLoan','Overdue')"; break;
        case 'pending':
            $where[] = "l.status = 'Pending'"; break;
        case 'history':
            $where[] = "l.status IN ('Returned','Lost','Damaged')"; break;
    }
}

if ($search !== '') {
    $where[]       = "(l.loan_code LIKE :q1 OR l.borrower_name LIKE :q2
                    OR l.borrower_ad LIKE :q3 OR h.asset_id LIKE :q4
                    OR m.asset_id LIKE :q5)";
    $qLike = '%' . $search . '%';
    $params[':q1'] = $qLike;
    $params[':q2'] = $qLike;
    $params[':q3'] = $qLike;
    $params[':q4'] = $qLike;
    $params[':q5'] = $qLike;
}
$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

/* ── KPI (เฉพาะ admin/staff/viewer) ─────────────────────────── */
$kpi = ['active'=>0,'overdue'=>0,'pending'=>0,'returned_month'=>0];
if (!$isBorrowerOnly) {
    $kpi = $pdo->query("
        SELECT
            SUM(status IN ('OnLoan','Overdue'))        AS active,
            SUM(status = 'Overdue')                    AS overdue,
            SUM(status = 'Pending')                    AS pending,
            SUM(status = 'Returned'
                AND MONTH(actual_return) = MONTH(CURRENT_DATE)
                AND YEAR(actual_return)  = YEAR(CURRENT_DATE)) AS returned_month
        FROM asset_loans
    ")->fetch();
}

/* ── Loanable pool (เฉพาะ admin/staff/viewer) ───────────────── */
$loanableHW  = 0;
$loanableMob = 0;
if (!$isBorrowerOnly) {
    $loanableHW = (int)$pdo->query("
        SELECT COUNT(*) FROM hardware_assets
        WHERE is_loanable = 1 AND status IN ('Active','In Stock')
          AND id NOT IN (SELECT asset_id FROM asset_loans
                         WHERE status IN ('OnLoan','Approved','Overdue')
                           AND asset_id IS NOT NULL)
    ")->fetchColumn();

    $loanableMob = (int)$pdo->query("
        SELECT COUNT(*) FROM mobile_assets
        WHERE is_loanable = 1 AND status IN ('Active','In Stock')
          AND id NOT IN (SELECT mobile_id FROM asset_loans
                         WHERE status IN ('OnLoan','Approved','Overdue')
                           AND mobile_id IS NOT NULL)
    ")->fetchColumn();
}

/* ── Main query ──────────────────────────────────────────────── */
$loans = $pdo->prepare("
    SELECT
        l.*,
        DATEDIFF(l.expected_return, CURRENT_DATE) AS days_left,
        h.asset_id   AS hw_code, h.brand AS hw_brand, h.model AS hw_model,
        h.category   AS hw_category,
        m.asset_id   AS mob_code, m.brand AS mob_brand, m.model AS mob_model,
        m.device_type,
        s.site_name  AS borrower_site,
        (SELECT COUNT(*) FROM loan_extensions e WHERE e.loan_id = l.id) AS ext_count
    FROM asset_loans l
    LEFT JOIN hardware_assets h ON h.id = l.asset_id
    LEFT JOIN mobile_assets   m ON m.id = l.mobile_id
    LEFT JOIN sites           s ON s.id = l.borrower_site_id
    {$whereSql}
    ORDER BY
        FIELD(l.status,'Overdue','OnLoan','Pending','Approved','Returned','Lost','Damaged'),
        l.expected_return ASC
    LIMIT 200
");
$loans->execute($params);
$loanList = $loans->fetchAll();

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

$page_title  = $isBorrowerOnly ? 'การยืมของฉัน' : 'Loan System';
$active_menu = 'loans';
require __DIR__ . '/../../includes/header.php';
?>

<?php if ($flash): ?>
<div class="alert alert-<?= e($flash['type']) ?> alert-dismissible fade show">
    <?= e($flash['message']) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<?php if ($isBorrowerOnly): ?>
<!-- ── Borrower view: banner แสดงตัวตน ────────────────────────── -->
<div class="alert alert-info d-flex align-items-center gap-2 mb-3 py-2">
    <i class="bi bi-person-circle fs-5"></i>
    <div>
        <strong><?= e($user['fullname']) ?></strong>
        <span class="text-muted ms-1">— แสดงเฉพาะรายการยืมของคุณ</span>
    </div>
    <a href="/it-asset-manager/loans/scan.php"
       class="btn btn-sm btn-outline-primary ms-auto">
        <i class="bi bi-qr-code-scan"></i> สแกนยืม
    </a>
    <a href="/it-asset-manager/loans/form.php"
       class="btn btn-sm btn-primary">
        <i class="bi bi-plus-lg"></i> ขอยืมอุปกรณ์
    </a>
</div>

<?php else: ?>
<!-- ── Admin/Staff/Viewer: KPI Cards ──────────────────────────── -->
<div class="row g-3 mb-3">
    <div class="col-6 col-md-3 col-xl">
        <div class="card metric-card metric-blue h-100">
            <div class="card-body">
                <div class="metric-label">กำลังยืมอยู่</div>
                <div class="metric-value"><?= number_format((int)$kpi['active']) ?></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3 col-xl">
        <div class="card metric-card metric-red h-100">
            <div class="card-body">
                <div class="metric-label">เลยกำหนด</div>
                <div class="metric-value"><?= number_format((int)$kpi['overdue']) ?></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3 col-xl">
        <div class="card metric-card metric-amber h-100">
            <div class="card-body">
                <div class="metric-label">รออนุมัติ</div>
                <div class="metric-value"><?= number_format((int)$kpi['pending']) ?></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3 col-xl">
        <div class="card metric-card metric-teal h-100">
            <div class="card-body">
                <div class="metric-label">คืนแล้ว (เดือนนี้)</div>
                <div class="metric-value"><?= number_format((int)$kpi['returned_month']) ?></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3 col-xl">
        <div class="card h-100" style="border-left:4px solid #8b5cf6;">
            <div class="card-body">
                <div class="metric-label">Pool ว่าง</div>
                <div class="metric-value"><?= $loanableHW + $loanableMob ?></div>
                <div class="metric-sub">HW <?= $loanableHW ?> · MOB <?= $loanableMob ?></div>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- ── Tabs + Search ─────────────────────────────────────────── -->
<div class="card mb-3">
    <div class="card-body py-2">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">

            <?php if (!$isBorrowerOnly): ?>
            <ul class="nav nav-pills nav-sm mb-0" style="gap:4px;">
                <?php
                $tabs = [
                    'active'  => ['label'=>'Active / Overdue', 'badge'=>(int)$kpi['active']],
                    'active'  => ['label'=>'ระหว่างยืม', 'badge'=>(int)$kpi['active']],
                    'pending' => ['label'=>'รออนุมัติ',         'badge'=>(int)$kpi['pending']],
                    'history' => ['label'=>'ประวัติ',            'badge'=>0],
                    'all'     => ['label'=>'ทั้งหมด',            'badge'=>0],
                ];
                foreach ($tabs as $key => $info):
                    $isActive = $tab === $key ? 'active' : '';
                ?>
                    <li class="nav-item">
                        <a class="nav-link py-1 px-3 <?= $isActive ?>"
                           href="?tab=<?= $key ?><?= $search ? '&q='.urlencode($search) : '' ?>">
                            <?= e($info['label']) ?>
                            <?php if ($info['badge'] > 0): ?>
                                <span class="badge text-bg-danger ms-1"><?= $info['badge'] ?></span>
                            <?php endif; ?>
                        </a>
                    </li>
                <?php endforeach; ?>
            </ul>
            <?php else: ?>
            <span class="text-muted small">รายการยืมทั้งหมดของคุณ</span>
            <?php endif; ?>

            <div class="d-flex gap-2 align-items-center">
                <form method="get" class="d-flex gap-2">
                    <?php if (!$isBorrowerOnly): ?>
                        <input type="hidden" name="tab" value="<?= e($tab) ?>">
                    <?php endif; ?>
                    <div class="input-group input-group-sm">
                        <span class="input-group-text bg-white"><i class="bi bi-search"></i></span>
                        <input type="text" name="q" value="<?= e($search) ?>"
                               class="form-control" style="width:180px;"
                               placeholder="Loan code, Asset ID">
                    </div>
                    <button class="btn btn-sm btn-outline-secondary">ค้นหา</button>
                </form>
                <?php if (can('request_loan')): ?>
                    <a href="/it-asset-manager/loans/scan.php"
                       class="btn btn-sm btn-outline-primary">
                        <i class="bi bi-qr-code-scan"></i> สแกนยืม
                    </a>
                    <a href="/it-asset-manager/loans/form.php"
                       class="btn btn-sm btn-primary">
                        <i class="bi bi-plus-lg"></i>
                        <?= $isBorrowerOnly ? 'ขอยืม' : 'New Loan' ?>
                    </a>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- ── Table ──────────────────────────────────────────────────── -->
<div class="card">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th>Loan Code</th>
                    <th>อุปกรณ์</th>
                    <?php if (!$isBorrowerOnly): ?>
                        <th>ผู้ยืม</th>
                    <?php endif; ?>
                    <th>วัตถุประสงค์</th>
                    <th>วันยืม</th>
                    <th>กำหนดคืน</th>
                    <th>Status</th>
                    <th class="text-end" style="width:<?= $isBorrowerOnly ? '80' : '140' ?>px;">
                        Actions
                    </th>
                </tr>
            </thead>
            <tbody>
            <?php if (!$loanList): ?>
                <tr>
                    <td colspan="<?= $isBorrowerOnly ? 7 : 8 ?>"
                        class="text-center text-muted py-5">
                        <i class="bi bi-inbox" style="font-size:32px;"></i>
                        <div class="mt-2">
                            <?= $isBorrowerOnly ? 'คุณยังไม่มีรายการยืม' : 'ไม่พบรายการ' ?>
                        </div>
                    </td>
                </tr>
            <?php else: foreach ($loanList as $l):
                $stClass = match($l['status']) {
                    'OnLoan'   => 'text-bg-primary',
                    'Overdue'  => 'text-bg-danger',
                    'Pending'  => 'text-bg-warning',
                    'Approved' => 'text-bg-info',
                    'Returned' => 'text-bg-success',
                    'Lost'     => 'text-bg-danger',
                    'Damaged'  => 'text-bg-secondary',
                    default    => 'text-bg-light border',
                };
                $days = (int)$l['days_left'];
                if ($l['status'] === 'Returned') {
                    $dueBadge = ''; $dueLabel = '';
                } elseif ($days < 0) {
                    $dueBadge = 'text-bg-danger';
                    $dueLabel = 'เลย ' . abs($days) . ' วัน';
                } elseif ($days <= 3) {
                    $dueBadge = 'text-bg-warning';
                    $dueLabel = 'เหลือ ' . $days . ' วัน';
                } else {
                    $dueBadge = ''; $dueLabel = '';
                }
                $assetCode = $l['hw_code']
                    ? ($l['hw_code'] . ' · ' . $l['hw_brand'] . ' ' . $l['hw_model'])
                    : ($l['mob_code'] . ' · ' . $l['mob_brand'] . ' ' . $l['mob_model']);
            ?>
                <tr class="<?= $l['status']==='Overdue' ? 'table-danger' : '' ?>">
                    <td>
                        <a href="/it-asset-manager/loans/detail.php?id=<?= e($l['id']) ?>"
                           class="fw-semibold text-decoration-none font-monospace">
                            <?= e($l['loan_code']) ?>
                        </a>
                        <?php if ($l['ext_count'] > 0): ?>
                            <span class="badge text-bg-light border text-muted ms-1"
                                  style="font-size:9px;">
                                ต่ออายุ <?= $l['ext_count'] ?>x
                            </span>
                        <?php endif; ?>
                    </td>
                    <td class="small">
                        <div><?= e($assetCode) ?></div>
                        <span class="text-muted">
                            <?= e($l['hw_category'] ?: $l['device_type'] ?: '') ?>
                        </span>
                    </td>
                    <?php if (!$isBorrowerOnly): ?>
                    <td>
                        <div><?= e($l['borrower_name']) ?></div>
                        <div class="text-muted small">
                            <?= e($l['borrower_ad']) ?>
                            <?= $l['borrower_dept'] ? '· ' . e($l['borrower_dept']) : '' ?>
                        </div>
                    </td>
                    <?php endif; ?>
                    <td class="small">
                        <div><?= e(mb_substr($l['purpose'] ?? '', 0, 40)) ?></div>
                        <span class="badge text-bg-light border text-muted"
                              style="font-size:10px;"><?= e($l['purpose_type']) ?></span>
                    </td>
                    <td class="small text-muted"><?= e($l['loan_date']) ?></td>
                    <td class="small">
                        <div><?= e($l['expected_return']) ?></div>
                        <?php if ($dueBadge): ?>
                            <span class="badge <?= $dueBadge ?>"><?= e($dueLabel) ?></span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <span class="badge <?= $stClass ?>"><?= e($l['status']) ?></span>
                    </td>
                    <td class="text-end">
                        <div class="btn-group btn-group-sm">
                            <a href="/it-asset-manager/loans/detail.php?id=<?= e($l['id']) ?>"
                               class="btn btn-outline-secondary" title="ดูรายละเอียด">
                                <i class="bi bi-eye"></i>
                            </a>
                            <?php if (!$isBorrowerOnly): ?>
                                <?php if ($l['status'] === 'Pending'): ?>
                                    <?php if (can('checkout_loan')): ?>
                                        <a href="/it-asset-manager/loans/checkout.php?id=<?= e($l['id']) ?>"
                                           class="btn btn-outline-success" title="ปล่อยของ">
                                            <i class="bi bi-box-arrow-right"></i>
                                        </a>
                                    <?php endif; ?>
                                <?php elseif (in_array($l['status'], ['OnLoan','Overdue'])): ?>
                                    <?php if (can('return_loan')): ?>
                                        <a href="/it-asset-manager/loans/return.php?id=<?= e($l['id']) ?>"
                                           class="btn btn-outline-primary" title="รับคืน">
                                            <i class="bi bi-box-arrow-in-left"></i>
                                        </a>
                                    <?php endif; ?>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require __DIR__ . '/../../includes/footer.php'; ?>