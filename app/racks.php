<?php
declare(strict_types=1);

/** IDF rack elevations. Inspired by ColdAisle cabinet rows; no ColdAisle files or schema. */

function ba_rack(PDO $db, int $id): ?array {
    $st = $db->prepare('SELECT r.*, g.name AS group_name, g.parent_id FROM racks r JOIN groups g ON g.id=r.group_id WHERE r.id=?');
    $st->execute([$id]);
    $row = $st->fetch();
    return $row ?: null;
}

function ba_racks_in_group(PDO $db, int $groupId): array {
    $st = $db->prepare('SELECT * FROM racks WHERE group_id=? ORDER BY sort_order, name, id');
    $st->execute([$groupId]);
    $rows = $st->fetchAll() ?: [];
    $st->closeCursor();
    return $rows;
}

function ba_rack_devices(PDO $db, int $rackId): array {
    $st = $db->prepare(
        'SELECT d.*, p.comm_state, s.temp_f, s.on_battery, s.output_status, s.capacity_pct,
                t.front_picture AS tpl_front, t.rear_picture AS tpl_rear
         FROM devices d
         LEFT JOIN poll_state p ON p.device_id=d.id
         LEFT JOIN samples s ON s.id = (SELECT id FROM samples WHERE device_id=d.id ORDER BY ts DESC LIMIT 1)
         LEFT JOIN device_templates t ON t.id=d.template_id
         WHERE d.rack_id=?
         ORDER BY d.position_u DESC'
    );
    $st->execute([$rackId]);
    $rows = $st->fetchAll() ?: [];
    $st->closeCursor();
    $out = [];
    foreach ($rows as $row) {
        $out[] = array_change_key_case($row, CASE_LOWER);
    }
    return $out;
}

function ba_faces_overlap(string $a, string $b): bool {
    if ($a === 'both' || $b === 'both') return true;
    return $a === $b;
}

/** A UPS fills the rack depth, so the elevation draws it on the front and the rear. */
function ba_device_on_face(array $d, string $face): bool {
    $kind = strtolower(trim((string)ba_col($d, 'kind', 'ups')));
    if ($kind === '' || $kind === 'ups') {
        return true;
    }
    return ba_faces_overlap($face, (string)ba_col($d, 'face', 'both'));
}

function ba_rack_face_picture(array $d, string $face): string {
    $front = ba_tpl_public_picture(ba_col($d, 'tpl_front', ba_col($d, 'front_picture', '')));
    $rear = ba_tpl_public_picture(ba_col($d, 'tpl_rear', ba_col($d, 'rear_picture', '')));
    if ($face === 'rear') {
        return $rear !== '' ? $rear : $front;
    }
    return $front;
}

function ba_u_ranges_overlap(int $a0, int $ah, int $b0, int $bh): bool {
    return $a0 < ($b0 + $bh) && $b0 < ($a0 + $ah);
}

function ba_slot_taken(array $occupants, int $pos, int $uh, string $face, ?int $skipId = null): ?array {
    foreach ($occupants as $d) {
        if ($skipId && (int)$d['id'] === $skipId) continue;
        if ($d['position_u'] === null) continue;
        if (!ba_device_on_face($d, $face)) continue;
        if (ba_u_ranges_overlap($pos, $uh, (int)$d['position_u'], max(1, (int)($d['u_height'] ?? 1)))) {
            return $d;
        }
    }
    return null;
}

function ba_occupied_map(array $devices, string $face): array {
    $map = [];
    foreach ($devices as $d) {
        if ($d['position_u'] === null) continue;
        if (!ba_device_on_face($d, $face)) continue;
        $start = (int)$d['position_u'];
        $uh = max(1, (int)($d['u_height'] ?? 1));
        for ($u = $start; $u < $start + $uh; $u++) {
            $map[$u] = true;
        }
    }
    return $map;
}

function ba_sync_location_from_group(PDO $db, int $deviceId, int $groupId, ?string $rackName): void {
    $path = ba_group_path($db, $groupId);
    $parts = array_values(array_filter(array_map('trim', explode('/', str_replace(' / ', '/', $path))), fn($p) => $p !== '' && $p !== '—'));
    $closet = $parts ? $parts[count($parts) - 1] : '';
    $building = count($parts) >= 2 ? $parts[count($parts) - 2] : '';
    $site = $parts[0] ?? 'Hospital';
    $db->prepare('UPDATE devices SET group_id=?, site=?, building=?, idf_closet=?, rack=?, updated_at=datetime(\'now\') WHERE id=?')
        ->execute([$groupId, $site, $building, $closet, $rackName, $deviceId]);
}

function ba_rack_health(array $devices): array {
    $worst = 'unknown';
    $label = 'No UPS on this rack';
    $reasons = [];
    foreach ($devices as $d) {
        if (($d['kind'] ?? 'ups') !== 'ups') continue;
        $cls = ba_worst($d);
        $name = $d['hostname'] ?: ($d['ip'] ?: 'UPS');
        if ($cls === 'st-batt') {
            $worst = 'batt';
            $label = 'On battery';
            $reasons[] = $name.' on battery';
        } elseif ($cls === 'st-down' && $worst !== 'batt') {
            $worst = 'down';
            $label = 'Unreachable';
            $reasons[] = $name.' not polling';
        } elseif (in_array($cls, ['st-hot', 'st-warn'], true) && !in_array($worst, ['batt', 'down'], true)) {
            $worst = 'warn';
            $label = 'Warning';
            $reasons[] = $name.' '.$cls;
        } elseif ($cls === 'st-ok' && $worst === 'unknown') {
            $worst = 'ok';
            $label = 'Healthy';
        }
    }
    if ($worst === 'ok' && !$reasons) $reasons[] = 'Monitored UPS online';
    return ['status' => $worst, 'label' => $label, 'reasons' => $reasons];
}

function ba_leaf_groups(array $groups): array {
    $hasKids = [];
    foreach ($groups as $g) {
        if ($g['parent_id'] !== null) {
            $hasKids[(int)$g['parent_id']] = true;
        }
    }
    $leaves = [];
    foreach ($groups as $g) {
        if (empty($hasKids[(int)$g['id']])) {
            $leaves[] = $g;
        }
    }
    return $leaves;
}

function ba_render_elevation(array $rack, array $devices, string $face, bool $compact, bool $admin, int $focusU = 0): void {
    $units = max(1, (int)$rack['u_height']);
    $occ = ba_occupied_map($devices, $face);
    $rackId = (int)$rack['id'];
    $cls = 'idf-elev' . ($compact ? ' idf-elev-row' : '');
    echo '<div class="idf-face">';
    if (!$compact) {
        echo '<div class="idf-face-title">'.h(ucfirst($face)).' <small>19″ × '.$units.'U</small></div>';
    }
    echo '<div class="'.$cls.'" style="--units:'.$units.'; --rail-in:19; --rack-h-in:'.htmlspecialchars((string)($units * 1.75), ENT_QUOTES).'">';
    echo '<div class="idf-u-rail" aria-hidden="true">';
    for ($u = $units; $u >= 1; $u--) {
        $mute = ($compact && $u % 2 === 0) ? ' mute' : '';
        echo '<div class="idf-u-tick'.$mute.'">'.$u.'</div>';
    }
    echo '</div><div class="idf-bay" role="img" aria-label="'.h(ucfirst($face).' '.$rack['name']).'">';

    for ($u = 1; $u <= $units; $u++) {
        if (!empty($occ[$u])) continue;
        $bottom = (($u - 1) / $units) * 100;
        $h = (1 / $units) * 100;
        $href = ba_href('/rack?id='.$rackId.'&u='.$u.'&face='.urlencode($face).(ba_tech_mode() ? '#place' : ''));
        $hot = !$compact && $focusU === $u;
        if ($admin) {
            $num = (ba_tech_mode() && !$compact) ? '<span>'.$u.'</span>' : '';
            echo '<a class="idf-empty'.($hot ? ' is-focus' : '').'" style="bottom:'.$bottom.'%;height:'.$h.'%" href="'.h($href).'" title="Place at U'.$u.' ('.$face.')">'.$num.'</a>';
        } else {
            echo '<span class="idf-empty" style="bottom:'.$bottom.'%;height:'.$h.'%"></span>';
        }
    }

    foreach ($devices as $d) {
        if ($d['position_u'] === null) continue;
        if (!ba_device_on_face($d, $face)) continue;
        $pos = (int)$d['position_u'];
        $uh = max(1, (int)($d['u_height'] ?? 1));
        $bottom = (($pos - 1) / $units) * 100;
        $h = ($uh / $units) * 100;
        $topU = $pos + $uh - 1;
        $kind = $d['kind'] ?? 'ups';
        $health = '';
        if ($kind === 'ups') {
            $clsH = ba_worst($d);
            if ($clsH === 'st-batt') $health = ' batt';
            elseif ($clsH === 'st-down') $health = ' down';
            elseif ($clsH === 'st-hot' || $clsH === 'st-warn') $health = ' warn';
            elseif ($clsH === 'st-ok') $health = ' ok';
        } elseif ($kind === 'router') {
            $comm = strtolower(trim((string)($d['comm_state'] ?? '')));
            if ($comm === 'down') $health = ' down';
            elseif ($comm === 'degraded') $health = ' warn';
            elseif ($comm === 'ok') $health = ' ok';
        }
        $label = $d['hostname'] ?: ($d['ip'] ?: ba_kind_label($kind));
        $uTxt = $pos === $topU ? 'U'.$pos : 'U'.$pos.'–'.$topU;
        $toDevice = in_array($kind, ['ups', 'router'], true) && (int)$d['id'] > 0 && ($kind !== 'ups' || ($d['ip'] ?? '') !== '');
        $href = $toDevice
            ? ba_href('/device?id='.(int)$d['id'])
            : ba_href('/rack?id='.$rackId.'&item='.(int)$d['id']);
        echo '<a class="idf-dev kind-'.h($kind).$health.'" href="'.h($href).'" style="bottom:'.$bottom.'%;height:'.$h.'%" title="'.h($label.' · '.$uTxt.' · '.ba_kind_label($kind)).'">';
        $pic = ba_rack_face_picture($d, $face);
        if ($pic !== '') echo '<img class="idf-dev-img" src="'.h($pic).'" alt="">';
        echo '<span class="idf-dev-meta"><span class="idf-dev-name">'.h($label).'</span>';
        echo '<span class="idf-dev-u">'.$uTxt.'</span></span></a>';
    }
    echo '</div></div></div>';
}

