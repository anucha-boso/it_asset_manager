#!/bin/bash
set -a
source /etc/it-asset-manager/webtime.env
set +a
/usr/bin/php "$(dirname "$(readlink -f "$0")")/employee_sync.php"