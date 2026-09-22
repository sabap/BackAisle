<?php
declare(strict_types=1);

function ba_latest_join(): string {
    return <<<SQL
SELECT d.*, p.comm_state, p.last_success, p.last_attempt, p.consecutive_failures, p.last_error, p.last_trap,
      s.output_status, s.battery_status, s.capacity_pct, s.runtime_min, s.load_pct, s.input_voltage, s.output_voltage,
      s.temp_f, s.humidity_pct, s.on_battery, s.sensor_present AS sample_sensor, s.ts AS sample_ts, s.power_w,
      c.temp_f AS climate_temp, c.humidity_pct AS climate_hum, c.sensor_present AS climate_sensor,
      g.name AS group_name, g.id AS gid
      FROM devices d
      LEFT JOIN groups g ON g.id = d.group_id
      LEFT JOIN poll_state p ON p.device_id = d.id
      LEFT JOIN samples s ON s.id = (
        SELECT id FROM samples WHERE device_id = d.id ORDER BY ts DESC LIMIT 1
      )
      LEFT JOIN samples c ON c.id = (
        SELECT id FROM samples WHERE device_id = d.id AND temp_f IS NOT NULL ORDER BY ts DESC LIMIT 1
      )
SQL;
}

function page_login(PDO $db): void {
    $err = '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $u = trim($_POST['username'] ?? '');
        $p = $_POST['password'] ?? '';
        if (ba_login($db, $u, $p)) {
            header('Location: /home.php');
            exit;
        }
        $err = 'Invalid credentials';
    }
    ?>
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Sign in · BackAisle</title><link rel="stylesheet" href="/assets/app.css"></head>
<body>
<div class="card login">
  <div class="brand"><span class="mark">BA</span> BackAisle</div>
  <p class="muted">IDF infrastructure — racks, UPS, climate. Not ColdAisle, not PowerPanel. Local or AD (LDAPS) accounts.</p>
  <?php if ($err): ?><div class="flash"><?= h($err) ?></div><?php endif; ?>
  <form method="post" class="stack">
    <label>User</label><input name="username" autofocus>
    <label>Password</label><input type="password" name="password">
    <p><button type="submit">Sign in</button></p>
  </form>
</div>
</body></html>
    <?php
}

function ba_cls_rank(string $cls): int {
    return ['st-batt' => 4, 'st-down' => 3, 'st-hot' => 2, 'st-warn' => 1, 'st-ok' => 0][$cls] ?? 0;
}

function ba_idf_summaries(PDO $db): array {
    $rows = $db->query(ba_latest_join() . ' WHERE '.ba_ups_only_sql())->fetchAll();
    $by = [];
    $bump = static function (array &$slot, array $r) {
        $slot['ups']++;
        $cls = ba_worst($r);
        if ($cls === 'st-batt') $slot['batt']++;
        elseif ($cls === 'st-down') $slot['down']++;
        elseif ($cls === 'st-hot') $slot['hot']++;
        elseif ($cls === 'st-ok') $slot['ok']++;
        if (ba_cls_rank($cls) > ba_cls_rank($slot['worst'])) $slot['worst'] = $cls;
        if ($r['temp_f'] !== null && $r['temp_f'] !== '') $slot['temps'][] = (float)$r['temp_f'];
        if ($r['humidity_pct'] !== null && $r['humidity_pct'] !== '') $slot['rhs'][] = (float)$r['humidity_pct'];
        $pw = $r['power_w'] ?? null;
        if ($pw === null && $r['load_pct'] !== null) $pw = (float)$r['load_pct'] * 20.0;
        if ($pw !== null && $pw !== '') $slot['powers'][] = (float)$pw;
    };
    $blank = static function (int $gid, string $name, string $building, string $closet): array {
        return [
            'group_id' => $gid ?: null,
            'name' => $name,
            'building' => $building,
            'idf_closet' => $closet,
            'path' => '',
            'ups' => 0, 'ok' => 0, 'batt' => 0, 'down' => 0, 'hot' => 0,
            'racks' => 0, 'temps' => [], 'rhs' => [], 'powers' => [],
            'worst' => 'st-ok',
        ];
    };
    foreach ($rows as $r) {
        $gid = (int)($r['group_id'] ?? 0);
        $key = $gid ? 'g'.$gid : 'x:'.($r['building'] ?? '').'/'.($r['idf_closet'] ?? '');
        if (!isset($by[$key])) {
            $by[$key] = $blank($gid, (string)($r['group_name'] ?: ($r['idf_closet'] ?: 'Ungrouped')), (string)($r['building'] ?? ''), (string)($r['idf_closet'] ?? ''));
        }
        $bump($by[$key], $r);
    }
    foreach ($db->query('SELECT group_id, COUNT(*) n FROM racks GROUP BY group_id') as $rk) {
        $gid = (int)$rk['group_id'];
        $key = 'g'.$gid;
        if (!isset($by[$key])) {
            $g = $db->prepare('SELECT name FROM groups WHERE id=?');
            $g->execute([$gid]);
            $name = (string)($g->fetchColumn() ?: 'Closet');
            $by[$key] = $blank($gid, $name, '', $name);
        }
        $by[$key]['racks'] = (int)$rk['n'];
    }
    foreach ($by as &$slot) {
        $slot['path'] = $slot['group_id'] ? ba_group_path($db, (int)$slot['group_id']) : trim($slot['building'].' / '.$slot['idf_closet'], ' /');
        $slot['temp'] = $slot['temps'] ? array_sum($slot['temps']) / count($slot['temps']) : null;
        $slot['rh'] = $slot['rhs'] ? array_sum($slot['rhs']) / count($slot['rhs']) : null;
        $slot['power'] = $slot['powers'] ? array_sum($slot['powers']) : null;
        unset($slot['temps'], $slot['rhs'], $slot['powers']);
    }
    unset($slot);
    $list = array_values($by);
    usort($list, static function ($a, $b) {
        $d = ba_cls_rank($b['worst']) <=> ba_cls_rank($a['worst']);
        if ($d !== 0) return $d;
        return strcasecmp((string)$a['path'], (string)$b['path']);
    });
    return $list;
}

