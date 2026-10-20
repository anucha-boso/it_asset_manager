<?php
/**
 * Access Requests — List
 * public/access-requests/index.php?tab=approve|it|mine|history
 *
 * 2026-10 redesign:
 *  - แบ่งเป็นแท็บ: รอฉันอนุมัติ / รอ IT ดำเนินการ (เฉพาะ it_admin, it_staff) / คำขอของฉัน / ประวัติที่ฉันพิจารณา
 *  - การ์ดรออนุมัติแสดง: ใคร → ระบบ·level, เหตุผลย่อ, รอมาแล้วกี่วัน
 *  - ระยะเวลารอคำนวณใน MySQL (TIMESTAMPDIFF) เพราะ timezone PHP-FPM ไม่ตรงกับ DB
 *  - แก้บั๊กเดิม: <<td, colspan="4" (ตารางมี 6 คอลัมน์), </div> เกิน
 */
declare(strict_types=1);

require_once __DIR__ . '/../../config/db_connect.php';
require_once __DIR__ . '/../../config/employee_db.php';
require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../includes/csrf.php';
require_once __DIR__ . '/../../includes/require_employee_link.php';
require_once __DIR__ . '/../../includes/module_access.php';
require_once __DIR__ . '/../../config/notification.php';

require_role(['it_admin', 'it_staff', 'it_viewer', 'it_borrower']);
require_module_access('ACCESS_REQUESTS');

$pdo        = db();
$employeeId = require_employee_link();
// ------ เช็คว่ามี email เก็บไว้ไหม (soft check ไม่บล็อกการใช้งาน) ------
$myEmail    = getEmployeeEmails([$employeeId]);
$myRole     = $_SESSION['iam_role'] ?? '';
$isItAdmin  = in_array($myRole, ['it_admin', 'it_staff'], true);
$myAd       = $_SESSION['iam_username'] ?? null;

