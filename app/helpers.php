<?php
declare(strict_types=1);

function h(?string $s): string {
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function ba_request_path(): string {
    foreach (['HTTP_X_ORIGINAL_URL', 'UNENCODED_URL', 'HTTP_URL'] as $k) {
        if (empty($_SERVER[$k])) {
            continue;
        }
        $p = parse_url((string)$_SERVER[$k], PHP_URL_PATH);
        if (is_string($p) && $p !== '' && !preg_match('/\.php$/i', $p)) {
            return rtrim($p, '/') ?: '/';
        }
    }
    $script = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
    $base = strtolower(basename($script));
    if ($base === 'writes.php') {
        $v = (string)($_GET['view'] ?? '');
        if ($v === 'template') {
            return '/writes/template';
        }
        if ($v === 'job') {
            return '/writes/job';
        }
        return '/writes';
    }
    if ($base === 'admin.php' && isset($_GET['download'])) {
        return '/admin/backup-download';
    }
    $map = [
        'index.php' => '/',
        'login.php' => '/login',
        'logout.php' => '/logout',
        'fleet.php' => '/fleet',
        'idfs.php' => '/idfs',
        'rack.php' => '/rack',
        'climate.php' => '/climate',
        'batteries.php' => '/batteries',
        'battery.php' => '/battery',
        'alerts.php' => '/alerts',
        'events.php' => '/events',
        'devices.php' => '/devices',
        'device.php' => '/device',
        'templates.php' => '/templates',
        'admin.php' => '/admin',
        'writes.php' => '/writes',
        'org.php' => '/org',
        'api_health.php' => '/api/health',
        'api_series.php' => '/api/series',
        'api_dashboard.php' => '/api/dashboard',
    ];
    if (isset($map[$base]) && $base !== 'index.php') {
        return $map[$base];
    }
    $uri = parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/';
    $uri = rtrim($uri, '/') ?: '/';
    if ($uri === '/login.php') {
        return '/login';
    }
    if ($uri === '/index.php' || $uri === '/index') {
        return '/';
    }
    $ubase = strtolower(basename($uri));
    if (isset($map[$ubase]) && $ubase !== 'index.php') {
        return $map[$ubase];
    }
    return $uri;
}

function ba_version(): string {
    $path = BA_ROOT . DIRECTORY_SEPARATOR . 'VERSION';
    if (is_file($path)) {
        $v = trim((string)file_get_contents($path));
        if ($v !== '' && preg_match('/^\d+\.\d+/', $v)) {
            return ltrim($v, 'vV');
        }
    }
    return '0.0.0';
}

function ba_python(): string {
    $s = ba_secrets();
    $candidates = [];
    if (!empty($s['PYTHON'])) {
        $candidates[] = $s['PYTHON'];
    }
    $candidates = array_merge($candidates, [
        'C:\\Program Files\\Python312\\python.exe',
        'C:\\Program Files\\Python313\\python.exe',
        'C:\\Python312\\python.exe',
        'C:\\Program Files\\Python311\\python.exe',
    ]);
    foreach ($candidates as $p) {
        if (!is_string($p) || $p === '' || !is_file($p)) {
            continue;
        }
        if (stripos($p, 'WindowsApps') !== false) {
            continue;
        }
        $sz = @filesize($p);
        if ($sz !== false && $sz >= 2048) {
            return $p;
        }
    }
    return '';
}

/** @param list<string> $args */
function ba_python_run(array $args, ?string $cwd = null): array {
    $py = ba_python();
    if ($py === '') {
        return ['code' => 127, 'stdout' => '', 'stderr' => 'python.exe not found (Microsoft Store stub is ignored). Install Python 3.12 from python.org.'];
    }
    $cmd = array_merge([$py], $args);
    $dspec = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $p = @proc_open($cmd, $dspec, $pipes, $cwd ?? BA_ROOT, null, ['bypass_shell' => true]);
    if (!is_resource($p)) {
        return ['code' => 1, 'stdout' => '', 'stderr' => 'proc_open failed for ' . $py];
    }
    fclose($pipes[0]);
    $stdout = (string)stream_get_contents($pipes[1]);
    $stderr = (string)stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($p);
    return ['code' => $code, 'stdout' => $stdout, 'stderr' => $stderr];
}

function ba_status_class(?int $output, ?int $onBatt, ?string $comm, ?float $temp, ?int $sensorExpected, ?int $sensorPresent): string {
    if ($comm === 'down') {
        return 'st-down';
    }
    if ($onBatt || $output === 3) {
        return 'st-batt';
    }
    if ($output && !in_array($output, [2, 8], true)) {
        return 'st-warn';
    }
    if ($sensorExpected && !$sensorPresent) {
        return 'st-warn';
    }
    if ($temp !== null && ($temp >= 85 || $temp <= 50)) {
        return 'st-hot';
    }
    if ($comm === 'degraded') {
        return 'st-warn';
    }
    return 'st-ok';
}

function ba_output_text(?int $v): string {
    return [
        1 => 'unknown', 2 => 'online', 3 => 'on battery', 4 => 'boost', 5 => 'sleep',
        6 => 'off', 7 => 'rebooting', 8 => 'eco', 9 => 'bypass', 10 => 'buck', 11 => 'overload',
    ][$v ?? 0] ?? '—';
}

function ba_fmt($v, string $unit = '', int $dec = 1): string {
    if ($v === null || $v === '') {
        return '—';
    }
    if (is_numeric($v)) {
        $s = $dec === 0 ? (string)(int)round((float)$v) : number_format((float)$v, $dec);
        return $unit === '' ? $s : "$s $unit";
    }
    return (string)$v;
}

function ba_qs(array $extra = []): string {
    return http_build_query(array_merge($_GET, $extra));
}

function ba_ups_only_sql(): string {
    return "IFNULL(d.kind,'ups')='ups'";
}

function ba_kind_label(?string $kind): string {
    return [
        'ups' => 'UPS',
        'switch' => 'Switch',
        'patch_panel' => 'Patch panel',
        'other' => 'Other',
    ][$kind ?? 'ups'] ?? 'UPS';
}

function ba_kind_options(?string $selected = 'ups'): string {
    $html = '';
    foreach (['ups' => 'UPS', 'switch' => 'Switch', 'patch_panel' => 'Patch panel', 'other' => 'Other'] as $k => $lab) {
        $sel = ($selected === $k) ? ' selected' : '';
        $html .= '<option value="'.h($k).'"'.$sel.'>'.h($lab).'</option>';
    }
    return $html;
}

function ba_worst(array $row): string {
    return ba_status_class(
        isset($row['output_status']) ? (int)$row['output_status'] : null,
        isset($row['on_battery']) ? (int)$row['on_battery'] : null,
        $row['comm_state'] ?? null,
        isset($row['temp_f']) ? (float)$row['temp_f'] : null,
        isset($row['sensor_expected']) ? (int)$row['sensor_expected'] : null,
        isset($row['sensor_present']) ? (int)$row['sensor_present'] : null
    );
}