function page_dashboard(PDO $db): void {
    $q = trim($_GET['q'] ?? '');
    try {
        $idfs = ba_idf_summaries($db);
    } catch (Throwable $e) {
        $idfs = [];
    }
    if ($q !== '') {
        $ql = strtolower($q);
        $idfs = array_values(array_filter($idfs, static function ($row) use ($ql) {
            $hay = strtolower($row['path'].' '.$row['name'].' '.$row['building'].' '.$row['idf_closet']);
            return str_contains($hay, $ql);
        }));
    }
    $open = 0;
    try {
        $open = (int)$db->query("SELECT COUNT(*) FROM alerts WHERE status='open'")->fetchColumn();
    } catch (Throwable $e) {
        $open = 0;
    }
    $nUps = 0; $ok = 0; $batt = 0; $hot = 0; $down = 0; $nRacks = 0;
    foreach ($idfs as $row) {
        $nUps += $row['ups'];
        $ok += $row['ok'];
        $batt += $row['batt'];
        $hot += $row['hot'];
        $down += $row['down'];
        $nRacks += $row['racks'];
    }
    ba_layout_start('Dashboard', 'dash');
    ?>
    <div class="dash-hero">
      <div>
        <h1>Campus IDFs</h1>
        <p class="muted">Power and climate across closets. Click an IDF to open its racks.</p>
      </div>
    </div>
    <div class="kpis dash-kpis">
      <div class="kpi"><span>IDFs</span><b><?= count($idfs) ?></b></div>
      <div class="kpi"><span>Racks</span><b><?= $nRacks ?></b></div>
      <div class="kpi"><span>UPS</span><b><?= $nUps ?></b></div>
      <div class="kpi ok"><span>Online</span><b><?= $ok ?></b></div>
      <div class="kpi crit"><span>On battery</span><b><?= $batt ?></b></div>
      <div class="kpi warn"><span>Hot closets</span><b><?= $hot ?></b></div>
      <div class="kpi crit"><span>Unreachable</span><b><?= $down ?></b></div>
      <div class="kpi warn"><span>Open alerts</span><b><?= $open ?></b></div>
    </div>
    <div class="dash-charts dash-charts-3">
      <div class="card dash-chart-card">
        <div class="dash-chart-head">
          <div class="legend">Average power</div>
          <span class="muted">Watts · last days</span>
        </div>
        <canvas id="dash-power" height="220"></canvas>
      </div>
      <div class="card dash-chart-card">
        <div class="dash-chart-head">
          <div class="legend">Average temperature</div>
          <span class="muted">°F · closet sensors</span>
        </div>
        <canvas id="dash-temp" height="220"></canvas>
      </div>
      <div class="card dash-chart-card">
        <div class="dash-chart-head">
          <div class="legend">Average humidity</div>
          <span class="muted">% RH</span>
        </div>
        <canvas id="dash-rh" height="220"></canvas>
      </div>
    </div>
    <div class="dash-charts">
      <div class="card dash-rank-card" id="dash-hot"></div>
      <div class="card dash-rank-card" id="dash-pwr"></div>
    </div>
    <div class="dash-list-head">
      <h2>IDFs</h2>
      <form class="filters" method="get">
        <input name="q" value="<?= h($q) ?>" placeholder="search closet or building">
        <button>Filter</button>
      </form>
    </div>
    <table class="dash-idf-table">
      <thead>
        <tr>
          <th>State</th>
          <th>IDF</th>
          <th>Racks</th>
          <th>UPS</th>
          <th>Temp</th>
          <th>RH</th>
          <th>Power</th>
          <th>On batt</th>
          <th>Down</th>
        </tr>
      </thead>
      <tbody>
      <?php if (!$idfs): ?>
        <tr><td colspan="9" class="muted">No IDFs match.</td></tr>
      <?php endif; ?>
      <?php foreach ($idfs as $row):
          $href = $row['group_id'] ? ba_href('/idfs?group='.(int)$row['group_id']) : ba_href('/idfs');
          $st = $row['worst'] === 'st-ok' ? 'ok' : ($row['worst'] === 'st-batt' ? 'battery' : ($row['worst'] === 'st-down' ? 'down' : ($row['worst'] === 'st-hot' ? 'hot' : 'warn')));
      ?>
        <tr class="<?= h($row['worst']) ?>">
          <td class="pill"><?= h($st) ?></td>
          <td><a href="<?= h($href) ?>"><?= h($row['path'] ?: $row['name']) ?></a></td>
          <td><?= (int)$row['racks'] ?></td>
          <td><?= (int)$row['ups'] ?></td>
          <td><?= $row['temp'] === null ? '—' : h(ba_fmt($row['temp'], '°F')) ?></td>
          <td><?= $row['rh'] === null ? '—' : h(ba_fmt($row['rh'], '%', 0)) ?></td>
          <td><?= $row['power'] === null ? '—' : h(ba_fmt($row['power'], 'W', 0)) ?></td>
          <td><?= (int)$row['batt'] ?></td>
          <td><?= (int)$row['down'] ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php
    ba_layout_end();
}

function page_fleet(PDO $db): void {
    $q = trim($_GET['q'] ?? '');
    $filter = $_GET['f'] ?? '';
    $sql = ba_latest_join();
    $args = [];
    $w = [];
    if ($q !== '') {
        $w[] = '(d.hostname LIKE ? OR d.ip LIKE ? OR d.idf_closet LIKE ? OR d.building LIKE ? OR d.serial LIKE ?)';
        $like = "%$q%";
        $args = array_merge($args, [$like, $like, $like, $like, $like]);
    }
    if ($filter === 'battery') $w[] = 's.on_battery = 1';
    if ($filter === 'hot') $w[] = 's.temp_f IS NOT NULL AND s.temp_f >= 85';
    if ($filter === 'down') $w[] = "p.comm_state IN ('down','degraded')";
    if ($filter === 'sensor') $w[] = 'd.sensor_expected = 1 AND IFNULL(d.sensor_present,0) = 0';
    $w[] = ba_ups_only_sql();
    if ($w) $sql .= ' WHERE ' . implode(' AND ', $w);
    $sql .= ' ORDER BY d.building, d.idf_closet, d.hostname';
    $st = $db->prepare($sql);
    $st->execute($args);
    $rows = $st->fetchAll();
    $open = (int)$db->query("SELECT COUNT(*) FROM alerts WHERE status='open'")->fetchColumn();
    $batt = 0; $hot = 0; $down = 0; $ok = 0;
    foreach ($rows as $r) {
        $cls = ba_worst($r);
        if ($cls === 'st-batt') $batt++;
        elseif ($cls === 'st-hot') $hot++;
        elseif ($cls === 'st-down') $down++;
        elseif ($cls === 'st-ok') $ok++;
    }
    ba_layout_start('UPS fleet', 'fleet');
    ?>
    <h1>UPS fleet</h1>
    <div class="kpis">
      <div class="kpi"><span>UPS units</span><b><?= count($rows) ?></b></div>
      <div class="kpi ok"><span>Online</span><b><?= $ok ?></b></div>
      <div class="kpi crit"><span>On battery</span><b><?= $batt ?></b></div>
      <div class="kpi warn"><span>Climate</span><b><?= $hot ?></b></div>
      <div class="kpi crit"><span>Unreachable</span><b><?= $down ?></b></div>
      <div class="kpi warn"><span>Open alerts</span><b><?= $open ?></b></div>
    </div>
    <form class="filters" method="get">
      <input name="q" value="<?= h($q) ?>" placeholder="search closet, IP, serial">
      <select name="f" onchange="this.form.submit()">
        <option value="">all states</option>
        <option value="battery" <?= $filter==='battery'?'selected':'' ?>>on battery</option>
        <option value="hot" <?= $filter==='hot'?'selected':'' ?>>too hot</option>
        <option value="down" <?= $filter==='down'?'selected':'' ?>>not polled / down</option>
        <option value="sensor" <?= $filter==='sensor'?'selected':'' ?>>sensor missing</option>
      </select>
      <button>Filter</button>
    </form>
    <table>
      <thead><tr><th>State</th><th>Group</th><th>Host</th><th>IP</th><th>UPS</th><th>Cap</th><th>Runtime</th><th>Load</th><th>Power</th><th>Temp</th><th>RH</th><th>Sensor</th><th>Last poll</th></tr></thead>
      <tbody>
      <?php foreach ($rows as $r): $cls = ba_worst($r); ?>
        <tr class="<?= $cls ?>">
          <td class="pill"><?= $cls === 'st-ok' ? 'ok' : ($cls === 'st-batt' ? 'battery' : ($cls === 'st-down' ? 'down' : ($cls === 'st-hot' ? 'hot' : 'warn'))) ?></td>
          <td><a href="/device.php?id=<?= (int)$r['id'] ?>"><?= h($r['group_name'] ?: ($r['building'] . ' / ' . $r['idf_closet'])) ?></a></td>
          <td><?= h($r['hostname']) ?></td>
          <td><?= h($r['ip']) ?></td>
          <td><?= h(ba_output_text(isset($r['output_status']) ? (int)$r['output_status'] : null)) ?></td>
          <td><?= ba_fmt($r['capacity_pct'] ?? null, '%', 0) ?></td>
          <td><?= ba_fmt($r['runtime_min'] ?? null, 'min', 0) ?></td>
          <td><?= ba_fmt($r['load_pct'] ?? null, '%', 0) ?></td>
          <td><?= ba_fmt($r['power_w'] ?? null, 'W', 0) ?></td>
          <td><?php
            if (($r['sensor_present'] ?? $r['sample_sensor'] ?? 0) && $r['temp_f'] === null) echo '<span class="muted">no reading</span>';
            else echo h(ba_fmt($r['temp_f'] ?? null, '°F'));
          ?></td>
          <td><?php
            if (($r['sensor_present'] ?? $r['sample_sensor'] ?? 0) && $r['humidity_pct'] === null) echo '<span class="muted">no reading</span>';
            else echo h(ba_fmt($r['humidity_pct'] ?? null, '%', 0));
          ?></td>
          <td><?php
            $exp = (int)($r['sensor_expected'] ?? 0);
            $presRaw = $r['sensor_present'] ?? $r['sample_sensor'] ?? null;
            if ($presRaw === null || $presRaw === '') {
                echo $exp ? '<span class="muted">not seen yet</span>' : '<span class="muted">not expected</span>';
            } elseif ((int)$presRaw === 1) echo 'attached';
            elseif (!$exp) echo '<span class="muted">not expected</span>';
            else echo '<span class="pill warn">absent</span>';
          ?></td>
          <td class="muted"><?= h($r['last_success'] ?? 'never') ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php
    ba_layout_end();
}

