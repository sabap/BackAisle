<?php
declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('log_errors', '1');
header('Content-Type: text/html; charset=utf-8');

function ba_home_fail(string $where, Throwable $e): void {
    http_response_code(500);
    echo '<!doctype html><meta charset="utf-8"><pre style="font:14px/1.4 Consolas,monospace;white-space:pre-wrap;padding:24px">';
    echo 'BackAisle failed at: ' . htmlspecialchars($where) . "\n\n";
    echo htmlspecialchars($e->getMessage()) . "\n";
    echo htmlspecialchars($e->getFile() . ':' . $e->getLine()) . "\n\n";
    echo htmlspecialchars($e->getTraceAsString());
    echo '</pre>';
    $log = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR . 'php-error.log';
    @file_put_contents($log, date('c') . " $where " . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine() . "\n", FILE_APPEND);
    exit;
}

$files = [
    'bootstrap.php' => __DIR__ . '/../app/bootstrap.php',
    'helpers.php' => __DIR__ . '/../app/helpers.php',
    'db.php' => __DIR__ . '/../app/db.php',
    'auth.php' => __DIR__ . '/../app/auth.php',
    'layout.php' => __DIR__ . '/../app/layout.php',
    'pages.php' => __DIR__ . '/../app/pages.php',
];
foreach ($files as $name => $path) {
    if (!is_file($path)) {
        ba_home_fail("missing $name", new RuntimeException($path));
    }
    try {
        require $path;
    } catch (Throwable $e) {
        ba_home_fail("require $name", $e);
    }
}

try {
    $db = ba_db();
} catch (Throwable $e) {
    ba_home_fail('database', $e);
}

if (empty($_SESSION['user'])) {
    header('Location: /login.php');
    exit;
}

try {
    if (function_exists('page_dashboard')) {
        page_dashboard($db);
    } else {
        echo '<!doctype html><p>Signed in as ' . htmlspecialchars((string)($_SESSION['user']['username'] ?? '')) . '. Dashboard function missing.</p>';
    }
} catch (Throwable $e) {
    ba_home_fail('dashboard', $e);
}
