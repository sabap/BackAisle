<?php
declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('log_errors', '1');
header('Content-Type: text/html; charset=utf-8');

function ba_snmp_fail(string $where, Throwable $e): void
{
    $log = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR . 'php-error.log';
    @file_put_contents($log, date('c') . " SNMP $where " . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine() . "\n", FILE_APPEND);
    echo '<!doctype html><meta charset="utf-8"><pre style="font:14px/1.4 Consolas,monospace;white-space:pre-wrap;padding:24px">';
    echo 'SNMP page failed at: ' . htmlspecialchars($where) . "\n\n";
    echo htmlspecialchars($e->getMessage()) . "\n";
    echo htmlspecialchars($e->getFile() . ':' . $e->getLine()) . "\n\n";
    echo htmlspecialchars($e->getTraceAsString());
    echo '</pre>';
    exit;
}

$files = [
    'bootstrap.php' => __DIR__ . '/../app/bootstrap.php',
    'helpers.php' => __DIR__ . '/../app/helpers.php',
    'db.php' => __DIR__ . '/../app/db.php',
    'auth.php' => __DIR__ . '/../app/auth.php',
    'layout.php' => __DIR__ . '/../app/layout.php',
    'org.php' => __DIR__ . '/../app/org.php',
    'snmp_page.php' => __DIR__ . '/../app/snmp_page.php',
];
foreach ($files as $name => $path) {
    if (!is_file($path)) {
        ba_snmp_fail("missing $name", new RuntimeException($path));
    }
    try {
        require $path;
    } catch (Throwable $e) {
        ba_snmp_fail("require $name", $e);
    }
}

try {
    $db = ba_db();
} catch (Throwable $e) {
    ba_snmp_fail('database', $e);
}

try {
    $user = ba_require_login();
} catch (Throwable $e) {
    ba_snmp_fail('login', $e);
}

try {
    page_snmp($db, $user);
} catch (Throwable $e) {
    ba_snmp_fail('page_snmp', $e);
}
