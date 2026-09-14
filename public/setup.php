<?php
declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
ini_set('log_errors', '1');
$baLogDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'logs';
if (!is_dir($baLogDir)) {
    @mkdir($baLogDir, 0775, true);
}
ini_set('error_log', $baLogDir . DIRECTORY_SEPARATOR . 'php-error.log');

require __DIR__ . '/../app/bootstrap.php';
require __DIR__ . '/../app/db.php';
require __DIR__ . '/../app/helpers.php';
require __DIR__ . '/../app/ldap.php';
require __DIR__ . '/../app/backup.php';

if (ba_is_installed() && !isset($_GET['force'])) {
    header('Location: /');
    exit;
}

$step = (int)($_GET['step'] ?? $_POST['step'] ?? 1);
$mode = (string)($_GET['mode'] ?? $_POST['mode'] ?? 'fresh');
if (!in_array($mode, ['fresh', 'restore', 'import'], true)) {
    $mode = 'fresh';
}
$errors = [];
$success = [];
$form = [
    'db_driver' => $_POST['db_driver'] ?? 'sqlite',
    'sql_host' => $_POST['sql_host'] ?? 'localhost',
    'sql_port' => $_POST['sql_port'] ?? '1433',
    'sql_database' => $_POST['sql_database'] ?? 'BackAisle',
    'sql_username' => $_POST['sql_username'] ?? 'sa',
    'sql_password' => $_POST['sql_password'] ?? '',
    'sql_encrypt' => isset($_POST['sql_encrypt']),
    'sql_trust_cert' => !isset($_POST['sql_submitted']) || isset($_POST['sql_trust_cert']),
    'create_database' => !isset($_POST['sql_submitted']) || isset($_POST['create_database']),
    'odbc_driver' => $_POST['odbc_driver'] ?? 'ODBC Driver 18 for SQL Server',
    'admin_username' => $_POST['admin_username'] ?? 'admin',
    'admin_password' => $_POST['admin_password'] ?? '',
    'admin_password2' => $_POST['admin_password2'] ?? '',
    'org_name' => $_POST['org_name'] ?? 'My Organization',
    'snmp_user' => $_POST['snmp_user'] ?? '',
    'snmp_auth' => $_POST['snmp_auth'] ?? '',
    'snmp_priv' => $_POST['snmp_priv'] ?? '',
    'ups_host' => $_POST['ups_host'] ?? '',
];

$phpOk = version_compare(PHP_VERSION, '8.1.0', '>=');
$drivers = PDO::getAvailableDrivers();
$hasSqlite = in_array('sqlite', $drivers, true);
$hasOdbc = in_array('odbc', $drivers, true);
$hasSqlsrv = in_array('sqlsrv', $drivers, true);
$hasCurl = extension_loaded('curl');
$hasZip = extension_loaded('zip');
$configWritable = is_writable(BA_ROOT . '/config') || is_writable(BA_ROOT) || @mkdir(BA_ROOT . '/config', 0775, true);

function ba_setup_dbcfg(array $form): array {
    if (($form['db_driver'] ?? '') === 'sqlsrv') {
        return [
            'driver' => 'sqlsrv',
            'host' => $form['sql_host'],
            'port' => (int)$form['sql_port'],
            'database' => $form['sql_database'],
            'username' => $form['sql_username'],
            'password' => $form['sql_password'],
            'encrypt' => !empty($form['sql_encrypt']),
            'trust_server_certificate' => !empty($form['sql_trust_cert']),
            'odbc_driver' => $form['odbc_driver'],
            'path' => BA_DB,
        ];
    }
    return ['driver' => 'sqlite', 'path' => BA_DB];
}