function ba_climate_temp(array $r)
{
    if (array_key_exists('climate_temp', $r) && $r['climate_temp'] !== null && $r['climate_temp'] !== '') {
        return $r['climate_temp'];
    }
    return $r['temp_f'] ?? null;
}

function ba_climate_hum(array $r)
{
    if (array_key_exists('climate_hum', $r) && $r['climate_hum'] !== null && $r['climate_hum'] !== '') {
        return $r['climate_hum'];
    }
    return $r['humidity_pct'] ?? null;
}

function ba_climate_sensor_state(array $r): string
{
    if (ba_climate_temp($r) !== null && ba_climate_temp($r) !== '') {
        return 'present';
    }
    $pres = $r['sensor_present'] ?? null;
    if ($pres === null || $pres === '') {
        $pres = $r['climate_sensor'] ?? null;
    }
    if ($pres === null || $pres === '') {
        $pres = $r['sample_sensor'] ?? null;
    }
    if ($pres === null || $pres === '') {
        return 'unknown';
    }
    return ((int)$pres) === 1 ? 'present' : 'absent';
}

/** One row per IDF. Prefer the UPS that actually has the EnviroSensor. */
function ba_climate_closets(array $rows): array
{
    $groups = [];
    foreach ($rows as $r) {
        $closet = trim((string)($r['idf_closet'] ?? ''));
        $key = $closet === ''
            ? 'device:' . (int)$r['id']
            : strtolower(trim((string)($r['building'] ?? '')) . '|' . $closet);
        $groups[$key][] = $r;
    }
    $out = [];
    foreach ($groups as $members) {
        $best = null;
        $bestScore = -1;
        $anyExpected = false;
        $anyKnown = false;
        foreach ($members as $r) {
            if ((int)($r['sensor_expected'] ?? 0) === 1) {
                $anyExpected = true;
            }
            $state = ba_climate_sensor_state($r);
            if ($state !== 'unknown') {
                $anyKnown = true;
            }
            $score = 0;
            if ($state === 'present') {
                $score += 4;
            }
            if (ba_climate_temp($r) !== null && ba_climate_temp($r) !== '') {
                $score += 2;
            }
            if (ba_climate_hum($r) !== null && ba_climate_hum($r) !== '') {
                $score += 1;
            }
            if ($score > $bestScore) {
                $best = $r;
                $bestScore = $score;
            }
        }
        $out[] = [
            'row' => $best,
            'count' => count($members),
            'expected' => $anyExpected,
            'known' => $anyKnown,
            'score' => $bestScore,
        ];
    }
    usort($out, static function (array $a, array $b): int {
        return ($b['score'] <=> $a['score']) ?: strcasecmp(
            (string)($a['row']['idf_closet'] ?? ''),
            (string)($b['row']['idf_closet'] ?? '')
        );
    });
    return $out;
}

function page_climate(PDO $db): void {
    $rows = $db->query(ba_latest_join() . ' WHERE '.ba_ups_only_sql()." ORDER BY d.building, d.idf_closet, d.hostname")->fetchAll();
    $closets = ba_climate_closets($rows);
    ba_layout_start('Climate', 'climate');
    echo '<h1>Closet climate</h1><p class="muted">One row per IDF. Temperature and humidity come from the UPS that has the ENVIROSENSOR. Other UPS in the same closet are not listed. A closet stays “not seen yet” until a poll actually reads the probe.</p>';
    echo '<table><thead><tr><th>Closet</th><th>Sensor</th><th>Temp</th><th>RH</th><th>Host</th></tr></thead><tbody>';
    foreach ($closets as $c) {
        $r = $c['row'];
        $state = ba_climate_sensor_state($r);
        $temp = ba_climate_temp($r);
        $hum = ba_climate_hum($r);
        $showReading = $state === 'present' || ($temp !== null && $temp !== '');
        echo '<tr class="'.h(ba_worst($r)).'">';
        echo '<td><a href="/device.php?id='.(int)$r['id'].'">'.h(trim((string)$r['building'].' / '.$r['idf_closet'], ' /')).'</a></td>';
        if ($showReading) {
            echo '<td>attached</td>';
            echo '<td>'.($temp === null || $temp === '' ? '<span class="muted">no reading</span>' : h(ba_fmt($temp, '°F'))).'</td>';
            echo '<td>'.($hum === null || $hum === '' ? '<span class="muted">no reading</span>' : h(ba_fmt($hum, '%', 0))).'</td>';
        } elseif (!$c['expected']) {
            echo '<td class="muted">not expected</td><td>—</td><td>—</td>';
        } elseif (!$c['known']) {
            echo '<td class="muted">not seen yet</td><td>—</td><td>—</td>';
        } else {
            echo '<td class="pill warn">absent</td><td>—</td><td>—</td>';
        }
        $host = (string)($r['hostname'] ?: $r['ip']);
        if ($c['count'] > 1 && $showReading) {
            $host .= ' · 1 of ' . (int)$c['count'] . ' UPS';
        }
        echo '<td><a href="/device.php?id='.(int)$r['id'].'">'.h($host).'</a></td></tr>';
    }
    if (!$closets) {
        echo '<tr><td colspan="5" class="muted">No UPS on the schedule.</td></tr>';
    }
    echo '</tbody></table>';
    ba_layout_end();
}

function page_batteries(PDO $db): void {
    $rows = $db->query(ba_latest_join() . ' WHERE '.ba_ups_only_sql()." ORDER BY CASE WHEN s.capacity_pct IS NULL THEN 1 ELSE 0 END, s.capacity_pct ASC, d.last_battery_replacement")->fetchAll();
    ba_layout_start('Batteries', 'batteries');
    echo '<h1>Battery fleet</h1><table><thead><tr><th>Closet</th><th>Host</th><th>Cap</th><th>Runtime</th><th>Status</th><th>Last replaced</th><th>Replace-by</th></tr></thead><tbody>';
    foreach ($rows as $r) {
        echo '<tr class="'.h(ba_worst($r)).'">';
        echo '<td><a href="/device.php?id='.(int)$r['id'].'">'.h($r['idf_closet']).'</a></td>';
        echo '<td>'.h($r['hostname']).'</td>';
        echo '<td>'.h(ba_fmt($r['capacity_pct']??null,'%',0)).'</td>';
        echo '<td>'.h(ba_fmt($r['runtime_min']??null,'min',0)).'</td>';
        echo '<td>'.h(ba_output_text(isset($r['output_status'])?(int)$r['output_status']:null)).'</td>';
        echo '<td>'.h($r['last_battery_replacement'] ?: '—').'</td>';
        echo '<td>'.h($r['warranty_replace_by'] ?: '—').'</td></tr>';
    }
    echo '</tbody></table>';
    ba_layout_end();
}

function page_alerts(PDO $db, array $user): void {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $user['role'] === 'admin') {
        $id = (int)($_POST['id'] ?? 0);
        $act = $_POST['act'] ?? '';
        if ($act === 'ack') {
            $db->prepare("UPDATE alerts SET status='acked', acked_at=datetime('now') WHERE id=? AND status='open'")->execute([$id]);
            ba_audit($db, 'ack_alert', 'alert', (string)$id);
        }
        if ($act === 'clear') {
            $db->prepare("UPDATE alerts SET status='cleared', cleared_at=datetime('now') WHERE id=?")->execute([$id]);
            ba_audit($db, 'clear_alert', 'alert', (string)$id);
        }
        header('Location: ' . ba_href('/alerts'));
        exit;
    }
    $rows = $db->query("SELECT a.*, d.hostname, d.ip, d.idf_closet FROM alerts a JOIN devices d ON d.id=a.device_id WHERE a.status IN ('open','acked') ORDER BY a.opened_at DESC")->fetchAll();
    ba_layout_start('Alerts', 'alerts');
    echo '<h1>Alerts</h1>';
    if (!$rows) echo '<div class="empty">No open alerts.</div>';
    else {
        echo '<table><thead><tr><th>Opened</th><th>Sev</th><th>Closet</th><th>Code</th><th>Message</th><th></th></tr></thead><tbody>';
        foreach ($rows as $r) {
            echo '<tr><td>'.h($r['opened_at']).'</td><td class="pill '.($r['severity']==='crit'?'batt':'warn').'">'.h($r['severity']).'</td>';
            echo '<td><a href="/device.php?id='.(int)$r['device_id'].'">'.h($r['idf_closet']).'</a></td>';
            echo '<td>'.h($r['code']).'</td><td>'.h($r['message']).'</td><td>';
            if ($user['role']==='admin') {
                echo '<form method="post" style="display:inline">';
                echo '<input type="hidden" name="id" value="'.(int)$r['id'].'">';
                if ($r['status']==='open') echo '<button name="act" value="ack">ack</button> ';
                echo '<button name="act" value="clear">clear</button></form>';
            }
            echo '</td></tr>';
        }
        echo '</tbody></table>';
    }
    ba_layout_end();
}

