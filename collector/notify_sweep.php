<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';
require dirname(__DIR__) . '/app/db.php';
require dirname(__DIR__) . '/app/ldap.php';
require dirname(__DIR__) . '/app/notify.php';

try {
    $force = in_array('--now', $argv ?? [], true);
    ba_notify_sweep(ba_db(), $force);
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(1);
}