function ba_loc_state(string $worst): string {
    return ['st-batt' => 'batt', 'st-down' => 'down', 'st-hot' => 'hot', 'st-warn' => 'warn'][$worst] ?? 'ok';
}

function ba_loc_rollup(int $id, array $groups, array $summaries, array &$memo): array {
    if (isset($memo[$id])) return $memo[$id];
    $base = $summaries[$id] ?? [
        'racks' => 0, 'ups' => 0, 'batt' => 0, 'down' => 0, 'hot' => 0,
        'worst' => 'st-ok', 'temp' => null,
    ];
    $kids = ba_group_children($groups, $id);
    $leaves = $kids ? 0 : 1;
    foreach ($kids as $c) {
        $sub = ba_loc_rollup((int)$c['id'], $groups, $summaries, $memo);
        $base['racks'] = (int)$base['racks'] + (int)$sub['racks'];
        $base['ups'] = (int)$base['ups'] + (int)$sub['ups'];
        $base['batt'] = (int)($base['batt'] ?? 0) + (int)($sub['batt'] ?? 0);
        $base['down'] = (int)($base['down'] ?? 0) + (int)($sub['down'] ?? 0);
        $base['hot'] = (int)($base['hot'] ?? 0) + (int)($sub['hot'] ?? 0);
        $leaves += (int)($sub['leaves'] ?? 0);
        if (ba_cls_rank((string)$sub['worst']) > ba_cls_rank((string)$base['worst'])) {
            $base['worst'] = $sub['worst'];
        }
    }
    $base['leaves'] = $leaves;
    $memo[$id] = $base;
    return $base;
}

function ba_render_loc_tree(array $groups, ?int $parent, array $summaries, array &$memo, int $depth = 0): void {
    foreach (ba_group_children($groups, $parent) as $g) {
        $id = (int)$g['id'];
        $kids = ba_group_children($groups, $id);
        $sum = ba_loc_rollup($id, $groups, $summaries, $memo);
        $st = ba_loc_state((string)$sum['worst']);
        $stLab = $st === 'batt' ? 'battery' : $st;
        $needle = strtolower($g['name']);
        $meta = (int)$sum['racks'].' rack'.(((int)$sum['racks']===1)?'':'s').' · '.(int)$sum['ups'].' UPS';
        if ($kids) {
            echo '<details class="loc-branch" data-loc-id="'.$id.'" data-loc-name="'.h($needle).'"'.($depth === 0 ? ' open' : '').'>';
            echo '<summary class="loc-sum">';
            echo '<span class="loc-chev" aria-hidden="true"></span>';
            echo '<span class="loc-title"><a href="'.h(ba_href('/idfs?group='.$id)).'">'.h($g['name']).'</a></span>';
            echo '<span class="loc-badge">'.(int)$sum['leaves'].' closet'.(((int)$sum['leaves']===1)?'':'s').'</span>';
            echo '<span class="loc-meta">'.$meta.'</span>';
            echo '<span class="pill '.$st.'">'.h($stLab).'</span>';
            echo '</summary><div class="loc-kids">';
            ba_render_loc_tree($groups, $id, $summaries, $memo, $depth + 1);
            echo '</div></details>';
        } else {
            $href = ba_href('/idfs?group='.$id);
            echo '<a class="loc-leaf '.h((string)$sum['worst']).'" href="'.h($href).'" data-loc-name="'.h($needle).'">';
            echo '<span class="loc-title">'.h($g['name']).'</span>';
            echo '<span class="loc-meta">'.$meta;
            if ($sum['temp'] !== null) echo ' · '.h(ba_fmt($sum['temp'], '°F'));
            echo '</span>';
            echo '<span class="pill '.$st.'">'.h($stLab).'</span>';
            echo '</a>';
        }
    }
}

/** Devices assigned to this closet, including units that are not in a rack. Child closets are not mixed in. */
function ba_idf_closet_devices(PDO $db, int $groupId): array {
    $sql = ba_latest_join()
        . ' WHERE (d.group_id=? OR d.rack_id IN (SELECT id FROM racks WHERE group_id=?))'
        . ' AND (d.decommissioned_at IS NULL OR d.decommissioned_at=?)';
    return ba_idf_fetch($db, $sql, [$groupId, $groupId, '']);
}

function ba_idf_fetch(PDO $db, string $sql, array $params): array {
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

function ba_idf_fetch_in(PDO $db, string $sqlBeforeIn, string $column, array $ids): array {
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static function (int $id): bool {
        return $id > 0;
    })));
    $out = [];
    foreach (array_chunk($ids, 400) as $chunk) {
        $ph = implode(',', array_fill(0, count($chunk), '?'));
        $rows = ba_idf_fetch($db, $sqlBeforeIn . ' ' . $column . ' IN (' . $ph . ')', $chunk);
        foreach ($rows as $row) {
            $out[] = $row;
        }
    }
    return $out;
}

function ba_idf_closet_context(PDO $db, array $devices): array {
    $ctx = [
        'alerts' => [],
        'thresholds' => [],
        'metrics' => [],
        'fields' => [],
        'profiles' => [],
        'error' => '',
    ];
    $ids = [];
    foreach ($devices as $d) {
        $id = (int)ba_col($d, 'id', 0);
        if ($id > 0) {
            $ids[] = $id;
        }
    }
    try {
        if (function_exists('ba_ensure_model_schema')) {
            ba_ensure_model_schema($db);
        }
    } catch (Throwable $e) {
        $ctx['error'] = 'model';
    }
    try {
        $ctx['profiles'] = ba_idf_fetch($db, 'SELECT * FROM model_profiles WHERE is_active=1 ORDER BY id', []);
    } catch (Throwable $e) {
        $ctx['profiles'] = [];
    }
    try {
        $fieldRows = ba_idf_fetch($db, 'SELECT * FROM model_profile_fields ORDER BY sort_order, id', []);
        foreach ($fieldRows as $f) {
            $pid = (int)ba_col($f, 'profile_id', 0);
            $key = trim((string)ba_col($f, 'field_key', ''));
            if ($pid < 1 || $key === '') {
                continue;
            }
            if (trim((string)ba_col($f, 'builtin_key', '')) !== '') {
                continue;
            }
            $show = ba_col($f, 'show_on_device', 1);
            if ($show === null || $show === '') {
                $show = 1;
            }
            if ((int)$show === 0) {
                continue;
            }
            $ctx['fields'][$pid][$key] = $f;
        }
    } catch (Throwable $e) {
        $ctx['fields'] = [];
    }
    try {
        $thrRows = ba_idf_fetch($db, 'SELECT * FROM thresholds WHERE scope=? OR scope=?', ['global', 'device']);
        foreach ($thrRows as $t) {
            $scope = strtolower(trim((string)ba_col($t, 'scope', '')));
            if ($scope === 'global') {
                $ctx['thresholds'][0] = $t;
            } elseif ($scope === 'device') {
                $did = (int)ba_col($t, 'device_id', 0);
                if ($did > 0) {
                    $ctx['thresholds'][$did] = $t;
                }
            }
        }
    } catch (Throwable $e) {
        $ctx['thresholds'] = [];
    }
    if ($ids) {
        try {
            $metricRows = ba_idf_fetch_in(
                $db,
                'SELECT device_id, field_key, value_num, value_text FROM device_metrics WHERE',
                'device_id',
                $ids
            );
            foreach ($metricRows as $m) {
                $did = (int)ba_col($m, 'device_id', 0);
                $key = trim((string)ba_col($m, 'field_key', ''));
                if ($did > 0 && $key !== '') {
                    $ctx['metrics'][$did][$key] = $m;
                }
            }
        } catch (Throwable $e) {
            $ctx['metrics'] = [];
        }
        try {
            $ctx['alerts'] = ba_idf_fetch_in(
                $db,
                "SELECT device_id, code, severity, status, message, opened_at, acked_at FROM alerts WHERE status IN ('open','acked') AND",
                'device_id',
                $ids
            );
            usort($ctx['alerts'], static function (array $a, array $b): int {
                $rank = static function (array $row): int {
                    $sev = strtolower(trim((string)ba_col($row, 'severity', '')));
                    if ($sev === 'crit') {
                        return 0;
                    }
                    if ($sev === 'warn') {
                        return 1;
                    }
                    return 2;
                };
                $d = $rank($a) <=> $rank($b);
                if ($d !== 0) {
                    return $d;
                }
                return strcmp((string)ba_col($b, 'opened_at', ''), (string)ba_col($a, 'opened_at', ''));
            });
        } catch (Throwable $e) {
            $ctx['alerts'] = [];
        }
    }
    return $ctx;
}

function ba_idf_kind_key(array $d): string {
    $kind = strtolower(trim((string)ba_col($d, 'kind', 'ups')));
    if ($kind === '' || $kind === 'ups') {
        return 'ups';
    }
    if (in_array($kind, ['router', 'switch', 'patch_panel', 'other'], true)) {
        return $kind;
    }
    return 'other';
}

function ba_idf_gear_state(array $d): string {
    $kind = ba_idf_kind_key($d);
    if ($kind === 'ups') {
        return ba_worst($d);
    }
    $comm = strtolower(trim((string)ba_col($d, 'comm_state', '')));
    if ($comm === 'down') {
        return 'st-down';
    }
    if ($comm === 'degraded') {
        return 'st-warn';
    }
    if ($comm === 'ok') {
        return 'st-ok';
    }
    if ($kind === 'router') {
        return 'st-warn';
    }
    return 'st-idle';
}

function ba_idf_rank(string $cls): int {
    if ($cls === 'st-idle' || $cls === 'none') {
        return -1;
    }
    return ba_cls_rank($cls);
}

