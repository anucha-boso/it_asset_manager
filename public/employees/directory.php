<?php
/**
 * Employee Directory — ภาพรวมพนักงานที่ถือ Asset/License/Loan/Access
 * public/employees/directory.php
 * เฉพาะ it_admin / it_staff
 *
 * เปลี่ยนจากเดิม (ต้องค้นหาก่อนถึงจะเห็นใคร + แสดงโปรไฟล์ซ้ำในหน้านี้)
 * เป็นตารางภาพรวม แล้วกดเข้า profile/index.php?emp=<id> แทน
 * → โปรไฟล์มีที่เดียว ตัวเลขตรงกันทุกหน้า
 *
 * Performance (ไม่มี N+1):
 *   Query 1 (it_asset_mgmt)  : UNION ALL + GROUP BY → map employee_id => counts (ครั้งเดียว)
 *   Query 2 (employee_db)    : ดึงข้อมูลพนักงานด้วย WHERE id IN (...) / หรือแบ่งหน้าเมื่อ show=all
 *   (2 DB คนละ connection จึงไม่ JOIN ข้าม database — รวมผลใน PHP)
 *
 * โหมด ?show=
 *   users    (default) : เฉพาะพนักงานที่มีรายการอย่างน้อย 1 อย่าง (รวมคนลาออกที่ยังถือของ)
 *   resigned            : เฉพาะคนลาออกแล้วแต่ยังมีรายการค้าง (ต้องเรียกคืน)
 *   all                 : พนักงาน Active ทั้งหมด (แบ่งหน้า)
 */
declare(strict_types=1);

require_once __DIR__ . '/../../config/db_connect.php';
require_once __DIR__ . '/../../config/employee_db.php';
require_once __DIR__ . '/../../config/auth.php';

require_role(['it_admin', 'it_staff']);

// ------ Backward compatibility: ลิงก์เดิม ?id=X → ไปหน้าโปรไฟล์ ------
if (isset($_GET['id']) && ctype_digit((string)$_GET['id']) && (int)$_GET['id'] > 0) {
    header('Location: /it-asset-manager/profile/index.php?emp=' . (int)$_GET['id']);
    exit;
}

$pdo = db();

// ------ Input ------
$searchQ = trim((string)($_GET['q'] ?? ''));
$show    = (string)($_GET['show'] ?? 'users');
if (!in_array($show, ['users', 'resigned', 'all'], true)) {
    $show = 'users';
}
$perPage = 50;
$page    = max(1, (int)($_GET['page'] ?? 1));

// escape wildcard ของ LIKE เพื่อให้ค้น "_" หรือ "%" ได้ตรงตัว
$like = '%' . addcslashes($searchQ, '%_\\') . '%';

// ------ Query 1: นับรายการต่อพนักงาน (ครั้งเดียวทั้งระบบ) ------
// Assets / Licenses นับทุกสถานะ (ตรงกับ KPI ในหน้า profile)
// Loans นับเฉพาะที่ยังไม่ปิด, Access นับ 1 ต่อ 1 แอป (dedupe เหมือนหน้า profile)
$countSql = "
    SELECT employee_id,
           SUM(src = 'asset') AS assets,
           SUM(src = 'lic')   AS licenses,
           SUM(src = 'loan')  AS loans,
           SUM(src = 'acc')   AS access_cnt
    FROM (
        SELECT assigned_employee_id AS employee_id, 'asset' AS src
          FROM hardware_assets WHERE assigned_employee_id IS NOT NULL
        UNION ALL
        SELECT assigned_employee_id, 'asset'
          FROM mobile_assets WHERE assigned_employee_id IS NOT NULL
        UNION ALL
        SELECT assigned_employee_id, 'lic'
          FROM software_allocation_map WHERE assigned_employee_id IS NOT NULL
        UNION ALL
        SELECT borrower_employee_id, 'loan'
          FROM asset_loans
         WHERE borrower_employee_id IS NOT NULL
           AND status IN ('Pending','Approved','OnLoan','Overdue')
        UNION ALL
        SELECT beneficiary_employee_id, 'acc'
          FROM access_requests
         WHERE beneficiary_employee_id IS NOT NULL AND status = 'Completed'
         GROUP BY beneficiary_employee_id, application_id
    ) t
    GROUP BY employee_id
";
$countMap = [];
foreach ($pdo->query($countSql)->fetchAll() as $r) {
    $countMap[(int)$r['employee_id']] = [
        'assets'   => (int)$r['assets'],
        'licenses' => (int)$r['licenses'],
        'loans'    => (int)$r['loans'],
        'access'   => (int)$r['access_cnt'],
    ];
}
$emptyCounts = ['assets' => 0, 'licenses' => 0, 'loans' => 0, 'access' => 0];

