<?php
require_once __DIR__ . '/../config/db_connect.php';
require_once __DIR__ . '/../config/notification.php';

notifyEvent('ACCESS_REQUEST_SUBMITTED', [
    'asset'    => 'CCMS (ทดสอบ)',
    'reporter' => 'ทดสอบระบบ',
    'issues'   => ['นี่คือข้อความทดสอบจาก IT Asset Manager'],
    'ticket_no'=> 'TEST-001',
    'note'     => 'ทดสอบ notifyEvent()',
    'to_email' => ['admin@onecoldchain.com'],
]);

echo "Sent (check error log if not received)";