function ba_setup_write_secrets(array $form): void {
    $dir = 'C:\\ProgramData\\BackAisle';
    if (!is_dir($dir)) @mkdir($dir, 0770, true);
    $lines = [
        '# Written by setup.php',
        'UPS_HOST=' . ($form['ups_host'] ?? ''),
        'SNMPV3_USER=' . ($form['snmp_user'] ?? ''),
        'SNMPV3_AUTH_PROTO=SHA',
        'SNMPV3_PRIV_PROTO=AES',
        'SNMPV3_AUTH_PASS=' . ($form['snmp_auth'] ?? ''),
        'SNMPV3_PRIV_PASS=' . ($form['snmp_priv'] ?? ''),
        'APP_ADMIN_USER=' . ($form['admin_username'] ?? 'admin'),
        'APP_ADMIN_PASS=' . ($form['admin_password'] ?? ''),
        'PYTHON=' . ba_python(),
        'AllowMultiWrite=false',
        '',
    ];
    @file_put_contents($dir . '\\secrets.env', implode("\n", $lines));
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];

    if ($action === 'choose_mode') {
        $mode = $_POST['mode'] ?? 'fresh';
        $step = 2;
    }

    if ($action === 'test_connection') {
        $step = 2;
        try {
            if ($form['db_driver'] === 'sqlsrv') {
                $pdo = ba_connect_sqlserver(ba_setup_dbcfg($form), false);
                $ver = $pdo->query('SELECT @@VERSION')->fetchColumn();
                $success[] = 'SQL Server connection OK. ' . strtok((string)$ver, "\n");
            } else {
                if (!$hasSqlite) throw new RuntimeException('pdo_sqlite is not loaded.');
                $dir = dirname(BA_DB);
                if (!is_dir($dir)) mkdir($dir, 0775, true);
                $pdo = new PDO('sqlite:' . BA_DB);
                $success[] = 'SQLite file is writable: ' . BA_DB;
            }
        } catch (Throwable $e) {
            $errors[] = 'Connection failed: ' . $e->getMessage();
        }
    }

    if ($action === 'save_database') {
        $step = 2;
        try {
            $dbCfg = ba_setup_dbcfg($form);
            if ($dbCfg['driver'] === 'sqlsrv') {
                $server = ba_connect_sqlserver($dbCfg, false);
                $dbName = preg_replace('/[^a-zA-Z0-9_]/', '', $form['sql_database']);
                if ($dbName === '') throw new RuntimeException('Invalid database name.');
                if (!empty($form['create_database'])) {
                    $st = $server->prepare('SELECT database_id FROM sys.databases WHERE name = ?');
                    $st->execute([$dbName]);
                    if (!$st->fetchColumn()) {
                        $server->exec("CREATE DATABASE [{$dbName}]");
                        $success[] = "Database [{$dbName}] created.";
                    } else {
                        $success[] = "Database [{$dbName}] already exists — applying schema.";
                    }
                }
                $pdo = ba_connect_sqlserver($dbCfg, true);
                ba_apply_sqlserver_schema($pdo);
                $success[] = 'SQL Server schema applied.';
            } else {
                $dir = dirname(BA_DB);
                if (!is_dir($dir)) mkdir($dir, 0775, true);
                $pdo = new PDO('sqlite:' . BA_DB, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
                ba_ensure_infra_schema($pdo);
                $success[] = 'SQLite database ready.';
            }
            ba_write_config([
                'installed' => false,
                'org_name' => $form['org_name'],
                'db' => $dbCfg,
            ]);
            $step = 3;
        } catch (Throwable $e) {
            $errors[] = 'Database setup failed: ' . $e->getMessage();
        }
    }

    if ($action === 'restore_backup') {
        $mode = 'restore';
        $step = 3;
        @set_time_limit(900);
        try {
            $file = $_FILES['backup_file'] ?? null;
            if (!is_array($file) || (int)($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                throw new RuntimeException('Upload a BackAisle site backup (.zip or .baisle).');
            }
            $orig = (string)($file['name'] ?? 'backup.zip');
            if (!preg_match('/\.(zip|baisle)$/i', $orig)) {
                throw new RuntimeException('Backup must be a .zip or encrypted .baisle from Admin → Site backup.');
            }
            $stageDir = BA_ROOT . '/storage/backups';
            if (!is_dir($stageDir)) mkdir($stageDir, 0775, true);
            $ext = preg_match('/\.baisle$/i', $orig) ? 'baisle' : 'zip';
            $stagePath = $stageDir . '/restore_upload_' . date('Ymd_His') . '.' . $ext;
            if (!move_uploaded_file($file['tmp_name'], $stagePath)) {
                throw new RuntimeException('Could not store uploaded backup.');
            }
            $res = BackAisleBackup::restoreLive($stagePath, [
                'password' => (string)($_POST['backup_password'] ?? ''),
                'create_pre_backup' => false,
            ]);
            $cfg = ba_config();
            $cfg['installed'] = true;
            $cfg['org_name'] = $form['org_name'] ?: ($cfg['org_name'] ?? 'BackAisle');
            $cfg['db'] = ba_setup_dbcfg($form);
            ba_write_config($cfg);
            $success[] = $res['message'] ?? 'Restore complete. Sign in with an account from the backup.';
            $step = 4;
        } catch (Throwable $e) {
            $errors[] = 'Restore failed: ' . $e->getMessage();
        }
    }

    if ($action === 'finish_fresh' || $action === 'import_powerpanel') {
        $step = 3;
        try {
            if ($form['admin_password'] === '' || strlen($form['admin_password']) < 8) {
                throw new RuntimeException('Admin password must be at least 8 characters.');
            }
            if ($form['admin_password'] !== $form['admin_password2']) {
                throw new RuntimeException('Admin passwords do not match.');
            }
            $dbCfg = ba_setup_dbcfg($form);
            ba_write_config([
                'installed' => true,
                'org_name' => $form['org_name'],
                'db' => $dbCfg,
            ]);
            ba_setup_write_secrets($form);
            $pdo = ba_db(true);
            if (ba_db_driver() !== 'sqlsrv') {
                ba_ensure_infra_schema($pdo);
            }
            $hash = password_hash($form['admin_password'], PASSWORD_DEFAULT);
            $st = $pdo->prepare('SELECT id FROM users WHERE username=?');
            $st->execute([$form['admin_username']]);
            $exist = $st->fetch();
            if ($exist) {
                $pdo->prepare('UPDATE users SET password_hash=?, role=? WHERE id=?')->execute([$hash, 'admin', $exist['id']]);
            } else {
                $pdo->prepare('INSERT INTO users (username, password_hash, role) VALUES (?,?,?)')
                    ->execute([$form['admin_username'], $hash, 'admin']);
            }
            $org = $pdo->query("SELECT id FROM groups WHERE parent_id IS NULL")->fetch();
            if (!$org) {
                $pdo->prepare('INSERT INTO groups (parent_id, name) VALUES (NULL,?)')->execute([$form['org_name'] ?: 'Campus']);
            }
            $success[] = 'Admin account saved.';
            if ($action === 'import_powerpanel') {
                $file = $_FILES['pp_zip'] ?? null;
                if (!is_array($file) || (int)($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                    throw new RuntimeException('Upload a PowerPanel profile.zip.');
                }
                $dest = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pp_' . bin2hex(random_bytes(4)) . '.zip';
                if (!move_uploaded_file($file['tmp_name'], $dest)) {
                    throw new RuntimeException('Could not store profile.zip.');
                }
                $code = 'import sys,json; sys.path.insert(0, r"' . BA_ROOT . '\\collector"); from import_powerpanel import import_zip; print(json.dumps(import_zip(sys.argv[1])))';
                $run = ba_python_run(['-c', $code, $dest]);
                @unlink($dest);
                if ($run['code'] !== 0) {
                    $detail = trim($run['stderr'] . "\n" . $run['stdout']);
                    throw new RuntimeException('PowerPanel import failed: ' . ($detail !== '' ? $detail : 'exit ' . $run['code']));
                }
                $success[] = 'PowerPanel import: ' . trim($run['stdout']);
            }
            $step = 4;
        } catch (Throwable $e) {
            $errors[] = $e->getMessage();
        }
    }
}

$steps = [1 => 'Welcome', 2 => 'Database', 3 => 'Site data', 4 => 'Done'];
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Setup · BackAisle</title>
  <link rel="stylesheet" href="/assets/app.css?v=setup1">
  <link rel="stylesheet" href="/assets/setup.css?v=setup1">
</head>
<body class="setup-body">
<div class="setup-wrap">
  <div class="brand"><span class="mark">BA</span> BackAisle <small>setup wizard</small></div>
  <ol class="setup-steps">
    <?php foreach ($steps as $n => $lab): ?>
      <li class="<?= $step === $n ? 'on' : ($step > $n ? 'done' : '') ?>"><?= $n ?>. <?= h($lab) ?></li>
    <?php endforeach; ?>
  </ol>

  <?php foreach ($errors as $e): ?><div class="flash setup-err"><?= h($e) ?></div><?php endforeach; ?>
  <?php foreach ($success as $e): ?><div class="flash"><?= h($e) ?></div><?php endforeach; ?>

  <?php if ($step === 1): ?>
    <h1>Install BackAisle</h1>
    <p class="muted">IDF infrastructure — racks, UPS, climate. This wizard writes config, connects the database, and can restore a site backup or import a PowerPanel profile.</p>
    <div class="card">
      <h3>Prerequisites</h3>
      <ul class="setup-checks">
        <li><?= $phpOk ? 'OK' : 'FAIL' ?> PHP <?= h(PHP_VERSION) ?> (8.1+)</li>
        <li><?= $hasSqlite ? 'OK' : 'FAIL' ?> pdo_sqlite</li>
        <li><?= $hasOdbc || $hasSqlsrv ? 'OK' : 'WARN' ?> SQL Server PDO (odbc/sqlsrv)<?= $hasOdbc || $hasSqlsrv ? '' : ' — needed only if you use SQL Server' ?></li>
        <li><?= $hasCurl ? 'OK' : 'WARN' ?> curl (in-app updates)</li>
        <li><?= $hasZip ? 'OK' : 'WARN' ?> zip (backups / restore)</li>
        <li><?= $configWritable ? 'OK' : 'FAIL' ?> config folder writable</li>
      </ul>
    </div>
    <form method="post" class="setup-modes">
      <input type="hidden" name="action" value="choose_mode">
      <button name="mode" value="fresh" class="card setup-mode">
        <strong>Fresh install</strong>
        <span>Create the database and first admin account.</span>
      </button>
      <button name="mode" value="restore" class="card setup-mode">
        <strong>Restore site backup</strong>
        <span>Upload a <code>backaisle-site_*.zip</code> or encrypted <code>.baisle</code> package.</span>
      </button>
      <button name="mode" value="import" class="card setup-mode">
        <strong>Import PowerPanel</strong>
        <span>Create the site, then load groups and devices from profile.zip.</span>
      </button>
    </form>
  <?php endif; ?>

  <?php if ($step === 2): ?>
    <h1>Database</h1>
    <p class="muted">Use a local SQLite file (no extra engine) or connect to SQL Server like ColdAisle. SQL Server is not installed by the PowerShell script — use an existing instance.</p>
    <form method="post" class="card stack" style="max-width:none">
      <input type="hidden" name="step" value="2">
      <input type="hidden" name="mode" value="<?= h($mode) ?>">
      <input type="hidden" name="sql_submitted" value="1">
      <label>Engine</label>
      <select name="db_driver" id="db_driver">
        <option value="sqlite" <?= $form['db_driver']==='sqlite'?'selected':'' ?>>SQLite (local file, recommended if you do not have SQL Server)</option>
        <option value="sqlsrv" <?= $form['db_driver']==='sqlsrv'?'selected':'' ?>>SQL Server (ODBC Driver 18)</option>
      </select>
      <div id="sql-fields" class="<?= $form['db_driver']==='sqlsrv'?'':'setup-hide' ?>">
        <label>SQL host</label><input name="sql_host" value="<?= h($form['sql_host']) ?>">
        <label>Port</label><input name="sql_port" value="<?= h($form['sql_port']) ?>">
        <label>Database name</label><input name="sql_database" value="<?= h($form['sql_database']) ?>">
        <label>SQL login</label><input name="sql_username" value="<?= h($form['sql_username']) ?>">
        <label>Password</label><input type="password" name="sql_password" value="<?= h($form['sql_password']) ?>">
        <label>ODBC driver</label><input name="odbc_driver" value="<?= h($form['odbc_driver']) ?>">
        <label><input type="checkbox" name="create_database" value="1" <?= $form['create_database']?'checked':'' ?>> Create database if it does not exist</label>
        <label><input type="checkbox" name="sql_encrypt" value="1" <?= $form['sql_encrypt']?'checked':'' ?>> Encrypt connection</label>
        <label><input type="checkbox" name="sql_trust_cert" value="1" <?= $form['sql_trust_cert']?'checked':'' ?>> Trust server certificate</label>
      </div>
      <div class="filters">
        <button name="action" value="test_connection">Test connection</button>
        <button name="action" value="save_database">Save and continue</button>
      </div>
    </form>
    <script>
      document.getElementById('db_driver').addEventListener('change', function () {
        document.getElementById('sql-fields').classList.toggle('setup-hide', this.value !== 'sqlsrv');
      });
    </script>
  <?php endif; ?>

  <?php if ($step === 3 && $mode === 'restore'): ?>
    <h1>Restore site backup</h1>
    <form method="post" enctype="multipart/form-data" class="card stack" style="max-width:none">
      <input type="hidden" name="step" value="3">
      <input type="hidden" name="mode" value="restore">
      <?php foreach (['db_driver','sql_host','sql_port','sql_database','sql_username','sql_password','odbc_driver','org_name'] as $k): ?>
        <input type="hidden" name="<?= h($k) ?>" value="<?= h((string)$form[$k]) ?>">
      <?php endforeach; ?>
      <label>Backup file (.zip or .baisle)</label>
      <input type="file" name="backup_file" accept=".zip,.baisle" required>
      <label>Password (encrypted packages)</label>
      <input type="password" name="backup_password" autocomplete="off">
      <button name="action" value="restore_backup">Restore package</button>
    </form>
  <?php elseif ($step === 3): ?>
    <h1><?= $mode === 'import' ? 'Admin account and PowerPanel import' : 'Admin account' ?></h1>
    <form method="post" enctype="multipart/form-data" class="card stack" style="max-width:none">
      <input type="hidden" name="step" value="3">
      <input type="hidden" name="mode" value="<?= h($mode) ?>">
      <?php foreach (['db_driver','sql_host','sql_port','sql_database','sql_username','sql_password','odbc_driver'] as $k): ?>
        <input type="hidden" name="<?= h($k) ?>" value="<?= h((string)$form[$k]) ?>">
      <?php endforeach; ?>
      <label>Organization name</label><input name="org_name" value="<?= h($form['org_name']) ?>" required>
      <label>Admin username</label><input name="admin_username" value="<?= h($form['admin_username']) ?>" required>
      <label>Admin password</label><input type="password" name="admin_password" required>
      <label>Confirm password</label><input type="password" name="admin_password2" required>
      <label>Optional SNMPv3 user</label><input name="snmp_user" value="<?= h($form['snmp_user']) ?>">
      <label>Auth passphrase</label><input type="password" name="snmp_auth">
      <label>Privacy passphrase</label><input type="password" name="snmp_priv">
      <label>Optional first UPS IP (UPS_HOST)</label><input name="ups_host" value="<?= h($form['ups_host']) ?>" placeholder="leave blank to add later">
      <?php if ($mode === 'import'): ?>
        <label>PowerPanel profile.zip</label>
        <input type="file" name="pp_zip" accept=".zip" required>
        <button name="action" value="import_powerpanel">Create site and import</button>
      <?php else: ?>
        <button name="action" value="finish_fresh">Create site</button>
      <?php endif; ?>
    </form>
  <?php endif; ?>

  <?php if ($step === 4): ?>
    <h1>Ready</h1>
    <p>BackAisle is installed. Sign in with the admin account you created (or an account from the restored backup).</p>
    <p><a class="btn" href="/login.php">Open BackAisle</a></p>
    <p class="muted">Start the collector after install: <code>python C:\inetpub\BackAisle\collector\collector.py</code> or the scheduled task registered by the installer.</p>
  <?php endif; ?>
</div>
</body>
</html>
