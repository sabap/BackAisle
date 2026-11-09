<?php
declare(strict_types=1);

/** Switch components: catalog templates, a bay drawing on the switch template, and the parts installed on one device. */

function ba_part_kinds(): array
{
    return [
        'power_supply' => 'Power supply',
        'supervisor' => 'Supervisor',
        'line_card' => 'Line card',
        'network_module' => 'Network module',
        'stack_port' => 'Stacking port',
        'base_ports' => 'Switch ports',
    ];
}

function ba_part_kind_label(string $kind): string
{
    return ba_part_kinds()[$kind] ?? $kind;
}

function ba_part_kind_allowed(string $kind): string
{
    $kind = strtolower(trim($kind));
    return isset(ba_part_kinds()[$kind]) ? $kind : '';
}

function ba_part_plug_types(): array
{
    return array_merge(ba_plug_types(), ['C13', 'C14', 'C15', 'C19', 'C20']);
}

function ba_part_plug_canon(string $plug): string
{
    $plug = trim($plug);
    if (strcasecmp($plug, 'L620') === 0) {
        return 'L6-20';
    }
    foreach (ba_part_plug_types() as $p) {
        if (strcasecmp($plug, $p) === 0) {
            return $p;
        }
    }
    return '';
}

function ba_part_plug_select(string $name, string $current): string
{
    $cur = ba_part_plug_canon($current);
    $html = '<select name="' . h($name) . '"><option value="">(plug type)</option>';
    foreach (ba_part_plug_types() as $p) {
        $sel = $cur === $p ? ' selected' : '';
        $html .= '<option value="' . h($p) . '"' . $sel . '>' . h($p) . '</option>';
    }
    return $html . '</select>';
}

function ba_parts_clip(string $raw, int $max): string
{
    $raw = trim(str_replace("\0", '', $raw));
    if (function_exists('mb_substr')) {
        return mb_substr($raw, 0, $max);
    }
    if (strlen($raw) > $max) {
        return substr($raw, 0, $max);
    }
    return $raw;
}

function ba_parts_nullable(string $raw, int $max): ?string
{
    $raw = ba_parts_clip($raw, $max);
    return $raw === '' ? null : $raw;
}

function ba_parts_date(string $raw): ?string
{
    $raw = trim($raw);
    if ($raw === '') {
        return null;
    }
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $raw, $m)) {
        throw new RuntimeException('Use a real date.');
    }
    if (!checkdate((int)$m[2], (int)$m[3], (int)$m[1])) {
        throw new RuntimeException('Use a real date.');
    }
    return $raw;
}

function ba_parts_int(mixed $raw, int $min, int $max, string $label): ?int
{
    $s = trim((string)$raw);
    if ($s === '') {
        return null;
    }
    if (!preg_match('/^\d+$/', $s)) {
        throw new RuntimeException($label . ' needs a whole number.');
    }
    $n = (int)$s;
    if ($n < $min || $n > $max) {
        throw new RuntimeException($label . ' is out of range.');
    }
    return $n;
}

function ba_parts_float(mixed $raw, float $min, float $max, string $label): ?float
{
    $s = trim((string)$raw);
    if ($s === '') {
        return null;
    }
    if (!preg_match('/^\d+(\.\d+)?$/', $s)) {
        throw new RuntimeException($label . ' needs a number.');
    }
    $n = (float)$s;
    if ($n < $min || $n > $max) {
        throw new RuntimeException($label . ' is out of range.');
    }
    return $n;
}

function ba_parts_num(mixed $n): string
{
    if ($n === null || $n === '') {
        return '';
    }
    $s = number_format((float)$n, 2, '.', '');
    $s = rtrim(rtrim($s, '0'), '.');
    return $s === '' ? '0' : $s;
}

function ba_parts_rows(PDO $db, string $sql, array $params = []): array
{
    $st = ba_pp_exec($db, $sql, $params);
    $rows = $st->fetchAll() ?: [];
    $st->closeCursor();
    $out = [];
    foreach ($rows as $row) {
        if (is_array($row)) {
            $out[] = array_change_key_case($row, CASE_LOWER);
        }
    }
    return $out;
}

function ba_parts_row(PDO $db, string $sql, array $params = []): ?array
{
    $rows = ba_parts_rows($db, $sql, $params);
    return $rows[0] ?? null;
}

function ba_ensure_parts_schema(PDO $db): void
{
    static $done = false;
    if ($done) {
        return;
    }
    if (ba_db_driver() === 'sqlsrv') {
        ba_ensure_column($db, 'devices', 'purchased_from', 'NVARCHAR(128) NULL');
        ba_ensure_column($db, 'devices', 'warranty_vendor', 'NVARCHAR(128) NULL');
        ba_ensure_column($db, 'devices', 'warranty_expires', 'NVARCHAR(32) NULL');
        ba_ensure_column($db, 'devices', 'device_picture', 'NVARCHAR(255) NULL');
        ba_ensure_column($db, 'device_templates', 'layout_json', 'NVARCHAR(MAX) NULL');
        ba_ensure_column($db, 'device_templates', 'port_type', 'NVARCHAR(64) NULL');
        ba_ensure_column($db, 'device_templates', 'port_speed', 'NVARCHAR(64) NULL');
        $db->exec(
            "IF NOT EXISTS (SELECT * FROM sys.tables WHERE name = 'part_templates')
             CREATE TABLE part_templates (
               id INT IDENTITY(1,1) PRIMARY KEY,
               name NVARCHAR(128) NOT NULL,
               vendor NVARCHAR(128) NULL,
               model NVARCHAR(128) NULL,
               part_kind NVARCHAR(32) NOT NULL,
               weight_kg FLOAT NULL,
               picture NVARCHAR(255) NULL,
               port_count INT NULL,
               port_type NVARCHAR(64) NULL,
               port_speed NVARCHAR(64) NULL,
               mgmt_port_count INT NULL,
               input_count INT NULL,
               plug_type NVARCHAR(32) NULL,
               input_voltage NVARCHAR(32) NULL,
               watts FLOAT NULL,
               notes NVARCHAR(MAX) NULL,
               is_active INT NOT NULL DEFAULT 1,
               created_at DATETIME2 NOT NULL DEFAULT SYSUTCDATETIME(),
               updated_at DATETIME2 NOT NULL DEFAULT SYSUTCDATETIME()
             )"
        );
        $db->exec(
            "IF NOT EXISTS (SELECT * FROM sys.tables WHERE name = 'device_parts')
             CREATE TABLE device_parts (
               id INT IDENTITY(1,1) PRIMARY KEY,
               device_id INT NOT NULL,
               part_template_id INT NULL,
               part_kind NVARCHAR(32) NOT NULL,
               slot_key NVARCHAR(32) NOT NULL,
               serial NVARCHAR(128) NULL,
               purchased_from NVARCHAR(128) NULL,
               install_date NVARCHAR(32) NULL,
               warranty_vendor NVARCHAR(128) NULL,
               warranty_expires NVARCHAR(32) NULL,
               weight_kg FLOAT NULL,
               port_count INT NULL,
               port_type NVARCHAR(64) NULL,
               port_speed NVARCHAR(64) NULL,
               mgmt_port_count INT NULL,
               input_count INT NULL,
               plug_type NVARCHAR(32) NULL,
               input_voltage NVARCHAR(32) NULL,
               watts FLOAT NULL,
               feed_label NVARCHAR(200) NULL,
               link_label NVARCHAR(200) NULL,
               stack_label NVARCHAR(200) NULL,
               notes NVARCHAR(MAX) NULL,
               created_at DATETIME2 NOT NULL DEFAULT SYSUTCDATETIME(),
               updated_at DATETIME2 NOT NULL DEFAULT SYSUTCDATETIME()
             )"
        );
        $db->exec(
            "IF NOT EXISTS (SELECT * FROM sys.tables WHERE name = 'template_parts')
             CREATE TABLE template_parts (
               id INT IDENTITY(1,1) PRIMARY KEY,
               template_id INT NOT NULL,
               part_template_id INT NOT NULL,
               slot_key NVARCHAR(32) NOT NULL,
               sort_order INT NOT NULL DEFAULT 0
             )"
        );
        $db->exec("IF NOT EXISTS (SELECT * FROM sys.indexes WHERE name = 'ux_device_parts_slot' AND object_id = OBJECT_ID('device_parts')) CREATE UNIQUE INDEX ux_device_parts_slot ON device_parts(device_id, slot_key)");
        $db->exec("IF NOT EXISTS (SELECT * FROM sys.indexes WHERE name = 'ux_template_parts_slot' AND object_id = OBJECT_ID('template_parts')) CREATE UNIQUE INDEX ux_template_parts_slot ON template_parts(template_id, slot_key)");
    } else {
        ba_ensure_column($db, 'devices', 'purchased_from', 'TEXT');
        ba_ensure_column($db, 'devices', 'warranty_vendor', 'TEXT');
        ba_ensure_column($db, 'devices', 'warranty_expires', 'TEXT');
        ba_ensure_column($db, 'devices', 'device_picture', 'TEXT');
        ba_ensure_column($db, 'device_templates', 'layout_json', 'TEXT');
        ba_ensure_column($db, 'device_templates', 'port_type', 'TEXT');
        ba_ensure_column($db, 'device_templates', 'port_speed', 'TEXT');
        $db->exec(
            'CREATE TABLE IF NOT EXISTS part_templates (
                id INTEGER PRIMARY KEY,
                name TEXT NOT NULL,
                vendor TEXT,
                model TEXT,
                part_kind TEXT NOT NULL,
                weight_kg REAL,
                picture TEXT,
                port_count INTEGER,
                port_type TEXT,
                port_speed TEXT,
                mgmt_port_count INTEGER,
                input_count INTEGER,
                plug_type TEXT,
                input_voltage TEXT,
                watts REAL,
                notes TEXT,
                is_active INTEGER NOT NULL DEFAULT 1,
                created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
            )'
        );
        $db->exec(
            'CREATE TABLE IF NOT EXISTS device_parts (
                id INTEGER PRIMARY KEY,
                device_id INTEGER NOT NULL,
                part_template_id INTEGER,
                part_kind TEXT NOT NULL,
                slot_key TEXT NOT NULL,
                serial TEXT,
                purchased_from TEXT,
                install_date TEXT,
                warranty_vendor TEXT,
                warranty_expires TEXT,
                weight_kg REAL,
                port_count INTEGER,
                port_type TEXT,
                port_speed TEXT,
                mgmt_port_count INTEGER,
                input_count INTEGER,
                plug_type TEXT,
                input_voltage TEXT,
                watts REAL,
                feed_label TEXT,
                link_label TEXT,
                stack_label TEXT,
                notes TEXT,
                created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
            )'
        );
        $db->exec(
            'CREATE TABLE IF NOT EXISTS template_parts (
                id INTEGER PRIMARY KEY,
                template_id INTEGER NOT NULL,
                part_template_id INTEGER NOT NULL,
                slot_key TEXT NOT NULL,
                sort_order INTEGER NOT NULL DEFAULT 0
            )'
        );
        $db->exec('CREATE UNIQUE INDEX IF NOT EXISTS ux_device_parts_slot ON device_parts(device_id, slot_key)');
        $db->exec('CREATE UNIQUE INDEX IF NOT EXISTS ux_template_parts_slot ON template_parts(template_id, slot_key)');
    }
    $done = true;
}