// ------------------------------------------------------------------
//  1) รอฉันอนุมัติ (Approver1/Approver2) — ใช้ทั้งแสดงผลและตัวเลขบนแท็บ
// ------------------------------------------------------------------
$approvalStmt = $pdo->prepare("
    SELECT s.id AS step_id, s.step_role,
           r.id AS request_id, r.request_no, r.reason, r.requestor_name, r.department,
           r.beneficiary_name, r.beneficiary_employee_id, r.requestor_employee_id,
           r.access_level_text, r.requested_at,
           TIMESTAMPDIFF(MINUTE, r.requested_at, NOW()) AS wait_minutes,
           a.app_name, lv.level_name
    FROM access_request_steps s
    JOIN access_requests r ON r.id = s.request_id
    JOIN access_applications a ON a.id = r.application_id
    LEFT JOIN access_application_levels lv ON lv.id = r.access_level_id
    WHERE s.action = 'Pending'
      AND s.step_role IN ('APPROVER1','APPROVER2')
      AND s.assignee_employee_id = :eid
      AND r.status NOT IN ('Completed','Rejected','Cancelled')
    ORDER BY r.requested_at ASC
");
$approvalStmt->execute([':eid' => $employeeId]);
$pendingApprovals = $approvalStmt->fetchAll();

// ------------------------------------------------------------------
//  2) รอ IT ดำเนินการ (เฉพาะ it_admin/it_staff)
// ------------------------------------------------------------------
$pendingItAction = [];
if ($isItAdmin) {
    $itStmt = $pdo->query("
        SELECT s.id AS step_id, s.step_role,
               r.id AS request_id, r.request_no, r.reason, r.requestor_name, r.department,
               r.beneficiary_name, r.beneficiary_employee_id, r.requestor_employee_id,
               r.access_level_text, r.requested_at,
               TIMESTAMPDIFF(MINUTE, r.requested_at, NOW()) AS wait_minutes,
               a.app_name, a.it_action_note, lv.level_name
        FROM access_request_steps s
        JOIN access_requests r ON r.id = s.request_id
        JOIN access_applications a ON a.id = r.application_id
        LEFT JOIN access_application_levels lv ON lv.id = r.access_level_id
        WHERE s.action = 'Pending' AND s.step_role = 'IT_ACTION'
          AND r.status NOT IN ('Completed','Rejected','Cancelled')
        ORDER BY r.requested_at ASC
    ");
    $pendingItAction = $itStmt->fetchAll();
}

// ------------------------------------------------------------------
//  เลือกแท็บ (whitelist) — ถ้าไม่ระบุ: มีงานรออนุมัติ → approve, IT มีงาน → it, ไม่งั้น mine
// ------------------------------------------------------------------
$allowedTabs = ['approve', 'mine', 'history'];
if ($isItAdmin) $allowedTabs[] = 'it';

$tab = (string)($_GET['tab'] ?? '');
if (!in_array($tab, $allowedTabs, true)) {
    $tab = $pendingApprovals ? 'approve' : (($isItAdmin && $pendingItAction) ? 'it' : 'mine');
}

// ------------------------------------------------------------------
//  3) คำขอของฉัน (query เฉพาะตอนเปิดแท็บนี้)
// ------------------------------------------------------------------
$myRequests = [];
if ($tab === 'mine') {
    $myStmt = $pdo->prepare("
        SELECT r.id, r.request_no, r.status, r.requested_at, r.beneficiary_name,
               r.requestor_employee_id, r.beneficiary_employee_id,
               r.access_level_text, lv.level_name, a.app_name
        FROM access_requests r
        JOIN access_applications a ON a.id = r.application_id
        LEFT JOIN access_application_levels lv ON lv.id = r.access_level_id
        WHERE r.requestor_employee_id = :eid
        ORDER BY r.requested_at DESC
        LIMIT 100
    ");
    $myStmt->execute([':eid' => $employeeId]);
    $myRequests = $myStmt->fetchAll();
}

// ------------------------------------------------------------------
//  4) ประวัติที่ฉันพิจารณา: step ที่ฉันเป็นผู้อนุมัติ + step IT ที่ฉันกดดำเนินการ
//     (placeholder ต้องไม่ซ้ำกันเพราะ ATTR_EMULATE_PREPARES = false)
// ------------------------------------------------------------------
$history = [];
if ($tab === 'history') {
    $hStmt = $pdo->prepare("
        SELECT s.action, s.step_role, s.acted_at, s.comment,
               r.id AS request_id, r.request_no, r.status,
               r.beneficiary_name, r.beneficiary_employee_id, r.requestor_employee_id,
               r.access_level_text, a.app_name, lv.level_name
        FROM access_request_steps s
        JOIN access_requests r ON r.id = s.request_id
        JOIN access_applications a ON a.id = r.application_id
        LEFT JOIN access_application_levels lv ON lv.id = r.access_level_id
        WHERE (s.assignee_employee_id = :eid AND s.action IN ('Approved','Rejected'))
           OR (s.step_role = 'IT_ACTION' AND s.acted_by_ad = :ad AND s.action IN ('Actioned','Rejected'))
        ORDER BY s.acted_at DESC
        LIMIT 100
    ");
    $hStmt->execute([':eid' => $employeeId, ':ad' => $myAd ?? '']);
    $history = $hStmt->fetchAll();
}

// ==================================================================
//  VIEW HELPERS
// ==================================================================
$roleLabels = ['APPROVER1' => 'Approver 1', 'APPROVER2' => 'Approver 2', 'IT_ACTION' => 'IT ดำเนินการ'];

$ageText = function (int $minutes): string {
    $minutes = max(0, $minutes);
    if ($minutes >= 1440) return intdiv($minutes, 1440) . ' วัน';
    if ($minutes >= 60)   return intdiv($minutes, 60) . ' ชม.';
    return max(1, $minutes) . ' นาที';
};
// สีตามระยะรอ: ≥3 วันแดง, ≥1 วันส้ม, น้อยกว่านั้นเทา
$ageClass = fn(int $m): string => $m >= 4320 ? 'text-danger' : ($m >= 1440 ? 'text-warning-emphasis' : 'text-body');

$fmtDate = fn(?string $dt): string => $dt ? date('d/m/Y H:i', strtotime($dt)) : '—';

$statusBadge = fn(string $st): array => match ($st) {
    'Completed'         => ['เสร็จสมบูรณ์', 'ar-pill-ok'],
    'Rejected'          => ['ถูกปฏิเสธ', 'ar-pill-bad'],
    'Cancelled'         => ['ยกเลิก', 'ar-pill-muted'],
    'Pending IT Action' => ['รอ IT ดำเนินการ', 'ar-pill-info'],
    default             => ['รออนุมัติ', 'ar-pill-wait'],
};

$actionBadge = fn(string $act): array => match ($act) {
    'Approved' => ['อนุมัติ', 'ar-pill-ok'],
    'Actioned' => ['ดำเนินการแล้ว', 'ar-pill-ok'],
    'Rejected' => ['ปฏิเสธ', 'ar-pill-bad'],
    default    => [$act, 'ar-pill-muted'],
};

// ผู้รับสิทธิ์: external / ตัวเอง / คนอื่น
$beneficiaryKind = function (array $r): string {
    if ($r['beneficiary_employee_id'] === null) return 'external';
    return (int)$r['beneficiary_employee_id'] === (int)$r['requestor_employee_id'] ? 'self' : 'other';
};

$levelOf = fn(array $r): string => $r['level_name'] ?: ($r['access_level_text'] ?: '');

$tabUrl = fn(string $t): string => '/it-asset-manager/access-requests/index.php?tab=' . $t;

$flash = $_SESSION['flash'] ?? null; unset($_SESSION['flash']);
$page_title  = 'Access Requests';
$active_menu = 'access_requests';
require __DIR__ . '/../../includes/header.php';
?>

<style>
/* ---- Access Requests list (scoped ด้วย prefix ar-) ---- */
.ar-pill { display:inline-block; font-size:.75rem; font-weight:600; padding:.1rem .55rem; border-radius:999px; white-space:nowrap; }
.ar-pill-wait  { color:#8A4B00; background:#FFF1D6; }
.ar-pill-info  { color:#0F5E73; background:#DCEFF3; }
.ar-pill-ok    { color:#0F6B45; background:#DDF3E8; }
.ar-pill-bad   { color:#B42318; background:#FDE7E4; }
.ar-pill-muted { color:#52606D; background:#EEF1F4; }
.ar-no { font-family:ui-monospace,SFMono-Regular,Menlo,monospace; font-size:.75rem; color:#52606D; background:#EEF1F4; padding:.05rem .45rem; border-radius:6px; }
.ar-tabs .nav-link { color:#52606D; }
.ar-tabs .nav-link.active { font-weight:600; color:#111827; }
.ar-count { font-size:.7rem; padding:.05rem .45rem; border-radius:999px; background:#E2E5E9; color:#3E4C59; margin-left:.25rem; }
.ar-count-hot { background:#B45309; color:#fff; }
.ar-card { transition:border-color .15s; }
.ar-card:hover { border-color:#9AA5B1; }
.ar-reason { overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
.ar-age-label { font-size:.7rem; color:#7B8794; }
.ar-row-link { cursor:pointer; }
</style>

<?php if ($flash): ?>
    <div class="alert alert-<?= e($flash['type']) ?> alert-dismissible fade show">
        <?= e($flash['message']) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<?php if (!$myEmail): ?>
<div class="alert alert-warning d-flex justify-content-between align-items-center">
    <span><i class="bi bi-exclamation-triangle"></i> คุณยังไม่ได้ตั้งค่า Email รับการแจ้งเตือน</span>
    <a href="/it-asset-manager/profile/index.php" class="btn btn-sm btn-warning">ตั้งค่าเลย</a>
</div>
<?php endif; ?>

<!-- ====== Header ====== -->
<div class="d-flex flex-wrap justify-content-between align-items-end gap-2 mb-3">
    <div class="text-muted small">คำขอสิทธิ์เข้าใช้งานระบบ</div>

    <a href="/it-asset-manager/access-requests/form.php" class="btn btn-primary">
        <i class="bi bi-plus-lg"></i> สร้างคำขอใหม่
    </a>
</div>

<!-- ====== Tabs ====== -->
<ul class="nav nav-underline ar-tabs mb-3 border-bottom">
    <li class="nav-item">
        <a class="nav-link <?= $tab === 'approve' ? 'active' : '' ?>" href="<?= e($tabUrl('approve')) ?>">
            รอฉันอนุมัติ
            <span class="ar-count <?= $pendingApprovals ? 'ar-count-hot' : '' ?>"><?= count($pendingApprovals) ?></span>
        </a>
    </li>
    <?php if ($isItAdmin): ?>
    <li class="nav-item">
        <a class="nav-link <?= $tab === 'it' ? 'active' : '' ?>" href="<?= e($tabUrl('it')) ?>">
            รอ IT ดำเนินการ
            <span class="ar-count <?= $pendingItAction ? 'ar-count-hot' : '' ?>"><?= count($pendingItAction) ?></span>
        </a>
    </li>
    <?php endif; ?>
    <li class="nav-item">
        <a class="nav-link <?= $tab === 'mine' ? 'active' : '' ?>" href="<?= e($tabUrl('mine')) ?>">คำขอของฉัน</a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?= $tab === 'history' ? 'active' : '' ?>" href="<?= e($tabUrl('history')) ?>">ประวัติที่ฉันพิจารณา</a>
    </li>
</ul>

<?php
// ------------------------------------------------------------------
//  แท็บ approve / it ใช้การ์ดหน้าตาเดียวกัน
// ------------------------------------------------------------------
if ($tab === 'approve' || $tab === 'it'):
    $rows  = $tab === 'approve' ? $pendingApprovals : $pendingItAction;
    $empty = $tab === 'approve' ? 'ไม่มีคำขอที่รอคุณอนุมัติ' : 'ไม่มีคำขอที่รอ IT ดำเนินการ';
?>
    <?php if (!$rows): ?>
        <div class="card"><div class="card-body text-center text-muted py-5">
            <i class="bi bi-check2-circle fs-2 d-block mb-2"></i><?= e($empty) ?>
        </div></div>
    <?php else: ?>
        <div class="d-flex flex-column gap-2">
        <?php foreach ($rows as $p):
            $kind  = $beneficiaryKind($p);
            $level = $levelOf($p);
            $wait  = (int)$p['wait_minutes'];
        ?>
            <div class="card ar-card">
                <div class="card-body d-flex flex-wrap align-items-center gap-3">
                    <div class="flex-grow-1" style="min-width:0; flex-basis:420px">
                        <div class="d-flex flex-wrap align-items-center gap-2 mb-1">
                            <span class="ar-no"><?= e($p['request_no']) ?></span>
                            <span class="ar-pill ar-pill-info"><?= e($roleLabels[$p['step_role']] ?? $p['step_role']) ?></span>
                            <?php if ($kind === 'external'): ?>
                                <span class="ar-pill ar-pill-wait">บุคคลภายนอก</span>
                            <?php endif; ?>
                        </div>
                        <div class="fw-semibold">
                            <?= e($p['beneficiary_name']) ?>
                            <?php if ($kind === 'self'): ?><span class="text-muted fw-normal small">(ขอให้ตัวเอง)</span><?php endif; ?>
                            <i class="bi bi-arrow-right mx-1 text-secondary" aria-hidden="true"></i>
                            <?= e($p['app_name']) ?><?php if ($level !== ''): ?> · <?= e($level) ?><?php endif; ?>
                        </div>
                        <div class="small text-muted ar-reason" title="<?= e($p['reason']) ?>">“<?= e($p['reason']) ?>”</div>
                        <div class="small text-secondary">
                            ยื่นโดย <?= e($p['requestor_name']) ?><?php if (!empty($p['department'])): ?> · <?= e($p['department']) ?><?php endif; ?>
                            <?php if ($tab === 'it' && !empty($p['it_action_note'])): ?>
                                · <i class="bi bi-info-circle"></i> <?= e($p['it_action_note']) ?>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="d-flex align-items-center gap-3 ms-auto">
                        <div class="text-end">
                            <div class="ar-age-label">รอมาแล้ว</div>
                            <div class="fw-bold <?= $ageClass($wait) ?>"><?= e($ageText($wait)) ?></div>
                        </div>
                        <a href="/it-asset-manager/access-requests/detail.php?id=<?= e((string)$p['request_id']) ?>"
                           class="btn btn-outline-primary">
                            <?= $tab === 'it' ? 'ดำเนินการ' : 'พิจารณา' ?>
                        </a>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
        </div>
    <?php endif; ?>

<?php
// ------------------------------------------------------------------
//  แท็บ mine
// ------------------------------------------------------------------
elseif ($tab === 'mine'): ?>
    <div class="card">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Request No.</th>
                        <th>ระบบ</th>
                        <th>ขอให้</th>
                        <th>Level</th>
                        <th>วันที่ขอ</th>
                        <th>สถานะ</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (!$myRequests): ?>
                    <tr><td colspan="6" class="text-center text-muted py-5">
                        ยังไม่มีคำขอ · <a href="/it-asset-manager/access-requests/form.php">สร้างคำขอใหม่</a>
                    </td></tr>
                <?php else: foreach ($myRequests as $r):
                    [$stText, $stClass] = $statusBadge($r['status']);
                    $kind = $beneficiaryKind($r);
                    $url  = '/it-asset-manager/access-requests/detail.php?id=' . (string)$r['id'];
                ?>
                    <tr class="ar-row-link" data-href="<?= e($url) ?>">
                        <td><a href="<?= e($url) ?>" class="ar-no text-decoration-none"><?= e($r['request_no']) ?></a></td>
                        <td class="fw-semibold"><?= e($r['app_name']) ?></td>
                        <td class="small">
                            <?php if ($kind === 'external'): ?>
                                <?= e($r['beneficiary_name']) ?> <span class="ar-pill ar-pill-wait">ภายนอก</span>
                            <?php elseif ($kind === 'self'): ?>
                                <span class="text-muted">ตัวเอง</span>
                            <?php else: ?>
                                <?= e($r['beneficiary_name']) ?>
                            <?php endif; ?>
                        </td>
                        <td class="text-muted small"><?= e($levelOf($r) ?: '—') ?></td>
                        <td class="text-muted small"><?= e($fmtDate($r['requested_at'])) ?></td>
                        <td><span class="ar-pill <?= $stClass ?>"><?= e($stText) ?></span></td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>

<?php
// ------------------------------------------------------------------
//  แท็บ history
// ------------------------------------------------------------------
else: ?>
    <div class="card">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Request No.</th>
                        <th>ผู้รับสิทธิ์ → ระบบ</th>
                        <th>ขั้นที่ฉันทำ</th>
                        <th>ผลของฉัน</th>
                        <th>วันที่</th>
                        <th>สถานะคำขอ</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (!$history): ?>
                    <tr><td colspan="6" class="text-center text-muted py-5">ยังไม่มีประวัติการพิจารณา</td></tr>
                <?php else: foreach ($history as $h):
                    [$acText, $acClass] = $actionBadge($h['action']);
                    [$stText, $stClass] = $statusBadge($h['status']);
                    $level = $levelOf($h);
                    $url   = '/it-asset-manager/access-requests/detail.php?id=' . (string)$h['request_id'];
                ?>
                    <tr class="ar-row-link" data-href="<?= e($url) ?>">
                        <td><a href="<?= e($url) ?>" class="ar-no text-decoration-none"><?= e($h['request_no']) ?></a></td>
                        <td class="small">
                            <?= e($h['beneficiary_name']) ?>
                            <i class="bi bi-arrow-right mx-1 text-secondary" aria-hidden="true"></i>
                            <span class="fw-semibold"><?= e($h['app_name']) ?></span><?php if ($level !== ''): ?> · <?= e($level) ?><?php endif; ?>
                        </td>
                        <td class="small text-muted"><?= e($roleLabels[$h['step_role']] ?? $h['step_role']) ?></td>
                        <td>
                            <span class="ar-pill <?= $acClass ?>"><?= e($acText) ?></span>
                            <?php if (!empty($h['comment'])): ?>
                                <div class="small text-muted ar-reason" style="max-width:220px" title="<?= e($h['comment']) ?>"><?= e($h['comment']) ?></div>
                            <?php endif; ?>
                        </td>
                        <td class="text-muted small"><?= e($fmtDate($h['acted_at'])) ?></td>
                        <td><span class="ar-pill <?= $stClass ?>"><?= e($stText) ?></span></td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>

<script>
// คลิกทั้งแถวในตารางเพื่อเปิดรายละเอียด (ลิงก์ในคอลัมน์แรกยังใช้ Tab/คีย์บอร์ดได้)
document.querySelectorAll('tr.ar-row-link').forEach(function (tr) {
    tr.addEventListener('click', function (e) {
        if (e.target.closest('a')) return;
        location.href = tr.dataset.href;
    });
});
</script>

<?php require __DIR__ . '/../../includes/footer.php'; ?>