function page_events(PDO $db): void {
    $st = $db->query("SELECT e.*, d.hostname, d.idf_closet FROM events e LEFT JOIN devices d ON d.id=e.device_id ORDER BY e.id DESC LIMIT 300");
    ba_layout_start('Power events', 'events');
    echo '<h1>Power events</h1><table><thead><tr><th>Time</th><th>Closet</th><th>Sev</th><th>Code</th><th>Message</th></tr></thead><tbody>';
    foreach ($st as $r) {
        echo '<tr><td>'.h($r['ts']).'</td><td>'.h($r['idf_closet'] ?: '—').'</td><td>'.h($r['severity']).'</td><td>'.h($r['code']).'</td><td>'.h($r['message']).'</td></tr>';
    }
    echo '</tbody></table>';
    ba_layout_end();
}

function page_devices(PDO $db, array $user): void {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $user['role'] === 'admin') {
        $ip = trim($_POST['ip'] ?? '');
        if (filter_var($ip, FILTER_VALIDATE_IP)) {
            $kind = $_POST['kind'] ?? 'ups';
            if (!in_array($kind, ['ups', 'switch', 'patch_panel', 'other'], true)) $kind = 'ups';
            $db->prepare("INSERT INTO devices (ip, hostname, site, building, idf_closet, rack, circuit, load_notes, sensor_expected, is_simulated, enabled, kind) VALUES (?,?,?,?,?,?,?,?,?,0,1,?)")
                ->execute([
                    $ip, trim($_POST['hostname'] ?? ''), trim($_POST['site'] ?? 'Hospital'),
                    trim($_POST['building'] ?? ''), trim($_POST['idf_closet'] ?? ''),
                    trim($_POST['rack'] ?? ''), trim($_POST['circuit'] ?? ''),
                    trim($_POST['load_notes'] ?? ''), isset($_POST['sensor_expected']) ? 1 : 0,
                    $kind,
                ]);
            ba_audit($db, 'add_device', 'device', $ip);
        }
        header('Location: ' . ba_href('/devices'));
        exit;
    }
    $rows = $db->query("SELECT * FROM devices ORDER BY is_simulated, building, idf_closet")->fetchAll();
    ba_layout_start('Inventory', 'devices');
    echo '<h1>Inventory</h1>';
    if ($user['role']==='admin') {
        echo '<div class="card"><form method="post" class="filters">';
        echo '<input name="ip" placeholder="IP" required>';
        echo '<input name="hostname" placeholder="hostname">';
        echo '<input name="site" value="Hospital" placeholder="site">';
        echo '<input name="building" placeholder="building">';
        echo '<input name="idf_closet" placeholder="IDF/closet">';
        echo '<input name="rack" placeholder="rack label">';
        echo '<select name="kind">'.ba_kind_options('ups').'</select>';
        echo '<input name="circuit" placeholder="circuit">';
        echo '<label class="muted"><input type="checkbox" name="sensor_expected" checked> sensor expected</label>';
        echo '<button>Add device</button></form><p class="muted">SNMPv3 profile comes from secrets.env. Model/serial/firmware auto-fill on next poll.</p></div>';
    }
    echo '<table><thead><tr><th>Kind</th><th>IP</th><th>Host</th><th>Model</th><th>Template</th><th>Serial</th><th>Closet</th><th>Rack / U</th></tr></thead><tbody>';
    $tplNames = [];
    foreach ($db->query('SELECT id, manufacturer, model FROM device_templates') as $t) {
        $tplNames[(int)$t['id']] = trim(($t['manufacturer'] ? $t['manufacturer'].' ' : '').$t['model']);
    }
    foreach ($rows as $r) {
        $u = $r['position_u'] ? ('U'.(int)$r['position_u']) : '';
        echo '<tr><td>'.h(ba_kind_label($r['kind'] ?? 'ups')).'</td>';
        echo '<td><a href="/device.php?id='.(int)$r['id'].'">'.h($r['ip'] ?: '—').'</a></td><td>'.h($r['hostname']).'</td><td>'.h($r['model']).'</td>';
        echo '<td>';
        if (!empty($r['template_id']) && isset($tplNames[(int)$r['template_id']])) {
            echo '<a href="'.h(ba_href('/templates?id='.(int)$r['template_id'])).'">'.h($tplNames[(int)$r['template_id']]).'</a>';
        } else echo '—';
        echo '</td><td>'.h($r['serial']).'</td><td>'.h($r['building'].' / '.$r['idf_closet']).'</td>';
        echo '<td>';
        if (!empty($r['rack_id'])) echo '<a href="'.h(ba_href('/rack?id='.(int)$r['rack_id'])).'">'.h(($r['rack'] ?: 'rack').' '.$u).'</a>';
        else echo h($r['rack'] ?: '—');
        echo '</td></tr>';
    }
    echo '</tbody></table>';
    ba_layout_end();
}