function ba_idf_state_label(string $cls): string {
    $map = [
        'st-batt' => 'battery',
        'st-down' => 'down',
        'st-hot' => 'hot',
        'st-warn' => 'warn',
        'st-ok' => 'ok',
        'st-idle' => 'placed',
        'st-crit' => 'critical',
    ];
    return $map[$cls] ?? 'none';
}

function ba_idf_kpi_class(string $cls): string {
    if ($cls === 'st-batt' || $cls === 'st-down') {
        return 'crit';
    }
    if ($cls === 'st-hot' || $cls === 'st-warn') {
        return 'warn';
    }
    if ($cls === 'st-ok') {
        return 'ok';
    }
    return '';
}

function ba_idf_threshold_set(array $ctx, int $deviceId): array {
    $defaults = [
        'capacity_low' => 30.0,
        'runtime_low_min' => 15.0,
        'temp_high_f' => 85.0,
        'temp_low_f' => 50.0,
        'humidity_high' => 70.0,
        'humidity_low' => 20.0,
    ];
    $global = $ctx['thresholds'][0] ?? [];
    $own = $ctx['thresholds'][$deviceId] ?? [];
    $out = [];
    foreach ($defaults as $key => $fallback) {
        $raw = null;
        if (is_array($own) && array_key_exists($key, $own) && $own[$key] !== null && $own[$key] !== '') {
            $raw = $own[$key];
        } elseif (is_array($global) && array_key_exists($key, $global) && $global[$key] !== null && $global[$key] !== '') {
            $raw = $global[$key];
        }
        $out[$key] = is_numeric($raw) ? (float)$raw : $fallback;
    }
    return $out;
}

function ba_idf_meter_flag(string $meter, mixed $value, array $th, array $device = []): string {
    if ($meter === 'comm') {
        $comm = strtolower(trim((string)$value));
        if ($comm === 'down') {
            return 'cell-bad';
        }
        if ($comm === 'degraded') {
            return 'cell-warn';
        }
        return '';
    }
    if ($meter === 'output') {
        $on = (int)ba_col($device, 'on_battery', 0) === 1;
        $out = ($value === null || $value === '' || !is_numeric($value)) ? null : (int)$value;
        if ($on || $out === 3) {
            return 'cell-batt';
        }
        if ($out !== null && $out !== 0 && !in_array($out, [2, 8], true)) {
            return 'cell-warn';
        }
        return '';
    }
    if ($value === null || $value === '' || !is_numeric($value)) {
        return '';
    }
    $n = (float)$value;
    if ($meter === 'cap' && $n < (float)$th['capacity_low']) {
        return 'cell-bad';
    }
    if ($meter === 'runtime' && $n < (float)$th['runtime_low_min']) {
        return 'cell-bad';
    }
    if ($meter === 'temp' && ($n > (float)$th['temp_high_f'] || $n < (float)$th['temp_low_f'])) {
        return 'cell-hot';
    }
    if ($meter === 'hum' && ($n > (float)$th['humidity_high'] || $n < (float)$th['humidity_low'])) {
        return 'cell-warn';
    }
    return '';
}

function ba_idf_metric_text(array $metric, array $field): string {
    $unitRaw = trim((string)ba_col($field, 'unit', ''));
    $unit = strtolower($unitRaw);
    $num = ba_col($metric, 'value_num', null);
    $text = trim((string)ba_col($metric, 'value_text', ''));
    $timed = in_array($unit, ['s', 'sec', 'second', 'seconds'], true);
    $bytes = in_array($unit, ['bytes', 'byte'], true);
    if (($timed || $bytes) && $num !== null && $num !== '' && is_numeric($num)) {
        $n = (float)$num;
        if ($timed && $n >= 90) {
            $sec = (int)round($n);
            $hours = intdiv($sec, 3600);
            $mins = intdiv($sec % 3600, 60);
            if ($hours > 0 && $mins > 0) {
                return $hours . ' h ' . $mins . ' min';
            }
            if ($hours > 0) {
                return $hours . ' h';
            }
            return $mins . ' min';
        }
        if ($bytes && $n >= 1000000) {
            if ($n >= 1000000000) {
                $shown = rtrim(rtrim(number_format($n / 1000000000, 2, '.', ''), '0'), '.');
                return $shown . ' GB';
            }
            $shown = rtrim(rtrim(number_format($n / 1000000, 2, '.', ''), '0'), '.');
            return $shown . ' MB';
        }
        $shown = rtrim(rtrim(number_format($n, 2, '.', ''), '0'), '.');
        return $unitRaw !== '' ? $shown . ' ' . $unitRaw : $shown;
    }
    if ($text !== '') {
        return $text;
    }
    if ($num !== null && $num !== '' && is_numeric($num)) {
        $shown = rtrim(rtrim(number_format((float)$num, 2, '.', ''), '0'), '.');
        return $unitRaw !== '' ? $shown . ' ' . $unitRaw : $shown;
    }
    return '';
}

function ba_idf_profile_for(array $device, array $profiles): ?array {
    $explicit = (int)ba_col($device, 'model_profile_id', 0);
    if ($explicit > 0) {
        foreach ($profiles as $p) {
            if ((int)ba_col($p, 'id', 0) === $explicit) {
                return $p;
            }
        }
    }
    $model = strtolower(trim((string)ba_col($device, 'model', '')));
    $vendor = strtolower(trim((string)ba_col($device, 'manufacturer', '')));
    $best = null;
    $bestLen = 0;
    foreach ($profiles as $p) {
        $mm = strtolower(trim((string)ba_col($p, 'match_model', '')));
        if ($mm !== '' && $model !== '' && str_contains($model, $mm) && strlen($mm) > $bestLen) {
            $best = $p;
            $bestLen = strlen($mm);
        }
    }
    if ($best) {
        return $best;
    }
    foreach ($profiles as $p) {
        $mv = strtolower(trim((string)ba_col($p, 'vendor', '')));
        if ($mv !== '' && (($vendor !== '' && $vendor === $mv) || ($model !== '' && str_contains($model, $mv)))) {
            return $p;
        }
    }
    $kind = strtolower(trim((string)ba_col($device, 'kind', '')));
    if ($kind === 'router') {
        return null;
    }
    if (count($profiles) === 1) {
        return $profiles[0];
    }
    return null;
}

function ba_idf_extras_text(array $device, array $ctx, int $limit = 6): string {
    $profile = ba_idf_profile_for($device, $ctx['profiles'] ?? []);
    if (!$profile) {
        return '';
    }
    $pid = (int)ba_col($profile, 'id', 0);
    $fields = $ctx['fields'][$pid] ?? [];
    $metrics = $ctx['metrics'][(int)ba_col($device, 'id', 0)] ?? [];
    $parts = [];
    foreach ($fields as $key => $field) {
        if (!isset($metrics[$key])) {
            continue;
        }
        $text = ba_idf_metric_text($metrics[$key], $field);
        if ($text === '') {
            continue;
        }
        $label = trim((string)ba_col($field, 'label', ''));
        $parts[] = $label !== '' ? $label . ' ' . $text : $text;
    }
    if (!$parts) {
        return '';
    }
    $shown = array_slice($parts, 0, max(1, $limit));
    $more = count($parts) - count($shown);
    $line = implode(' · ', $shown);
    if ($more > 0) {
        $line .= ' · +' . $more . ' more';
    }
    return $line;
}

function ba_idf_counts(array $devices, array $ctx): array {
    $c = [
        'ups' => 0, 'routers' => 0, 'switches' => 0, 'patches' => 0, 'other' => 0,
        'batt' => 0, 'down' => 0, 'hot' => 0, 'warn' => 0,
        'alerts' => 0, 'crit_alerts' => 0,
        'temps' => [], 'rhs' => [], 'power' => 0.0, 'power_n' => 0,
        'worst' => 'st-ok', 'polled' => 0, 'devices' => count($devices),
    ];
    foreach ($devices as $d) {
        $kind = ba_idf_kind_key($d);
        $st = ba_idf_gear_state($d);
        if ($kind === 'ups') {
            $c['ups']++;
        } elseif ($kind === 'router') {
            $c['routers']++;
        } elseif ($kind === 'switch') {
            $c['switches']++;
        } elseif ($kind === 'patch_panel') {
            $c['patches']++;
        } else {
            $c['other']++;
        }
        if ($kind === 'ups' || $kind === 'router') {
            $c['polled']++;
        }
        if ($st === 'st-batt') {
            $c['batt']++;
        } elseif ($st === 'st-down') {
            $c['down']++;
        } elseif ($st === 'st-hot') {
            $c['hot']++;
        } elseif ($st === 'st-warn') {
            $c['warn']++;
        }
        $countsForState = ($kind === 'ups' || $kind === 'router' || in_array($st, ['st-batt', 'st-down', 'st-hot', 'st-warn'], true));
        if ($countsForState && ba_idf_rank($st) > ba_idf_rank((string)$c['worst'])) {
            $c['worst'] = $st;
        }
        if ($kind === 'ups') {
            $temp = ba_climate_temp($d);
            $hum = ba_climate_hum($d);
            if ($temp !== null) {
                $c['temps'][] = (float)$temp;
            }
            if ($hum !== null) {
                $c['rhs'][] = (float)$hum;
            }
            $pw = ba_col($d, 'power_w', null);
            if ($pw !== null && $pw !== '' && is_numeric($pw)) {
                $c['power'] += (float)$pw;
                $c['power_n']++;
            }
        }
    }
    foreach ($ctx['alerts'] ?? [] as $alert) {
        $c['alerts']++;
        if (strtolower(trim((string)ba_col($alert, 'severity', ''))) === 'crit') {
            $c['crit_alerts']++;
        }
    }
    if ($c['polled'] === 0 && $c['batt'] === 0 && $c['down'] === 0 && $c['hot'] === 0 && $c['warn'] === 0) {
        $c['worst'] = 'none';
    }
    return $c;
}

