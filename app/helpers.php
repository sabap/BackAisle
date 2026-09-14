<?php
declare(strict_types=1);

function h(?string $s): string {
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
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
    if (!empty($s['PYTHON']) && is_file($s['PYTHON'])) {
        return $s['PYTHON'];
    }
    foreach ([
        'C:\\Python312\\python.exe',
        'C:\\Program Files\\Python312\\python.exe',
        'C:\\Program Files\\Python311\\python.exe',
    ] as $p) {
        if (is_file($p)) return $p;
    }
    return 'python';
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
