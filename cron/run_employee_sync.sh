#!/bin/bash
set -a
source /etc/it-asset-manager/webtime.env
set +a
/usr/bin/php /var/www/cc_app/it-asset-manager/cron/employee_sync.php