function ba_idf_headline(array $c): string {
    $parts = [];
    if ((int)$c['batt'] > 0) {
        $parts[] = (int)$c['batt'] . ' on battery';
    }
    if ((int)$c['down'] > 0) {
        $parts[] = (int)$c['down'] . ' unreachable';
    }
    if ((int)$c['hot'] > 0) {
        $parts[] = (int)$c['hot'] . ' out of climate range';
    }
    $warn = (int)$c['warn'];
    if ($warn > 0) {
        $parts[] = $warn . ($warn === 1 ? ' warning' : ' warnings');
    }
    $alerts = (int)$c['alerts'];
    if ($alerts > 0) {
        $parts[] = $alerts . ($alerts === 1 ? ' alert' : ' alerts');
    }
    if ($parts) {
        return implode(' · ', $parts);
    }
    if ((int)$c['polled'] === 0) {
        return 'No polled gear in this closet yet. Racks below show what is placed.';
    }
    return 'Polled gear in this closet is online.';
}

function ba_idf_lead_class(array $c): string {
    if ((int)$c['batt'] > 0 || (int)$c['down'] > 0 || (int)$c['crit_alerts'] > 0) {
        return 'bad';
    }
    if ((int)$c['hot'] > 0 || (int)$c['warn'] > 0 || (int)$c['alerts'] > 0) {
        return 'warn';
    }
    return '';
}

function ba_idf_climate_line(array $c): string {
    $bits = [];
    if (!empty($c['temps'])) {
        $bits[] = number_format(array_sum($c['temps']) / count($c['temps']), 1) . ' °F avg';
    }
    if (!empty($c['rhs'])) {
        $bits[] = number_format(array_sum($c['rhs']) / count($c['rhs']), 0) . '% RH avg';
    }
    if (!empty($c['power_n'])) {
        $bits[] = number_format((float)$c['power'], 0) . ' W';
    }
    return implode(' · ', $bits);
}

function ba_idf_place(array $d, array $racksById): array {
    $rid = (int)ba_col($d, 'rack_id', 0);
    $name = '';
    if ($rid > 0 && isset($racksById[$rid])) {
        $name = trim((string)ba_col($racksById[$rid], 'name', ''));
    }
    if ($name === '') {
        $name = trim((string)ba_col($d, 'rack', ''));
    }
    $pos = ba_col($d, 'position_u', null);
    $uTxt = '';
    if ($pos !== null && $pos !== '' && is_numeric($pos)) {
        $u = (int)$pos;
        $uh = max(1, (int)ba_col($d, 'u_height', 1));
        $top = $u + $uh - 1;
        $uTxt = $u === $top ? 'U' . $u : 'U' . $u . '–' . $top;
    }
    if ($name === '' && $uTxt === '') {
        return ['text' => 'Not placed', 'href' => ''];
    }
    $text = $name !== '' && $uTxt !== '' ? $name . ' · ' . $uTxt : ($name !== '' ? $name : $uTxt);
    $href = $rid > 0 ? ba_href('/rack?id=' . $rid) : '';
    return ['text' => $text, 'href' => $href];
}

function ba_idf_device_label(array $d): string {
    $name = trim((string)ba_col($d, 'hostname', ''));
    if ($name === '') {
        $name = trim((string)ba_col($d, 'ip', ''));
    }
    if ($name === '') {
        $name = ba_kind_label(ba_idf_kind_key($d));
    }
    return $name;
}

function ba_idf_model_text(array $d): string {
    $model = trim((string)ba_col($d, 'model', ''));
    $mfr = trim((string)ba_col($d, 'manufacturer', ''));
    if ($model !== '' && $mfr !== '' && stripos($model, $mfr) === false) {
        return $mfr . ' ' . $model;
    }
    if ($model !== '') {
        return $model;
    }
    return $mfr !== '' ? $mfr : '—';
}

function ba_idf_sort_devices(array $rows): array {
    usort($rows, static function (array $a, array $b): int {
        $d = ba_idf_rank(ba_idf_gear_state($b)) <=> ba_idf_rank(ba_idf_gear_state($a));
        if ($d !== 0) {
            return $d;
        }
        return strcasecmp(ba_idf_device_label($a), ba_idf_device_label($b));
    });
    return $rows;
}

function ba_idf_cell(string $html, string $class = ''): void {
    if ($class === '') {
        echo '<td>' . $html . '</td>';
        return;
    }
    echo '<td class="' . $class . '">' . $html . '</td>';
}

function ba_idf_comm_cell(array $d): void {
    $comm = strtolower(trim((string)ba_col($d, 'comm_state', '')));
    $kind = ba_idf_kind_key($d);
    if ($comm === 'down') {
        ba_idf_cell('down', 'cell-bad');
        return;
    }
    if ($comm === 'degraded') {
        ba_idf_cell('degraded', 'cell-warn');
        return;
    }
    if ($comm === 'ok') {
        ba_idf_cell('ok');
        return;
    }
    if ($kind === 'router') {
        ba_idf_cell('no answer', 'cell-warn');
        return;
    }
    if ($kind === 'ups') {
        ba_idf_cell('<span class="muted">unknown</span>');
        return;
    }
    ba_idf_cell('<span class="muted">not polled</span>');
}

function ba_idf_device_cell(array $d, string $extra): void {
    $id = (int)ba_col($d, 'id', 0);
    $html = '<a href="' . h(ba_href('/device?id=' . $id)) . '">' . h(ba_idf_device_label($d)) . '</a>';
    if ($extra !== '') {
        $html .= '<div class="idf-extra">' . h($extra) . '</div>';
    }
    ba_idf_cell($html);
}

function ba_idf_place_cell(array $d, array $racksById): void {
    $place = ba_idf_place($d, $racksById);
    if ($place['text'] === 'Not placed') {
        ba_idf_cell('<span class="muted">Not placed</span>');
        return;
    }
    if ($place['href'] !== '') {
        ba_idf_cell('<a href="' . h($place['href']) . '">' . h($place['text']) . '</a>');
        return;
    }
    ba_idf_cell(h($place['text']));
}

function ba_idf_ups_note(array $d, array $ctx): string {
    $extra = ba_idf_extras_text($d, $ctx, 6);
    $expected = (int)ba_col($d, 'sensor_expected', 0) === 1;
    $pres = ba_col($d, 'sensor_present', null);
    $absent = $expected && ($pres === null || $pres === '' || (int)$pres === 0);
    if (!$absent) {
        return $extra;
    }
    $note = 'Sensor expected and not attached';
    return $extra === '' ? $note : $extra . ' · ' . $note;
}

function ba_idf_table_open(string $title, string $note, array $heads, string $actionsHtml = ''): void {
    $noteHtml = $note !== '' ? '<span class="muted">' . h($note) . '</span>' : '';
    if ($actionsHtml !== '') {
        $noteHtml .= $actionsHtml;
    }
    ba_card_open($title, $noteHtml);
    echo '<table class="idf-dev-table"><thead><tr>';
    foreach ($heads as $head) {
        echo '<th>' . h($head) . '</th>';
    }
    echo '</tr></thead><tbody>';
}

function ba_idf_table_close(): void {
    echo '</tbody></table>';
    ba_card_close();
}

function ba_idf_num_html(mixed $value, string $unit, int $dec = 0): string {
    return h(ba_fmt($value, $unit, $dec));
}