function ba_part_public_picture(mixed $raw): string
{
    $path = str_replace('\\', '/', trim((string)$raw));
    if ($path === '' || str_contains($path, '..') || str_contains($path, "\0")) {
        return '';
    }
    if (preg_match('#^/assets/tpl/parts/[0-9]+/part\.(jpe?g|png|webp)$#', $path) !== 1) {
        return '';
    }
    return $path;
}

function ba_device_picture_public(mixed $raw, int $deviceId): string
{
    $path = str_replace('\\', '/', trim((string)$raw));
    if ($deviceId < 1 || $path === '' || str_contains($path, '..') || str_contains($path, "\0")) {
        return '';
    }
    if (preg_match('#^/assets/dev/([0-9]+)/front\.png$#', $path, $m) !== 1) {
        return '';
    }
    if ((int)$m[1] !== $deviceId) {
        return '';
    }
    return $path;
}

function ba_device_picture_src(array $device): string
{
    $id = (int)ba_col($device, 'id', 0);
    $path = ba_device_picture_public(ba_col($device, 'device_picture', ''), $id);
    if ($path === '') {
        return '';
    }
    $full = BA_ROOT . '\\public' . str_replace('/', '\\', $path);
    if (!is_file($full)) {
        return '';
    }
    return $path . '?v=' . (string)filemtime($full);
}

function ba_parts_clear_device_image(int $deviceId): void
{
    if ($deviceId < 1) {
        return;
    }
    $dir = BA_ROOT . '\\public\\assets\\dev\\' . $deviceId;
    if (!is_dir($dir)) {
        return;
    }
    foreach (glob($dir . '\\front*.png') ?: [] as $file) {
        if (is_file($file)) {
            @unlink($file);
        }
    }
    @rmdir($dir);
}

function ba_parts_asset_file(string $rel): string
{
    $rel = str_replace('\\', '/', $rel);
    if (!str_starts_with($rel, '/assets/') || str_contains($rel, '..')) {
        throw new RuntimeException('Could not read a picture.');
    }
    $root = realpath(BA_ROOT . '\\public\\assets');
    $full = realpath(BA_ROOT . '\\public' . str_replace('/', '\\', $rel));
    if ($root === false || $full === false || !is_file($full)) {
        throw new RuntimeException('Could not read a picture.');
    }
    $rootNorm = strtolower(str_replace('/', '\\', $root));
    $fullNorm = strtolower(str_replace('/', '\\', $full));
    if (!str_starts_with($fullNorm, $rootNorm . '\\')) {
        throw new RuntimeException('Could not read a picture.');
    }
    return $full;
}

function ba_parts_image_load(string $path)
{
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    $img = false;
    if ($ext === 'jpg' || $ext === 'jpeg') {
        $img = @imagecreatefromjpeg($path);
    } elseif ($ext === 'png') {
        $img = @imagecreatefrompng($path);
    } elseif ($ext === 'webp' && function_exists('imagecreatefromwebp')) {
        $img = @imagecreatefromwebp($path);
    }
    if (!$img) {
        throw new RuntimeException('Could not read a picture.');
    }
    return $img;
}

function ba_parts_image_fit($img, int $maxWidth)
{
    $w = imagesx($img);
    $h = imagesy($img);
    if ($w < 1 || $h < 1) {
        throw new RuntimeException('Could not read a picture.');
    }
    if ($w <= $maxWidth) {
        return $img;
    }
    $nw = $maxWidth;
    $nh = max(1, (int)round($h * ($maxWidth / $w)));
    $scaled = imagecreatetruecolor($nw, $nh);
    imagealphablending($scaled, false);
    imagesavealpha($scaled, true);
    imagecopyresampled($scaled, $img, 0, 0, 0, 0, $nw, $nh, $w, $h);
    imagedestroy($img);
    return $scaled;
}

/** Build this switch photo from the template chassis and installed part pictures. The template file is not written. */
function ba_parts_compose_device_image(PDO $db, int $deviceId): string
{
    ba_ensure_parts_schema($db);
    if (!function_exists('imagecreatetruecolor')) {
        throw new RuntimeException('PHP GD is required to build the device image.');
    }
    $device = ba_parts_row($db, 'SELECT * FROM devices WHERE id=?', [$deviceId]);
    if (!$device) {
        throw new RuntimeException('Device not found');
    }
    ba_parts_require_switch($device);
    $template = ba_parts_switch_template($db, $device);
    $rel = ba_tpl_public_picture($template['front_picture'] ?? '');
    if ($rel === '') {
        throw new RuntimeException('Add a front picture on the switch template first.');
    }
    $templateFile = ba_parts_asset_file($rel);
    $templateStamp = filemtime($templateFile) . ':' . filesize($templateFile);
    $canvas = ba_parts_image_fit(ba_parts_image_load($templateFile), 1600);
    imagealphablending($canvas, true);
    $dw = imagesx($canvas);
    $dh = imagesy($canvas);
    $byKey = [];
    foreach (ba_device_part_rows($db, $deviceId) as $part) {
        $byKey[(string)$part['slot_key']] = $part;
    }
    foreach (ba_parts_layout_of($template) as $slot) {
        $part = $byKey[$slot['key']] ?? null;
        if (!$part) {
            continue;
        }
        $pic = ba_part_public_picture($part['tpl_picture'] ?? '');
        if ($pic === '') {
            continue;
        }
        $x = (int)round($dw * ((float)$slot['x'] / 100));
        $y = (int)round($dh * ((float)$slot['y'] / 100));
        $w = (int)round($dw * ((float)$slot['w'] / 100));
        $h = (int)round($dh * ((float)$slot['h'] / 100));
        if ($x < 0) {
            $x = 0;
        }
        if ($y < 0) {
            $y = 0;
        }
        if ($x >= $dw || $y >= $dh) {
            continue;
        }
        if ($x + $w > $dw) {
            $w = $dw - $x;
        }
        if ($y + $h > $dh) {
            $h = $dh - $y;
        }
        if ($w < 1 || $h < 1) {
            continue;
        }
        $partImg = ba_parts_image_load(ba_parts_asset_file($pic));
        $sw = imagesx($partImg);
        $sh = imagesy($partImg);
        if ($sw < 1 || $sh < 1) {
            imagedestroy($partImg);
            continue;
        }
        $scale = max($w / $sw, $h / $sh);
        $cropW = max(1, (int)round($w / $scale));
        $cropH = max(1, (int)round($h / $scale));
        if ($cropW > $sw) {
            $cropW = $sw;
        }
        if ($cropH > $sh) {
            $cropH = $sh;
        }
        $sx = (int)max(0, ($sw - $cropW) / 2);
        $sy = (int)max(0, ($sh - $cropH) / 2);
        imagecopyresampled($canvas, $partImg, $x, $y, $sx, $sy, $w, $h, $cropW, $cropH);
        imagedestroy($partImg);
    }
    $dir = BA_ROOT . '\\public\\assets\\dev\\' . $deviceId;
    if (!is_dir($dir) && !mkdir($dir, 0770, true) && !is_dir($dir)) {
        imagedestroy($canvas);
        throw new RuntimeException('Could not create the device image folder.');
    }
    $dest = $dir . '\\front.png';
    $tmp = $dir . '\\front.tmp.png';
    $wrote = imagepng($canvas, $tmp, 6);
    imagedestroy($canvas);
    if (!$wrote || !is_file($tmp)) {
        @unlink($tmp);
        throw new RuntimeException('Could not write the device image.');
    }
    if (is_file($dest)) {
        @unlink($dest);
    }
    if (!rename($tmp, $dest)) {
        @unlink($tmp);
        throw new RuntimeException('Could not replace the device image.');
    }
    $after = filemtime($templateFile) . ':' . filesize($templateFile);
    if ($after !== $templateStamp) {
        @unlink($dest);
        throw new RuntimeException('The template picture was not left as it was.');
    }
    $public = '/assets/dev/' . $deviceId . '/front.png';
    ba_pp_exec($db, 'UPDATE devices SET device_picture=? WHERE id=?', [$public, $deviceId]);
    ba_audit($db, 'device_image', 'device', (string)$deviceId);
    return $public;
}

function ba_part_picture_dir(int $id): string
{
    return BA_ROOT . '\\public\\assets\\tpl\\parts\\' . $id;
}

function ba_part_clear_files(int $id): void
{
    $dir = ba_part_picture_dir($id);
    foreach (glob($dir . '\\part.*') ?: [] as $old) {
        if (is_file($old)) {
            @unlink($old);
        }
    }
}

function ba_part_save_picture(int $id, array $file): ?string
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if (($file['error'] ?? 0) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Upload failed');
    }
    $ext = strtolower(pathinfo((string)($file['name'] ?? ''), PATHINFO_EXTENSION));
    if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true)) {
        throw new RuntimeException('Pictures must be JPG, PNG, or WebP');
    }
    if (($file['size'] ?? 0) > 4 * 1024 * 1024) {
        throw new RuntimeException('Picture is too large. The limit is 4 MB.');
    }
    $dir = ba_part_picture_dir($id);
    if (!is_dir($dir) && !mkdir($dir, 0770, true) && !is_dir($dir)) {
        throw new RuntimeException('Cannot store picture');
    }
    ba_part_clear_files($id);
    $name = 'part.' . ($ext === 'jpeg' ? 'jpg' : $ext);
    if (!move_uploaded_file((string)$file['tmp_name'], $dir . '\\' . $name)) {
        throw new RuntimeException('Could not save picture');
    }
    return '/assets/tpl/parts/' . $id . '/' . $name;
}

function ba_part_catalog(PDO $db): array
{
    ba_ensure_parts_schema($db);
    return ba_parts_rows($db, 'SELECT * FROM part_templates ORDER BY part_kind, vendor, name, id');
}

function ba_part_template(PDO $db, int $id): ?array
{
    if ($id < 1) {
        return null;
    }
    ba_ensure_parts_schema($db);
    return ba_parts_row($db, 'SELECT * FROM part_templates WHERE id=?', [$id]);
}

function ba_parts_spec_labels(): array
{
    return [
        'weight_kg' => 'Weight',
        'port_count' => 'Port count',
        'port_type' => 'Port type',
        'port_speed' => 'Port speed',
        'mgmt_port_count' => 'Management ports',
        'input_count' => 'Inputs',
        'plug_type' => 'Plug type',
        'input_voltage' => 'Input voltage',
        'watts' => 'Watts',
    ];
}

function ba_parts_blank_spec(): array
{
    return [
        'weight_kg' => null,
        'port_count' => null,
        'port_type' => null,
        'port_speed' => null,
        'mgmt_port_count' => null,
        'input_count' => null,
        'plug_type' => null,
        'input_voltage' => null,
        'watts' => null,
    ];
}

function ba_parts_spec_from_row(array $row): array
{
    $spec = ba_parts_blank_spec();
    foreach ($spec as $key => $_) {
        $v = $row[$key] ?? null;
        if ($v === null || $v === '') {
            $spec[$key] = null;
            continue;
        }
        if (in_array($key, ['weight_kg', 'watts'], true)) {
            $spec[$key] = (float)$v;
        } elseif (in_array($key, ['port_count', 'mgmt_port_count', 'input_count'], true)) {
            $spec[$key] = (int)$v;
        } else {
            $spec[$key] = (string)$v;
        }
    }
    return $spec;
}