function page_device(PDO $db, array $user): void {
    $id = (int)($_GET['id'] ?? 0);
    $st = $db->prepare(ba_latest_join() . ' WHERE d.id=?');
    $st->execute([$id]);
    $r = $st->fetch();
    if (!$r) { http_response_code(404); echo 'not found'; return; }
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $user['role'] === 'admin') {
        if (isset($_POST['save'])) {
            $newIp = trim($_POST['ip'] ?? $r['ip']);
            if (!filter_var($newIp, FILTER_VALIDATE_IP)) {
                $newIp = $r['ip'];
            }
            $gid = $_POST['group_id'] === '' ? null : (int)$_POST['group_id'];
            $sid = $_POST['snmp_profile_id'] === '' ? null : (int)$_POST['snmp_profile_id'];
            $kind = $_POST['kind'] ?? ($r['kind'] ?? 'ups');
            if (!in_array($kind, ['ups', 'switch', 'patch_panel', 'other'], true)) $kind = 'ups';
            $rid = ($_POST['rack_id'] ?? '') === '' ? null : (int)$_POST['rack_id'];
            $pos = ($_POST['position_u'] ?? '') === '' ? null : (int)$_POST['position_u'];
            $uh = max(1, (int)($_POST['u_height'] ?? 1));
            $face = $_POST['face'] ?? 'both';
            if (!in_array($face, ['front', 'rear', 'both'], true)) $face = 'both';
            $tid = ($_POST['template_id'] ?? '') === '' ? null : (int)$_POST['template_id'];
            $db->prepare("UPDATE devices SET ip=?, hostname=?, site=?, building=?, idf_closet=?, rack=?, circuit=?, load_notes=?, install_date=?, last_battery_replacement=?, warranty_replace_by=?, sensor_expected=?, enabled=?, group_id=?, snmp_profile_id=?, kind=?, rack_id=?, position_u=?, u_height=?, face=?, template_id=? WHERE id=?")
                ->execute([
                    $newIp, trim($_POST['hostname']??''), trim($_POST['site']??''), trim($_POST['building']??''),
                    trim($_POST['idf_closet']??''), trim($_POST['rack']??''), trim($_POST['circuit']??''),
                    trim($_POST['load_notes']??''), $_POST['install_date']?:null, $_POST['last_battery_replacement']?:null,
                    $_POST['warranty_replace_by']?:null, isset($_POST['sensor_expected'])?1:0, isset($_POST['enabled'])?1:0,
                    $gid, $sid, $kind, $rid, $pos, $uh, $face, $tid, $id,
                ]);
            ba_audit($db, 'update_device', 'device', (string)$id, $newIp !== $r['ip'] ? 'ip '.$r['ip'].' -> '.$newIp : null);
        }
        if (isset($_POST['apply_template'])) {
            try {
                $tid = (int)($_POST['template_id'] ?? 0);
                if ($tid < 1) throw new RuntimeException('Choose a template');
                ba_apply_template($db, $id, $tid);
                ba_audit($db, 'apply_template', 'device', (string)$id, (string)$tid);
            } catch (Throwable $e) {
                header('Location: ' . ba_href('/device?id='.$id.'&err='.rawurlencode($e->getMessage())));
                exit;
            }
            header('Location: ' . ba_href('/device?id='.$id));
            exit;
        }
        if (isset($_POST['thresh'])) {
            $db->prepare("DELETE FROM thresholds WHERE scope='device' AND device_id=?")->execute([$id]);
            $db->prepare("INSERT INTO thresholds (scope, device_id, on_battery_minutes, capacity_low, runtime_low_min, temp_high_f, temp_low_f, humidity_high, humidity_low, poll_fail_count) VALUES ('device',?,?,?,?,?,?,?,?,?)")
                ->execute([$id, (int)$_POST['on_battery_minutes'], (int)$_POST['capacity_low'], (int)$_POST['runtime_low_min'],
                    (float)$_POST['temp_high_f'], (float)$_POST['temp_low_f'], (int)$_POST['humidity_high'], (int)$_POST['humidity_low'], (int)$_POST['poll_fail_count']]);
            ba_audit($db, 'update_thresholds', 'device', (string)$id, json_encode($_POST));
        }
        header('Location: ' . ba_href('/device?id='.$id));
        exit;
    }
    $th = $db->prepare("SELECT * FROM thresholds WHERE (scope='device' AND device_id=?) OR scope='global' ORDER BY CASE scope WHEN 'device' THEN 1 ELSE 0 END DESC LIMIT 1");
    $th->execute([$id]);
    $thr = $th->fetch() ?: [];
    ba_layout_start($r['hostname'] ?: $r['ip'], 'devices');
    if (!empty($_GET['err'])) echo '<div class="flash">'.h((string)$_GET['err']).'</div>';
    ?>
    <h1><?= h($r['hostname'] ?: $r['ip']) ?> <span class="muted"><?= h($r['model']) ?> · <?= h($r['serial']) ?> · <?= h($r['mac']) ?></span></h1>
    <div class="kpis">
      <div class="kpi"><span>UPS</span><b><?= h(ba_output_text(isset($r['output_status'])?(int)$r['output_status']:null)) ?></b></div>
      <div class="kpi"><span>Capacity</span><b><?= h(ba_fmt($r['capacity_pct']??null,'%',0)) ?></b></div>
      <div class="kpi"><span>Runtime</span><b><?= h(ba_fmt($r['runtime_min']??null,'min',0)) ?></b></div>
      <div class="kpi"><span>Load</span><b><?= h(ba_fmt($r['load_pct']??null,'%',0)) ?></b></div>
      <div class="kpi"><span>Input</span><b><?= h(ba_fmt($r['input_voltage']??null,'V')) ?></b></div>
      <div class="kpi"><span>Temp</span><b><?php
        $presRaw = $r['sensor_present'] ?? $r['sample_sensor'] ?? null;
        $pres = ($presRaw === null || $presRaw === '') ? null : (int)$presRaw;
        $tempShow = (isset($r['climate_temp']) && $r['climate_temp'] !== null && $r['climate_temp'] !== '') ? $r['climate_temp'] : ($r['temp_f'] ?? null);
        if ($pres === 1 && ($tempShow === null || $tempShow === '')) echo 'n/a';
        elseif ($pres === 0 && $r['sensor_expected']) echo 'absent';
        elseif ($pres === null && $r['sensor_expected']) echo '—';
        else echo h(ba_fmt($tempShow,'°F'));
      ?></b></div>
    </div>
    <p class="muted">Closet <?= h($r['site'].' / '.$r['building'].' / '.$r['idf_closet']) ?>
      <?php if (!empty($r['rack_id'])): ?>
        · <a href="<?= h(ba_href('/rack?id='.(int)$r['rack_id'])) ?>">rack <?= h($r['rack']) ?> U<?= (int)$r['position_u'] ?></a>
      <?php endif; ?>
      · <?= h(ba_kind_label($r['kind'] ?? 'ups')) ?>
      · comm <?= h($r['comm_state'] ?: 'unknown') ?> · last ok <?= h($r['last_success'] ?: 'never') ?> · SNMP name <?= h($r['snmp_name']) ?> · fw <?= h($r['firmware']) ?></p>
    <div id="device-charts" data-id="<?= $id ?>" class="charts">
      <div><div class="legend">Capacity %</div><canvas data-series="capacity" data-color="#3ddc97"></canvas></div>
      <div><div class="legend">Runtime min</div><canvas data-series="runtime" data-color="#5b9fd4"></canvas></div>
      <div><div class="legend">Load %</div><canvas data-series="load" data-color="#f5c542"></canvas></div>
      <div><div class="legend">Input V</div><canvas data-series="vin" data-color="#d7e0ea"></canvas></div>
      <div><div class="legend">Temp °F</div><canvas data-series="temp" data-color="#ff7a45"></canvas></div>
      <div><div class="legend">Humidity %</div><canvas data-series="rh" data-color="#9fd"></canvas></div>
    </div>
    <?php if ($user['role']==='admin'): ?>
    <div class="grid2">
      <form method="post" class="card stack">
        <h3>Inventory</h3>
        <label>IP</label><input name="ip" value="<?= h($r['ip']) ?>">
        <label>Hostname</label><input name="hostname" value="<?= h($r['hostname']) ?>">
        <label>Group</label>
        <select name="group_id"><option value="">(none)</option><?php
          foreach ($db->query('SELECT * FROM groups ORDER BY name') as $g) {
              $sel = ((int)$r['group_id'] === (int)$g['id']) ? ' selected' : '';
              echo '<option value="'.(int)$g['id'].'"'.$sel.'>'.h($g['name']).'</option>';
          }
        ?></select>
        <label>SNMPv3 profile</label>
        <select name="snmp_profile_id"><option value="">(secrets.env default)</option><?php
          foreach ($db->query('SELECT id,name FROM snmp_profiles') as $p) {
              $sel = ((int)$r['snmp_profile_id'] === (int)$p['id']) ? ' selected' : '';
              echo '<option value="'.(int)$p['id'].'"'.$sel.'>'.h($p['name']).'</option>';
          }
        ?></select>
        <label>Site</label><input name="site" value="<?= h($r['site']) ?>">
        <label>Building</label><input name="building" value="<?= h($r['building']) ?>">
        <label>IDF / closet</label><input name="idf_closet" value="<?= h($r['idf_closet']) ?>">
        <label>Device template</label>
        <div class="filters">
          <select name="template_id"><?= ba_template_options($db, isset($r['template_id']) ? (int)$r['template_id'] : null) ?></select>
          <button name="apply_template" value="1">Apply template</button>
        </div>
        <p class="muted">Apply copies manufacturer, model, kind, U height, face, ports, and VA from the template.</p>
        <label>Kind</label><select name="kind"><?= ba_kind_options($r['kind'] ?? 'ups') ?></select>
        <label>Network rack</label>
        <select name="rack_id"><option value="">(not placed)</option><?php
          foreach ($db->query('SELECT r.id, r.name, g.name AS gname FROM racks r JOIN groups g ON g.id=r.group_id ORDER BY g.name, r.sort_order, r.name') as $rk) {
              $sel = ((int)($r['rack_id'] ?? 0) === (int)$rk['id']) ? ' selected' : '';
              echo '<option value="'.(int)$rk['id'].'"'.$sel.'>'.h($rk['gname'].' / '.$rk['name']).'</option>';
          }
        ?></select>
        <label>Bottom U</label><input type="number" name="position_u" min="1" value="<?= h((string)($r['position_u'] ?? '')) ?>">
        <label>Height (U)</label><input type="number" name="u_height" min="1" max="8" value="<?= h((string)($r['u_height'] ?? 1)) ?>">
        <label>Face</label><select name="face"><?php
          foreach (['both'=>'both (full depth)','front'=>'front','rear'=>'rear'] as $fv=>$fl) {
              $sel = (($r['face'] ?? 'both') === $fv) ? ' selected' : '';
              echo '<option value="'.$fv.'"'.$sel.'>'.$fl.'</option>';
          }
        ?></select>
        <label>Rack label (text)</label><input name="rack" value="<?= h($r['rack']) ?>">
        <label>Circuit</label><input name="circuit" value="<?= h($r['circuit']) ?>">
        <label>Load notes</label><textarea name="load_notes"><?= h($r['load_notes']) ?></textarea>
        <label>Install</label><input type="date" name="install_date" value="<?= h($r['install_date']) ?>">
        <label>Last battery replacement</label><input type="date" name="last_battery_replacement" value="<?= h($r['last_battery_replacement']) ?>">
        <label>Warranty / replace-by</label><input type="date" name="warranty_replace_by" value="<?= h($r['warranty_replace_by']) ?>">
        <label><input type="checkbox" name="sensor_expected" <?= $r['sensor_expected']?'checked':'' ?>> sensor expected</label>
        <label><input type="checkbox" name="enabled" <?= $r['enabled']?'checked':'' ?>> enabled</label>
        <button name="save" value="1">Save</button>
      </form>
      <form method="post" class="card stack">
        <h3>Thresholds (this device)</h3>
        <label>On battery longer than (min)</label><input name="on_battery_minutes" type="number" value="<?= h((string)($thr['on_battery_minutes']??5)) ?>">
        <label>Capacity below %</label><input name="capacity_low" type="number" value="<?= h((string)($thr['capacity_low']??30)) ?>">
        <label>Runtime below (min)</label><input name="runtime_low_min" type="number" value="<?= h((string)($thr['runtime_low_min']??15)) ?>">
        <label>Temp high °F</label><input name="temp_high_f" type="number" step="0.1" value="<?= h((string)($thr['temp_high_f']??85)) ?>">
        <label>Temp low °F</label><input name="temp_low_f" type="number" step="0.1" value="<?= h((string)($thr['temp_low_f']??50)) ?>">
        <label>Humidity high %</label><input name="humidity_high" type="number" value="<?= h((string)($thr['humidity_high']??70)) ?>">
        <label>Humidity low %</label><input name="humidity_low" type="number" value="<?= h((string)($thr['humidity_low']??20)) ?>">
        <label>Poll failures</label><input name="poll_fail_count" type="number" value="<?= h((string)($thr['poll_fail_count']??3)) ?>">
        <button name="thresh" value="1">Save thresholds</button>
        <p class="muted">Tighten a threshold against the live reading to fire an alert on the next poll.</p>
      </form>
    </div>
    <?php endif;
    ba_layout_end();
}