function ba_render_idf_telemetry(PDO $db, int $groupId, array $groups, array $devices, array $ctx, array $counts, array $racksById, bool $loadError): void {
    echo '<div class="idf-telemetry">';
    if ($loadError) {
        echo '<p class="idf-lead bad">Telemetry could not be loaded.</p>';
    } elseif (!$devices) {
        echo '<p class="muted">No devices are assigned to this closet yet.</p>';
    } else {
        $stateCls = ba_idf_kpi_class((string)$counts['worst']);
        $alertCls = 'ok';
        if ((int)$counts['crit_alerts'] > 0) {
            $alertCls = 'crit';
        } elseif ((int)$counts['alerts'] > 0) {
            $alertCls = 'warn';
        }
        echo '<div class="kpis dash-kpis">';
        echo '<div class="kpi ' . $stateCls . '"><span>State</span><b>' . h(ba_idf_state_label((string)$counts['worst'])) . '</b></div>';
        echo '<div class="kpi"><span>UPS</span><b>' . (int)$counts['ups'] . '</b></div>';
        echo '<div class="kpi ' . ((int)$counts['batt'] > 0 ? 'crit' : 'ok') . '"><span>On battery</span><b>' . (int)$counts['batt'] . '</b></div>';
        echo '<div class="kpi ' . ((int)$counts['down'] > 0 ? 'crit' : 'ok') . '"><span>Unreachable</span><b>' . (int)$counts['down'] . '</b></div>';
        echo '<div class="kpi ' . ((int)$counts['hot'] > 0 ? 'warn' : 'ok') . '"><span>Hot / climate</span><b>' . (int)$counts['hot'] . '</b></div>';
        echo '<div class="kpi"><span>Routers</span><b>' . (int)$counts['routers'] . '</b></div>';
        echo '<div class="kpi"><span>Switches</span><b>' . (int)$counts['switches'] . '</b></div>';
        echo '<div class="kpi ' . $alertCls . '"><span>Alerts</span><b>' . (int)$counts['alerts'] . '</b></div>';
        echo '</div>';
        $climate = ba_idf_climate_line($counts);
        if ($climate !== '') {
            echo '<p class="muted idf-climate">' . h($climate) . '</p>';
        }
        if (!empty($ctx['alerts'])) {
            $byId = [];
            foreach ($devices as $d) {
                $byId[(int)ba_col($d, 'id', 0)] = $d;
            }
            ba_idf_table_open('Open alerts', '', ['Severity', 'Device', 'Code', 'Message', 'Opened', 'Status'], '<a href="' . h(ba_href('/alerts')) . '">Alert list</a>');
            foreach ($ctx['alerts'] as $alert) {
                $sev = strtolower(trim((string)ba_col($alert, 'severity', '')));
                $rowCls = $sev === 'crit' ? 'st-crit' : 'st-warn';
                $did = (int)ba_col($alert, 'device_id', 0);
                $label = isset($byId[$did]) ? ba_idf_device_label($byId[$did]) : ('Device ' . $did);
                $status = strtolower(trim((string)ba_col($alert, 'status', '')));
                echo '<tr class="' . $rowCls . '">';
                ba_idf_cell(h($sev !== '' ? $sev : 'warn'), 'pill ' . ($sev === 'crit' ? 'crit' : 'warn'));
                ba_idf_cell('<a href="' . h(ba_href('/device?id=' . $did)) . '">' . h($label) . '</a>');
                ba_idf_cell(h((string)ba_col($alert, 'code', '')));
                ba_idf_cell(h((string)ba_col($alert, 'message', '')), 'idf-alert-msg');
                ba_idf_cell(h((string)ba_col($alert, 'opened_at', '')));
                ba_idf_cell($status === 'acked' ? '<span class="muted">acked</span>' : h($status !== '' ? $status : 'open'));
                echo '</tr>';
            }
            ba_idf_table_close();
        }
        $buckets = ['ups' => [], 'router' => [], 'switch' => [], 'patch_panel' => [], 'other' => []];
        foreach ($devices as $d) {
            $buckets[ba_idf_kind_key($d)][] = $d;
        }
        if ($buckets['ups']) {
            ba_idf_table_open('UPS', '', ['State', 'Device', 'Model', 'Place', 'Comm', 'Output', 'Cap', 'Runtime', 'Load', 'Power', 'Input', 'Temp', 'RH', 'Last poll']);
            foreach (ba_idf_sort_devices($buckets['ups']) as $d) {
                $st = ba_idf_gear_state($d);
                $th = ba_idf_threshold_set($ctx, (int)ba_col($d, 'id', 0));
                $temp = ba_climate_temp($d);
                $hum = ba_climate_hum($d);
                $out = ba_col($d, 'output_status', null);
                echo '<tr class="' . h($st) . '">';
                ba_idf_cell(h(ba_idf_state_label($st)), 'pill');
                ba_idf_device_cell($d, ba_idf_ups_note($d, $ctx));
                ba_idf_cell(h(ba_idf_model_text($d)));
                ba_idf_place_cell($d, $racksById);
                ba_idf_comm_cell($d);
                ba_idf_cell(h(ba_output_text(is_numeric($out) ? (int)$out : null)), ba_idf_meter_flag('output', $out, $th, $d));
                ba_idf_cell(ba_idf_num_html(ba_col($d, 'capacity_pct', null), '%', 0), ba_idf_meter_flag('cap', ba_col($d, 'capacity_pct', null), $th, $d));
                ba_idf_cell(ba_idf_num_html(ba_col($d, 'runtime_min', null), 'min', 0), ba_idf_meter_flag('runtime', ba_col($d, 'runtime_min', null), $th, $d));
                ba_idf_cell(ba_idf_num_html(ba_col($d, 'load_pct', null), '%', 0));
                ba_idf_cell(ba_idf_num_html(ba_col($d, 'power_w', null), 'W', 0));
                ba_idf_cell(ba_idf_num_html(ba_col($d, 'input_voltage', null), 'V', 0));
                ba_idf_cell(ba_idf_num_html($temp, '°F', 1), ba_idf_meter_flag('temp', $temp, $th, $d));
                ba_idf_cell(ba_idf_num_html($hum, '%', 0), ba_idf_meter_flag('hum', $hum, $th, $d));
                $last = trim((string)ba_col($d, 'last_success', ''));
                ba_idf_cell('<span class="muted">' . h($last !== '' ? $last : 'never') . '</span>');
                echo '</tr>';
            }
            ba_idf_table_close();
        }
        if ($buckets['router']) {
            ba_idf_table_open('Routers', '', ['State', 'Device', 'Model', 'Place', 'Comm', 'Last success', 'Readings']);
            foreach (ba_idf_sort_devices($buckets['router']) as $d) {
                $st = ba_idf_gear_state($d);
                $readings = ba_idf_extras_text($d, $ctx, 6);
                $last = trim((string)ba_col($d, 'last_success', ''));
                echo '<tr class="' . h($st) . '">';
                ba_idf_cell(h(ba_idf_state_label($st)), 'pill');
                ba_idf_device_cell($d, '');
                ba_idf_cell(h(ba_idf_model_text($d)));
                ba_idf_place_cell($d, $racksById);
                ba_idf_comm_cell($d);
                ba_idf_cell('<span class="muted">' . h($last !== '' ? $last : 'never') . '</span>');
                ba_idf_cell($readings !== '' ? h($readings) : '<span class="muted">—</span>', 'idf-read');
                echo '</tr>';
            }
            ba_idf_table_close();
        }
        if ($buckets['switch']) {
            ba_idf_table_open('Switches', 'Shown on the drawing. Not polled.', ['State', 'Device', 'Model', 'Place', 'Ports']);
            foreach (ba_idf_sort_devices($buckets['switch']) as $d) {
                $st = ba_idf_gear_state($d);
                $ports = ba_col($d, 'port_count', null);
                $portTxt = (is_numeric($ports) && (int)$ports > 0) ? (string)(int)$ports : '—';
                echo '<tr class="' . h($st) . '">';
                ba_idf_cell(h(ba_idf_state_label($st)), 'pill');
                ba_idf_device_cell($d, '');
                ba_idf_cell(h(ba_idf_model_text($d)));
                ba_idf_place_cell($d, $racksById);
                ba_idf_cell(h($portTxt));
                echo '</tr>';
            }
            ba_idf_table_close();
        }
        if ($buckets['patch_panel']) {
            ba_idf_table_open('Patch panels', 'Shown on the drawing. Not polled.', ['Device', 'Model', 'Place', 'Ports']);
            foreach (ba_idf_sort_devices($buckets['patch_panel']) as $d) {
                $ports = ba_col($d, 'port_count', null);
                $portTxt = (is_numeric($ports) && (int)$ports > 0) ? (string)(int)$ports : '—';
                $st = ba_idf_gear_state($d);
                echo '<tr class="' . h($st) . '">';
                ba_idf_device_cell($d, '');
                ba_idf_cell(h(ba_idf_model_text($d)));
                ba_idf_place_cell($d, $racksById);
                ba_idf_cell(h($portTxt));
                echo '</tr>';
            }
            ba_idf_table_close();
        }
        if ($buckets['other']) {
            ba_idf_table_open('Other', '', ['State', 'Device', 'Model', 'Place', 'Comm']);
            foreach (ba_idf_sort_devices($buckets['other']) as $d) {
                $st = ba_idf_gear_state($d);
                echo '<tr class="' . h($st) . '">';
                ba_idf_cell(h(ba_idf_state_label($st)), 'pill');
                ba_idf_device_cell($d, '');
                ba_idf_cell(h(ba_idf_model_text($d)));
                ba_idf_place_cell($d, $racksById);
                ba_idf_comm_cell($d);
                echo '</tr>';
            }
            ba_idf_table_close();
        }
    }
    $kids = ba_group_children($groups, $groupId);
    if ($kids) {
        try {
            $summaries = [];
            foreach (ba_idf_summaries($db) as $s) {
                if (!empty($s['group_id'])) {
                    $summaries[(int)$s['group_id']] = $s;
                }
            }
            $memo = [];
            ba_idf_table_open('Closets', '', ['State', 'Closet', 'Racks', 'UPS', 'Temp', 'On battery', 'Down']);
            foreach ($kids as $kid) {
                $kidId = (int)ba_col($kid, 'id', 0);
                $sum = ba_loc_rollup($kidId, $groups, $summaries, $memo);
                $st = (string)($sum['worst'] ?? 'st-ok');
                $battN = (int)($sum['batt'] ?? 0);
                $downN = (int)($sum['down'] ?? 0);
                echo '<tr class="' . h($st) . '">';
                ba_idf_cell(h(ba_idf_state_label($st)), 'pill');
                ba_idf_cell('<a href="' . h(ba_href('/idfs?group=' . $kidId)) . '">' . h((string)ba_col($kid, 'name', 'Closet')) . '</a>');
                ba_idf_cell((string)(int)($sum['racks'] ?? 0));
                ba_idf_cell((string)(int)($sum['ups'] ?? 0));
                ba_idf_cell(h(ba_fmt($sum['temp'] ?? null, '°F')));
                ba_idf_cell((string)$battN, $battN > 0 ? 'cell-batt' : '');
                ba_idf_cell((string)$downN, $downN > 0 ? 'cell-bad' : '');
                echo '</tr>';
            }
            ba_idf_table_close();
        } catch (Throwable $e) {
            echo '<p class="muted">Closet totals are unavailable.</p>';
        }
    }
    echo '</div>';
}

