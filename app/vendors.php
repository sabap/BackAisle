<?php
declare(strict_types=1);

/** Power vendor defaults. CyberPower onboarding, firmware packages, and battery date. */

function ba_ensure_vendor_schema(PDO $db): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    if (ba_db_driver() === 'sqlsrv') {
        $db->exec("IF OBJECT_ID('dbo.power_vendors','U') IS NULL CREATE TABLE power_vendors (
            code NVARCHAR(32) NOT NULL PRIMARY KEY,
            name NVARCHAR(64) NOT NULL,
            apply_config INT NOT NULL DEFAULT 0,
            config_template_id INT NULL,
            enforce_firmware INT NOT NULL DEFAULT 0,
            firmware_id INT NULL,
            set_battery_date INT NOT NULL DEFAULT 0,
            updated_at NVARCHAR(32) NULL,
            updated_by NVARCHAR(128) NULL
        )");
    } else {
        $db->exec("CREATE TABLE IF NOT EXISTS power_vendors (
            code TEXT PRIMARY KEY,
            name TEXT NOT NULL,
            apply_config INTEGER NOT NULL DEFAULT 0,
            config_template_id INTEGER,
            enforce_firmware INTEGER NOT NULL DEFAULT 0,
            firmware_id INTEGER,
            set_battery_date INTEGER NOT NULL DEFAULT 0,
            updated_at TEXT,
            updated_by TEXT
        )");
    }
    ba_ensure_column($db, 'firmware_images', 'notes_path', ba_db_driver() === 'sqlsrv' ? 'NVARCHAR(512) NULL' : 'TEXT');
    ba_ensure_column($db, 'firmware_images', 'source_name', ba_db_driver() === 'sqlsrv' ? 'NVARCHAR(255) NULL' : 'TEXT');
    $have = $db->prepare('SELECT code FROM power_vendors WHERE code=?');
    $have->execute(['cyberpower']);
    if (!$have->fetch()) {
        $db->prepare('INSERT INTO power_vendors (code, name, apply_config, enforce_firmware, set_battery_date) VALUES (?,?,0,0,0)')
            ->execute(['cyberpower', 'CyberPower']);
    }
}

/** @return array<string, mixed> */
function ba_vendor_settings(PDO $db, string $code = 'cyberpower'): array
{
    ba_ensure_vendor_schema($db);
    $st = $db->prepare('SELECT * FROM power_vendors WHERE code=?');
    $st->execute([$code]);
    $row = $st->fetch();
    if (!$row) {
        return [
            'code' => $code,
            'name' => $code,
            'apply_config' => 0,
            'config_template_id' => 0,
            'enforce_firmware' => 0,
            'firmware_id' => 0,
            'set_battery_date' => 0,
        ];
    }
    return [
        'code' => (string)$row['code'],
        'name' => (string)$row['name'],
        'apply_config' => (int)$row['apply_config'],
        'config_template_id' => (int)($row['config_template_id'] ?? 0),
        'enforce_firmware' => (int)$row['enforce_firmware'],
        'firmware_id' => (int)($row['firmware_id'] ?? 0),
        'set_battery_date' => (int)$row['set_battery_date'],
    ];
}

/**
 * Classify names inside a CyberPower firmware zip.
 *
 * @param list<string> $names
 * @return array{fw:array{zip:string,base:string},data:array{zip:string,base:string},notes:list<array{zip:string,base:string}>}
 */
function ba_firmware_zip_plan(array $names): array
{
    $fw = [];
    $data = [];
    $notes = [];
    foreach ($names as $name) {
        $raw = str_replace('\\', '/', (string)$name);
        if ($raw === '' || str_ends_with($raw, '/')) {
            continue;
        }
        if (str_contains($raw, '../') || str_starts_with($raw, '/') || str_contains($raw, ':')) {
            throw new RuntimeException('The firmware package has an unsafe path');
        }
        $base = basename($raw);
        if ($base === '' || $base === '.' || $base === '..') {
            continue;
        }
        $item = ['zip' => (string)$name, 'base' => $base];
        if (preg_match('/^cpsrm2scfw_.*\.bin$/i', $base)) {
            $fw[] = $item;
        } elseif (preg_match('/^cpsrm2scdata_.*\.bin$/i', $base)) {
            $data[] = $item;
        } elseif (preg_match('/\.txt$/i', $base)) {
            $notes[] = $item;
        }
    }
    if (count($fw) !== 1 || count($data) !== 1) {
        throw new RuntimeException('The package needs one cpsrm2scfw bin and one cpsrm2scdata bin');
    }
    return ['fw' => $fw[0], 'data' => $data[0], 'notes' => $notes];
}