function page_admin(PDO $db, array $user): void {
    ba_require_admin();
    $updMsg = '';
    $updFlash = 'ok';
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['updates_save'])) {
        BackAisleUpdate::saveConfig([
            'enabled' => isset($_POST['updates_enabled']),
            'auto_check' => isset($_POST['updates_auto_check']),
            'check_interval_hours' => (int)($_POST['check_interval_hours'] ?? 24),
            'ssl_verify' => isset($_POST['updates_ssl_verify']),
        ]);
        ba_audit($db, 'updates_save', 'system', null);
        header('Location: ' . ba_href('/admin#updates'));
        exit;
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_check'])) {
        try {
            $updStatus = BackAisleUpdate::checkForUpdate(true);
            ba_audit($db, 'update_check', 'system', $updStatus['latest'] ?? null, $updStatus['error'] ?? ($updStatus['source'] ?? ''));
            if (!empty($updStatus['update_available'])) {
                $updMsg = 'Update available: v' . ($updStatus['latest'] ?? '?')
                    . ' (you have v' . ($updStatus['current'] ?? '?') . ').';
                $updFlash = 'ok';
            } elseif (!empty($updStatus['ok'])) {
                $updMsg = 'You are on the latest version (v' . ($updStatus['current'] ?? '?') . ').';
                $updFlash = 'ok';
            } else {
                $updMsg = (string)($updStatus['error'] ?? 'Update check failed.');
                $updFlash = 'err';
            }
        } catch (Throwable $e) {
            $updMsg = $e->getMessage();
            $updStatus = ['ok' => false, 'error' => $e->getMessage()];
            $updFlash = 'err';
        }
        $_SESSION['ba_flash'] = $updMsg;
        $_SESSION['ba_flash_type'] = $updFlash;
        $_SESSION['ba_upd'] = $updStatus;
        header('Location: /admin.php?checked=1');
        exit;
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_apply'])) {
        @ini_set('max_execution_time', '600');
        @set_time_limit(600);
        @ignore_user_abort(true);
        try {
            $res = BackAisleUpdate::applyUpdate(trim((string)($_POST['target_version'] ?? '')) ?: null);
            ba_audit($db, 'update_apply', 'system', $res['version'] ?? null, $res['message'] ?? '');
            $_SESSION['ba_flash'] = $res['message'] ?? 'Updated.';
            $_SESSION['ba_flash_type'] = 'ok';
            $_SESSION['ba_upd'] = [
                'ok' => true,
                'current' => $res['version'] ?? ba_version(),
                'latest' => $res['version'] ?? ba_version(),
                'update_available' => false,
            ];
        } catch (Throwable $e) {
            ba_audit($db, 'update_apply_fail', 'system', null, $e->getMessage());
            $_SESSION['ba_flash'] = $e->getMessage();
            $_SESSION['ba_flash_type'] = 'err';
            $_SESSION['ba_upd'] = ['ok' => false, 'error' => $e->getMessage()];
        }
        header('Location: /admin.php?updated=1');
        exit;
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_backup_now'])) {
        try {
            $b = BackAisleUpdate::createRecoveryBackup();
            ba_audit($db, 'update_backup_now', 'system', null, basename($b['site_package']));
            $updMsg = 'Recovery backup created: '.basename($b['site_package']).' and '.basename($b['code_zip']).'.';
            $updFlash = 'ok';
        } catch (Throwable $e) {
            $updMsg = $e->getMessage();
            $updFlash = 'err';
        }
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['install_ca'])) {
        try {
            $res = BackAisleUpdate::installCaBundle();
            $updMsg = (string)($res['message'] ?? ('CA bundle installed: ' . ($res['path'] ?? '')));
            $updFlash = 'ok';
        } catch (Throwable $e) {
            $updMsg = $e->getMessage();
            $updFlash = 'err';
        }
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['export_site_backup'])) {
        try {
            $path = BackAisleBackup::export([
                'include_audit' => isset($_POST['include_audit']),
                'include_readings' => isset($_POST['include_readings']),
                'encrypt' => isset($_POST['encrypt_backup']),
                'password' => (string)($_POST['backup_password'] ?? ''),
            ]);
            ba_audit($db, 'export_site_backup', 'system', null, basename($path));
            $updMsg = 'Site package written: '.basename($path).' ('.BackAisleBackup::formatBytes((int)filesize($path)).').';
            $updFlash = 'ok';
        } catch (Throwable $e) {
            $updMsg = $e->getMessage();
            $updFlash = 'err';
        }
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['restore_site_backup'])) {
        try {
            $name = (string)($_POST['restore_file'] ?? '');
            $path = BackAisleBackup::safeFile($name);
            if (!$path && !empty($_FILES['restore_upload']['tmp_name'])) {
                $orig = basename((string)($_FILES['restore_upload']['name'] ?? 'upload.zip'));
                $dest = BackAisleBackup::backupDir() . DIRECTORY_SEPARATOR . preg_replace('/[^A-Za-z0-9._-]/', '_', $orig);
                if (!move_uploaded_file($_FILES['restore_upload']['tmp_name'], $dest)) {
                    throw new RuntimeException('Upload failed');
                }
                $path = $dest;
            }
            if (!$path) throw new RuntimeException('Choose a site package to restore.');
            $res = BackAisleBackup::restoreLive($path, [
                'password' => (string)($_POST['restore_password'] ?? ''),
                'create_pre_backup' => isset($_POST['create_pre_backup']),
            ]);
            ba_audit($db, 'restore_site_backup', 'system', null, basename($path));
            $updMsg = $res['message'];
            $updFlash = 'ok';
        } catch (Throwable $e) {
            $updMsg = $e->getMessage();
            $updFlash = 'err';
        }
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['global_thresh'])) {
        $db->prepare("UPDATE thresholds SET on_battery_minutes=?, capacity_low=?, runtime_low_min=?, temp_high_f=?, temp_low_f=?, humidity_high=?, humidity_low=?, poll_fail_count=? WHERE scope='global'")
            ->execute([(int)$_POST['on_battery_minutes'], (int)$_POST['capacity_low'], (int)$_POST['runtime_low_min'],
                (float)$_POST['temp_high_f'], (float)$_POST['temp_low_f'], (int)$_POST['humidity_high'], (int)$_POST['humidity_low'], (int)$_POST['poll_fail_count']]);
        ba_audit($db, 'update_global_thresholds', 'thresholds', 'global');
        header('Location: ' . ba_href('/admin'));
        exit;
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['new_user'])) {
        $hash = password_hash($_POST['password'] ?? '', PASSWORD_DEFAULT);
        $role = ($_POST['role'] ?? '') === 'admin' ? 'admin' : 'viewer';
        $db->prepare('INSERT INTO users (username, password_hash, role) VALUES (?,?,?)')->execute([trim($_POST['username']??''), $hash, $role]);
        ba_audit($db, 'create_user', 'user', $_POST['username'] ?? '');
        header('Location: ' . ba_href('/admin'));
        exit;
    }
    $thr = $db->query("SELECT * FROM thresholds WHERE scope='global'")->fetch() ?: [];
    $users = $db->query('SELECT id, username, role, created_at FROM users ORDER BY username')->fetchAll();
    $audit = $db->query('SELECT * FROM audit_log ORDER BY id DESC LIMIT 80')->fetchAll();
    if (!empty($_SESSION['ba_flash'])) {
        $updMsg = (string)$_SESSION['ba_flash'];
        unset($_SESSION['ba_flash']);
    }
    if (!empty($_SESSION['ba_flash_type'])) {
        $updFlash = (string)$_SESSION['ba_flash_type'];
        unset($_SESSION['ba_flash_type']);
    }
    if (!empty($_SESSION['ba_upd']) && is_array($_SESSION['ba_upd'])) {
        $updStatus = $_SESSION['ba_upd'];
        unset($_SESSION['ba_upd']);
    }
    $updCfg = BackAisleUpdate::config();
    if (!isset($updStatus)) {
        $updStatus = $updCfg['auto_check'] ? BackAisleUpdate::checkForUpdate(false) : BackAisleUpdate::cachedStatus();
    }
    $packages = [];
    try { $packages = BackAisleBackup::listPackages(); } catch (Throwable $e) { $packages = []; }
    $caStatus = BackAisleUpdate::caBundleStatus();
    ba_layout_start('Admin', 'admin');
    if ($updMsg) {
        $flashCls = 'flash';
        if (in_array($updFlash, ['ok', 'info', 'err'], true)) {
            $flashCls .= ' ' . $updFlash;
        }
        echo '<div class="'.$flashCls.'">'.h($updMsg).'</div>';
    }
    ?>
    <div class="grid2">
      <form method="post" class="card stack">
        <h3>Default thresholds</h3>
        <label>On battery minutes</label><input name="on_battery_minutes" type="number" value="<?= h((string)($thr['on_battery_minutes']??5)) ?>">
        <label>Capacity low %</label><input name="capacity_low" type="number" value="<?= h((string)($thr['capacity_low']??30)) ?>">
        <label>Runtime low min</label><input name="runtime_low_min" type="number" value="<?= h((string)($thr['runtime_low_min']??15)) ?>">
        <label>Temp high / low °F</label>
        <input name="temp_high_f" type="number" step="0.1" value="<?= h((string)($thr['temp_high_f']??85)) ?>">
        <input name="temp_low_f" type="number" step="0.1" value="<?= h((string)($thr['temp_low_f']??50)) ?>">
        <label>Humidity high / low</label>
        <input name="humidity_high" type="number" value="<?= h((string)($thr['humidity_high']??70)) ?>">
        <input name="humidity_low" type="number" value="<?= h((string)($thr['humidity_low']??20)) ?>">
        <label>Poll fail count</label><input name="poll_fail_count" type="number" value="<?= h((string)($thr['poll_fail_count']??3)) ?>">
        <button name="global_thresh" value="1">Save defaults</button>
      </form>
      <div class="card">
        <h3>Users</h3>
        <table><?php foreach ($users as $u) echo '<tr><td>'.h($u['username']).'</td><td>'.h($u['role']).'</td></tr>'; ?></table>
        <form method="post" class="stack">
          <label>New user</label><input name="username" required>
          <input type="password" name="password" placeholder="password" required>
          <select name="role"><option>viewer</option><option>admin</option></select>
          <button name="new_user" value="1">Add user</button>
        </form>
      </div>
    </div>
    <div class="card" id="updates">
      <h3>Updates <span class="muted">v<?= h(ba_version()) ?></span></h3>
      <p class="muted">Same flow as ColdAisle: Check for updates, then Update. If GitHub is blocked,
        jsDelivr is used. If the PHP curl extension is off, Check uses Windows curl.exe (same as the
        overlay). Keep <code>extension=curl</code> and <code>extension=openssl</code> in the site
        <code>php.ini</code> (FastCGI <code>-c</code>), not only the global CLI ini.</p>
      <?php if ($updStatus): ?>
        <?php if (!empty($updStatus['update_available'])): ?>
          <div class="flash info">Update available: v<?= h((string)$updStatus['latest']) ?> (you have v<?= h((string)$updStatus['current']) ?>)
            · <a href="<?= h((string)($updStatus['notes_url'] ?? BackAisleUpdate::changelogUrl())) ?>" target="_blank" rel="noopener">Release notes</a>
            <?php if (!empty($updStatus['notes'])): ?><pre class="update-notes"><?= h((string)$updStatus['notes']) ?></pre><?php endif; ?>
          </div>
        <?php elseif (!empty($updStatus['ok'])): ?>
          <div class="flash ok">Up to date (v<?= h((string)$updStatus['current']) ?>).
            <?php if (!empty($updStatus['latest'])): ?> Latest: v<?= h((string)$updStatus['latest']) ?>.<?php endif; ?>
            <?php if (!empty($updStatus['source'])): ?> Source: <?= h((string)$updStatus['source']) ?>.<?php endif; ?>
            <?php if (!empty($updStatus['checked_at'])): ?> Last check: <?= h((string)$updStatus['checked_at']) ?><?= !empty($updStatus['cached']) ? ' (cached)' : '' ?><?php endif; ?>
          </div>
        <?php else: ?>
          <div class="flash"><?= h((string)($updStatus['error'] ?? 'Could not check for updates.')) ?></div>
        <?php endif; ?>
      <?php endif; ?>
      <form method="post" action="/admin.php" class="stack" style="max-width:none">
        <label><input type="checkbox" name="updates_enabled" value="1" <?= !empty($updCfg['enabled'])?'checked':'' ?>> Enable update checks</label>
        <label><input type="checkbox" name="updates_auto_check" value="1" <?= !empty($updCfg['auto_check'])?'checked':'' ?>> Auto-check when opening Admin</label>
        <label>Check interval (hours)</label>
        <input type="number" min="1" max="168" name="check_interval_hours" value="<?= (int)$updCfg['check_interval_hours'] ?>">
        <label><input type="checkbox" name="updates_ssl_verify" value="1" <?= !empty($updCfg['ssl_verify'])?'checked':'' ?>> Verify TLS certificates when contacting GitHub (recommended)</label>
        <p class="muted">Requires a CA certificate list. Status:
          <?php if (!empty($caStatus['found'])): ?>
            OK <code><?= h((string)$caStatus['path']) ?></code>
          <?php else: ?>
            Missing — click <strong>Install CA certificates</strong> below (keeps verify enabled).
          <?php endif; ?>
        </p>
        <button name="updates_save" value="1">Save update settings</button>
      </form>
      <div class="filters" style="margin-top:.8rem">
        <?php $caOk = !empty($caStatus['found']); ?>
        <form method="post" action="/admin.php" <?= $caOk ? 'onsubmit="return false;"' : '' ?>>
          <button type="submit" name="install_ca" value="1" <?= $caOk ? 'disabled' : '' ?>>Install CA certificates</button>
        </form>
        <form method="post" action="/admin.php"><button type="submit" name="update_check" value="1">Check for updates</button></form>
        <form method="post" action="/admin.php" onsubmit="return confirm('Create a recovery backup now? Writes a full site package and an application-files zip. Does not apply an update.');">
          <button type="submit" name="update_backup_now" value="1">Create recovery backup</button>
        </form>
        <?php
          $canApply = $updStatus
              && empty($updStatus['error'])
              && !empty($updStatus['latest'])
              && version_compare((string)$updStatus['latest'], (string)($updStatus['current'] ?? ba_version()), '>');
        ?>
        <?php if ($canApply): ?>
          <form method="post" action="/admin.php" onsubmit="return confirm('Backup this install and update to v<?= h((string)$updStatus['latest']) ?>? The site may be briefly unavailable.');">
            <input type="hidden" name="target_version" value="<?= h((string)$updStatus['latest']) ?>">
            <button type="submit" name="update_apply" value="1">Update to v<?= h((string)$updStatus['latest']) ?></button>
          </form>
        <?php endif; ?>
      </div>
      <p class="muted">PHP zip: <?= extension_loaded('zip') ? 'loaded' : 'missing' ?>. IIS app pool needs Modify on the site folder. Restore inventory from the site package below — the <code>backup_</code> zip is files only.</p>
    </div>

    <div class="card" id="backup">
      <h3>Site backup &amp; migration</h3>
      <p class="muted">Export a portable package of this site (SQLite database, secrets, template pictures).
        Restore on this running site below. Packages do not include IIS bindings or PHP itself.
        Encrypted packages use AES-256-GCM (<code>.baisle</code>); the password is not stored.</p>
      <form method="post" class="stack" style="max-width:none">
        <label><input type="checkbox" name="include_audit" value="1" checked> Include audit log</label>
        <label><input type="checkbox" name="include_readings" value="1" checked> Include sample / event history</label>
        <label><input type="checkbox" name="encrypt_backup" value="1"> Encrypt backup with a password</label>
        <label>Password (if encrypting)</label><input type="password" name="backup_password" autocomplete="new-password">
        <button name="export_site_backup" value="1">Download site backup</button>
      </form>
      <h3>Restore on this site</h3>
      <form method="post" enctype="multipart/form-data" class="stack" style="max-width:none" onsubmit="return confirm('Restore this package onto the running site? A pre-restore backup is created if checked.');">
        <label>Existing package</label>
        <select name="restore_file">
          <option value="">(upload below)</option>
          <?php foreach ($packages as $p): if ($p['kind'] !== 'site') continue; ?>
            <option value="<?= h($p['name']) ?>"><?= h($p['name']) ?> · <?= h(BackAisleBackup::formatBytes($p['bytes'])) ?></option>
          <?php endforeach; ?>
        </select>
        <label>Or upload</label><input type="file" name="restore_upload" accept=".zip,.baisle">
        <label>Password (encrypted packages)</label><input type="password" name="restore_password" autocomplete="off">
        <label><input type="checkbox" name="create_pre_backup" value="1" checked> Create a pre-restore backup first</label>
        <button name="restore_site_backup" value="1">Restore site package</button>
      </form>
      <h3>Files on disk</h3>
      <table>
        <thead><tr><th>File</th><th>Kind</th><th>Size</th><th>When</th><th></th></tr></thead>
        <tbody>
        <?php if (!$packages): ?>
          <tr><td colspan="5" class="muted">No packages yet — create a recovery backup or site backup above.</td></tr>
        <?php endif; ?>
        <?php foreach ($packages as $p): ?>
          <tr>
            <td><code><?= h($p['name']) ?></code></td>
            <td><?= h($p['kind']) ?></td>
            <td><?= h(BackAisleBackup::formatBytes($p['bytes'])) ?></td>
            <td><?= h(date('Y-m-d H:i', $p['mtime'])) ?></td>
            <td><a href="<?= h(ba_href('/admin/backup-download?file='.rawurlencode($p['name']))) ?>">download</a></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <div class="card">
      <h3>Audit</h3>
      <table><thead><tr><th>Time</th><th>User</th><th>Action</th><th>Entity</th><th>Details</th></tr></thead><tbody>
      <?php foreach ($audit as $a) echo '<tr><td>'.h($a['ts']).'</td><td>'.h($a['username']).'</td><td>'.h($a['action']).'</td><td>'.h($a['entity'].' '.$a['entity_id']).'</td><td>'.h($a['details']).'</td></tr>'; ?>
      </tbody></table>
    </div>
    <?php
    ba_layout_end();
}

