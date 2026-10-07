<?php
declare(strict_types=1);

require_once __DIR__ . '/mib_lib.php';

/** Model profiles name the fields a device model returns. Credentials stay on snmp_profiles. */

function ba_ensure_model_schema(PDO $db): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    if (ba_db_driver() === 'sqlsrv') {
        $db->exec("IF OBJECT_ID('dbo.model_profiles','U') IS NULL CREATE TABLE model_profiles (
            id INT IDENTITY(1,1) PRIMARY KEY,
            name NVARCHAR(150) NOT NULL,
            vendor NVARCHAR(100) NULL,
            match_model NVARCHAR(150) NULL,
            notes NVARCHAR(500) NULL,
            is_active INT NOT NULL CONSTRAINT DF_ba_mp_active DEFAULT 1,
            created_at DATETIME2 NOT NULL CONSTRAINT DF_ba_mp_created DEFAULT SYSUTCDATETIME()
        )");
        $db->exec("IF OBJECT_ID('dbo.model_profile_fields','U') IS NULL CREATE TABLE model_profile_fields (
            id INT IDENTITY(1,1) PRIMARY KEY,
            profile_id INT NOT NULL,
            field_key NVARCHAR(80) NOT NULL,
            label NVARCHAR(120) NOT NULL,
            oid NVARCHAR(200) NOT NULL,
            syntax NVARCHAR(80) NULL,
            unit NVARCHAR(40) NULL,
            scale FLOAT NOT NULL CONSTRAINT DF_ba_mpf_scale DEFAULT 1,
            enum_json NVARCHAR(MAX) NULL,
            builtin_key NVARCHAR(60) NULL,
            show_on_device INT NOT NULL CONSTRAINT DF_ba_mpf_show DEFAULT 1,
            sort_order INT NOT NULL CONSTRAINT DF_ba_mpf_sort DEFAULT 0
        )");
        $db->exec("IF OBJECT_ID('dbo.mib_modules','U') IS NULL CREATE TABLE mib_modules (
            id INT IDENTITY(1,1) PRIMARY KEY,
            name NVARCHAR(150) NOT NULL,
            filename NVARCHAR(200) NOT NULL,
            object_count INT NOT NULL CONSTRAINT DF_ba_mib_n DEFAULT 0,
            status NVARCHAR(20) NOT NULL CONSTRAINT DF_ba_mib_st DEFAULT 'ok',
            error NVARCHAR(400) NULL,
            uploaded_at DATETIME2 NOT NULL CONSTRAINT DF_ba_mib_at DEFAULT SYSUTCDATETIME()
        )");
        $db->exec("IF OBJECT_ID('dbo.mib_objects','U') IS NULL CREATE TABLE mib_objects (
            id INT IDENTITY(1,1) PRIMARY KEY,
            module_id INT NOT NULL,
            name NVARCHAR(150) NOT NULL,
            oid NVARCHAR(200) NOT NULL,
            syntax NVARCHAR(240) NULL,
            units NVARCHAR(40) NULL,
            access NVARCHAR(40) NULL,
            enum_json NVARCHAR(MAX) NULL
        )");
        $db->exec("IF OBJECT_ID('dbo.device_metrics','U') IS NULL CREATE TABLE device_metrics (
            device_id INT NOT NULL,
            field_key NVARCHAR(80) NOT NULL,
            value_num FLOAT NULL,
            value_text NVARCHAR(200) NULL,
            polled_at DATETIME2 NOT NULL CONSTRAINT DF_ba_dm_at DEFAULT SYSUTCDATETIME(),
            CONSTRAINT PK_ba_device_metrics PRIMARY KEY (device_id, field_key)
        )");
    } else {
        $db->exec("CREATE TABLE IF NOT EXISTS model_profiles (
            id INTEGER PRIMARY KEY,
            name TEXT NOT NULL,
            vendor TEXT,
            match_model TEXT,
            notes TEXT,
            is_active INTEGER NOT NULL DEFAULT 1,
            created_at TEXT NOT NULL DEFAULT (datetime('now'))
        )");
        $db->exec("CREATE TABLE IF NOT EXISTS model_profile_fields (
            id INTEGER PRIMARY KEY,
            profile_id INTEGER NOT NULL,
            field_key TEXT NOT NULL,
            label TEXT NOT NULL,
            oid TEXT NOT NULL,
            syntax TEXT,
            unit TEXT,
            scale REAL NOT NULL DEFAULT 1,
            enum_json TEXT,
            builtin_key TEXT,
            show_on_device INTEGER NOT NULL DEFAULT 1,
            sort_order INTEGER NOT NULL DEFAULT 0
        )");
        $db->exec("CREATE TABLE IF NOT EXISTS mib_modules (
            id INTEGER PRIMARY KEY,
            name TEXT NOT NULL,
            filename TEXT NOT NULL,
            object_count INTEGER NOT NULL DEFAULT 0,
            status TEXT NOT NULL DEFAULT 'ok',
            error TEXT,
            uploaded_at TEXT NOT NULL DEFAULT (datetime('now'))
        )");
        $db->exec("CREATE TABLE IF NOT EXISTS mib_objects (
            id INTEGER PRIMARY KEY,
            module_id INTEGER NOT NULL,
            name TEXT NOT NULL,
            oid TEXT NOT NULL,
            syntax TEXT,
            units TEXT,
            access TEXT,
            enum_json TEXT
        )");
        $db->exec('CREATE INDEX IF NOT EXISTS ix_mib_objects_name ON mib_objects(name)');
        $db->exec('CREATE INDEX IF NOT EXISTS ix_mib_objects_oid ON mib_objects(oid)');
        $db->exec("CREATE TABLE IF NOT EXISTS device_metrics (
            device_id INTEGER NOT NULL,
            field_key TEXT NOT NULL,
            value_num REAL,
            value_text TEXT,
            polled_at TEXT NOT NULL,
            PRIMARY KEY (device_id, field_key)
        )");
    }
    ba_ensure_column($db, 'devices', 'model_profile_id', 'INT NULL');
    ba_seed_cyberpower_profile($db);
}