function ba_parts_spec_from_post(array $post): array
{
    $plug = ba_part_plug_canon((string)($post['plug_type'] ?? ''));
    return [
        'weight_kg' => ba_parts_float($post['weight_kg'] ?? '', 0, 500, 'Weight'),
        'port_count' => ba_parts_int($post['port_count'] ?? '', 0, 512, 'Port count'),
        'port_type' => ba_parts_nullable((string)($post['port_type'] ?? ''), 64),
        'port_speed' => ba_parts_nullable((string)($post['port_speed'] ?? ''), 64),
        'mgmt_port_count' => ba_parts_int($post['mgmt_port_count'] ?? '', 0, 16, 'Management ports'),
        'input_count' => ba_parts_int($post['input_count'] ?? '', 0, 8, 'Inputs'),
        'plug_type' => $plug !== '' ? $plug : null,
        'input_voltage' => ba_parts_nullable((string)($post['input_voltage'] ?? ''), 32),
        'watts' => ba_parts_float($post['watts'] ?? '', 0, 100000, 'Watts'),
    ];
}

function ba_parts_pct(mixed $v): float
{
    if (!is_numeric($v)) {
        return 0.0;
    }
    $n = round((float)$v, 1);
    if ($n < 0) {
        return 0.0;
    }
    if ($n > 100) {
        return 100.0;
    }
    return $n;
}

function ba_parts_layout_clean(string $raw): array
{
    if (strlen($raw) > 20000) {
        throw new RuntimeException('The bay drawing is too large.');
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        throw new RuntimeException('The bay drawing could not be read.');
    }
    $out = [];
    $seen = [];
    foreach ($data as $row) {
        if (!is_array($row) || count($out) >= 40) {
            break;
        }
        $key = strtolower((string)($row['key'] ?? ''));
        $key = preg_replace('/[^a-z0-9_-]/', '', $key) ?? '';
        if ($key === '' || strlen($key) > 32 || isset($seen[$key]) || preg_match('/^[a-z]/', $key) !== 1) {
            continue;
        }
        $kind = ba_part_kind_allowed((string)($row['kind'] ?? ''));
        if ($kind === '') {
            continue;
        }
        $label = ba_parts_clip((string)($row['label'] ?? ''), 40);
        if ($label === '') {
            $label = ba_part_kind_label($kind);
        }
        $x = ba_parts_pct($row['x'] ?? 0);
        $y = ba_parts_pct($row['y'] ?? 0);
        $w = ba_parts_pct($row['w'] ?? 20);
        $h = ba_parts_pct($row['h'] ?? 12);
        if ($w < 4) {
            $w = 4.0;
        }
        if ($h < 4) {
            $h = 4.0;
        }
        if ($x + $w > 100) {
            $x = max(0, round(100 - $w, 1));
        }
        if ($x + $w > 100) {
            $w = round(100 - $x, 1);
        }
        if ($y + $h > 100) {
            $y = max(0, round(100 - $h, 1));
        }
        if ($y + $h > 100) {
            $h = round(100 - $y, 1);
        }
        $seen[$key] = true;
        $out[] = [
            'key' => $key,
            'kind' => $kind,
            'label' => $label,
            'x' => $x,
            'y' => $y,
            'w' => $w,
            'h' => $h,
        ];
    }
    return $out;
}

function ba_parts_box_style(array $slot): string
{
    return 'left:' . ba_parts_num($slot['x']) . '%;top:' . ba_parts_num($slot['y'])
        . '%;width:' . ba_parts_num($slot['w']) . '%;height:' . ba_parts_num($slot['h']) . '%';
}