// ------ Query 2: ข้อมูลพนักงาน ------
$empCols  = "id, title, first_name, last_name, department, position, person_code, resign_status";
$rows     = [];
$total    = 0;
$resignedWithItems = 0;

$searchSql = '';
$searchParams = [];
if ($searchQ !== '') {
    // PDO native prepare: placeholder ห้ามซ้ำชื่อ
    $searchSql = " AND (first_name LIKE :q1 OR last_name LIKE :q2 OR person_code LIKE :q3)";
    $searchParams = [':q1' => $like, ':q2' => $like, ':q3' => $like];
}

if ($show === 'all') {
    // พนักงาน Active ทั้งหมด — แบ่งหน้าที่ SQL
    $cntStmt = employee_db()->prepare("SELECT COUNT(*) FROM employees WHERE resign_status = 'Active'" . $searchSql);
    $cntStmt->execute($searchParams);
    $total = (int)$cntStmt->fetchColumn();

    $offset = ($page - 1) * $perPage;
    $stmt = employee_db()->prepare("
        SELECT $empCols FROM employees
        WHERE resign_status = 'Active' $searchSql
        ORDER BY first_name, last_name
        LIMIT $perPage OFFSET $offset
    ");
    $stmt->execute($searchParams);
    $rows = $stmt->fetchAll();
} elseif ($countMap) {
    // users / resigned — ดึงเฉพาะ id ที่อยู่ใน map (หลักร้อยคน) แล้วแบ่งหน้าใน PHP
    $ids = array_keys($countMap);
    $ph  = [];
    $idParams = [];
    foreach ($ids as $i => $id) {
        $ph[] = ':id' . $i;
        $idParams[':id' . $i] = $id;
    }
    $stmt = employee_db()->prepare("
        SELECT $empCols FROM employees
        WHERE id IN (" . implode(',', $ph) . ") $searchSql
        ORDER BY first_name, last_name
    ");
    $stmt->execute($idParams + $searchParams);
    $matched = $stmt->fetchAll();

    foreach ($matched as $m) {
        if ($m['resign_status'] !== 'Active') $resignedWithItems++;
    }
    if ($show === 'resigned') {
        $matched = array_values(array_filter($matched, fn($m) => $m['resign_status'] !== 'Active'));
    }

    $total = count($matched);
    $rows  = array_slice($matched, ($page - 1) * $perPage, $perPage);
}

$totalPages = max(1, (int)ceil($total / $perPage));

/** สร้าง query string โดยคงค่า filter ปัจจุบันไว้ */
function dir_url(array $override): string {
    global $searchQ, $show, $page;
    $params = array_merge(['q' => $searchQ, 'show' => $show, 'page' => $page], $override);
    $params = array_filter($params, fn($v) => $v !== '' && $v !== null);
    return '?' . http_build_query($params);
}

/** badge ตัวเลข — 0 แสดงจางๆ */
function count_badge(int $n, string $cls): string {
    return $n > 0
        ? '<span class="badge ' . $cls . '">' . $n . '</span>'
        : '<span class="text-muted small">0</span>';
}

$page_title  = 'Employee Directory';
$active_menu = 'employee_directory';
require __DIR__ . '/../../includes/header.php';
?>

<!-- ====== Summary ====== -->
<div class="row g-3 mb-3">
    <div class="col-6 col-md-4">
        <div class="card metric-card metric-blue h-100">
            <div class="card-body py-3">
                <div class="metric-label small"><i class="bi bi-people"></i> พนักงานที่มีรายการในระบบ</div>
                <div class="metric-value"><?= count($countMap) ?></div>
            </div>
        </div>
    </div>
    <?php if ($show !== 'all' && $searchQ === ''): ?>
    <div class="col-6 col-md-4">
        <div class="card metric-card metric-red h-100">
            <div class="card-body py-3">
                <div class="metric-label small"><i class="bi bi-exclamation-triangle"></i> ลาออกแล้วแต่ยังมีรายการค้าง</div>
                <div class="metric-value"><?= $resignedWithItems ?></div>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<!-- ====== Filter bar ====== -->
<div class="card mb-3">
    <div class="card-body py-2">
        <form method="get" class="row g-2 align-items-center">
            <input type="hidden" name="show" value="<?= e($show) ?>">
            <div class="col-md-5">
                <div class="input-group input-group-sm">
                    <span class="input-group-text"><i class="bi bi-search"></i></span>
                    <input type="text" name="q" value="<?= e($searchQ) ?>" class="form-control"
                           placeholder="ค้นหาชื่อ นามสกุล หรือรหัสพนักงาน...">
                    <button class="btn btn-outline-secondary">ค้นหา</button>
                </div>
            </div>
            <div class="col-md-7 text-md-end">
                <div class="btn-group btn-group-sm" role="group">
                    <a href="<?= e(dir_url(['show' => 'users', 'page' => 1])) ?>"
                       class="btn <?= $show === 'users' ? 'btn-primary' : 'btn-outline-primary' ?>">ผู้ใช้ระบบ</a>
                    <a href="<?= e(dir_url(['show' => 'resigned', 'page' => 1])) ?>"
                       class="btn <?= $show === 'resigned' ? 'btn-danger' : 'btn-outline-danger' ?>">ลาออก/มีของค้าง</a>
                    <a href="<?= e(dir_url(['show' => 'all', 'page' => 1])) ?>"
                       class="btn <?= $show === 'all' ? 'btn-secondary' : 'btn-outline-secondary' ?>">พนักงานทั้งหมด</a>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- ====== Table ====== -->
<div class="card">
    <div class="card-header bg-white d-flex justify-content-between align-items-center">
        <strong><i class="bi bi-person-lines-fill"></i> รายชื่อพนักงาน</strong>
        <span class="text-muted small">ทั้งหมด <?= number_format($total) ?> คน</span>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th>พนักงาน</th>
                    <th>แผนก / ตำแหน่ง</th>
                    <th class="text-center" style="width:90px;">Assets</th>
                    <th class="text-center" style="width:90px;">Licenses</th>
                    <th class="text-center" style="width:90px;">Loans</th>
                    <th class="text-center" style="width:90px;">Access</th>
                    <th style="width:40px;"></th>
                </tr>
            </thead>
            <tbody>
            <?php if (!$rows): ?>
                <tr><td colspan="7" class="text-center text-muted py-5">
                    <i class="bi bi-inbox" style="font-size:32px;"></i>
                    <div class="mt-2">ไม่พบพนักงานที่ตรงกับเงื่อนไข</div>
                </td></tr>
            <?php else: foreach ($rows as $emp):
                $eid      = (int)$emp['id'];
                $c        = $countMap[$eid] ?? $emptyCounts;
                $isResign = $emp['resign_status'] !== 'Active';
                $name     = trim($emp['title'] . ' ' . $emp['first_name'] . ' ' . $emp['last_name']);
                $url      = '/it-asset-manager/profile/index.php?emp=' . $eid;
            ?>
                <tr style="cursor:pointer;" onclick="window.location.href='<?= e($url) ?>'">
                    <td>
                        <a href="<?= e($url) ?>" class="fw-semibold text-decoration-none"><?= e($name) ?></a>
                        <?php if ($isResign): ?>
                            <span class="badge text-bg-danger ms-1"><?= e((string)$emp['resign_status']) ?></span>
                        <?php endif; ?>
                        <div class="text-muted small"><?= e((string)$emp['person_code']) ?></div>
                    </td>
                    <td class="small">
                        <?= e($emp['department'] ?? '—') ?>
                        <?php if (!empty($emp['position'])): ?>
                            <div class="text-muted"><?= e($emp['position']) ?></div>
                        <?php endif; ?>
                    </td>
                    <td class="text-center"><?= count_badge($c['assets'],   'text-bg-primary') ?></td>
                    <td class="text-center"><?= count_badge($c['licenses'], 'text-bg-warning') ?></td>
                    <td class="text-center"><?= count_badge($c['loans'],    'text-bg-info') ?></td>
                    <td class="text-center"><?= count_badge($c['access'],   'text-bg-danger') ?></td>
                    <td class="text-end text-muted"><i class="bi bi-chevron-right"></i></td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>

    <?php if ($totalPages > 1): ?>
    <div class="card-footer bg-white">
        <nav>
            <ul class="pagination pagination-sm justify-content-center mb-0">
                <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                    <a class="page-link" href="<?= e(dir_url(['page' => $page - 1])) ?>">&laquo;</a>
                </li>
                <?php
                $start = max(1, $page - 3);
                $end   = min($totalPages, $page + 3);
                for ($p = $start; $p <= $end; $p++): ?>
                    <li class="page-item <?= $p === $page ? 'active' : '' ?>">
                        <a class="page-link" href="<?= e(dir_url(['page' => $p])) ?>"><?= $p ?></a>
                    </li>
                <?php endfor; ?>
                <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
                    <a class="page-link" href="<?= e(dir_url(['page' => $page + 1])) ?>">&raquo;</a>
                </li>
            </ul>
        </nav>
    </div>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/../../includes/footer.php'; ?>