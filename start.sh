#!/bin/bash
#pkill -f server.php
#nohup php -f server.php </dev/null >~/pbmail/server.log 2>&1 &
pkill -9 supervisord
supervisord -c supervisor.conf