function page_idfs(PDO $db, array $user): void {
    $admin = ba_editor($user, 'edit_infrastructure');
    $gid = (int)($_GET['group'] ?? 0);
    $msg = '';
    $groups = ba_groups($db);

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $admin) {
        $act = $_POST['act'] ?? '';
        try {
            if ($act === 'add_rack') {
                $g = (int)$_POST['group_id'];
                $name = trim($_POST['name'] ?? '');
                $uh = max(1, min(58, (int)($_POST['u_height'] ?? 42)));
                $sort = (int)($_POST['sort_order'] ?? 0);
                if ($name === '') throw new RuntimeException('Rack name required');
                $exist = $db->prepare('SELECT id FROM groups WHERE id=?');
                $exist->execute([$g]);
                if (!$exist->fetch()) throw new RuntimeException('Unknown closet / group');
                $db->prepare('INSERT INTO racks (group_id, name, u_height, sort_order, notes) VALUES (?,?,?,?,?)')
                    ->execute([$g, $name, $uh, $sort, trim($_POST['notes'] ?? '')]);
                ba_audit($db, 'add_rack', 'rack', $name, 'group '.$g);
                $msg = 'Rack added';
                $gid = $g;
            } elseif ($act === 'del_rack') {
                $rid = (int)$_POST['rack_id'];
                $rk = ba_rack($db, $rid);
                if (!$rk) throw new RuntimeException('Rack not found');
                $db->prepare('UPDATE devices SET rack_id=NULL, position_u=NULL, rack=NULL WHERE rack_id=?')->execute([$rid]);
                $db->prepare('DELETE FROM racks WHERE id=?')->execute([$rid]);
                ba_audit($db, 'del_rack', 'rack', (string)$rid);
                $msg = 'Rack removed (units unplaced)';
                $gid = (int)$rk['group_id'];
            }
        } catch (Throwable $e) {
            $msg = $e->getMessage();
        }
    }

    ba_layout_start($gid ? 'IDF closet' : 'IDFs', 'idfs');
    if ($msg) echo '<div class="flash">'.h($msg).'</div>';

    if ($gid) {
        $g = null;
        foreach ($groups as $row) {
            if ((int)$row['id'] === $gid) { $g = $row; break; }
        }
        if (!$g) {
            echo '<p class="empty">Closet not found.</p>';
            ba_layout_end();
            return;
        }
        $racks = ba_racks_in_group($db, $gid);
        $maxU = 1;
        $rackMap = [];
        foreach ($racks as $rk) {
            if (!is_array($rk)) {
                continue;
            }
            $row = array_change_key_case($rk, CASE_LOWER);
            $maxU = max($maxU, (int)($row['u_height'] ?? 1));
            $rackMap[(int)($row['id'] ?? 0)] = $row;
        }
        $loadError = false;
        try {
            $devices = ba_idf_closet_devices($db, $gid);
        } catch (Throwable $e) {
            $devices = [];
            $loadError = true;
            @file_put_contents(BA_ROOT . '\\logs\\php-error.log', date('c') . ' idf devices ' . $e->getMessage() . "\n", FILE_APPEND);
        }
        $ctx = $loadError ? [
            'alerts' => [], 'thresholds' => [], 'metrics' => [], 'fields' => [], 'profiles' => [], 'error' => 'devices',
        ] : ba_idf_closet_context($db, $devices);
        $counts = ba_idf_counts($devices, $ctx);
        if ($loadError) {
            $lead = 'Telemetry could not be loaded.';
            $leadCls = 'bad';
        } else {
            $lead = ba_idf_headline($counts);
            $leadCls = ba_idf_lead_class($counts);
        }
        echo '<div class="idf-page">';
        echo '<div class="dash-hero"><div>';
        echo '<p class="muted"><a href="'.h(ba_href('/idfs')).'">Locations</a> / '.h(ba_group_path($db, $gid)).'</p>';
        echo '<h1>'.h($g['name']).'</h1>';
        echo '<p class="idf-lead' . ($leadCls !== '' ? ' ' . $leadCls : '') . '">' . h($lead) . '</p>';
        echo '<p class="muted">'.(ba_tech_mode()
            ? 'Add a rack, open it, then tap a U to place a device. U1 is the bottom.'
            : 'Click an empty U to place a UPS, switch, or patch panel. U1 is the bottom of the rack.').'</p>';
        echo '</div>';
        if ($admin && !ba_tech_mode()) {
            echo '<button type="button" class="btn" data-open-modal="modal-add-rack">Add network rack</button>';
        }
        echo '</div>';
        ba_render_idf_telemetry($db, $gid, $groups, $devices, $ctx, $counts, $rackMap, $loadError);

        if ($racks) {
            echo '<div class="idf-row-shell"><div class="idf-row-viewport" data-idf-pan="1"><div class="idf-row-strip" style="--max-units:'.$maxU.'">';
            foreach ($racks as $rk) {
                $devs = ba_rack_devices($db, (int)$rk['id']);
                $used = 0;
                foreach ($devs as $d) {
                    if ($d['position_u'] !== null) $used += max(1, (int)($d['u_height'] ?? 1));
                }
                $pct = (int)$rk['u_height'] ? round(100 * $used / (int)$rk['u_height']) : 0;
                echo '<div class="idf-row-cab">';
                echo '<div class="idf-row-head"><a href="/rack.php?id='.(int)$rk['id'].'"><strong>'.h($rk['name']).'</strong></a>';
                echo '<span class="muted">'.(int)$rk['u_height'].'U · '.$used.'U used · '.$pct.'%</span></div>';
                ba_render_elevation($rk, $devs, 'front', true, $admin);
                echo '<div class="idf-row-foot"><a href="/rack.php?id='.(int)$rk['id'].'">open rack</a>';
                if ($admin) {
                    echo '<form method="post" class="inline" onsubmit="return confirm(\'Remove this rack?\')">';
                    echo '<input type="hidden" name="act" value="del_rack"><input type="hidden" name="rack_id" value="'.(int)$rk['id'].'">';
                    echo '<button class="btn-quiet">remove</button></form>';
                }
                echo '</div></div>';
            }
            echo '</div></div></div>';
        } else {
            echo '<div class="empty">No racks in this closet yet.</div>';
        }

        if ($admin) {
            $rackName = 'Rack '.chr(65 + count($racks));
            $rackSort = (string)(count($racks) + 1);
            if (ba_tech_mode()) {
                echo '<section class="ucard idf-add-rack"><div class="ucard-head"><h3>Add network rack</h3></div>';
                echo '<form method="post" class="ucard-body stack idf-add-rack">';
            } else {
                ba_users_modal_open('modal-add-rack', 'Add network rack', false);
                echo '<form method="post" class="stack idf-add-rack">';
            }
            echo '<input type="hidden" name="act" value="add_rack">';
            echo '<input type="hidden" name="group_id" value="'.$gid.'">';
            echo '<div class="tech-field"><label>Name</label><input name="name" value="'.h($rackName).'" required></div>';
            echo '<div class="tech-field"><label>Height (U)</label><input type="number" name="u_height" min="4" max="58" value="42"></div>';
            echo '<div class="tech-field"><label>Left-to-right order</label><input type="number" name="sort_order" value="'.h($rackSort).'"></div>';
            echo '<div class="tech-field"><label>Notes</label><input name="notes" placeholder="optional"></div>';
            echo '<button>Add rack</button></form>';
            if (ba_tech_mode()) {
                echo '</section>';
            } else {
                ba_users_modal_close();
            }
        }
        echo '</div>';
        ba_layout_end();
        return;
    }

    $summaries = [];
    foreach (ba_idf_summaries($db) as $s) {
        if (!empty($s['group_id'])) $summaries[(int)$s['group_id']] = $s;
    }
    $memo = [];
    $sites = ba_group_children($groups, null);
    $nSites = count($sites);
    $nClosets = 0;
    $nRacks = 0;
    $nUps = 0;
    foreach ($sites as $site) {
        $roll = ba_loc_rollup((int)$site['id'], $groups, $summaries, $memo);
        $nClosets += (int)$roll['leaves'];
        $nRacks += (int)$roll['racks'];
        $nUps += (int)$roll['ups'];
    }
    ?>
    <div class="dash-hero">
      <div>
        <h1>Locations</h1>
        <p class="muted">Informational view of the tree managed under <a href="<?= h(ba_href('/org?tab=groups')) ?>">Org</a>. Expand a campus, then open an IDF.</p>
      </div>
    </div>
    <div class="kpis dash-kpis">
      <div class="kpi"><span>Campuses</span><b><?= $nSites ?></b></div>
      <div class="kpi"><span>Closets</span><b><?= $nClosets ?></b></div>
      <div class="kpi"><span>Racks</span><b><?= $nRacks ?></b></div>
      <div class="kpi"><span>UPS</span><b><?= $nUps ?></b></div>
    </div>
    <section class="ucard">
      <div class="ucard-head">
        <h3>Locations</h3>
        <div class="head-actions loc-toolbar">
          <input type="search" id="loc-search" placeholder="Filter locations…" autocomplete="off">
          <button type="button" class="btn" id="loc-expand">Expand all</button>
          <button type="button" class="btn" id="loc-collapse">Collapse all</button>
        </div>
      </div>
      <div class="ucard-body loc-tree" data-loc-tree="1">
      <?php
      if (!$sites) echo '<p class="empty">No locations yet. Add a group on the Org page, then add an IDF under that group.</p>';
      else ba_render_loc_tree($groups, null, $summaries, $memo, 0);
      ?>
      </div>
    </section>
    <?php
    if (ba_tech_mode()) {
        echo '<p class="muted">Open a closet to add a rack, then tap a U to place a device.</p>';
    } else {
        echo '<p class="muted"><a href="'.h(ba_href('/org?tab=groups')).'">Add a group or an IDF on the Org page.</a> Re-upload the PowerPanel profile there if a campus has no closets under it.</p>';
    }
    ba_layout_end();
}

function ba_render_tech_u_picker(array $rack, array $devices, int $focusU, string $focusFace): void {
    $face = $focusFace === 'rear' ? 'rear' : 'front';
    $units = max(1, (int)$rack['u_height']);
    $occ = ba_occupied_map($devices, $face);
    $id = (int)$rack['id'];
    echo '<div class="tech-u-picker">';
    echo '<div class="tech-u-head"><strong>Tap a U to place gear</strong><span class="tech-u-faces">';
    foreach (['front' => 'Front', 'rear' => 'Rear'] as $f => $lab) {
        $href = ba_href('/rack?id='.$id.'&face='.$f.($focusU > 0 ? '&u='.$focusU : '').'#place');
        echo '<a class="btn'.($face === $f ? ' on' : '').'" href="'.h($href).'">'.h($lab).'</a>';
    }
    echo '</span></div><div class="tech-u-grid">';
    for ($u = $units; $u >= 1; $u--) {
        $busy = !empty($occ[$u]);
        $on = $focusU === $u ? ' on' : '';
        if ($busy) {
            echo '<span class="tech-u busy'.$on.'">'.$u.'</span>';
        } else {
            $href = ba_href('/rack?id='.$id.'&u='.$u.'&face='.$face.'#place');
            echo '<a class="tech-u'.$on.'" href="'.h($href).'">'.$u.'</a>';
        }
    }
    echo '</div></div>';
}

