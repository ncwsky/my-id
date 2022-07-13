#!/usr/bin/env php
<?php
define('RUN_DIR', __DIR__);
define('VENDOR_DIR', __DIR__ . '/vendor');
//define('MY_PHP_DIR', __DIR__ . '/vendor/myphps/myphp');
//define('MY_PHP_SRV_DIR', __DIR__ . '/vendor/myphps/my-php-srv');
define('ID_NAME', 'my_id_incr');
define('ID_LISTEN', '0.0.0.0');
define('ID_PORT', 55012);
define('IS_SWOOLE', 0);

require __DIR__. '/vendor/myphps/my-id/my_id_incr.php';