function ba_seed_cyberpower_profile(PDO $db): void
{
    $n = (int)$db->query('SELECT COUNT(*) FROM model_profiles')->fetchColumn();
    if ($n > 0) {
        return;
    }
    $db->prepare('INSERT INTO model_profiles (name, vendor, match_model, notes) VALUES (?,?,?,?)')->execute([
        'CyberPower UPS',
        'CyberPower',
        'PR',
        'Seeded from the collector map already used for this fleet. Summary fields stay on the device page. Extra fields appear only after the card returns them.',
    ]);
    $pid = ba_last_id($db);
    $fields = [
        ['capacity', 'Capacity', '1.3.6.1.4.1.3808.1.1.1.2.2.1.0', '%', 'capacity_pct'],
        ['runtime', 'Runtime', '1.3.6.1.4.1.3808.1.1.1.2.2.4.0', 'min', 'runtime_min'],
        ['load', 'Load', '1.3.6.1.4.1.3808.1.1.1.4.2.3.0', '%', 'load_pct'],
        ['input_voltage', 'Input voltage', '1.3.6.1.4.1.3808.1.1.1.3.2.1.0', 'V', 'input_voltage'],
        ['output_voltage', 'Output voltage', '1.3.6.1.4.1.3808.1.1.1.4.2.1.0', 'V', 'output_voltage'],
        ['output_status', 'Output status', '1.3.6.1.4.1.3808.1.1.1.4.1.1.0', '', 'output_status'],
        ['battery_status', 'Battery status', '1.3.6.1.4.1.3808.1.1.1.2.1.1.0', '', 'battery_status'],
        ['temperature', 'Temperature', '1.3.6.1.4.1.3808.1.1.8.2.3.1.3.1', '°F', 'temp_f'],
        ['humidity', 'Humidity', '1.3.6.1.4.1.3808.1.1.8.3.2.1.3.1', '%', 'humidity_pct'],
        ['battery_last_replace', 'Battery last replaced', '1.3.6.1.4.1.3808.1.1.1.2.1.3.0', '', 'last_battery_replacement'],
        ['input_frequency', 'Input frequency', '1.3.6.1.2.1.33.1.3.3.1.2.1', 'Hz', ''],
    ];
    $ins = $db->prepare('INSERT INTO model_profile_fields (profile_id, field_key, label, oid, unit, scale, builtin_key, show_on_device, sort_order) VALUES (?,?,?,?,?,?,?,?,?)');
    $i = 0;
    foreach ($fields as $f) {
        $scale = $f[0] === 'input_frequency' ? 0.1 : 1;
        $ins->execute([$pid, $f[0], $f[1], $f[2], $f[3], $scale, $f[4] !== '' ? $f[4] : null, 1, $i]);
        $i++;
    }
}