function ba_parts_json(array $data): string
{
    return (string)json_encode($data, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
}

function ba_parts_save_template_switch(PDO $db, int $templateId, string $kind, array $post): void
{
    ba_ensure_parts_schema($db);
    if (array_key_exists('port_type', $post) || array_key_exists('port_speed', $post)) {
        ba_pp_exec(
            $db,
            'UPDATE device_templates SET port_type=?, port_speed=? WHERE id=?',
            [
                ba_parts_nullable((string)($post['port_type'] ?? ''), 64),
                ba_parts_nullable((string)($post['port_speed'] ?? ''), 64),
                $templateId,
            ]
        );
    }
    if ($kind !== 'switch' || !isset($post['layout_json'])) {
        return;
    }
    $layout = ba_parts_layout_clean((string)$post['layout_json']);
    $json = ba_parts_json($layout);
    $own = !$db->inTransaction();
    if ($own) {
        $db->beginTransaction();
    }
    try {
        ba_pp_exec($db, 'UPDATE device_templates SET layout_json=? WHERE id=?', [$json, $templateId]);
        if (!empty($post['kit_posted'])) {
            ba_pp_exec($db, 'DELETE FROM template_parts WHERE template_id=?', [$templateId]);
            $posted = is_array($post['kit_part'] ?? null) ? $post['kit_part'] : [];
            $order = 0;
            foreach ($layout as $slot) {
                $pid = (int)($posted[$slot['key']] ?? 0);
                if ($pid < 1) {
                    continue;
                }
                $part = ba_part_template($db, $pid);
                if (!$part || (string)$part['part_kind'] !== $slot['kind']) {
                    continue;
                }
                ba_pp_exec(
                    $db,
                    'INSERT INTO template_parts (template_id, part_template_id, slot_key, sort_order) VALUES (?,?,?,?)',
                    [$templateId, $pid, $slot['key'], $order]
                );
                $order++;
            }
        }
        if ($own) {
            $db->commit();
        }
    } catch (Throwable $e) {
        if ($own && $db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }
}

function ba_parts_save_component(PDO $db, array $post, array $files): int
{
    ba_ensure_parts_schema($db);
    $id = (int)($post['id'] ?? 0);
    $name = ba_parts_clip((string)($post['name'] ?? ''), 128);
    if ($name === '') {
        throw new RuntimeException('Name is required');
    }
    $kind = ba_part_kind_allowed((string)($post['part_kind'] ?? ''));
    if ($kind === '') {
        throw new RuntimeException('Choose a component type');
    }
    $spec = ba_parts_spec_from_post($post);
    $vendor = ba_parts_nullable((string)($post['vendor'] ?? ''), 128);
    $model = ba_parts_nullable((string)($post['model'] ?? ''), 128);
    $notes = ba_parts_nullable((string)($post['notes'] ?? ''), 4000);
    $vals = [
        $name,
        $vendor,
        $model,
        $kind,
        $spec['weight_kg'],
        $spec['port_count'],
        $spec['port_type'],
        $spec['port_speed'],
        $spec['mgmt_port_count'],
        $spec['input_count'],
        $spec['plug_type'],
        $spec['input_voltage'],
        $spec['watts'],
        $notes,
    ];
    if ($id > 0) {
        if (!ba_part_template($db, $id)) {
            throw new RuntimeException('Component not found');
        }
        $vals[] = $id;
        ba_pp_exec(
            $db,
            "UPDATE part_templates SET name=?, vendor=?, model=?, part_kind=?, weight_kg=?, port_count=?, port_type=?, port_speed=?, mgmt_port_count=?, input_count=?, plug_type=?, input_voltage=?, watts=?, notes=?, updated_at=datetime('now') WHERE id=?",
            $vals
        );
    } else {
        ba_pp_exec(
            $db,
            'INSERT INTO part_templates (name, vendor, model, part_kind, weight_kg, port_count, port_type, port_speed, mgmt_port_count, input_count, plug_type, input_voltage, watts, notes) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
            $vals
        );
        $id = ba_last_id($db);
        if ($id < 1) {
            throw new RuntimeException('Could not save the component');
        }
    }
    if (!empty($post['clear_picture'])) {
        ba_part_clear_files($id);
        ba_pp_exec($db, 'UPDATE part_templates SET picture=NULL WHERE id=?', [$id]);
    }
    if (!empty($files['picture']) && is_array($files['picture'])) {
        $rel = ba_part_save_picture($id, $files['picture']);
        if ($rel) {
            ba_pp_exec($db, 'UPDATE part_templates SET picture=? WHERE id=?', [$rel, $id]);
        }
    }
    ba_audit($db, 'save_part_template', 'part_template', (string)$id);
    return $id;
}

function ba_parts_set_component_active(PDO $db, int $id, bool $active): void
{
    ba_ensure_parts_schema($db);
    ba_pp_exec($db, "UPDATE part_templates SET is_active=?, updated_at=datetime('now') WHERE id=?", [$active ? 1 : 0, $id]);
    ba_audit($db, $active ? 'activate_part_template' : 'deactivate_part_template', 'part_template', (string)$id);
}

function ba_device_part_rows(PDO $db, int $deviceId): array
{
    ba_ensure_parts_schema($db);
    return ba_parts_rows(
        $db,
        'SELECT p.*, t.name AS tpl_name, t.vendor AS tpl_vendor, t.model AS tpl_model, t.picture AS tpl_picture, t.weight_kg AS tpl_weight
         FROM device_parts p
         LEFT JOIN part_templates t ON t.id = p.part_template_id
         WHERE p.device_id=?
         ORDER BY p.id',
        [$deviceId]
    );
}

function ba_part_weight(array $part): ?float
{
    if (!array_key_exists('weight_kg', $part) || $part['weight_kg'] === null || $part['weight_kg'] === '') {
        return null;
    }
    return (float)$part['weight_kg'];
}

function ba_parts_weight_report(?array $template, array $parts): array
{
    $shell = null;
    if ($template && array_key_exists('weight_kg', $template) && $template['weight_kg'] !== null && $template['weight_kg'] !== '') {
        $shell = (float)$template['weight_kg'];
    }
    $partSum = 0.0;
    $missing = 0;
    foreach ($parts as $part) {
        $w = ba_part_weight($part);
        if ($w === null) {
            $missing++;
        } else {
            $partSum += $w;
        }
    }
    return [
        'shell' => $shell,
        'parts' => $partSum,
        'total' => ($shell ?? 0.0) + $partSum,
        'missing' => $missing,
    ];
}

function ba_parts_switch_template(PDO $db, array $device): ?array
{
    $tid = (int)ba_col($device, 'template_id', 0);
    if ($tid < 1) {
        return null;
    }
    ba_ensure_parts_schema($db);
    return ba_parts_row(
        $db,
        'SELECT id, manufacturer, model, weight_kg, layout_json, port_count, port_type, port_speed, front_picture FROM device_templates WHERE id=?',
        [$tid]
    );
}

function ba_parts_kit(PDO $db, int $templateId): array
{
    if ($templateId < 1) {
        return [];
    }
    ba_ensure_parts_schema($db);
    return ba_parts_rows(
        $db,
        'SELECT slot_key, part_template_id, sort_order FROM template_parts WHERE template_id=? ORDER BY sort_order, id',
        [$templateId]
    );
}

function ba_parts_layout_of(?array $template): array
{
    if (!$template) {
        return [];
    }
    $raw = (string)($template['layout_json'] ?? '');
    if ($raw === '') {
        return [];
    }
    try {
        return ba_parts_layout_clean($raw);
    } catch (Throwable $e) {
        return [];
    }
}

function ba_parts_device_act(string $act): bool
{
    return in_array($act, ['save_part', 'delete_part', 'install_kit', 'save_chassis', 'refresh_image'], true);
}

function ba_parts_require_switch(array $device): void
{
    $kind = strtolower(trim((string)ba_col($device, 'kind', '')));
    if ($kind !== 'switch') {
        throw new RuntimeException('Components are saved on a switch.');
    }
}

function ba_parts_find_slot(array $layout, string $key): ?array
{
    foreach ($layout as $slot) {
        if ($slot['key'] === $key) {
            return $slot;
        }
    }
    return null;
}

function ba_parts_slot_key(string $raw): string
{
    $key = strtolower(trim($raw));
    $key = preg_replace('/[^a-z0-9_-]/', '', $key) ?? '';
    if ($key === '' || strlen($key) > 32 || preg_match('/^[a-z]/', $key) !== 1) {
        throw new RuntimeException('That bay is not on this switch template.');
    }
    return $key;
}

function ba_parts_save_device_part(PDO $db, int $deviceId, array $device, array $post): string
{
    ba_parts_require_switch($device);
    $key = ba_parts_slot_key((string)($post['slot_key'] ?? ''));
    $template = ba_parts_switch_template($db, $device);
    $layout = ba_parts_layout_of($template);
    $existing = ba_parts_row($db, 'SELECT * FROM device_parts WHERE device_id=? AND slot_key=?', [$deviceId, $key]);
    $slot = ba_parts_find_slot($layout, $key);
    if (!$slot) {
        if (!$existing) {
            throw new RuntimeException('That bay is not on this switch template.');
        }
        $slot = [
            'key' => $key,
            'kind' => ba_part_kind_allowed((string)$existing['part_kind']) ?: 'line_card',
            'label' => $key,
        ];
    }
    $postedId = (int)($post['part_id'] ?? 0);
    if ($existing && $postedId > 0 && $postedId !== (int)$existing['id']) {
        throw new RuntimeException('That component does not belong to this switch.');
    }
    $tplId = (int)($post['part_template_id'] ?? 0);
    $partTpl = $tplId > 0 ? ba_part_template($db, $tplId) : null;
    if ($tplId > 0 && (!$partTpl || (string)$partTpl['part_kind'] !== $slot['kind'])) {
        throw new RuntimeException('That component template does not match this bay.');
    }
    $postedSpec = ba_parts_spec_from_post($post);
    if ($existing) {
        $spec = $postedSpec;
    } else {
        $spec = $partTpl ? ba_parts_spec_from_row($partTpl) : ba_parts_blank_spec();
        foreach ($postedSpec as $col => $value) {
            if ($value !== null) {
                $spec[$col] = $value;
            }
        }
    }
    $serial = ba_parts_nullable((string)($post['serial'] ?? ''), 128);
    $bought = ba_parts_nullable((string)($post['purchased_from'] ?? ''), 128);
    $install = ba_parts_date((string)($post['install_date'] ?? ''));
    $wVendor = ba_parts_nullable((string)($post['warranty_vendor'] ?? ''), 128);
    $wExpires = ba_parts_date((string)($post['warranty_expires'] ?? ''));
    $feed = ba_parts_nullable((string)($post['feed_label'] ?? ''), 200);
    $link = ba_parts_nullable((string)($post['link_label'] ?? ''), 200);
    $stack = ba_parts_nullable((string)($post['stack_label'] ?? ''), 200);
    $notes = ba_parts_nullable((string)($post['notes'] ?? ''), 4000);
    $vals = [
        $partTpl ? (int)$partTpl['id'] : null,
        $slot['kind'],
        $spec['weight_kg'],
        $spec['port_count'],
        $spec['port_type'],
        $spec['port_speed'],
        $spec['mgmt_port_count'],
        $spec['input_count'],
        $spec['plug_type'],
        $spec['input_voltage'],
        $spec['watts'],
        $serial,
        $bought,
        $install,
        $wVendor,
        $wExpires,
        $feed,
        $link,
        $stack,
        $notes,
    ];
    if ($existing) {
        $vals[] = (int)$existing['id'];
        $vals[] = $deviceId;
        ba_pp_exec(
            $db,
            "UPDATE device_parts SET part_template_id=?, part_kind=?, weight_kg=?, port_count=?, port_type=?, port_speed=?, mgmt_port_count=?, input_count=?, plug_type=?, input_voltage=?, watts=?, serial=?, purchased_from=?, install_date=?, warranty_vendor=?, warranty_expires=?, feed_label=?, link_label=?, stack_label=?, notes=?, updated_at=datetime('now') WHERE id=? AND device_id=?",
            $vals
        );
    } else {
        array_unshift($vals, $deviceId, $key);
        ba_pp_exec(
            $db,
            'INSERT INTO device_parts (device_id, slot_key, part_template_id, part_kind, weight_kg, port_count, port_type, port_speed, mgmt_port_count, input_count, plug_type, input_voltage, watts, serial, purchased_from, install_date, warranty_vendor, warranty_expires, feed_label, link_label, stack_label, notes) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
            $vals
        );
    }
    ba_audit($db, 'save_part', 'device', (string)$deviceId, $key);
    return $key;
}

function ba_parts_delete_device_part(PDO $db, int $deviceId, array $device, array $post): void
{
    ba_parts_require_switch($device);
    $pid = (int)($post['part_id'] ?? 0);
    $row = ba_parts_row($db, 'SELECT id, slot_key FROM device_parts WHERE id=? AND device_id=?', [$pid, $deviceId]);
    if (!$row) {
        throw new RuntimeException('That component is not on this switch.');
    }
    ba_pp_exec($db, 'DELETE FROM device_parts WHERE id=? AND device_id=?', [$pid, $deviceId]);
    ba_audit($db, 'delete_part', 'device', (string)$deviceId, (string)$row['slot_key']);
}

function ba_parts_install_kit(PDO $db, int $deviceId, array $device): int
{
    ba_parts_require_switch($device);
    $template = ba_parts_switch_template($db, $device);
    if (!$template) {
        throw new RuntimeException('Assign a switch template first.');
    }
    $kit = ba_parts_kit($db, (int)$template['id']);
    $have = [];
    foreach (ba_device_part_rows($db, $deviceId) as $part) {
        $have[(string)$part['slot_key']] = true;
    }
    $added = 0;
    $own = !$db->inTransaction();
    if ($own) {
        $db->beginTransaction();
    }
    try {
        foreach ($kit as $row) {
            $key = (string)$row['slot_key'];
            if ($key === '' || isset($have[$key])) {
                continue;
            }
            $partTpl = ba_part_template($db, (int)$row['part_template_id']);
            if (!$partTpl) {
                continue;
            }
            $spec = ba_parts_spec_from_row($partTpl);
            ba_pp_exec(
                $db,
                'INSERT INTO device_parts (device_id, slot_key, part_template_id, part_kind, weight_kg, port_count, port_type, port_speed, mgmt_port_count, input_count, plug_type, input_voltage, watts) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)',
                [
                    $deviceId,
                    $key,
                    (int)$partTpl['id'],
                    (string)$partTpl['part_kind'],
                    $spec['weight_kg'],
                    $spec['port_count'],
                    $spec['port_type'],
                    $spec['port_speed'],
                    $spec['mgmt_port_count'],
                    $spec['input_count'],
                    $spec['plug_type'],
                    $spec['input_voltage'],
                    $spec['watts'],
                ]
            );
            $have[$key] = true;
            $added++;
        }
        if ($own) {
            $db->commit();
        }
    } catch (Throwable $e) {
        if ($own && $db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }
    ba_audit($db, 'install_kit', 'device', (string)$deviceId, (string)$added);
    return $added;
}

function ba_parts_save_chassis(PDO $db, int $deviceId, array $device, array $post): void
{
    ba_parts_require_switch($device);
    ba_ensure_parts_schema($db);
    ba_pp_exec(
        $db,
        "UPDATE devices SET serial=?, purchased_from=?, warranty_vendor=?, warranty_expires=?, install_date=?, updated_at=datetime('now') WHERE id=?",
        [
            ba_parts_nullable((string)($post['serial'] ?? ''), 128),
            ba_parts_nullable((string)($post['purchased_from'] ?? ''), 128),
            ba_parts_nullable((string)($post['warranty_vendor'] ?? ''), 128),
            ba_parts_date((string)($post['warranty_expires'] ?? '')),
            ba_parts_date((string)($post['install_date'] ?? '')),
            $deviceId,
        ]
    );
    ba_audit($db, 'save_chassis', 'device', (string)$deviceId);
}

function ba_parts_handle_device(PDO $db, int $deviceId, array $device): void
{
    $act = (string)($_POST['act'] ?? '');
    try {
        if ($act === 'save_part') {
            $key = ba_parts_save_device_part($db, $deviceId, $device, $_POST);
            header('Location: ' . ba_href('/device?id=' . $deviceId . '&notice=part&bay=' . rawurlencode($key) . ba_parts_image_query($db, $deviceId)));
            return;
        }
        if ($act === 'delete_part') {
            ba_parts_delete_device_part($db, $deviceId, $device, $_POST);
            header('Location: ' . ba_href('/device?id=' . $deviceId . '&notice=removed' . ba_parts_image_query($db, $deviceId)));
            return;
        }
        if ($act === 'install_kit') {
            $n = ba_parts_install_kit($db, $deviceId, $device);
            $image = $n > 0 ? ba_parts_image_query($db, $deviceId) : '';
            header('Location: ' . ba_href('/device?id=' . $deviceId . '&notice=kit&parts=' . $n . $image));
            return;
        }
        if ($act === 'save_chassis') {
            ba_parts_save_chassis($db, $deviceId, $device, $_POST);
            header('Location: ' . ba_href('/device?id=' . $deviceId . '&notice=chassis'));
            return;
        }
        if ($act === 'refresh_image') {
            ba_parts_compose_device_image($db, $deviceId);
            header('Location: ' . ba_href('/device?id=' . $deviceId . '&notice=image'));
            return;
        }
    } catch (RuntimeException $e) {
        $bay = ba_parts_clip((string)($_POST['slot_key'] ?? ''), 32);
        $extra = $bay !== '' ? '&bay=' . rawurlencode($bay) : '';
        header('Location: ' . ba_href('/device?id=' . $deviceId . '&err=' . rawurlencode($e->getMessage()) . $extra));
        return;
    } catch (Throwable $e) {
        header('Location: ' . ba_href('/device?id=' . $deviceId . '&err=' . rawurlencode('Could not save that component.')));
        return;
    }
    header('Location: ' . ba_href('/device?id=' . $deviceId));
}

function ba_parts_image_query(PDO $db, int $deviceId): string
{
    try {
        ba_parts_compose_device_image($db, $deviceId);
        return '';
    } catch (RuntimeException $e) {
        return '&image=' . rawurlencode($e->getMessage());
    }
}

function ba_parts_datalists(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    echo '<datalist id="ba-port-types">';
    foreach (['RJ45', 'SFP', 'SFP+', 'SFP28', 'QSFP', 'QSFP28', 'GBIC'] as $item) {
        echo '<option value="' . h($item) . '"></option>';
    }
    echo '</datalist><datalist id="ba-port-speeds">';
    foreach (['100M', '1G', '2.5G', '5G', '10G', '25G', '40G', '100G', '1/10G'] as $item) {
        echo '<option value="' . h($item) . '"></option>';
    }
    echo '</datalist>';
}

function ba_parts_field(string $label, string $html): void
{
    echo '<label>' . h($label) . '</label>' . $html;
}

function ba_parts_text_input(string $name, string $value, string $extra = ''): string
{
    return '<input name="' . h($name) . '" value="' . h($value) . '"' . $extra . '>';
}

function ba_part_template_options(array $catalog, string $kind, int $selected): string
{
    $html = '<option value="">(none)</option>';
    foreach ($catalog as $row) {
        if ((string)($row['part_kind'] ?? '') !== $kind) {
            continue;
        }
        $id = (int)$row['id'];
        if (!(int)($row['is_active'] ?? 0) && $id !== $selected) {
            continue;
        }
        $bits = [];
        $vendor = trim((string)($row['vendor'] ?? ''));
        $name = trim((string)($row['name'] ?? ''));
        $model = trim((string)($row['model'] ?? ''));
        if ($vendor !== '') {
            $bits[] = $vendor;
        }
        $bits[] = $name !== '' ? $name : $model;
        if ($model !== '' && $model !== $name) {
            $bits[] = $model;
        }
        $weight = ba_parts_num($row['weight_kg'] ?? null);
        $label = trim(implode(' ', $bits));
        if ($weight !== '') {
            $label .= ' · ' . $weight . ' kg';
        }
        $sel = $id === $selected ? ' selected' : '';
        $html .= '<option value="' . $id . '"' . $sel . '>' . h($label) . '</option>';
    }
    return $html;
}

function ba_parts_catalog_json(array $catalog): string
{
    $out = [];
    foreach ($catalog as $row) {
        $spec = ba_parts_spec_from_row($row);
        $out[] = array_merge([
            'id' => (int)$row['id'],
            'kind' => (string)$row['part_kind'],
            'name' => (string)($row['name'] ?? ''),
            'vendor' => (string)($row['vendor'] ?? ''),
            'model' => (string)($row['model'] ?? ''),
            'active' => (int)($row['is_active'] ?? 0),
        ], $spec);
    }
    return ba_parts_json($out);
}

function ba_parts_show_power(string $kind): bool
{
    return $kind === 'power_supply';
}

function ba_parts_show_ports(string $kind): bool
{
    return $kind !== 'power_supply';
}

function ba_parts_show_mgmt(string $kind): bool
{
    return $kind === 'supervisor';
}

function ba_parts_link_name(string $kind): string
{
    if ($kind === 'power_supply') {
        return 'feed_label';
    }
    if ($kind === 'stack_port') {
        return 'stack_label';
    }
    return 'link_label';
}

function ba_parts_link_label(string $kind): string
{
    if ($kind === 'power_supply') {
        return 'Plug connection';
    }
    if ($kind === 'stack_port') {
        return 'Stack connection';
    }
    return 'Link connection';
}

function ba_parts_link_holder(string $kind): string
{
    if ($kind === 'power_supply') {
        return 'UPS or PDU port';
    }
    if ($kind === 'stack_port') {
        return 'Stack member and port';
    }
    return 'What this connects to';
}

function ba_render_part_spec_fields(string $kind, array $values): void
{
    $val = static function (string $key) use ($values): string {
        $raw = $values[$key] ?? '';
        if ($raw === null) {
            return '';
        }
        if (in_array($key, ['weight_kg', 'watts'], true)) {
            return ba_parts_num($raw);
        }
        return (string)$raw;
    };
    ba_parts_field('Weight (kg)', ba_parts_text_input('weight_kg', $val('weight_kg'), ' type="number" step="0.01" min="0" max="500"'));
    if (ba_parts_show_power($kind)) {
        ba_parts_field('Number of inputs', ba_parts_text_input('input_count', $val('input_count'), ' type="number" min="0" max="8"'));
        ba_parts_field('Input plug type', ba_part_plug_select('plug_type', $val('plug_type')));
        ba_parts_field('Input voltage', ba_parts_text_input('input_voltage', $val('input_voltage'), ' placeholder="208 VAC" maxlength="32"'));
        ba_parts_field('Watts', ba_parts_text_input('watts', $val('watts'), ' type="number" step="0.1" min="0" max="100000"'));
    }
    if (ba_parts_show_ports($kind)) {
        ba_parts_field('Number of ports', ba_parts_text_input('port_count', $val('port_count'), ' type="number" min="0" max="512"'));
        ba_parts_field('Port type', ba_parts_text_input('port_type', $val('port_type'), ' list="ba-port-types" maxlength="64" placeholder="RJ45, SFP+"'));
        if ($kind !== 'stack_port') {
            ba_parts_field('Port speed', ba_parts_text_input('port_speed', $val('port_speed'), ' list="ba-port-speeds" maxlength="64" placeholder="1G, 10G"'));
        }
    }
    if (ba_parts_show_mgmt($kind)) {
        ba_parts_field('Management ports', ba_parts_text_input('mgmt_port_count', $val('mgmt_port_count'), ' type="number" min="0" max="16"'));
    }
}

function ba_render_part_modal(string $key, string $title, string $kind, ?array $part, array $catalog, bool $canEdit, bool $open, bool $orphan): void
{
    ba_users_modal_open('modal-bay-' . $key, $title, $open);
    if ($orphan) {
        echo '<p class="muted">This bay was removed from the template. The saved component is still on the switch.</p>';
    }
    $values = $part ?: [];
    $picture = ba_part_public_picture($part['tpl_picture'] ?? '');
    if ($picture !== '') {
        echo '<p><img class="idf-tpl-preview" src="' . h($picture) . '" alt=""></p>';
    }
    if (!$canEdit) {
        $rows = [
            ['Serial number', (string)($values['serial'] ?? '')],
            ['Purchased from', (string)($values['purchased_from'] ?? '')],
            ['Install date', (string)($values['install_date'] ?? '')],
            ['Warranty vendor', (string)($values['warranty_vendor'] ?? '')],
            ['Warranty expiration', (string)($values['warranty_expires'] ?? '')],
            ['Weight (kg)', ba_parts_num($values['weight_kg'] ?? null)],
        ];
        if (ba_parts_show_power($kind)) {
            $rows[] = ['Inputs', (string)($values['input_count'] ?? '')];
            $rows[] = ['Plug type', (string)($values['plug_type'] ?? '')];
            $rows[] = ['Input voltage', (string)($values['input_voltage'] ?? '')];
            $rows[] = ['Watts', ba_parts_num($values['watts'] ?? null)];
            $rows[] = ['Plug connection', (string)($values['feed_label'] ?? '')];
        }
        if (ba_parts_show_ports($kind)) {
            $rows[] = ['Ports', (string)($values['port_count'] ?? '')];
            $rows[] = ['Port type', (string)($values['port_type'] ?? '')];
            if ($kind !== 'stack_port') {
                $rows[] = ['Port speed', (string)($values['port_speed'] ?? '')];
            }
        }
        if (ba_parts_show_mgmt($kind)) {
            $rows[] = ['Management ports', (string)($values['mgmt_port_count'] ?? '')];
        }
        if (!ba_parts_show_power($kind)) {
            $rows[] = [ba_parts_link_label($kind), (string)($values[ba_parts_link_name($kind)] ?? '')];
        }
        $rows[] = ['Notes', (string)($values['notes'] ?? '')];
        echo '<dl class="dev-facts chassis-read">';
        foreach ($rows as $row) {
            $text = trim($row[1]);
            if ($text === '') {
                $text = '—';
            }
            echo '<div><dt>' . h($row[0]) . '</dt><dd>' . h($text) . '</dd></div>';
        }
        echo '</dl>';
        ba_users_modal_close();
        return;
    }
    $selected = (int)($values['part_template_id'] ?? 0);
    echo '<form method="post" class="stack">';
    echo '<input type="hidden" name="slot_key" value="' . h($key) . '">';
    echo '<input type="hidden" name="part_id" value="' . (int)($values['id'] ?? 0) . '">';
    ba_parts_field('Component template', '<select name="part_template_id" data-part-fill="1">' . ba_part_template_options($catalog, $kind, $selected) . '</select>');
    if (!$part) {
        echo '<p class="muted">An empty box on a new part keeps the component template value.</p>';
    }
    ba_parts_field('Serial number', ba_parts_text_input('serial', (string)($values['serial'] ?? ''), ' maxlength="128"'));
    ba_render_part_spec_fields($kind, $values);
    $linkName = ba_parts_link_name($kind);
    ba_parts_field(ba_parts_link_label($kind), ba_parts_text_input($linkName, (string)($values[$linkName] ?? ''), ' maxlength="200" placeholder="' . h(ba_parts_link_holder($kind)) . '"'));
    ba_parts_field('Purchased from', ba_parts_text_input('purchased_from', (string)($values['purchased_from'] ?? ''), ' maxlength="128"'));
    ba_parts_field('Install date', '<input type="date" name="install_date" value="' . h((string)($values['install_date'] ?? '')) . '">');
    ba_parts_field('Warranty vendor', ba_parts_text_input('warranty_vendor', (string)($values['warranty_vendor'] ?? ''), ' maxlength="128"'));
    ba_parts_field('Warranty expiration', '<input type="date" name="warranty_expires" value="' . h((string)($values['warranty_expires'] ?? '')) . '">');
    ba_parts_field('Notes', '<textarea name="notes" maxlength="4000">' . h((string)($values['notes'] ?? '')) . '</textarea>');
    echo '<p class="muted">Saved values stay until you edit them. A later SNMP poll can fill these fields.</p>';
    echo '<button name="act" value="save_part">Save component</button>';
    echo '</form>';
    if ($part) {
        echo '<form method="post" class="inline chassis-remove">';
        echo '<input type="hidden" name="act" value="delete_part">';
        echo '<input type="hidden" name="part_id" value="' . (int)$part['id'] . '">';
        echo '<button class="btn-quiet">Remove component</button></form>';
    }
    ba_users_modal_close();
}

function ba_render_bay_button(array $slot, ?array $part, bool $absolute): void
{
    $key = (string)$slot['key'];
    $kind = (string)$slot['kind'];
    $label = (string)$slot['label'];
    $name = '';
    if ($part) {
        $name = trim((string)($part['tpl_name'] ?? ''));
        if ($name === '') {
            $name = trim((string)($part['serial'] ?? ''));
        }
        if ($name === '') {
            $name = 'Installed';
        }
    }
    $cls = 'chassis-slot kind-' . $kind . ($part ? ' is-filled' : ' is-empty');
    echo '<button type="button" class="' . h($cls) . '"';
    if ($absolute) {
        echo ' style="' . ba_parts_box_style($slot) . '"';
    }
    echo ' data-open-modal="modal-bay-' . h($key) . '" aria-label="' . h($label) . '">';
    $picture = ba_part_public_picture($part['tpl_picture'] ?? '');
    if ($picture !== '') {
        echo '<img src="' . h($picture) . '" alt="">';
    }
    echo '<span class="chassis-cap"><b>' . h($label) . '</b><small>' . h($part ? $name : 'Empty') . '</small></span>';
    echo '</button>';
}

function ba_render_switch_chassis(PDO $db, array $user, array $device, bool $canEdit): void
{
    ba_ensure_parts_schema($db);
    $id = (int)ba_col($device, 'id', 0);
    $template = ba_parts_switch_template($db, $device);
    $layout = ba_parts_layout_of($template);
    $parts = ba_device_part_rows($db, $id);
    $report = ba_parts_weight_report($template, $parts);
    $catalog = ba_part_catalog($db);
    $byKey = [];
    foreach ($parts as $part) {
        $byKey[(string)$part['slot_key']] = $part;
    }
    $kitCount = 0;
    if ($template) {
        $kitCount = count(ba_parts_kit($db, (int)$template['id']));
    }
    $front = ba_tpl_public_picture($template['front_picture'] ?? '');
    echo '<section class="ucard chassis-card">';
    echo '<div class="ucard-head"><h3>Chassis</h3>';
    if ($canEdit && ($front !== '' || $kitCount > 0)) {
        echo '<div class="head-actions">';
        if ($front !== '') {
            echo '<form method="post" class="inline"><button name="act" value="refresh_image">Update Device Image</button></form>';
        }
        if ($kitCount > 0) {
            echo '<form method="post" class="inline"><button name="act" value="install_kit">Install template parts</button></form>';
        }
        echo '</div>';
    }
    echo '</div><div class="ucard-body">';
    echo '<p class="chassis-note">Click a bay to open that component. Saving a component builds this switch image from the empty chassis and the part pictures. The template picture stays as it is. Install template parts fills empty bays and leaves serials already saved.</p>';
    echo '<dl class="dev-facts">';
    echo '<div><dt>Rack height</dt><dd>' . (int)ba_col($device, 'u_height', 1) . ' U</dd></div>';
    if ($report['shell'] === null) {
        echo '<div><dt>Shell weight</dt><dd>Not set</dd></div>';
    } else {
        echo '<div><dt>Shell weight</dt><dd>' . h(ba_parts_num($report['shell'])) . ' kg</dd></div>';
    }
    echo '<div><dt>Part weight</dt><dd>' . h(ba_parts_num($report['parts'])) . ' kg</dd></div>';
    echo '<div><dt>Chassis weight</dt><dd>' . h(ba_parts_num($report['total'])) . ' kg</dd></div>';
    $face = [];
    $portCount = $template['port_count'] ?? null;
    if ($portCount !== null && $portCount !== '') {
        $face[] = (int)$portCount . ' ports';
    }
    $portType = trim((string)($template['port_type'] ?? ''));
    $portSpeed = trim((string)($template['port_speed'] ?? ''));
    if ($portType !== '') {
        $face[] = $portType;
    }
    if ($portSpeed !== '') {
        $face[] = $portSpeed;
    }
    echo '<div><dt>Faceplate</dt><dd>' . h($face !== [] ? implode(' · ', $face) : 'Set on the template') . '</dd></div>';
    echo '</dl>';
    if ($report['shell'] === null) {
        echo '<p class="muted">Shell weight is not set on the template, so the total counts it as 0. Enter 0 on the template when the empty chassis weighs nothing.</p>';
    }
    if ($report['missing'] > 0) {
        $noun = $report['missing'] === 1 ? 'part has' : 'parts have';
        echo '<p class="muted">' . (int)$report['missing'] . ' ' . $noun . ' no weight, so those parts are left out of the total.</p>';
    }
    if (!$template) {
        echo '<p class="muted">Assign a switch template to draw the chassis.</p>';
    } elseif ($layout === []) {
        echo '<p class="muted">This switch template has no bays yet. Open the template, add a front picture, and draw the bays.</p>';
    } elseif ($front === '') {
        echo '<p class="muted">This template has bays but no front picture, so they are listed instead of drawn on the chassis.</p>';
        echo '<div class="chassis-list">';
        foreach ($layout as $slot) {
            ba_render_bay_button($slot, $byKey[$slot['key']] ?? null, false);
        }
        echo '</div>';
    } else {
        echo '<div class="chassis-stage"><img class="chassis-photo" src="' . h($front) . '" alt="" draggable="false"><div class="chassis-slots">';
        foreach ($layout as $slot) {
            ba_render_bay_button($slot, $byKey[$slot['key']] ?? null, true);
        }
        echo '</div></div>';
    }
    $openBay = (string)($_GET['bay'] ?? '');
    $notice = (string)($_GET['notice'] ?? '');
    $hasErr = (string)($_GET['err'] ?? '') !== '';
    $used = [];
    foreach ($layout as $slot) {
        $used[$slot['key']] = true;
        $part = $byKey[$slot['key']] ?? null;
        $open = $openBay === $slot['key'] && ($notice === 'part' || $hasErr);
        ba_render_part_modal($slot['key'], (string)$slot['label'], (string)$slot['kind'], $part, $catalog, $canEdit, $open, false);
    }
    $orphans = [];
    foreach ($parts as $part) {
        $key = (string)$part['slot_key'];
        if (!isset($used[$key])) {
            $orphans[] = $part;
        }
    }
    if ($orphans !== []) {
        echo '<h4 class="chassis-extra">Parts not on the drawing</h4><div class="chassis-list">';
        foreach ($orphans as $part) {
            $key = (string)$part['slot_key'];
            $kind = ba_part_kind_allowed((string)$part['part_kind']) ?: 'line_card';
            $slot = ['key' => $key, 'kind' => $kind, 'label' => $key];
            ba_render_bay_button($slot, $part, false);
        }
        echo '</div>';
        foreach ($orphans as $part) {
            $key = (string)$part['slot_key'];
            $kind = ba_part_kind_allowed((string)$part['part_kind']) ?: 'line_card';
            $open = $openBay === $key && ($notice === 'part' || $hasErr);
            ba_render_part_modal($key, $key, $kind, $part, $catalog, $canEdit, $open, true);
        }
    }
    echo '<h4 class="chassis-extra">Chassis record</h4>';
    if ($canEdit) {
        echo '<form method="post" class="stack chassis-form">';
        echo '<div class="chassis-field">';
        ba_parts_field('Serial number', ba_parts_text_input('serial', (string)ba_col($device, 'serial', ''), ' maxlength="128"'));
        echo '</div><div class="chassis-field">';
        ba_parts_field('Purchased from', ba_parts_text_input('purchased_from', (string)ba_col($device, 'purchased_from', ''), ' maxlength="128"'));
        echo '</div><div class="chassis-field">';
        ba_parts_field('Warranty vendor', ba_parts_text_input('warranty_vendor', (string)ba_col($device, 'warranty_vendor', ''), ' maxlength="128"'));
        echo '</div><div class="chassis-field">';
        ba_parts_field('Install date', '<input type="date" name="install_date" value="' . h((string)ba_col($device, 'install_date', '')) . '">');
        echo '</div><div class="chassis-field">';
        ba_parts_field('Warranty expiration', '<input type="date" name="warranty_expires" value="' . h((string)ba_col($device, 'warranty_expires', '')) . '">');
        echo '</div>';
        echo '<button name="act" value="save_chassis">Save chassis record</button></form>';
    } else {
        echo '<dl class="dev-facts">';
        foreach ([
            'Serial number' => (string)ba_col($device, 'serial', ''),
            'Purchased from' => (string)ba_col($device, 'purchased_from', ''),
            'Warranty vendor' => (string)ba_col($device, 'warranty_vendor', ''),
            'Install date' => (string)ba_col($device, 'install_date', ''),
            'Warranty expiration' => (string)ba_col($device, 'warranty_expires', ''),
        ] as $label => $value) {
            $text = trim($value);
            echo '<div><dt>' . h($label) . '</dt><dd>' . h($text !== '' ? $text : '—') . '</dd></div>';
        }
        echo '</dl>';
    }
    echo '</div></section>';
    ba_parts_datalists();
    echo '<script type="application/json" id="part-catalog">' . ba_parts_catalog_json($catalog) . '</script>';
    echo <<<'JS'
<script>
(function () {
  var raw = document.getElementById("part-catalog");
  if (!raw) return;
  var rows = [];
  try { rows = JSON.parse(raw.textContent || "[]"); } catch (e) { rows = []; }
  var byId = {};
  rows.forEach(function (row) { byId[String(row.id)] = row; });
  var names = ["weight_kg", "port_count", "port_type", "port_speed", "mgmt_port_count", "input_count", "plug_type", "input_voltage", "watts"];
  document.querySelectorAll("form.chassis-remove").forEach(function (form) {
    form.addEventListener("submit", function (e) {
      if (!window.confirm("Remove this component from the switch?")) e.preventDefault();
    });
  });
  document.querySelectorAll("select[data-part-fill]").forEach(function (sel) {
    sel.addEventListener("change", function () {
      var row = byId[sel.value];
      if (!row || !sel.form) return;
      names.forEach(function (name) {
        var el = sel.form.elements[name];
        if (!el) return;
        var value = row[name];
        el.value = (value === null || value === undefined) ? "" : value;
      });
    });
  });
})();
</script>
JS;
}

function ba_render_part_catalog(PDO $db, bool $admin): void
{
    ba_ensure_parts_schema($db);
    $rows = ba_part_catalog($db);
    $action = $admin ? '<a class="btn" href="/templates.php?action=part">New component</a>' : '';
    ba_card_open('Components', $action);
    echo '<p class="muted">Reusable power supplies, cards, network modules, and stacking ports. A switch template picks one for each bay. Adding a switch can then install those parts without typing them again.</p>';
    echo '<table><thead><tr><th>Type</th><th>Name</th><th>Vendor</th><th>Model</th><th>Weight</th><th></th></tr></thead><tbody>';
    foreach ($rows as $row) {
        $dim = !(int)$row['is_active'] ? ' class="muted"' : '';
        echo '<tr' . $dim . '><td>' . h(ba_part_kind_label((string)$row['part_kind'])) . '</td>';
        echo '<td><a href="/templates.php?part=' . (int)$row['id'] . '">' . h((string)$row['name']) . '</a></td>';
        echo '<td>' . h((string)($row['vendor'] ?? '')) . '</td><td>' . h((string)($row['model'] ?? '')) . '</td>';
        $weight = ba_parts_num($row['weight_kg'] ?? null);
        echo '<td>' . h($weight !== '' ? $weight . ' kg' : '—') . '</td><td>';
        if ($admin) {
            echo '<form method="post" class="inline"><input type="hidden" name="id" value="' . (int)$row['id'] . '">';
            if ((int)$row['is_active']) {
                echo '<button class="btn-quiet" name="act" value="deactivate_part">deactivate</button>';
            } else {
                echo '<button class="btn-quiet" name="act" value="activate_part">activate</button>';
            }
            echo '</form>';
        }
        echo '</td></tr>';
    }
    if (!$rows) {
        echo '<tr><td colspan="6" class="muted">No components yet.</td></tr>';
    }
    echo '</tbody></table>';
    ba_card_close();
}

function ba_parts_page_component(PDO $db, array $user, bool $admin, string $msg): void
{
    ba_ensure_parts_schema($db);
    $id = (int)($_GET['part'] ?? $_POST['id'] ?? 0);
    $row = $id > 0 ? ba_part_template($db, $id) : null;
    if ($id > 0 && !$row) {
        http_response_code(404);
        echo 'Component not found';
        return;
    }
    $posted = ($msg !== '' && $_SERVER['REQUEST_METHOD'] === 'POST') ? $_POST : [];
    $val = static function (string $key) use ($row, $posted): string {
        if (array_key_exists($key, $posted)) {
            return (string)$posted[$key];
        }
        if (!$row || !array_key_exists($key, $row) || $row[$key] === null) {
            return '';
        }
        if (in_array($key, ['weight_kg', 'watts'], true)) {
            return ba_parts_num($row[$key]);
        }
        return (string)$row[$key];
    };
    $kind = ba_part_kind_allowed($val('part_kind'));
    if ($kind === '' && $row) {
        $kind = (string)$row['part_kind'];
    }
    if ($kind === '') {
        $kind = 'power_supply';
    }
    ba_layout_start($row ? ('Component: ' . (string)$row['name']) : 'New component', 'templates');
    if ($msg !== '') {
        echo '<div class="flash">' . h($msg) . '</div>';
    }
    echo '<p class="muted"><a href="/templates.php">All templates</a></p>';
    echo '<h1>' . h($row ? (string)$row['name'] : 'New component') . '</h1>';
    echo '<p class="muted">A component template is the model of one part. Serial numbers, warranty, and plug or link mapping are entered on each switch.</p>';
    if ($admin) {
        echo '<form method="post" enctype="multipart/form-data" class="ucard stack">';
        echo '<div class="ucard-head"><h3>Component</h3></div><div class="ucard-body">';
        echo '<input type="hidden" name="act" value="save_part_tpl">';
        if ($row) {
            echo '<input type="hidden" name="id" value="' . (int)$row['id'] . '">';
        }
        ba_parts_field('Name', ba_parts_text_input('name', $val('name'), ' required maxlength="128"'));
        ba_parts_field('Vendor', ba_parts_text_input('vendor', $val('vendor'), ' maxlength="128" placeholder="Cisco"'));
        ba_parts_field('Model', ba_parts_text_input('model', $val('model'), ' maxlength="128"'));
        echo '<label>Type</label><select name="part_kind" id="part-kind">';
        foreach (ba_part_kinds() as $key => $label) {
            $sel = $kind === $key ? ' selected' : '';
            echo '<option value="' . h($key) . '"' . $sel . '>' . h($label) . '</option>';
        }
        echo '</select>';
        echo '<div id="part-power"' . (ba_parts_show_power($kind) ? '' : ' hidden') . '>';
        ba_parts_field('Number of inputs', ba_parts_text_input('input_count', $val('input_count'), ' type="number" min="0" max="8"'));
        ba_parts_field('Input plug type', ba_part_plug_select('plug_type', $val('plug_type')));
        ba_parts_field('Input voltage', ba_parts_text_input('input_voltage', $val('input_voltage'), ' maxlength="32" placeholder="208 VAC"'));
        ba_parts_field('Watts', ba_parts_text_input('watts', $val('watts'), ' type="number" step="0.1" min="0"'));
        echo '</div><div id="part-ports"' . (ba_parts_show_ports($kind) ? '' : ' hidden') . '>';
        ba_parts_field('Number of ports', ba_parts_text_input('port_count', $val('port_count'), ' type="number" min="0" max="512"'));
        ba_parts_field('Port type', ba_parts_text_input('port_type', $val('port_type'), ' list="ba-port-types" maxlength="64"'));
        echo '<div id="part-speed"' . ($kind === 'stack_port' ? ' hidden' : '') . '>';
        ba_parts_field('Port speed', ba_parts_text_input('port_speed', $val('port_speed'), ' list="ba-port-speeds" maxlength="64"'));
        echo '</div></div><div id="part-mgmt"' . (ba_parts_show_mgmt($kind) ? '' : ' hidden') . '>';
        ba_parts_field('Management ports', ba_parts_text_input('mgmt_port_count', $val('mgmt_port_count'), ' type="number" min="0" max="16"'));
        echo '</div>';
        ba_parts_field('Weight (kg)', ba_parts_text_input('weight_kg', $val('weight_kg'), ' type="number" step="0.01" min="0" max="500"'));
        echo '<p class="muted">Weight is required for the chassis total. 0 is a real weight.</p>';
        ba_parts_field('Notes', '<textarea name="notes" maxlength="4000">' . h($val('notes')) . '</textarea>');
        echo '<p class="muted">Use notes for detail that does not have its own box yet. A later SNMP poll can fill the saved fields.</p>';
        echo '<label>Picture</label><input type="file" name="picture" accept="image/jpeg,image/png,image/webp">';
        $picture = ba_part_public_picture($row['picture'] ?? '');
        if ($picture !== '') {
            echo '<p><img class="idf-tpl-preview" src="' . h($picture) . '" alt=""> <label><input type="checkbox" name="clear_picture"> clear</label></p>';
        }
        echo '<button>Save component</button></div></form>';
        ba_parts_datalists();
        echo <<<'JS'
<script>
(function () {
  var kind = document.getElementById("part-kind");
  var power = document.getElementById("part-power");
  var ports = document.getElementById("part-ports");
  var speed = document.getElementById("part-speed");
  var mgmt = document.getElementById("part-mgmt");
  function toggle() {
    var value = kind ? kind.value : "";
    if (power) power.hidden = value !== "power_supply";
    if (ports) ports.hidden = value === "power_supply";
    if (speed) speed.hidden = value === "stack_port" || value === "power_supply";
    if (mgmt) mgmt.hidden = value !== "supervisor";
  }
  if (kind) kind.addEventListener("change", toggle);
  toggle();
})();
</script>
JS;
    } elseif ($row) {
        echo '<section class="ucard"><div class="ucard-head"><h3>Component</h3></div><div class="ucard-body">';
        echo '<p>' . h(ba_part_kind_label((string)$row['part_kind'])) . '</p>';
        echo '<p>Weight: <strong>' . h(ba_parts_num($row['weight_kg'] ?? null) !== '' ? ba_parts_num($row['weight_kg']) . ' kg' : '—') . '</strong></p>';
        echo '</div></section>';
    }
    ba_layout_end();
}

function ba_render_switch_editor(PDO $db, ?array $tpl): void
{
    ba_ensure_parts_schema($db);
    $kind = (string)($tpl['kind'] ?? 'other');
    $catalog = ba_part_catalog($db);
    $layout = [];
    $kit = [];
    $front = '';
    if ($tpl) {
        $full = ba_parts_row($db, 'SELECT layout_json, front_picture, port_type, port_speed, port_count FROM device_templates WHERE id=?', [(int)$tpl['id']]);
        if ($full) {
            $tpl = array_merge($tpl, $full);
            $front = ba_tpl_public_picture($full['front_picture'] ?? '');
            try {
                $layout = ba_parts_layout_of($full);
            } catch (Throwable $e) {
                $layout = [];
            }
        }
        foreach (ba_parts_kit($db, (int)$tpl['id']) as $row) {
            $kit[(string)$row['slot_key']] = (int)$row['part_template_id'];
        }
    }
    echo '<div id="switch-parts"' . ($kind === 'switch' ? '' : ' hidden') . '>';
    echo '<h3>Switch bays</h3>';
    echo '<p class="muted">Weight above is the empty chassis. Each component adds its own weight. A saved 0 is a real weight. Port count above is the fixed faceplate.</p>';
    ba_parts_field('Faceplate port type', ba_parts_text_input('port_type', (string)($tpl['port_type'] ?? ''), ' list="ba-port-types" maxlength="64" placeholder="RJ45"'));
    ba_parts_field('Faceplate port speed', ba_parts_text_input('port_speed', (string)($tpl['port_speed'] ?? ''), ' list="ba-port-speeds" maxlength="64" placeholder="1G"'));
    if (!$tpl || (int)($tpl['id'] ?? 0) < 1) {
        echo '<p class="muted">Save the template and add a front picture. Then draw a box for each bay.</p></div>';
        ba_parts_datalists();
        echo <<<'JS'
<script>
(function () {
  var kind = document.querySelector("select[name=kind]");
  var box = document.getElementById("switch-parts");
  function toggle() { if (box) box.hidden = !kind || kind.value !== "switch"; }
  if (kind) kind.addEventListener("change", toggle);
  toggle();
})();
</script>
JS;
        return;
    }
    if ($front === '') {
        echo '<p class="muted">Add a front picture of the empty chassis, save, and then draw the bays on that photo.</p></div>';
        ba_parts_datalists();
        echo <<<'JS'
<script>
(function () {
  var kind = document.querySelector("select[name=kind]");
  var box = document.getElementById("switch-parts");
  function toggle() { if (box) box.hidden = !kind || kind.value !== "switch"; }
  if (kind) kind.addEventListener("change", toggle);
  toggle();
})();
</script>
JS;
        return;
    }
    $json = ba_parts_json($layout);
    echo '<p class="muted">The front picture is the empty chassis. Add a box for each bay, drag it, and pull the edges until it covers that bay. A card that fills two bays is one box. Component pictures draw inside the boxes on the device page.</p>';
    echo '<input type="hidden" name="layout_json" id="layout-json" value="' . h($json) . '">';
    echo '<input type="hidden" name="kit_posted" value="1">';
    echo '<div id="slot-protos" hidden>';
    echo '<div class="slot-box proto kind-line_card" data-key="" data-kind="line_card" data-label="" data-x="10" data-y="10" data-w="28" data-h="16">';
    echo '<span class="slot-label"></span>';
    foreach (['nw', 'n', 'ne', 'e', 'se', 's', 'sw', 'w'] as $edge) {
        echo '<b data-edge="' . $edge . '"></b>';
    }
    echo '</div><div class="slot-row" data-key="">';
    echo '<label>Bay name</label><input data-role="label" maxlength="40" disabled>';
    echo '<label>Type</label><select data-role="kind" disabled>';
    foreach (ba_part_kinds() as $key => $label) {
        echo '<option value="' . h($key) . '">' . h($label) . '</option>';
    }
    echo '</select><label>Template part for this bay</label><select data-role="kit" disabled></select>';
    echo '</div></div>';
    echo '<div class="slot-editor"><div class="slot-stage" id="slot-stage"><img src="' . h($front) . '" alt="" draggable="false"><div class="slot-layer" id="slot-layer">';
    foreach ($layout as $slot) {
        echo '<div class="slot-box kind-' . h($slot['kind']) . '" data-key="' . h($slot['key']) . '" data-kind="' . h($slot['kind']) . '" data-label="' . h($slot['label']) . '"';
        echo ' data-x="' . h(ba_parts_num($slot['x'])) . '" data-y="' . h(ba_parts_num($slot['y'])) . '" data-w="' . h(ba_parts_num($slot['w'])) . '" data-h="' . h(ba_parts_num($slot['h'])) . '"';
        echo ' style="' . ba_parts_box_style($slot) . '"><span class="slot-label">' . h($slot['label']) . '</span>';
        foreach (['nw', 'n', 'ne', 'e', 'se', 's', 'sw', 'w'] as $edge) {
            echo '<b data-edge="' . $edge . '"></b>';
        }
        echo '</div>';
    }
    echo '</div></div><div class="slot-side"><div id="slot-list">';
    foreach ($layout as $slot) {
        $selected = (int)($kit[$slot['key']] ?? 0);
        echo '<div class="slot-row" data-key="' . h($slot['key']) . '">';
        echo '<label>Bay name</label><input data-role="label" maxlength="40" value="' . h($slot['label']) . '">';
        echo '<label>Type</label><select data-role="kind">';
        foreach (ba_part_kinds() as $key => $label) {
            $sel = $slot['kind'] === $key ? ' selected' : '';
            echo '<option value="' . h($key) . '"' . $sel . '>' . h($label) . '</option>';
        }
        echo '</select><label>Template part for this bay</label>';
        echo '<select data-role="kit" name="kit_part[' . h($slot['key']) . ']">' . ba_part_template_options($catalog, $slot['kind'], $selected) . '</select>';
        echo '</div>';
    }
    echo '</div>';
    echo '<p class="slot-actions"><button type="button" id="slot-add">Add bay</button> <button type="button" id="slot-del" class="btn-quiet">Remove bay</button></p>';
    echo '<p class="muted">Drag a box to move it. Drag an edge to resize it. The bay key stays put so installed serials remain attached.</p>';
    echo '</div></div></div>';
    ba_parts_datalists();
    echo '<script type="application/json" id="part-catalog">' . ba_parts_catalog_json($catalog) . '</script>';
    echo <<<'JS'
<script>
(function () {
  var kindSel = document.querySelector("select[name=kind]");
  var wrap = document.getElementById("switch-parts");
  function showWrap() { if (wrap) wrap.hidden = !kindSel || kindSel.value !== "switch"; }
  if (kindSel) kindSel.addEventListener("change", showWrap);
  showWrap();
  var stage = document.getElementById("slot-stage");
  var list = document.getElementById("slot-list");
  var dataEl = document.getElementById("layout-json");
  var protos = document.getElementById("slot-protos");
  if (!stage || !list || !dataEl || !protos) return;
  var raw = document.getElementById("part-catalog");
  var catalog = [];
  try { catalog = JSON.parse(raw ? raw.textContent || "[]" : "[]"); } catch (e) { catalog = []; }
  var selected = "";
  var drag = null;
  function num(n) {
    n = Math.round(n * 10) / 10;
    if (n < 0) n = 0;
    if (n > 100) n = 100;
    return n;
  }
  function boxFor(key) {
    return stage.querySelector('.slot-box[data-key="' + key + '"]');
  }
  function rowFor(key) {
    return list.querySelector('.slot-row[data-key="' + key + '"]');
  }
  function applyBox(box) {
    box.style.left = box.getAttribute("data-x") + "%";
    box.style.top = box.getAttribute("data-y") + "%";
    box.style.width = box.getAttribute("data-w") + "%";
    box.style.height = box.getAttribute("data-h") + "%";
  }
  function serialize() {
    var slots = [];
    stage.querySelectorAll(".slot-box").forEach(function (box) {
      if (box.classList.contains("proto")) return;
      slots.push({
        key: box.getAttribute("data-key"),
        kind: box.getAttribute("data-kind"),
        label: box.getAttribute("data-label") || "",
        x: parseFloat(box.getAttribute("data-x") || "0"),
        y: parseFloat(box.getAttribute("data-y") || "0"),
        w: parseFloat(box.getAttribute("data-w") || "4"),
        h: parseFloat(box.getAttribute("data-h") || "4")
      });
    });
    dataEl.value = JSON.stringify(slots);
  }
  function selectSlot(key) {
    selected = key || "";
    stage.querySelectorAll(".slot-box").forEach(function (box) {
      box.classList.toggle("is-on", box.getAttribute("data-key") === selected);
    });
    list.querySelectorAll(".slot-row").forEach(function (row) {
      row.classList.toggle("is-on", row.getAttribute("data-key") === selected);
    });
  }
  function fillKit(sel, kind, current) {
    var keep = sel.value || String(current || "");
    sel.textContent = "";
    var blank = document.createElement("option");
    blank.value = "";
    blank.textContent = "(none)";
    sel.appendChild(blank);
    catalog.forEach(function (row) {
      if (row.kind !== kind) return;
      if (!row.active && String(row.id) !== keep) return;
      var opt = document.createElement("option");
      opt.value = String(row.id);
      var label = ((row.vendor ? row.vendor + " " : "") + (row.name || row.model || "Component")).trim();
      if (row.weight_kg !== null && row.weight_kg !== undefined && row.weight_kg !== "") {
        label += " · " + row.weight_kg + " kg";
      }
      opt.textContent = label;
      if (String(row.id) === keep) opt.selected = true;
      sel.appendChild(opt);
    });
  }
  function nextKey(kind) {
    var prefix = { power_supply: "psu", supervisor: "sup", line_card: "lc", network_module: "nm", stack_port: "stack", base_ports: "ports" }[kind] || "bay";
    var n = 1;
    var key = prefix + n;
    while (boxFor(key)) {
      n += 1;
      key = prefix + n;
    }
    return key;
  }
  list.addEventListener("input", function (e) {
    var row = e.target.closest(".slot-row");
    if (!row || e.target.getAttribute("data-role") !== "label") return;
    var box = boxFor(row.getAttribute("data-key"));
    if (!box) return;
    box.setAttribute("data-label", e.target.value);
    var cap = box.querySelector(".slot-label");
    if (cap) cap.textContent = e.target.value || row.getAttribute("data-key");
    serialize();
  });
  list.addEventListener("change", function (e) {
    if (e.target.getAttribute("data-role") !== "kind") return;
    var row = e.target.closest(".slot-row");
    if (!row) return;
    var box = boxFor(row.getAttribute("data-key"));
    if (!box) return;
    var kind = e.target.value;
    box.setAttribute("data-kind", kind);
    box.className = "slot-box kind-" + kind + (box.classList.contains("is-on") ? " is-on" : "");
    var kit = row.querySelector("[data-role=kit]");
    if (kit) fillKit(kit, kind, "");
    serialize();
  });
  list.addEventListener("click", function (e) {
    var row = e.target.closest(".slot-row");
    if (row) selectSlot(row.getAttribute("data-key"));
  });
  stage.addEventListener("pointerdown", function (e) {
    var box = e.target.closest(".slot-box");
    if (!box || !stage.contains(box) || box.classList.contains("proto")) return;
    var rect = stage.getBoundingClientRect();
    if (rect.width < 20 || rect.height < 20) return;
    selectSlot(box.getAttribute("data-key"));
    drag = {
      box: box,
      edge: e.target.getAttribute("data-edge") || "",
      x: e.clientX,
      y: e.clientY,
      ox: parseFloat(box.getAttribute("data-x") || "0"),
      oy: parseFloat(box.getAttribute("data-y") || "0"),
      ow: parseFloat(box.getAttribute("data-w") || "4"),
      oh: parseFloat(box.getAttribute("data-h") || "4"),
      w: rect.width,
      h: rect.height
    };
    e.preventDefault();
  });
  window.addEventListener("pointermove", function (e) {
    if (!drag) return;
    var dx = (e.clientX - drag.x) / drag.w * 100;
    var dy = (e.clientY - drag.y) / drag.h * 100;
    var x1 = drag.ox;
    var y1 = drag.oy;
    var x2 = drag.ox + drag.ow;
    var y2 = drag.oy + drag.oh;
    if (!drag.edge) {
      x1 = num(drag.ox + dx);
      y1 = num(drag.oy + dy);
      if (x1 + drag.ow > 100) x1 = num(100 - drag.ow);
      if (y1 + drag.oh > 100) y1 = num(100 - drag.oh);
      x2 = x1 + drag.ow;
      y2 = y1 + drag.oh;
    } else {
      if (drag.edge.indexOf("w") >= 0) x1 = num(drag.ox + dx);
      if (drag.edge.indexOf("e") >= 0) x2 = num(drag.ox + drag.ow + dx);
      if (drag.edge.indexOf("n") >= 0) y1 = num(drag.oy + dy);
      if (drag.edge.indexOf("s") >= 0) y2 = num(drag.oy + drag.oh + dy);
      if (x2 - x1 < 4) {
        if (drag.edge.indexOf("w") >= 0) x1 = num(x2 - 4);
        else x2 = num(x1 + 4);
      }
      if (y2 - y1 < 4) {
        if (drag.edge.indexOf("n") >= 0) y1 = num(y2 - 4);
        else y2 = num(y1 + 4);
      }
    }
    drag.box.setAttribute("data-x", String(num(x1)));
    drag.box.setAttribute("data-y", String(num(y1)));
    drag.box.setAttribute("data-w", String(num(x2 - x1)));
    drag.box.setAttribute("data-h", String(num(y2 - y1)));
    applyBox(drag.box);
  });
  window.addEventListener("pointerup", function () {
    if (!drag) return;
    drag = null;
    serialize();
  });
  var addBtn = document.getElementById("slot-add");
  if (addBtn) addBtn.addEventListener("click", function () {
    if (stage.querySelectorAll(".slot-box:not(.proto)").length >= 40) return;
    var kind = "line_card";
    var key = nextKey(kind);
    var n = stage.querySelectorAll(".slot-box:not(.proto)").length;
    var boxProto = protos.querySelector(".slot-box");
    var rowProto = protos.querySelector(".slot-row");
    var box = boxProto.cloneNode(true);
    box.classList.remove("proto");
    box.setAttribute("data-key", key);
    box.setAttribute("data-kind", kind);
    box.setAttribute("data-label", "Line card " + (n + 1));
    box.setAttribute("data-x", String(8 + (n % 4) * 3));
    box.setAttribute("data-y", String(8 + (n % 4) * 3));
    box.setAttribute("data-w", "28");
    box.setAttribute("data-h", "16");
    var cap = box.querySelector(".slot-label");
    if (cap) cap.textContent = box.getAttribute("data-label");
    applyBox(box);
    var layer = document.getElementById("slot-layer");
    (layer || stage).appendChild(box);
    var row = rowProto.cloneNode(true);
    row.setAttribute("data-key", key);
    row.querySelectorAll("[disabled]").forEach(function (el) { el.disabled = false; });
    var label = row.querySelector("[data-role=label]");
    label.value = box.getAttribute("data-label");
    var kindInput = row.querySelector("[data-role=kind]");
    kindInput.value = kind;
    var kit = row.querySelector("[data-role=kit]");
    kit.name = "kit_part[" + key + "]";
    fillKit(kit, kind, "");
    list.appendChild(row);
    selectSlot(key);
    serialize();
  });
  var delBtn = document.getElementById("slot-del");
  if (delBtn) delBtn.addEventListener("click", function () {
    if (!selected) return;
    if (!window.confirm("Remove this bay from the drawing?")) return;
    var box = boxFor(selected);
    var row = rowFor(selected);
    if (box) box.remove();
    if (row) row.remove();
    selected = "";
    serialize();
  });
  var form = stage.closest("form");
  if (form) form.addEventListener("submit", serialize);
  var first = stage.querySelector(".slot-box");
  if (first) selectSlot(first.getAttribute("data-key"));
  serialize();
})();
</script>
JS;
}