function page_rack(PDO $db, array $user): void {
    $admin = ba_editor($user, 'edit_infrastructure');
    $id = (int)($_GET['id'] ?? 0);
    $rack = ba_rack($db, $id);
    if (!$rack) {
        http_response_code(404);
        echo 'Rack not found';
        return;
    }
    $msg = '';
    $focusU = isset($_GET['u']) ? (int)$_GET['u'] : 0;
    $focusFace = $_GET['face'] ?? 'front';
    if (!in_array($focusFace, ['front', 'rear', 'both'], true)) $focusFace = 'front';
    $itemId = (int)($_GET['item'] ?? 0);

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $admin) {
        $act = $_POST['act'] ?? '';
        try {
            $occupants = ba_rack_devices($db, $id);
            if ($act === 'save_rack') {
                $name = trim($_POST['name'] ?? '');
                $uh = max(1, min(58, (int)($_POST['u_height'] ?? 42)));
                $sort = (int)($_POST['sort_order'] ?? 0);
                if ($name === '') throw new RuntimeException('Name required');
                $db->prepare('UPDATE racks SET name=?, u_height=?, sort_order=?, notes=?, updated_at=datetime(\'now\') WHERE id=?')
                    ->execute([$name, $uh, $sort, trim($_POST['notes'] ?? ''), $id]);
                ba_audit($db, 'update_rack', 'rack', (string)$id);
                $msg = 'Rack saved';
            } elseif ($act === 'place_existing') {
                $did = (int)$_POST['device_id'];
                $pos = (int)$_POST['position_u'];
                $uh = max(1, min(60, (int)($_POST['u_height'] ?? 2)));
                $face = $_POST['face'] ?? 'both';
                if (!in_array($face, ['front', 'rear', 'both'], true)) $face = 'both';
                $st = $db->prepare('SELECT * FROM devices WHERE id=?');
                $st->execute([$did]);
                $dev = $st->fetch();
                if (!$dev) throw new RuntimeException('Unit not found');
                if ($pos < 1 || ($pos + $uh - 1) > (int)$rack['u_height']) {
                    throw new RuntimeException('U range does not fit this rack');
                }
                $placedKind = strtolower(trim((string)($dev['kind'] ?? 'ups')));
                $slotFace = ($placedKind === '' || $placedKind === 'ups') ? 'both' : $face;
                $hit = ba_slot_taken($occupants, $pos, $uh, $slotFace, $did);
                if ($hit) throw new RuntimeException('U overlap with '.($hit['hostname'] ?: $hit['ip']));
                $db->prepare('UPDATE devices SET rack_id=?, position_u=?, u_height=?, face=?, kind=IFNULL(kind,\'ups\'), updated_at=datetime(\'now\') WHERE id=?')
                    ->execute([$id, $pos, $uh, $face, $did]);
                ba_sync_location_from_group($db, $did, (int)$rack['group_id'], $rack['name']);
                ba_audit($db, 'place_device', 'device', (string)$did, 'rack '.$id.' U'.$pos);
                $msg = 'Placed on U'.$pos;
                $focusU = 0;
            } elseif ($act === 'add_item') {
                $tid = (int)($_POST['template_id'] ?? 0);
                $tpl = $tid ? ba_template($db, $tid) : null;
                $kind = ba_kind_allowed((string)($_POST['kind'] ?? ($tpl['kind'] ?? 'switch')), 'other');
                $pos = (int)$_POST['position_u'];
                $uh = max(1, min(60, (int)($_POST['u_height'] ?? ($tpl['u_height'] ?? 1))));
                $face = $_POST['face'] ?? ($tpl['face'] ?? ($kind === 'patch_panel' ? 'front' : 'both'));
                if (!in_array($face, ['front', 'rear', 'both'], true)) $face = 'front';
                $host = trim($_POST['hostname'] ?? '');
                if ($host === '') throw new RuntimeException('Name required');
                if ($pos < 1 || ($pos + $uh - 1) > (int)$rack['u_height']) {
                    throw new RuntimeException('U range does not fit this rack');
                }
                $hit = ba_slot_taken($occupants, $pos, $uh, $face, null);
                if ($hit) throw new RuntimeException('U overlap with '.($hit['hostname'] ?: $hit['ip']));
                $ip = trim($_POST['ip'] ?? '');
                if ($ip !== '' && !filter_var($ip, FILTER_VALIDATE_IP)) throw new RuntimeException('Bad IP');
                if ($kind === 'ups' && $ip === '') {
                    throw new RuntimeException('UPS needs an IP so it can be polled');
                }
                if ($kind === 'router' && $ip === '') {
                    throw new RuntimeException('A router needs an IP so it can be polled');
                }
                $enabled = ($kind === 'ups' || $kind === 'router') ? 1 : 0;
                $sensor = ($kind === 'ups') ? 1 : 0;
                $ports = ($_POST['port_count'] ?? '') === '' ? ($tpl['port_count'] ?? null) : (int)$_POST['port_count'];
                $model = trim($_POST['model'] ?? '') ?: (string)($tpl['model'] ?? '');
                $mfr = trim($_POST['manufacturer'] ?? '') ?: (string)($tpl['manufacturer'] ?? '');
                $db->prepare(
                    'INSERT INTO devices (ip, hostname, model, manufacturer, site, building, idf_closet, rack, kind, rack_id, position_u, u_height, face, port_count, sensor_expected, is_simulated, enabled, group_id, template_id, va_rating)
                     VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
                )->execute([
                    $ip, $host, $model, $mfr,
                    '', '', '', $rack['name'], $kind, $id, $pos, $uh, $face, $ports, $sensor, 0, $enabled, (int)$rack['group_id'],
                    $tid ?: null, $tpl['va_rating'] ?? null,
                ]);
                $did = ba_last_id($db);
                if ($did > 0) {
                    ba_pp_exec($db, 'UPDATE devices SET department_id=? WHERE id=?', [ba_device_department_choice($user), $did]);
                    if ($tid > 0) {
                        ba_ensure_template_links($db);
                        $linked = ba_template($db, $tid);
                        if ($linked && !empty($linked['model_profile_id'])) {
                            $db->prepare('UPDATE devices SET model_profile_id=? WHERE id=?')->execute([(int)$linked['model_profile_id'], $did]);
                        }
                        if ($linked && !empty($linked['snmp_profile_id'])) {
                            $db->prepare('UPDATE devices SET snmp_profile_id=? WHERE id=?')->execute([(int)$linked['snmp_profile_id'], $did]);
                        }
                    }
                }
                ba_sync_location_from_group($db, $did, (int)$rack['group_id'], $rack['name']);
                ba_audit($db, 'add_rack_item', 'device', (string)$did, $kind.' U'.$pos);
                $msg = ba_kind_label($kind).' added at U'.$pos;
                $focusU = 0;
            } elseif ($act === 'unplace') {
                $did = (int)$_POST['device_id'];
                $db->prepare('UPDATE devices SET rack_id=NULL, position_u=NULL, rack=NULL, updated_at=datetime(\'now\') WHERE id=? AND rack_id=?')
                    ->execute([$did, $id]);
                ba_audit($db, 'unplace_device', 'device', (string)$did);
                $msg = 'Removed from rack';
            } elseif ($act === 'delete_item') {
                $did = (int)$_POST['device_id'];
                $st = $db->prepare('SELECT * FROM devices WHERE id=? AND rack_id=?');
                $st->execute([$did, $id]);
                $dev = $st->fetch();
                if (!$dev) throw new RuntimeException('Not on this rack');
                if (($dev['kind'] ?? 'ups') === 'ups') throw new RuntimeException('Unplace a UPS instead of deleting it');
                if (function_exists('ba_ensure_parts_schema')) {
                    ba_ensure_parts_schema($db);
                    ba_pp_exec($db, 'DELETE FROM device_parts WHERE device_id=?', [$did]);
                }
                $db->prepare('DELETE FROM devices WHERE id=?')->execute([$did]);
                ba_audit($db, 'delete_rack_item', 'device', (string)$did);
                $msg = 'Removed from inventory';
            }
        } catch (Throwable $e) {
            $msg = $e->getMessage();
        }
        $rack = ba_rack($db, $id) ?: $rack;
    }

    $devices = ba_rack_devices($db, $id);
    $item = null;
    if ($itemId) {
        foreach ($devices as $d) {
            if ((int)$d['id'] === $itemId) { $item = $d; break; }
        }
    }

    $unplaced = $db->prepare(
        "SELECT id, hostname, ip, kind, model FROM devices
         WHERE rack_id IS NULL AND IFNULL(kind,'ups')='ups'
           AND (group_id=? OR group_id IS NULL)
         ORDER BY hostname, ip"
    );
    $unplaced->execute([(int)$rack['group_id']]);
    $unplacedRows = $unplaced->fetchAll();

    $units = max(1, (int)$rack['u_height']);
    $used = 0;
    foreach ($devices as $d) {
        if ($d['position_u'] !== null) $used += max(1, (int)($d['u_height'] ?? 1));
    }
    $free = max(0, $units - $used);
    $pct = $units ? round(100 * $used / $units, 1) : 0;
    $health = ba_rack_health($devices);
    $path = ba_group_path($db, (int)$rack['group_id']);
    $aspectH = $units * 1.75;
    $aspectHTxt = rtrim(rtrim(sprintf('%.2F', $aspectH), '0'), '.');
    $preU = $focusU > 0 ? $focusU : 1;
    $sorted = $devices;
    usort($sorted, fn($a, $b) => ((int)($b['position_u'] ?? 0)) <=> ((int)($a['position_u'] ?? 0)));

    ba_layout_start('Rack: '.$rack['name'], 'idfs');
    if ($msg) echo '<div class="flash">'.h($msg).'</div>';
    ?>
    <div class="idf-cab-head">
      <div>
        <span class="muted"><?= h($path) ?></span>
        <h1 class="idf-cab-title"><?= h($rack['name']) ?></h1>
        <p class="mb-0">
          <span class="idf-chip idf-chip-<?= h($health['status']) ?>" title="<?= h(implode("\n", $health['reasons'])) ?>">
            <span class="idf-chip-dot" aria-hidden="true"></span>
            <?= h($health['label']) ?>
          </span>
        </p>
        <p class="muted mb-0">
          Rails: 19″ · <?= $units ?>U (<?= h($aspectHTxt) ?>″ tall)
          · <?= count($devices) ?> device(s)
        </p>
      </div>
      <div class="idf-cab-actions">
        <a class="btn" href="<?= h(ba_href('/idfs?group='.(int)$rack['group_id'])) ?>">All racks</a>
        <?php if ($admin): ?>
          <button type="button" class="btn" data-open-modal="modal-place-ups">Place existing UPS</button>
          <button type="button" class="btn" data-open-modal="modal-add-item">Add switch or patch panel</button>
        <?php endif; ?>
      </div>
    </div>

    <div class="idf-detail-grid">
      <div class="card idf-col">
        <div class="card-header"><h2>Front</h2></div>
        <div class="card-body">
          <?php ba_render_elevation($rack, $devices, 'front', false, $admin, $focusFace === 'rear' ? 0 : $focusU); ?>
        </div>
      </div>
      <div class="card idf-col">
        <div class="card-header"><h2>Rear</h2></div>
        <div class="card-body">
          <?php ba_render_elevation($rack, $devices, 'rear', false, $admin, $focusFace === 'rear' ? $focusU : 0); ?>
        </div>
      </div>
      <div class="card idf-col idf-props-col">
        <div class="card-header"><h2>Rack properties</h2></div>
        <div class="card-body">
          <div class="idf-metrics">
            <div class="idf-metric"><div class="label">U Used</div><div class="value"><?= $used ?></div></div>
            <div class="idf-metric"><div class="label">U Free</div><div class="value"><?= $free ?></div></div>
            <div class="idf-metric ok"><div class="label">Utilization</div><div class="value"><?= h((string)$pct) ?>%</div></div>
          </div>
          <dl class="idf-prop-list">
            <div><dt>Name</dt><dd><?= h($rack['name']) ?></dd></div>
            <div><dt>Health</dt><dd>
              <span class="idf-chip idf-chip-<?= h($health['status']) ?>">
                <span class="idf-chip-dot" aria-hidden="true"></span><?= h($health['label']) ?>
              </span>
              <?php if ($health['reasons']): ?>
                <ul class="idf-reasons"><?php foreach (array_slice($health['reasons'], 0, 6) as $reason): ?>
                  <li><?= h($reason) ?></li>
                <?php endforeach; ?></ul>
              <?php endif; ?>
            </dd></div>
            <div><dt>Closet</dt><dd><?= h($path) ?></dd></div>
            <div><dt>U height</dt><dd><?= $units ?>U</dd></div>
            <div><dt>Rail width</dt><dd>19 in (EIA-310)</dd></div>
            <div><dt>U pitch</dt><dd>1.75 in</dd></div>
            <div><dt>Bay ratio</dt><dd>19 : <?= h($aspectHTxt) ?></dd></div>
            <?php if (trim((string)($rack['notes'] ?? '')) !== ''): ?>
              <div><dt>Notes</dt><dd><?= h($rack['notes']) ?></dd></div>
            <?php endif; ?>
          </dl>
        </div>
        <div class="card-header idf-card-split"><h2>Devices</h2></div>
        <div class="card-body flush">
          <table class="idf-dev-table">
            <thead><tr><th>U</th><th>Name</th><th>Make</th><th>Model</th><th>IP</th><th>Status</th><?php if ($admin): ?><th></th><?php endif; ?></tr></thead>
            <tbody>
            <?php if (!$sorted): ?>
              <tr><td colspan="<?= $admin ? 7 : 6 ?>" class="muted">Empty rack. Click an empty U to place a device.</td></tr>
            <?php endif; ?>
            <?php foreach ($sorted as $d):
                $pos = (int)$d['position_u'];
                $uh = max(1, (int)($d['u_height'] ?? 1));
                $top = $pos + $uh - 1;
                $uLabel = $d['position_u'] === null ? '—' : ($pos === $top ? (string)$pos : $pos.'–'.$top);
                $kind = $d['kind'] ?? 'ups';
                $name = $d['hostname'] ?: ($d['ip'] ?: ba_kind_label($kind));
                $href = in_array($kind, ['ups', 'router'], true) ? ba_href('/device?id='.(int)$d['id']) : ba_href('/rack?id='.$id.'&item='.(int)$d['id']);
                if ($kind === 'ups') {
                    $st = ba_output_text(isset($d['output_status']) ? (int)$d['output_status'] : null).' · '.($d['comm_state'] ?: 'unknown');
                } elseif ($kind === 'router') {
                    $st = $d['comm_state'] ?: 'unknown';
                } else {
                    $st = ba_kind_label($kind);
                }
            ?>
              <tr>
                <td title="<?= h($d['face'] ?? '') ?>"><?= h($uLabel) ?></td>
                <td><a href="<?= h($href) ?>"><?= h($name) ?></a></td>
                <td><?= h($d['manufacturer'] ?: '—') ?></td>
                <td><?= h($d['model'] ?: '—') ?><?= !empty($d['port_count']) ? ' · '.(int)$d['port_count'].'p' : '' ?></td>
                <td><?= h($d['ip'] ?: '—') ?></td>
                <td><?= h($st) ?></td>
                <?php if ($admin): ?>
                <td>
                  <form method="post" class="inline">
                    <input type="hidden" name="device_id" value="<?= (int)$d['id'] ?>">
                    <button class="btn-quiet" name="act" value="unplace">unplace</button>
                    <?php if ($kind !== 'ups'): ?>
                      <button class="btn-quiet" name="act" value="delete_item" onclick="return confirm('Delete this item?')">delete</button>
                    <?php endif; ?>
                  </form>
                </td>
                <?php endif; ?>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php if ($admin): ?>
        <div class="card-header idf-card-split"><h2>Edit rack</h2></div>
        <div class="card-body">
          <form method="post" class="stack idf-edit-rack">
            <input type="hidden" name="act" value="save_rack">
            <label>Name</label><input name="name" value="<?= h($rack['name']) ?>" required>
            <label>Height (U)</label><input type="number" name="u_height" min="4" max="58" value="<?= $units ?>">
            <label>Left-to-right order</label><input type="number" name="sort_order" value="<?= (int)$rack['sort_order'] ?>">
            <label>Notes</label><textarea name="notes"><?= h($rack['notes']) ?></textarea>
            <button>Save rack</button>
          </form>
        </div>
        <?php endif; ?>
      </div>
    </div>

    <?php if ($admin): ?>
    <div id="place">
      <?php if (ba_tech_mode()) { ba_render_tech_u_picker($rack, $devices, $focusU, $focusFace); } ?>
      <?php ba_users_modal_open('modal-place-ups', 'Place existing UPS', $focusU > 0); ?>
      <form method="post" class="stack">
        <?php if ($focusU): ?><p class="muted">Clicked U<?= $focusU ?> (<?= h($focusFace) ?>).</p><?php endif; ?>
        <input type="hidden" name="act" value="place_existing">
        <label>UPS</label>
        <select name="device_id" required>
          <option value="">choose</option>
          <?php foreach ($unplacedRows as $d): ?>
            <option value="<?= (int)$d['id'] ?>"><?= h(($d['hostname'] ?: $d['ip']).' · '.$d['ip']) ?></option>
          <?php endforeach; ?>
        </select>
        <label>Bottom U</label><input type="number" name="position_u" min="1" max="<?= $units ?>" value="<?= $preU ?>" required>
        <label>Height (U)</label><input type="number" name="u_height" min="1" max="60" value="2">
        <label>Face</label>
        <select name="face">
          <option value="both" selected>both (full depth)</option>
          <option value="front">front</option>
          <option value="rear">rear</option>
        </select>
        <button>Place UPS</button>
        <p class="muted">A UPS fills the rack depth, so the rear view draws it with the rear picture. Unplaced UPS in this closet. A 2U UPS at U1 occupies U1–U2.</p>
      </form>
      <?php ba_users_modal_close(); ?>
      <?php ba_users_modal_open('modal-add-item', 'Add to this rack', false, '', true); ?>
      <form method="post" class="stack">
        <input type="hidden" name="act" value="add_item">
        <label>Template</label>
        <select name="template_id" data-tpl-fill="1"><?= ba_template_options($db) ?></select>
        <label>Kind</label>
        <select name="kind">
          <?php foreach (['switch' => 'Switch', 'router' => 'Router', 'patch_panel' => 'Patch panel', 'other' => 'Other'] as $k => $lab): ?>
            <option value="<?= h($k) ?>"><?= h($lab) ?></option>
          <?php endforeach; ?>
        </select>
        <label>Name</label><input name="hostname" placeholder="IDF2A-SW-01 or PP-A" required>
        <label>Manufacturer / model</label>
        <div class="filters"><input name="manufacturer" placeholder="Cisco, Panduit…"><input name="model" placeholder="model"></div>
        <label>Bottom U</label><input type="number" name="position_u" min="1" max="<?= $units ?>" value="<?= $preU ?>" required>
        <label>Height (U)</label><input type="number" name="u_height" min="1" max="60" value="1">
        <label>Face</label>
        <select name="face">
          <option value="front"<?= $focusFace==='front'?' selected':'' ?>>front</option>
          <option value="rear"<?= $focusFace==='rear'?' selected':'' ?>>rear</option>
          <option value="both"<?= $focusFace==='both'?' selected':'' ?>>both</option>
        </select>
        <label>Ports (patch panels)</label><input type="number" name="port_count" min="0" placeholder="24 or 48">
        <label>Management IP</label><input name="ip" placeholder="required for a router">
        <?php ba_department_field($db, $user, null); ?>
        <button>Add to rack</button>
        <p class="muted">A router with an IP is polled with its SNMPv3 credentials. A switch or patch panel stays on the drawing. A template also copies its credentials and model profile.</p>
      </form>
      <?php ba_users_modal_close(); ?>
    </div>
    <?php endif; ?>
    <?php
    ba_layout_end();
}