function ba_field_key(string $label): string
{
    $key = strtolower(trim($label));
    $key = preg_replace('/[^a-z0-9]+/', '_', $key) ?? '';
    $key = trim((string)$key, '_');
    return $key !== '' ? substr($key, 0, 60) : 'field';
}

function ba_oid_ok(string $oid): bool
{
    return (bool)preg_match('/^\d+(?:\.\d+){3,}$/', $oid);
}

/** @return list<array<string, mixed>> */
function ba_model_profiles(PDO $db): array
{
    ba_ensure_model_schema($db);
    return $db->query('SELECT * FROM model_profiles ORDER BY name')->fetchAll() ?: [];
}

function ba_model_for_device(PDO $db, array $device): ?array
{
    ba_ensure_model_schema($db);
    $explicit = (int)ba_col($device, 'model_profile_id', 0);
    if ($explicit > 0) {
        $st = $db->prepare('SELECT * FROM model_profiles WHERE id=? AND is_active=1');
        $st->execute([$explicit]);
        $row = $st->fetch();
        if ($row) {
            return $row;
        }
    }
    $model = strtolower(trim((string)ba_col($device, 'model', '')));
    $vendor = strtolower(trim((string)ba_col($device, 'manufacturer', '')));
    $profiles = $db->query('SELECT * FROM model_profiles WHERE is_active=1 ORDER BY id')->fetchAll() ?: [];
    $best = null;
    $bestLen = 0;
    foreach ($profiles as $p) {
        $mm = strtolower(trim((string)($p['match_model'] ?? '')));
        if ($mm !== '' && $model !== '' && str_contains($model, $mm) && strlen($mm) > $bestLen) {
            $best = $p;
            $bestLen = strlen($mm);
        }
    }
    if ($best) {
        return $best;
    }
    foreach ($profiles as $p) {
        $mv = strtolower(trim((string)($p['vendor'] ?? '')));
        if ($mv !== '' && (($vendor !== '' && $vendor === $mv) || ($model !== '' && str_contains($model, $mv)))) {
            return $p;
        }
    }
    if (count($profiles) === 1) {
        return $profiles[0];
    }
    return null;
}

/** @return list<array<string, mixed>> */
function ba_model_fields(PDO $db, int $profileId): array
{
    $st = $db->prepare('SELECT * FROM model_profile_fields WHERE profile_id=? ORDER BY sort_order, id');
    $st->execute([$profileId]);
    return $st->fetchAll() ?: [];
}

function ba_model_add_field(PDO $db, int $profileId, string $label, string $oid, string $unit, float $scale, string $enumJson = ''): void
{
    $label = trim($label);
    $oid = trim($oid);
    if ($label === '' || !ba_oid_ok($oid)) {
        throw new RuntimeException('A field needs a name and a numeric OID.');
    }
    if ($scale == 0.0) {
        throw new RuntimeException('Scale cannot be 0.');
    }
    $key = ba_field_key($label);
    $st = $db->prepare('SELECT id FROM model_profile_fields WHERE profile_id=? AND field_key=?');
    $st->execute([$profileId, $key]);
    $existing = $st->fetch();
    if ($existing) {
        $bk = $db->prepare('SELECT builtin_key FROM model_profile_fields WHERE id=?');
        $bk->execute([(int)$existing['id']]);
        if (trim((string)$bk->fetchColumn()) !== '') {
            throw new RuntimeException('That name is already on the device summary.');
        }
        $db->prepare('UPDATE model_profile_fields SET label=?, oid=?, unit=?, scale=?, enum_json=?, show_on_device=1 WHERE id=?')
            ->execute([$label, $oid, $unit, $scale, $enumJson !== '' ? $enumJson : null, (int)$existing['id']]);
        return;
    }
    $sort = (int)$db->query('SELECT COALESCE(MAX(sort_order),0)+1 FROM model_profile_fields WHERE profile_id=' . (int)$profileId)->fetchColumn();
    $db->prepare('INSERT INTO model_profile_fields (profile_id, field_key, label, oid, unit, scale, enum_json, show_on_device, sort_order) VALUES (?,?,?,?,?,?,?,?,?)')
        ->execute([$profileId, $key, $label, $oid, $unit, $scale, $enumJson !== '' ? $enumJson : null, 1, $sort]);
}