function ba_firmware_version_label(string $fwBase, string $infoText): string
{
    if (preg_match('/\bv(\d+\.\d+\.\d+)\b/i', $infoText, $match)) {
        return $match[1];
    }
    if (preg_match('/cpsrm2scfw_(\d{3})\.bin$/i', $fwBase, $match)) {
        $digits = $match[1];
        return $digits[0] . '.' . $digits[1] . '.' . $digits[2];
    }
    throw new RuntimeException('The firmware package does not say which version it is');
}

function ba_firmware_byte_sum(string $path): int
{
    $handle = fopen($path, 'rb');
    if ($handle === false) {
        throw new RuntimeException('Could not read a firmware file');
    }
    $sum = 0;
    try {
        while (!feof($handle)) {
            $chunk = fread($handle, 65536);
            if ($chunk === false || $chunk === '') {
                break;
            }
            $length = strlen($chunk);
            for ($i = 0; $i < $length; $i++) {
                $sum = ($sum + ord($chunk[$i])) & 0xFFFFFFFF;
            }
        }
    } finally {
        fclose($handle);
    }
    return $sum;
}

/** @param array<string, string> $sums hex checksum by base name, without a prefix */
function ba_firmware_check_sums(string $fwPath, string $fwBase, string $dataPath, string $dataBase, array $sums): void
{
    $expect = [
        strtolower($fwBase) => $fwPath,
        strtolower($dataBase) => $dataPath,
    ];
    foreach ($expect as $base => $path) {
        if (!isset($sums[$base])) {
            continue;
        }
        $want = hexdec($sums[$base]);
        $have = ba_firmware_byte_sum($path);
        if ($have !== $want) {
            throw new RuntimeException('The firmware package checksum does not match ' . $base);
        }
    }
}

/** @return array<string, string> */
function ba_firmware_checksums_from_info(string $info): array
{
    $sums = [];
    if (preg_match_all('/(cpsrm2sc(?:fw|data)_\S+\.bin)\s+0x([0-9a-f]+)/i', $info, $matches, PREG_SET_ORDER)) {
        foreach ($matches as $match) {
            $sums[strtolower($match[1])] = $match[2];
        }
    }
    return $sums;
}

