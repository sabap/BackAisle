<?php
declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';
require __DIR__ . '/../app/helpers.php';
require __DIR__ . '/../app/db.php';
require __DIR__ . '/../app/auth.php';
require __DIR__ . '/../app/layout.php';
require __DIR__ . '/../app/pages.php';
require __DIR__ . '/../app/writes.php';
require __DIR__ . '/../app/ldap.php';
require __DIR__ . '/../app/org.php';
require __DIR__ . '/../app/racks.php';
require __DIR__ . '/../app/templates.php';
require __DIR__ . '/../app/backup.php';
require __DIR__ . '/../app/update.php';

$path = ba_request_path();

if (!ba_is_installed() && $path !== '/setup' && !str_starts_with($path, '/setup')) {
    header('Location: /setup.php');
    exit;
}

try {
    $db = ba_db();
} catch (Throwable $e) {
    @file_put_contents(BA_ROOT . '\\logs\\php-error.log', date('c') . ' ' . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n", FILE_APPEND);
    http_response_code(503);
    echo 'BackAisle database is not ready.';
    exit;
}

if ($path === '/login') {
    page_login($db);
    exit;
}
if ($path === '/logout') {
    $_SESSION = [];
    session_destroy();
    header('Location: /login.php');
    exit;
}

if ($path === '/api/health') {
    page_api_health($db);
    exit;
}

try {
    BackAisleUpdate::applyPendingReplacements();
} catch (Throwable $e) {
    // next request retries
}

$user = ba_require_login();

switch ($path) {
    case '/':
        page_dashboard($db);
        break;
    case '/fleet':
        page_fleet($db);
        break;
    case '/idfs':
        page_idfs($db, $user);
        break;
    case '/rack':
        page_rack($db, $user);
        break;
    case '/climate':
        page_climate($db);
        break;
    case '/batteries':
        page_batteries($db);
        break;
    case '/alerts':
        page_alerts($db, $user);
        break;
    case '/events':
        page_events($db);
        break;
    case '/devices':
        page_devices($db, $user);
        break;
    case '/templates':
        page_templates($db, $user);
        break;
    case '/device':
        page_device($db, $user);
        break;
    case '/admin':
        page_admin($db, $user);
        break;
    case '/admin/backup-download':
        ba_require_admin();
        $path = BackAisleBackup::safeFile((string)($_GET['file'] ?? ''));
        if (!$path) {
            http_response_code(404);
            echo 'Not found';
            break;
        }
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="'.basename($path).'"');
        header('Content-Length: '.(string)filesize($path));
        readfile($path);
        break;
    case '/writes':
        page_fleet_writes($db, $user);
        break;
    case '/writes/template':
        page_template_view($db);
        break;
    case '/writes/job':
        page_job_view($db);
        break;
    case '/org':
        page_org($db, $user);
        break;
    case '/battery':
        page_battery_report($db);
        break;
    case '/api/series':
        page_api_series($db);
        break;
    case '/api/dashboard':
        page_api_dashboard($db);
        break;
    default:
        http_response_code(404);
        echo 'Not found';
}