function ba_render_device_readings(PDO $db, array $device): void
{
    ba_ensure_model_schema($db);
    $profile = ba_model_for_device($db, $device);
    echo '<section class="ucard"><div class="ucard-head"><h3>Readings</h3></div><div class="ucard-body">';
    if (!$profile) {
        echo '<p class="muted">No model profile yet. Models is where a vendor MIB becomes the fields for this device.</p>';
        echo '</div></section>';
        return;
    }
    $explicit = (int)ba_col($device, 'model_profile_id', 0);
    $how = $explicit > 0 ? 'Assigned profile' : 'Matched profile';
    echo '<p class="muted">' . h($how) . ' ' . h((string)$profile['name']) . '. Capacity, runtime, and the battery dates stay in the summary above. A field shows here only after this card returns it.</p>';
    $id = (int)ba_col($device, 'id', 0);
    $fields = ba_model_fields($db, (int)$profile['id']);
    $extra = [];
    foreach ($fields as $f) {
        if (trim((string)($f['builtin_key'] ?? '')) === '') {
            $extra[$f['field_key']] = $f;
        }
    }
    $lines = '';
    $shown = 0;
    if ($extra !== []) {
        $st = $db->prepare('SELECT * FROM device_metrics WHERE device_id=?');
        $st->execute([$id]);
        foreach ($st->fetchAll() ?: [] as $metric) {
            $key = (string)($metric['field_key'] ?? '');
            if (!isset($extra[$key])) {
                continue;
            }
            $f = $extra[$key];
            $text = trim((string)($metric['value_text'] ?? ''));
            if ($text === '' && ($metric['value_num'] ?? null) !== null && $metric['value_num'] !== '') {
                $num = (float)$metric['value_num'];
                $text = rtrim(rtrim(number_format($num, 2, '.', ''), '0'), '.');
                $unit = trim((string)($f['unit'] ?? ''));
                if ($unit !== '') {
                    $text .= ' ' . $unit;
                }
            }
            if ($text === '') {
                continue;
            }
            $lines .= '<tr><td>' . h((string)$f['label']) . '</td><td>' . h($text) . '</td></tr>';
            $shown++;
        }
    }
    if ($shown === 0) {
        echo '<p class="muted">No extra readings yet. They fill in on the next climate poll when the card answers.</p>';
    } else {
        echo '<table><thead><tr><th>Field</th><th>Value</th></tr></thead><tbody>' . $lines . '</tbody></table>';
    }
    echo '</div></section>';
}

function ba_mib_name_for_oid(PDO $db, string $oid): string
{
    $st = $db->prepare("SELECT name FROM mib_objects WHERE oid=? AND access NOT IN ('node') ORDER BY id DESC LIMIT 1");
    $st->execute([$oid]);
    $name = $st->fetchColumn();
    return $name ? (string)$name : $oid;
}