function ba_store_firmware_zip(PDO $db, array $user, string $tmpPath, string $originalName): string
{
    ba_ensure_vendor_schema($db);
    if (!is_file($tmpPath)) {
        throw new RuntimeException('The firmware package was not received');
    }
    if (filesize($tmpPath) > 32 * 1024 * 1024) {
        throw new RuntimeException('The firmware package is larger than 32 MB');
    }
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('PHP cannot open zip packages on this server');
    }
    $zip = new ZipArchive();
    if ($zip->open($tmpPath) !== true) {
        throw new RuntimeException('The firmware package is not a zip file');
    }
    $names = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $stat = $zip->statIndex($i);
        if (is_array($stat) && isset($stat['name'])) {
            $names[] = (string)$stat['name'];
        }
    }
    $plan = ba_firmware_zip_plan($names);
    $infoText = '';
    foreach ($plan['notes'] as $note) {
        if (strcasecmp($note['base'], 'Info.txt') === 0) {
            $infoText = (string)$zip->getFromName($note['zip']);
            break;
        }
    }
    $version = ba_firmware_version_label($plan['fw']['base'], $infoText);
    if (!preg_match('/^\d+\.\d+\.\d+$/', $version)) {
        $zip->close();
        throw new RuntimeException('The firmware version is not a dotted number');
    }
    $root = BA_FIRMWARE_DIR;
    if (!is_dir($root) && !@mkdir($root, 0770, true) && !is_dir($root)) {
        $zip->close();
        throw new RuntimeException('Could not create the firmware folder');
    }
    $staging = $root . DIRECTORY_SEPARATOR . '_incoming_' . bin2hex(random_bytes(4));
    if (!@mkdir($staging, 0770, true)) {
        $zip->close();
        throw new RuntimeException('Could not stage the firmware package');
    }
    try {
        $written = [];
        $members = array_merge([$plan['fw'], $plan['data']], $plan['notes']);
        foreach ($members as $member) {
            $bytes = $zip->getFromName($member['zip']);
            if (!is_string($bytes) || $bytes === '') {
                throw new RuntimeException('The firmware package is missing ' . $member['base']);
            }
            $dest = $staging . DIRECTORY_SEPARATOR . $member['base'];
            if (file_put_contents($dest, $bytes) === false) {
                throw new RuntimeException('Could not store ' . $member['base']);
            }
            $written[$member['base']] = $dest;
        }
        $fwStaged = $written[$plan['fw']['base']];
        $dataStaged = $written[$plan['data']['base']];
        ba_firmware_check_sums(
            $fwStaged,
            $plan['fw']['base'],
            $dataStaged,
            $plan['data']['base'],
            ba_firmware_checksums_from_info($infoText)
        );
        $folder = $root . DIRECTORY_SEPARATOR . $version;
        if (!is_dir($folder) && !@mkdir($folder, 0770, true) && !is_dir($folder)) {
            throw new RuntimeException('Could not create the version folder');
        }
        foreach ($written as $base => $from) {
            $target = $folder . DIRECTORY_SEPARATOR . $base;
            if (is_file($target)) {
                @unlink($target);
            }
            if (!@rename($from, $target)) {
                if (!@copy($from, $target)) {
                    throw new RuntimeException('Could not keep ' . $base);
                }
                @unlink($from);
            }
        }
        foreach (scandir($folder) ?: [] as $existing) {
            if (!preg_match('/\.txt$/i', $existing)) {
                continue;
            }
            $keep = false;
            foreach ($plan['notes'] as $note) {
                if (strcasecmp($note['base'], $existing) === 0) {
                    $keep = true;
                    break;
                }
            }
            if (!$keep) {
                @unlink($folder . DIRECTORY_SEPARATOR . $existing);
            }
        }
    } finally {
        $zip->close();
        if (is_dir($staging)) {
            foreach (scandir($staging) ?: [] as $left) {
                if ($left === '.' || $left === '..') {
                    continue;
                }
                @unlink($staging . DIRECTORY_SEPARATOR . $left);
            }
            @rmdir($staging);
        }
    }
    $fwDest = $folder . DIRECTORY_SEPARATOR . $plan['fw']['base'];
    $dataDest = $folder . DIRECTORY_SEPARATOR . $plan['data']['base'];
    $now = gmdate('Y-m-d H:i:s');
    $who = (string)($user['username'] ?? '');
    $source = basename(str_replace('\\', '/', $originalName));
    $found = $db->prepare('SELECT id FROM firmware_images WHERE version=?');
    $found->execute([$version]);
    $existing = $found->fetch();
    if ($existing) {
        $db->prepare('UPDATE firmware_images SET fw_path=?, data_path=?, notes_path=?, source_name=?, uploaded_at=?, uploaded_by=? WHERE id=?')
            ->execute([$fwDest, $dataDest, $folder, $source, $now, $who, (int)$existing['id']]);
    } else {
        $db->prepare('INSERT INTO firmware_images (version, fw_path, data_path, notes_path, source_name, uploaded_at, uploaded_by) VALUES (?,?,?,?,?,?,?)')
            ->execute([$version, $fwDest, $dataDest, $folder, $source, $now, $who]);
    }
    ba_audit($db, 'firmware_upload', 'firmware', $version, $source);
    return $version;
}

/** @return list<array<string, mixed>> */
function ba_firmware_images(PDO $db): array
{
    ba_ensure_vendor_schema($db);
    $rows = $db->query('SELECT * FROM firmware_images')->fetchAll();
    usort($rows, static function ($a, $b): int {
        return version_compare((string)$b['version'], (string)$a['version']);
    });
    return $rows;
}

/** Refuse a factory account before a new card is inserted. The password is not returned. */
function ba_vendor_onboarding_login(PDO $db, ?int $webId): void
{
    $factory = ba_rmcard_factory_login();
    $user = '';
    $pass = '';
    if ($webId !== null && $webId > 0) {
        $st = $db->prepare('SELECT username FROM web_credentials WHERE id=?');
        $st->execute([$webId]);
        $row = $st->fetch();
        if (!$row) {
            throw new RuntimeException('Unknown Web/SSH/FTP login');
        }
        $user = trim((string)$row['username']);
        $rec = ba_webcred_read()[(string)$webId] ?? null;
        $pass = is_array($rec) ? (string)($rec['password'] ?? '') : '';
        if ($user === '' || $pass === '') {
            throw new RuntimeException('The Web/SSH/FTP login has no password');
        }
    } else {
        $secrets = ba_secrets();
        $user = trim((string)($secrets['UPS_WEB_USER'] ?? ''));
        $pass = (string)($secrets['UPS_WEB_PASS'] ?? '');
        if ($user === '' || $pass === '') {
            throw new RuntimeException('Assign a Web/SSH/FTP login that is not the factory account');
        }
    }
    $factoryUser = (string)$factory['user'];
    $factoryPass = (string)$factory['pass'];
    if (strcasecmp($user, $factoryUser) === 0 || hash_equals($factoryPass, $pass)) {
        throw new RuntimeException('Choose a Web/SSH/FTP login that is not the factory account');
    }
}

