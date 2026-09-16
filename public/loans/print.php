<?php
/**
 * Loan Print — ใบยืมอุปกรณ์ IT (print-friendly)
 * /var/www/lab/it-asset-manager/public/loans/print.php?id=N
 */
declare(strict_types=1);
require_once __DIR__ . '/../../config/db_connect.php';
require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../includes/module_access.php';
require_role(['it_admin','it_staff','it_viewer','it_borrower']);
require_module_access('LOANS');



$pdo = db();

$id = isset($_GET['id']) && ctype_digit((string)$_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) { http_response_code(400); exit('Missing id'); }

$loan = $pdo->prepare("
    SELECT l.*,
        h.asset_id AS hw_code, h.brand AS hw_brand, h.model AS hw_model,
        h.serial_number AS hw_serial, h.category, h.department AS hw_dept,
        m.asset_id AS mob_code, m.brand AS mob_brand, m.model AS mob_model,
        m.serial_number AS mob_serial, m.device_type,
        n.asset_id AS net_code, n.brand AS net_brand, n.model AS net_model,
        n.device_type AS net_device_type,
        s.site_name AS borrower_site, s2.site_name AS asset_site
    FROM asset_loans l
    LEFT JOIN hardware_assets h ON h.id = l.asset_id
    LEFT JOIN mobile_assets   m ON m.id = l.mobile_id
    LEFT JOIN network_assets  n ON n.id = l.network_asset_id
    LEFT JOIN sites           s ON s.id  = l.borrower_site_id
    LEFT JOIN sites           s2 ON s2.id = (
        SELECT site_id FROM hardware_assets WHERE id = l.asset_id LIMIT 1
    )
    WHERE l.id = :id
");
$loan->execute([':id' => $id]);
$loan = $loan->fetch();
if (!$loan) { http_response_code(404); exit('Loan not found'); }
/* borrower ดูได้เฉพาะ loan ของตัวเองเท่านั้น */
if (is_borrower_only() && $loan['borrower_ad'] !== iam_user()['username']) {
    http_response_code(403);
    exit('ไม่มีสิทธิ์ดูรายการนี้');
}

/* Peripherals */
$peripherals = [];
if ($loan['asset_id']) {
    $ps = $pdo->prepare("SELECT * FROM asset_peripherals WHERE asset_id=:aid ORDER BY type,slot");
    $ps->execute([':aid' => $loan['asset_id']]);
    $peripherals = $ps->fetchAll();
}

$assetCode  = $loan['hw_code'] ?: ($loan['mob_code'] ?: $loan['net_code']);
$assetLabel = $loan['hw_code']
    ? "{$loan['hw_brand']} {$loan['hw_model']}"
    : ($loan['mob_code']
        ? "{$loan['mob_brand']} {$loan['mob_model']}"
        : "{$loan['net_brand']} {$loan['net_model']}");
$serial = $loan['hw_serial'] ?: $loan['mob_serial'] ?: '—';
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <title>ใบยืมอุปกรณ์ IT — <?= e($loan['loan_code']) ?></title>
    <style>
    * { box-sizing: border-box; margin: 0; padding: 0; }
    body {
        font-family: "Sarabun", "TH SarabunPSK", Arial, sans-serif;
        font-size: 14px; color: #000;
        padding: 20mm 20mm 15mm;
    }
    .logo-row { display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:12px; }
    .company { font-size:18px; font-weight:bold; }
    .doc-title { font-size:20px; font-weight:bold; text-align:center; margin-bottom:4px; }
    .doc-code  { font-size:13px; text-align:center; color:#555; margin-bottom:16px; }
    table.info { width:100%; border-collapse:collapse; margin-bottom:12px; }
    table.info td { padding:4px 8px; font-size:13px; }
    table.info td:first-child { width:130px; color:#555; font-weight:bold; white-space:nowrap; }
    .section-title {
        background:#1e3a5f; color:#fff;
        padding:4px 10px; font-weight:bold; font-size:13px;
        margin: 12px 0 6px;
    }
    table.items { width:100%; border-collapse:collapse; }
    table.items th, table.items td {
        border:1px solid #999; padding:5px 8px; font-size:12px;
    }
    table.items th { background:#f0f0f0; text-align:left; }
    .sign-row { display:grid; grid-template-columns:1fr 1fr; gap:20px; margin-top:20px; }
    .sign-box { border:1px solid #ccc; padding:10px 12px; }
    .sign-box .title { font-weight:bold; font-size:13px; margin-bottom:40px; }
    .sign-box .line  { border-top:1px solid #999; padding-top:4px; font-size:12px; color:#555; }
    .badge-code {
        font-size:18px; font-weight:bold; font-family:monospace;
        border:2px solid #1e3a5f; padding:6px 14px;
        display:inline-block; border-radius:6px;
    }
    @media print {
        body { padding: 10mm 15mm; }
        .no-print { display: none; }
        @page { margin: 0; }
    }
    </style>
</head>
<body>

<div class="no-print" style="text-align:right;margin-bottom:12px;">
    <button onclick="window.print()"
            style="padding:6px 16px;background:#1e3a5f;color:#fff;border:none;border-radius:4px;cursor:pointer;font-size:13px;">
        🖨 พิมพ์
    </button>
    <button onclick="window.close()"
            style="padding:6px 16px;background:#ddd;border:none;border-radius:4px;cursor:pointer;font-size:13px;margin-left:6px;">
        ✕ ปิด
    </button>
</div>

<!-- Header -->
<div class="logo-row">
    <div>
        <div class="company">Pacific Cold Storage</div>
        <div style="font-size:12px;color:#555;">IT Asset Management</div>
    </div>
    <div style="text-align:right;">
        <div class="badge-code"><?= e($loan['loan_code']) ?></div>
        <div style="font-size:11px;color:#555;margin-top:4px;">
            สร้างเมื่อ: <?= e(date('d/m/Y', strtotime($loan['created_at']))) ?>
        </div>
    </div>
</div>

<div class="doc-title">ใบยืมอุปกรณ์ IT</div>
<div class="doc-code">IT Equipment Loan Form</div>

<!-- ข้อมูลอุปกรณ์ -->
<div class="section-title">ข้อมูลอุปกรณ์ที่ยืม</div>
<table class="info">
    <tr>
        <td>รหัสอุปกรณ์</td>
        <td><strong><?= e($assetCode) ?></strong></td>
        <td width="120" style="color:#555;font-weight:bold;">ประเภท</td>
        <td><?= e($loan['category'] ?: $loan['device_type'] ?: $loan['net_device_type'] ?: '—') ?></td>
    </tr>
    <tr>
        <td>ยี่ห้อ / รุ่น</td>
        <td><?= e($assetLabel) ?></td>
        <td style="color:#555;font-weight:bold;">S/N</td>
        <td class="font-monospace"><?= e($serial) ?></td>
    </tr>
    <?php if ($peripherals): ?>
    <tr>
        <td>อุปกรณ์เสริม</td>
        <td colspan="3">
            <?php foreach ($peripherals as $p): ?>
                <?= e($p['type']) ?><?= $p['slot']>1?' '.$p['slot']:'' ?>
                <?= $p['serial_number'] ? '(S/N: '.e($p['serial_number']).')' : '' ?> &nbsp;
            <?php endforeach; ?>
        </td>
    </tr>
    <?php endif; ?>
    <?php if ($loan['accessories_out']): ?>
    <tr>
        <td>อุปกรณ์ที่ส่งมอบ</td>
        <td colspan="3"><?= e($loan['accessories_out']) ?></td>
    </tr>
    <?php endif; ?>
    <?php if ($loan['condition_out']): ?>
    <tr>
        <td>สภาพตอนส่งมอบ</td>
        <td colspan="3"><?= e($loan['condition_out']) ?></td>
    </tr>
    <?php endif; ?>
</table>

<!-- ข้อมูลผู้ยืม -->
<div class="section-title">ข้อมูลผู้ยืม</div>
<table class="info">
    <tr>
        <td>ชื่อผู้ยืม</td>
        <td><strong><?= e($loan['borrower_name']) ?></strong></td>
        <td width="120" style="color:#555;font-weight:bold;">AD Username</td>
        <td><?= e($loan['borrower_ad']) ?></td>
    </tr>
    <tr>
        <td>หน่วยงาน</td>
        <td><?= e($loan['borrower_dept'] ?: '—') ?></td>
        <td style="color:#555;font-weight:bold;">เบอร์โทร</td>
        <td><?= e($loan['borrower_phone'] ?: '—') ?></td>
    </tr>
    <tr>
        <td>Site / ที่ใช้งาน</td>
        <td><?= e($loan['borrower_site'] ?: '—') ?></td>
        <td style="color:#555;font-weight:bold;">วัตถุประสงค์</td>
        <td><?= e($loan['purpose']) ?> [<?= e($loan['purpose_type']) ?>]</td>
    </tr>
</table>

<!-- กำหนดการ -->
<div class="section-title">กำหนดการ</div>
<table class="info">
    <tr>
        <td>วันที่ยืม</td>
        <td><strong><?= e(date('d/m/Y', strtotime($loan['loan_date']))) ?></strong></td>
        <td width="120" style="color:#555;font-weight:bold;">กำหนดคืน</td>
        <td><strong style="color:#c00;"><?= e(date('d/m/Y', strtotime($loan['expected_return']))) ?></strong></td>
    </tr>
    <?php if ($loan['approved_by']): ?>
    <tr>
        <td>ผู้อนุมัติ</td>
        <td><?= e($loan['approved_by']) ?></td>
        <td style="color:#555;font-weight:bold;">ผู้ปล่อยของ</td>
        <td><?= e($loan['handed_out_by'] ?: '—') ?></td>
    </tr>
    <?php endif; ?>
    <?php if ($loan['notes']): ?>
    <tr>
        <td>หมายเหตุ</td>
        <td colspan="3"><?= e($loan['notes']) ?></td>
    </tr>
    <?php endif; ?>
</table>

<!-- ลายเซ็น -->
<div class="sign-row">
    <div class="sign-box">
        <div class="title">ผู้ยืม</div>
        <div class="line">ลายเซ็น / วันที่</div>
        <div style="font-size:12px;color:#555;margin-top:4px;"><?= e($loan['borrower_name']) ?></div>
    </div>
    <div class="sign-box">
        <div class="title">ผู้อนุมัติ / ผู้ส่งมอบ (IT)</div>
        <div class="line">ลายเซ็น / วันที่</div>
        <div style="font-size:12px;color:#555;margin-top:4px;">
            <?= $loan['handed_out_by'] ? e($loan['handed_out_by']) : '(IT Staff)' ?>
        </div>
    </div>
</div>

<!-- ตารางรับคืน -->
<div class="section-title" style="margin-top:20px;">บันทึกการรับคืน</div>
<table class="items">
    <thead>
        <tr>
            <th>วันที่คืนจริง</th>
            <th>สภาพเครื่องตอนคืน</th>
            <th>อุปกรณ์เสริมที่รับคืน</th>
            <th>ผู้รับคืน</th>
            <th>ลายเซ็นผู้รับคืน</th>
        </tr>
    </thead>
    <tbody>
        <?php if ($loan['actual_return']): ?>
        <tr>
            <td><?= e(date('d/m/Y', strtotime($loan['actual_return']))) ?></td>
            <td><?= e($loan['condition_in'] ?: '—') ?></td>
            <td><?= e($loan['accessories_in'] ?: '—') ?></td>
            <td><?= e($loan['received_by'] ?: '—') ?></td>
            <td></td>
        </tr>
        <?php else: ?>
        <tr>
            <td style="height:30px;"></td><td></td><td></td><td></td><td></td>
        </tr>
        <?php endif; ?>
    </tbody>
</table>

<div style="margin-top:16px;font-size:11px;color:#888;text-align:center;">
    Pacific Cold Storage · IT Asset Management System ·
    พิมพ์เมื่อ <?= date('d/m/Y H:i') ?>
</div>

</body>
</html>