function page_models(PDO $db, array $user): void
{
    ba_ensure_model_schema($db);
    $admin = ba_editor($user, 'edit_snmp');
    $msg = '';
    $id = (int)($_GET['id'] ?? 0);
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $admin) {
        $act = (string)($_POST['act'] ?? '');
        try {
            if ($act === 'save_profile') {
                $pid = (int)($_POST['id'] ?? 0);
                $name = trim((string)($_POST['name'] ?? ''));
                if ($name === '') {
                    throw new RuntimeException('Name is required.');
                }
                $vendor = trim((string)($_POST['vendor'] ?? ''));
                $match = trim((string)($_POST['match_model'] ?? ''));
                $notes = trim((string)($_POST['notes'] ?? ''));
                if ($pid > 0) {
                    $db->prepare('UPDATE model_profiles SET name=?, vendor=?, match_model=?, notes=? WHERE id=?')
                        ->execute([$name, $vendor, $match, $notes, $pid]);
                } else {
                    $db->prepare('INSERT INTO model_profiles (name, vendor, match_model, notes) VALUES (?,?,?,?)')
                        ->execute([$name, $vendor, $match, $notes]);
                    $pid = ba_last_id($db);
                }
                $id = $pid;
                $msg = 'Model profile saved.';
            } elseif ($act === 'add_field') {
                $pid = (int)($_POST['profile_id'] ?? 0);
                ba_model_add_field(
                    $db,
                    $pid,
                    (string)($_POST['label'] ?? ''),
                    (string)($_POST['oid'] ?? ''),
                    trim((string)($_POST['unit'] ?? '')),
                    ($_POST['scale'] ?? '') === '' ? 1.0 : (float)$_POST['scale']
                );
                $id = $pid;
                $msg = 'Field added. It shows on a device after that card returns a value.';
            } elseif ($act === 'delete_field') {
                $fid = (int)($_POST['field_id'] ?? 0);
                $st = $db->prepare('SELECT profile_id, builtin_key FROM model_profile_fields WHERE id=?');
                $st->execute([$fid]);
                $row = $st->fetch();
                if ($row && trim((string)($row['builtin_key'] ?? '')) !== '') {
                    throw new RuntimeException('Summary fields stay on the device page. Remove an extra field instead.');
                }
                if ($row) {
                    $db->prepare('DELETE FROM model_profile_fields WHERE id=?')->execute([$fid]);
                    $id = (int)$row['profile_id'];
                }
                $msg = 'Field removed.';
            } elseif ($act === 'save_fields') {
                $pid = (int)($_POST['profile_id'] ?? 0);
                $ids = $_POST['field_id'] ?? [];
                $labels = $_POST['field_label'] ?? [];
                $units = $_POST['field_unit'] ?? [];
                $scales = $_POST['field_scale'] ?? [];
                if (!is_array($ids)) {
                    $ids = [];
                }
                foreach ($ids as $i => $fid) {
                    $fid = (int)$fid;
                    $scale = isset($scales[$i]) && $scales[$i] !== '' ? (float)$scales[$i] : 1.0;
                    if ($scale == 0.0) {
                        throw new RuntimeException('Scale cannot be 0.');
                    }
                    $db->prepare('UPDATE model_profile_fields SET label=?, unit=?, scale=? WHERE id=? AND profile_id=? AND (builtin_key IS NULL OR builtin_key=\'\')')
                        ->execute([trim((string)($labels[$i] ?? '')), trim((string)($units[$i] ?? '')), $scale, $fid, $pid]);
                }
                $id = $pid;
                $msg = 'Fields saved.';
            } elseif ($act === 'upload_mib') {
                $files = $_FILES['mib'] ?? null;
                if (!is_array($files) || ($files['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                    throw new RuntimeException('Choose a MIB file.');
                }
                if (($files['error'] ?? 0) !== UPLOAD_ERR_OK) {
                    throw new RuntimeException('Upload failed.');
                }
                $orig = (string)($files['name'] ?? 'module.mib');
                $ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
                $tmp = (string)($files['tmp_name'] ?? '');
                $stored = 0;
                if ($ext === 'zip') {
                    if (!class_exists('ZipArchive')) {
                        throw new RuntimeException('This PHP build cannot open zip files. Upload the MIB on its own.');
                    }
                    $zip = new ZipArchive();
                    if ($zip->open($tmp) !== true) {
                        throw new RuntimeException('Could not open the zip file.');
                    }
                    if ($zip->numFiles > 40) {
                        $zip->close();
                        throw new RuntimeException('Upload 40 MIB files or fewer at a time.');
                    }
                    for ($i = 0; $i < $zip->numFiles; $i++) {
                        $entry = (string)$zip->getNameIndex($i);
                        $base = basename(str_replace('\\', '/', $entry));
                        $eext = strtolower(pathinfo($base, PATHINFO_EXTENSION));
                        if (!in_array($eext, ['mib', 'my', 'txt', ''], true) || str_ends_with($entry, '/')) {
                            continue;
                        }
                        $body = $zip->getFromIndex($i);
                        if (!is_string($body) || $body === '') {
                            continue;
                        }
                        ba_mib_store($db, $base, $body);
                        $stored++;
                    }
                    $zip->close();
                } else {
                    $body = file_get_contents($tmp);
                    if (!is_string($body)) {
                        throw new RuntimeException('Could not read the MIB file.');
                    }
                    ba_mib_store($db, basename($orig), $body);
                    $stored = 1;
                }
                if ($stored < 1) {
                    throw new RuntimeException('The upload had no MIB files.');
                }
                $msg = 'Stored ' . $stored . ' MIB module' . ($stored === 1 ? '' : 's') . '.';
            } elseif ($act === 'walk') {
                $deviceId = (int)($_POST['device_id'] ?? 0);
                $pid = (int)($_POST['profile_id'] ?? 0);
                $root = trim((string)($_POST['root'] ?? ''));
                if ($deviceId < 1 || $pid < 1) {
                    throw new RuntimeException('Choose a device and a model profile.');
                }
                if (!ba_oid_ok($root)) {
                    throw new RuntimeException('Walk root must be a numeric OID, at least four numbers.');
                }
                @set_time_limit(90);
                $script = BA_ROOT . DIRECTORY_SEPARATOR . 'collector' . DIRECTORY_SEPARATOR . 'discover.py';
                $run = ba_python_run([$script, '--id', (string)$deviceId, '--root', $root, '--max', '40']);
                $raw = (string)$run['stdout'];
                $pos = strpos($raw, '{');
                $json = $pos === false ? '' : substr($raw, $pos);
                $data = json_decode($json, true);
                if (!is_array($data)) {
                    $detail = trim((string)$run['stderr']);
                    if (strlen($detail) > 300) {
                        $detail = substr($detail, 0, 300);
                    }
                    throw new RuntimeException('Walk failed. ' . ($detail !== '' ? $detail : 'No result.'));
                }
                if (!empty($data['error'])) {
                    throw new RuntimeException('Walk failed. ' . (string)$data['error']);
                }
                $rows = [];
                foreach ($data['rows'] ?? [] as $row) {
                    if (!is_array($row)) {
                        continue;
                    }
                    $oid = trim((string)($row['oid'] ?? ''));
                    if (!ba_oid_ok($oid)) {
                        continue;
                    }
                    $rows[] = [
                        'oid' => $oid,
                        'name' => ba_mib_name_for_oid($db, $oid),
                        'value' => substr(trim((string)($row['value'] ?? '')), 0, 120),
                    ];
                }
                $_SESSION['ba_walk'] = ['device_id' => $deviceId, 'profile_id' => $pid, 'root' => $root, 'rows' => $rows];
                $id = $pid;
                $msg = count($rows) . ' objects answered under ' . $root . '. Check the ones to keep on this model.';
            } elseif ($act === 'save_walk') {
                $walk = $_SESSION['ba_walk'] ?? null;
                $pid = (int)($_POST['profile_id'] ?? 0);
                if (!is_array($walk) || (int)($walk['profile_id'] ?? 0) !== $pid) {
                    throw new RuntimeException('Walk again, then save the fields.');
                }
                $allowed = [];
                foreach ($walk['rows'] as $row) {
                    $allowed[$row['oid']] = $row;
                }
                $picked = $_POST['oid'] ?? [];
                if (!is_array($picked)) {
                    $picked = [];
                }
                $n = 0;
                foreach ($picked as $oid) {
                    $oid = (string)$oid;
                    if (!isset($allowed[$oid])) {
                        continue;
                    }
                    $row = $allowed[$oid];
                    $label = $row['name'] !== $oid ? str_replace('_', ' ', (string)$row['name']) : $oid;
                    ba_model_add_field($db, $pid, $label, $oid, '', 1);
                    $n++;
                }
                unset($_SESSION['ba_walk']);
                $id = $pid;
                $msg = 'Added ' . $n . ' field' . ($n === 1 ? '' : 's') . ' to the model profile.';
            }
        } catch (Throwable $e) {
            $msg = $e->getMessage();
        }
    }
    $profiles = ba_model_profiles($db);
    $current = null;
    foreach ($profiles as $p) {
        if ((int)$p['id'] === $id) {
            $current = $p;
        }
    }
    ba_layout_start('Models', 'models');
    if ($msg !== '') {
        echo '<div class="flash">' . h($msg) . '</div>';
    }
    echo '<h1>Models</h1>';
    echo '<p class="muted">SNMPv3 credentials are the login. A model profile is the field list for one kind of device. Upload a vendor MIB to name those OIDs. A field is polled with the climate pass and shows on the device only when that card returns a value.</p>';
    echo '<div class="card-board">';
    ba_card_open('Model profiles');
    echo '<table><thead><tr><th>Name</th><th>Vendor</th><th>Matches</th><th></th></tr></thead><tbody>';
    foreach ($profiles as $p) {
        echo '<tr><td>' . h((string)$p['name']) . '</td><td>' . h((string)($p['vendor'] ?? '')) . '</td><td>' . h((string)($p['match_model'] ?? '')) . '</td>';
        echo '<td><a href="' . h(ba_href('/models?id=' . (int)$p['id'])) . '">Edit</a></td></tr>';
    }
    if ($profiles === []) {
        echo '<tr><td colspan="4" class="muted">No model profiles yet.</td></tr>';
    }
    echo '</tbody></table>';
    ba_card_close();

    $formTitle = $current ? 'Edit ' . (string)$current['name'] : 'New model profile';
    echo '<form method="post" class="ucard stack"><div class="ucard-head"><h3>' . h($formTitle) . '</h3></div><div class="ucard-body">';
    echo '<input type="hidden" name="act" value="save_profile">';
    echo '<input type="hidden" name="id" value="' . (int)($current['id'] ?? 0) . '">';
    echo '<label>Name</label><input name="name" value="' . h((string)($current['name'] ?? '')) . '"' . ($admin ? '' : ' disabled') . '>';
    echo '<label>Vendor</label><input name="vendor" value="' . h((string)($current['vendor'] ?? '')) . '"' . ($admin ? '' : ' disabled') . '>';
    echo '<label>Match model</label><input name="match_model" value="' . h((string)($current['match_model'] ?? '')) . '"' . ($admin ? '' : ' disabled') . '>';
    echo '<p class="muted">A device uses the profile you assign. Otherwise the match text is looked for in the model name. While only one profile exists, every device uses it.</p>';
    echo '<label>Notes</label><textarea name="notes"' . ($admin ? '' : ' disabled') . '>' . h((string)($current['notes'] ?? '')) . '</textarea>';
    if ($admin) {
        echo '<button>Save profile</button>';
    }
    echo '</div></form>';
    echo '</div>';

    if ($current) {
        $fields = ba_model_fields($db, (int)$current['id']);
        ba_card_open('Fields');
        echo '<p class="muted">Summary fields are already on the device page. Extra fields are the ones a MIB or a walk adds.</p>';
        echo '<table><thead><tr><th>Field</th><th>OID</th><th>Unit</th><th>Scale</th><th></th></tr></thead><tbody>';
        $extras = [];
        foreach ($fields as $f) {
            $builtin = trim((string)($f['builtin_key'] ?? '')) !== '';
            if ($builtin) {
                echo '<tr><td>' . h((string)$f['label']) . '</td><td class="muted">' . h((string)$f['oid']) . '</td><td>' . h((string)($f['unit'] ?? '')) . '</td><td class="muted">summary</td><td></td></tr>';
            } else {
                $extras[] = $f;
            }
        }
        echo '</tbody></table>';
        if ($admin && $extras !== []) {
            echo '<form method="post">';
            echo '<input type="hidden" name="act" value="save_fields"><input type="hidden" name="profile_id" value="' . (int)$current['id'] . '">';
            echo '<table><thead><tr><th>Extra field</th><th>OID</th><th>Unit</th><th>Scale</th><th></th></tr></thead><tbody>';
            foreach ($extras as $f) {
                $fid = (int)$f['id'];
                echo '<tr><td><input name="field_label[]" value="' . h((string)$f['label']) . '"><input type="hidden" name="field_id[]" value="' . $fid . '"></td>';
                echo '<td class="muted">' . h((string)$f['oid']) . '</td>';
                echo '<td><input name="field_unit[]" value="' . h((string)($f['unit'] ?? '')) . '"></td>';
                echo '<td><input name="field_scale[]" value="' . h((string)$f['scale']) . '"></td>';
                echo '<td><button form="drop-' . $fid . '" class="btn">Remove</button></td></tr>';
            }
            echo '</tbody></table><button>Save extra fields</button></form>';
            foreach ($extras as $f) {
                $fid = (int)$f['id'];
                echo '<form id="drop-' . $fid . '" method="post"><input type="hidden" name="act" value="delete_field"><input type="hidden" name="field_id" value="' . $fid . '"></form>';
            }
        } elseif ($extras === []) {
            echo '<p class="muted">No extra fields yet. Search a MIB or walk one live unit.</p>';
        }
        if ($admin) {
            echo '<form method="post" class="stack" style="margin-top:1rem">';
            echo '<input type="hidden" name="act" value="add_field"><input type="hidden" name="profile_id" value="' . (int)$current['id'] . '">';
            echo '<label>Add a field</label><input name="label" placeholder="Input frequency">';
            echo '<label>OID</label><input name="oid" placeholder="1.3.6.1.2.1.33.1.3.3.1.2.1">';
            echo '<label>Unit</label><input name="unit" placeholder="Hz">';
            echo '<label>Scale</label><input name="scale" placeholder="0.1">';
            echo '<button>Add field</button></form>';
            $q = trim((string)($_GET['q'] ?? ''));
            echo '<form method="get" class="stack" style="margin-top:1rem">';
            echo '<input type="hidden" name="id" value="' . (int)$current['id'] . '">';
            echo '<label>Search uploaded MIBs</label><input name="q" value="' . h($q) . '">';
            echo '<button>Search</button></form>';
            if ($q !== '') {
                $hits = ba_mib_search($db, $q);
                if ($hits === []) {
                    echo '<p class="muted">No readable objects match.</p>';
                }
                foreach ($hits as $hit) {
                    echo '<form method="post" class="filters">';
                    echo '<input type="hidden" name="act" value="add_field"><input type="hidden" name="profile_id" value="' . (int)$current['id'] . '">';
                    echo '<input type="hidden" name="label" value="' . h((string)$hit['name']) . '">';
                    echo '<input type="hidden" name="oid" value="' . h((string)$hit['oid']) . '">';
                    echo '<input type="hidden" name="unit" value="' . h((string)($hit['units'] ?? '')) . '">';
                    echo '<span>' . h((string)$hit['module_name']) . ' ' . h((string)$hit['name']) . ' <span class="muted">' . h((string)$hit['oid']) . '</span></span>';
                    echo '<button>Add</button></form>';
                }
            }
        }
        ba_card_close();

        if ($admin) {
            $walk = $_SESSION['ba_walk'] ?? null;
            ba_card_open('Walk one device');
            echo '<p class="muted">Walk stays under the root you type and stops after 40 replies. Check the objects worth keeping. This does not poll the rest of the fleet.</p>';
            echo '<form method="post" class="stack">';
            echo '<input type="hidden" name="act" value="walk"><input type="hidden" name="profile_id" value="' . (int)$current['id'] . '">';
            echo '<label>Device</label><select name="device_id">';
            foreach ($db->query("SELECT id, hostname, ip, model FROM devices WHERE enabled=1 ORDER BY hostname, ip") as $d) {
                echo '<option value="' . (int)$d['id'] . '">' . h((string)(($d['hostname'] ?: $d['ip']) . ' ' . ($d['model'] ?? ''))) . '</option>';
            }
            echo '</select>';
            echo '<label>Walk root</label><input name="root" value="1.3.6.1.2.1.33">';
            echo '<p class="muted">1.3.6.1.2.1.33 is standard UPS-MIB. A vendor branch is longer, for example the enterprise OID from the uploaded MIB.</p>';
            echo '<button>Walk</button></form>';
            if (is_array($walk) && (int)($walk['profile_id'] ?? 0) === (int)$current['id'] && ($walk['rows'] ?? []) !== []) {
                echo '<form method="post">';
                echo '<input type="hidden" name="act" value="save_walk"><input type="hidden" name="profile_id" value="' . (int)$current['id'] . '">';
                echo '<table><thead><tr><th></th><th>Name</th><th>OID</th><th>Value</th></tr></thead><tbody>';
                foreach ($walk['rows'] as $row) {
                    echo '<tr><td><input type="checkbox" name="oid[]" value="' . h((string)$row['oid']) . '"></td>';
                    echo '<td>' . h((string)$row['name']) . '</td><td class="muted">' . h((string)$row['oid']) . '</td><td>' . h((string)$row['value']) . '</td></tr>';
                }
                echo '</tbody></table><button>Add checked fields</button></form>';
            }
            ba_card_close();
        }
    }

    ba_card_open('MIB library', $admin ? '' : '');
    echo '<p class="muted">A MIB names OIDs. It does not decide which fields a device shows. Upload the vendor file, or a zip of the MIB and the files it imports.</p>';
    if ($admin) {
        echo '<form method="post" enctype="multipart/form-data" class="filters">';
        echo '<input type="hidden" name="act" value="upload_mib">';
        echo '<input type="file" name="mib" accept=".mib,.my,.txt,.zip">';
        echo '<button>Upload</button></form>';
    }
    $mods = $db->query('SELECT * FROM mib_modules ORDER BY name')->fetchAll() ?: [];
    echo '<table><thead><tr><th>Module</th><th>File</th><th>Objects</th><th>Uploaded</th></tr></thead><tbody>';
    foreach ($mods as $mod) {
        echo '<tr><td>' . h((string)$mod['name']) . '</td><td>' . h((string)$mod['filename']) . '</td><td>' . (int)$mod['object_count'] . '</td><td>' . h((string)$mod['uploaded_at']) . '</td></tr>';
    }
    if ($mods === []) {
        echo '<tr><td colspan="4" class="muted">No MIBs uploaded yet.</td></tr>';
    }
    echo '</tbody></table>';
    ba_card_close();
    ba_layout_end();
}