function ba_vendor_switch(string $name, string $label, bool $on): void
{
    echo '<label class="switch"><input type="checkbox" name="' . h($name) . '" value="1"' . ($on ? ' checked' : '') . '>';
    echo '<span class="switch-ui" aria-hidden="true"></span><span class="switch-label">' . h($label) . '</span></label>';
}

function page_power_vendors(PDO $db, array $user): void
{
    ba_require_perm('edit_writes');
    ba_ensure_vendor_schema($db);
    $msg = '';
    $bad = false;
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        try {
            $act = (string)($_POST['act'] ?? '');
            if ($act === 'save_cyberpower') {
                $settings = ba_vendor_settings($db);
                $apply = isset($_POST['apply_config']) ? 1 : 0;
                $enforce = isset($_POST['enforce_firmware']) ? 1 : 0;
                $battery = isset($_POST['set_battery_date']) ? 1 : 0;
                $configId = (int)($_POST['config_template_id'] ?? 0);
                $firmwareId = (int)($_POST['firmware_id'] ?? 0);
                if ($apply === 1) {
                    $chk = $db->prepare('SELECT id FROM config_templates WHERE id=?');
                    $chk->execute([$configId]);
                    $haveConfig = (bool)$chk->fetch();
                    $chk->closeCursor();
                    if ($configId < 1 || !$haveConfig) {
                        throw new RuntimeException('Choose a config profile before turning on the default config');
                    }
                }
                if ($enforce === 1) {
                    $chk = $db->prepare('SELECT id FROM firmware_images WHERE id=?');
                    $chk->execute([$firmwareId]);
                    $haveFirmware = (bool)$chk->fetch();
                    $chk->closeCursor();
                    if ($firmwareId < 1 || !$haveFirmware) {
                        throw new RuntimeException('Choose an uploaded firmware version before turning on the minimum firmware');
                    }
                }
                $db->prepare('UPDATE power_vendors SET apply_config=?, config_template_id=?, enforce_firmware=?, firmware_id=?, set_battery_date=?, updated_at=?, updated_by=? WHERE code=?')
                    ->execute([
                        $apply,
                        $configId > 0 ? $configId : null,
                        $enforce,
                        $firmwareId > 0 ? $firmwareId : null,
                        $battery,
                        gmdate('Y-m-d H:i:s'),
                        (string)($user['username'] ?? ''),
                        'cyberpower',
                    ]);
                ba_audit($db, 'vendor_settings', 'power_vendor', 'cyberpower', 'onboarding defaults saved');
                $msg = 'CyberPower onboarding defaults saved';
            } elseif ($act === 'upload_firmware_zip') {
                $file = $_FILES['package'] ?? null;
                if (!is_array($file) || (int)($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                    throw new RuntimeException('Choose the zip file from CyberPower');
                }
                if ((int)$file['error'] !== UPLOAD_ERR_OK) {
                    throw new RuntimeException('The firmware package did not upload');
                }
                $version = ba_store_firmware_zip($db, $user, (string)$file['tmp_name'], (string)($file['name'] ?? 'firmware.zip'));
                $msg = 'Firmware ' . $version . ' is stored for deployment';
            } else {
                throw new RuntimeException('Unknown action');
            }
        } catch (Throwable $e) {
            $bad = true;
            $msg = $e->getMessage();
        }
    }

    $settings = ba_vendor_settings($db);
    $profiles = $db->query('SELECT id, name FROM config_templates ORDER BY name')->fetchAll();
    $images = ba_firmware_images($db);
    ba_layout_start('Power vendors', 'vendors');
    if ($msg !== '') {
        echo '<div class="flash' . ($bad ? ' err' : '') . '">' . h($msg) . '</div>';
    }
    echo '<h1>Power vendors</h1>';
    echo '<p class="muted">Each vendor keeps the onboarding defaults, the commands for that vendor, and the firmware packages used when a new unit is added. CyberPower is the first section.</p>';

    ba_card_open('CyberPower');
    echo '<p class="muted">These settings apply when someone adds a default UPS. A factory card is signed in with the factory account. If it asks for a new username and password, BackAisle answers with the Web/SSH/FTP login saved on that UPS. If it opens at the command prompt, BackAisle sets the name and password there. Apply config profile uses that same check when its factory login box is checked. That apply does not update firmware and does not set the battery date.</p>';
    echo '<form method="post" class="stack">';
    echo '<input type="hidden" name="act" value="save_cyberpower">';
    ba_vendor_switch('apply_config', 'Apply a default config profile', (int)$settings['apply_config'] === 1);
    echo '<label for="vendor-config">Default config profile</label><select id="vendor-config" name="config_template_id">';
    echo '<option value="0">Choose a profile</option>';
    foreach ($profiles as $profile) {
        $id = (int)$profile['id'];
        $selected = $id === (int)$settings['config_template_id'] ? ' selected' : '';
        echo '<option value="' . $id . '"' . $selected . '>' . h((string)$profile['name']) . '</option>';
    }
    echo '</select>';
    echo '<p class="muted">Turn this on to write the selected profile during new CyberPower onboarding. The card keeps its own IP address, gateway, subnet mask, hostname, and MAC address.</p>';
    ba_vendor_switch('enforce_firmware', 'Require a minimum firmware version', (int)$settings['enforce_firmware'] === 1);
    echo '<label for="vendor-fw">Minimum firmware</label><select id="vendor-fw" name="firmware_id">';
    echo '<option value="0">Choose an uploaded version</option>';
    foreach ($images as $image) {
        $id = (int)$image['id'];
        $selected = $id === (int)$settings['firmware_id'] ? ' selected' : '';
        echo '<option value="' . $id . '"' . $selected . '>' . h((string)$image['version']) . '</option>';
    }
    echo '</select>';
    echo '<p class="muted">Turn this on to update a new card when its firmware is older than the version selected here. The card reboots twice. Do not power off the UPS during that update.</p>';
    ba_vendor_switch('set_battery_date', 'Set the battery replacement date to today', (int)$settings['set_battery_date'] === 1);
    echo '<p class="muted">Turn this on to write today as the battery replacement date over SNMPv3 after the card is online.</p>';
    echo '<button>Save CyberPower defaults</button></form>';

    echo '<h3>Firmware repository</h3>';
    echo '<p class="muted">Upload the zip from CyberPower. It contains the firmware bin, the data bin, and the update notes. BackAisle extracts those files and keeps them outside the website.</p>';
    echo '<form method="post" enctype="multipart/form-data" class="stack">';
    echo '<input type="hidden" name="act" value="upload_firmware_zip">';
    echo '<label for="vendor-zip">Firmware zip</label><input id="vendor-zip" type="file" name="package" accept=".zip,application/zip" required>';
    echo '<button>Store firmware package</button></form>';
    if (!$images) {
        echo '<p class="muted">No firmware packages are stored yet.</p>';
    } else {
        echo '<table><thead><tr><th>Version</th><th>Firmware</th><th>Data</th><th>Notes</th><th>Uploaded</th></tr></thead><tbody>';
        foreach ($images as $image) {
            $notes = [];
            $dir = (string)($image['notes_path'] ?? '');
            if ($dir !== '' && is_dir($dir)) {
                foreach (scandir($dir) ?: [] as $entry) {
                    if (preg_match('/\.txt$/i', $entry)) {
                        $notes[] = $entry;
                    }
                }
            }
            echo '<tr><td>' . h((string)$image['version']) . '</td><td>' . h(basename((string)$image['fw_path'])) . '</td><td>' . h(basename((string)$image['data_path'])) . '</td><td>' . h($notes ? implode(', ', $notes) : 'none') . '</td><td>' . h((string)$image['uploaded_at']) . '</td></tr>';
        }
        echo '</tbody></table>';
    }
    ba_card_close();

    ba_card_open('Other vendors');
    echo '<p class="muted">APC and other power vendors will get a section here for their own defaults, commands, and firmware. Nothing is configured for them yet.</p>';
    ba_card_close();
    ba_layout_end();
}
