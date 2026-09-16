<?php
/**
 * /var/www/lab/it-asset-manager/config/modules.php
 * Master list ของ module/เมนูใน IT Asset Manager
 * ใช้ทั้งฝั่ง sidebar (can_access_module) และหน้า admin (แสดง checkbox)
 */
declare(strict_types=1);

function iam_module_list(): array
{
    return [
        'DASHBOARD'       => ['label' => 'Dashboard'],
        'ASSETS'          => ['label' => 'Hardware Assets'],
        'MOBILE'          => ['label' => 'Mobile Assets'],
        'SOFTWARE'        => ['label' => 'Software Licenses'],
        'LOANS'           => ['label' => 'Loans (ยืม-คืน)'],
        'MAINTENANCE'     => ['label' => 'Maintenance Logs'],
        'NETWORK'         => ['label' => 'Network Devices'],
        'SITES'           => ['label' => 'Sites'],
        'ACCESS_REQUESTS' => ['label' => 'Access Requests'],
    ];
}