function page_api_series(PDO $db): void {
    $id = (int)($_GET['id'] ?? 0);
    $st = $db->prepare("SELECT ts, capacity_pct, runtime_min, load_pct, input_voltage, temp_f, humidity_pct FROM samples WHERE device_id=? ORDER BY ts DESC LIMIT 720");
    $st->execute([$id]);
    $rows = array_reverse($st->fetchAll());
    $pack = function (string $k) use ($rows) {
        $out = [];
        foreach ($rows as $r) $out[] = ['t' => $r['ts'], 'v' => $r[$k] === null ? null : (float)$r[$k]];
        return $out;
    };
    header('Content-Type: application/json');
    echo json_encode([
        'capacity' => $pack('capacity_pct'),
        'runtime' => $pack('runtime_min'),
        'load' => $pack('load_pct'),
        'vin' => $pack('input_voltage'),
        'temp' => $pack('temp_f'),
        'rh' => $pack('humidity_pct'),
    ]);
}

function page_api_dashboard(PDO $db): void {
    header('Content-Type: application/json');
    $power = [];
    $temp = [];
    $humid = [];
    foreach ($db->query("SELECT hour_ts, AVG(power_avg) pw, AVG(temp_f_avg) tf, AVG(humidity_avg) rh FROM samples_hourly WHERE hour_ts >= datetime('now','-7 days') GROUP BY hour_ts ORDER BY hour_ts") as $r) {
        $power[] = ['t' => $r['hour_ts'], 'v' => $r['pw'] === null ? null : (float)$r['pw']];
        $temp[] = ['t' => $r['hour_ts'], 'v' => $r['tf'] === null ? null : (float)$r['tf']];
        $humid[] = ['t' => $r['hour_ts'], 'v' => $r['rh'] === null ? null : (float)$r['rh']];
    }
    $powerHas = false;
    foreach ($power as $pt) { if ($pt['v'] !== null) { $powerHas = true; break; } }
    if (!$power || !$powerHas) {
        $power = [];
        $temp = [];
        $humid = [];
        foreach ($db->query("SELECT strftime('%Y-%m-%d %H:00:00', ts) h, AVG(power_w) pw, AVG(temp_f) tf, AVG(humidity_pct) rh FROM samples WHERE ts >= datetime('now','-2 days') GROUP BY h ORDER BY h") as $r) {
            $power[] = ['t' => $r['h'], 'v' => $r['pw'] === null ? null : (float)$r['pw']];
            $temp[] = ['t' => $r['h'], 'v' => $r['tf'] === null ? null : (float)$r['tf']];
            $humid[] = ['t' => $r['h'], 'v' => $r['rh'] === null ? null : (float)$r['rh']];
        }
    }
    $hottest = $db->query("SELECT g.id, g.name, AVG(s.temp_f) t FROM devices d JOIN groups g ON g.id=d.group_id
        JOIN samples s ON s.id = (SELECT id FROM samples WHERE device_id=d.id ORDER BY ts DESC LIMIT 1)
        WHERE s.temp_f IS NOT NULL AND ".ba_ups_only_sql()." GROUP BY g.id, g.name ORDER BY t DESC LIMIT 5")->fetchAll();
    $powerTop = $db->query("SELECT g.id, g.name, AVG(COALESCE(s.power_w, s.load_pct*20.0)) w FROM devices d JOIN groups g ON g.id=d.group_id
        JOIN samples s ON s.id = (SELECT id FROM samples WHERE device_id=d.id ORDER BY ts DESC LIMIT 1)
        WHERE ".ba_ups_only_sql()." GROUP BY g.id, g.name ORDER BY w DESC LIMIT 5")->fetchAll();
    echo json_encode(['power' => $power, 'temp' => $temp, 'humid' => $humid, 'hottest' => $hottest, 'power_top' => $powerTop]);
}

function page_api_health(PDO $db): void {
    header('Content-Type: application/json');
    $n = (int)$db->query('SELECT COUNT(*) FROM devices')->fetchColumn();
    echo json_encode(['ok' => true, 'version' => ba_version(), 'devices' => $n]);
}
