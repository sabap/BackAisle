<?php
declare(strict_types=1);

function ba_file_tail(string $path, int $lines = 8, int $maxBytes = 32768): string
{
    if (!is_file($path) || $maxBytes < 1 || $lines < 1) {
        return '';
    }
    $size = filesize($path);
    if ($size === false || $size < 1) {
        return '';
    }
    $fh = @fopen($path, 'rb');
    if ($fh === false) {
        return '';
    }
    $read = (int)min($size, $maxBytes);
    if ($read > 0 && fseek($fh, -$read, SEEK_END) !== 0) {
        fseek($fh, 0);
        $read = (int)min($size, $maxBytes);
    }
    $raw = $read > 0 ? (string)fread($fh, $read) : '';
    fclose($fh);
    $raw = str_replace("\r\n", "\n", $raw);
    $raw = str_replace("\r", "\n", $raw);
    $parts = explode("\n", trim($raw));
    return implode("\n", array_slice($parts, -$lines));
}

function ba_txt(mixed $v, string $empty = '-'): string
{
    if ($v === null || $v === false || $v === '') {
        return $empty;
    }
    if ($v instanceof DateTimeInterface) {
        return $v->format('Y-m-d H:i:s');
    }
    if (is_bool($v)) {
        return $v ? '1' : '0';
    }
    return (string)$v;
}

/** Do not exec tasklist/schtasks from IIS — FastCGI treats their stderr as HTTP 500. */
function ba_pid_running(int $pid): bool
{
    return $pid > 0;
}

function ba_schtask_info(string $name): array
{
    return [
        'name' => $name,
        'ok' => false,
        'raw' => '',
        'status' => '',
        'next' => '',
        'last' => '',
        'result' => '',
    ];
}

function ba_collector_status(): array
{
    $pidPath = BA_ROOT . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR . 'collector.pid';
    $hbPath = BA_ROOT . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR . 'collector.heartbeat.json';
    $logPath = BA_ROOT . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR . 'collector.log';
    $pid = 0;
    if (is_file($pidPath)) {
        $pid = (int)trim((string)@file_get_contents($pidPath));
    }
    $hb = null;
    if (is_file($hbPath)) {
        $j = json_decode((string)@file_get_contents($hbPath), true);
        if (is_array($j)) {
            $hb = $j;
        }
    }
    $hbAge = null;
    if ($hb && !empty($hb['ts'])) {
        $hbAge = time() - (int)$hb['ts'];
    } elseif (is_file($hbPath)) {
        $hbAge = time() - (int)filemtime($hbPath);
    }
    $fresh = $hbAge !== null && $hbAge <= 120;
    $running = $fresh;
    $logTail = ba_file_tail($logPath, 8, 32768);
    $label = 'Stopped';
    $cls = 'down';
    $detail = 'No collector process. Register BackAisleCollector in Task Scheduler or start collector.py.';
    if ($fresh) {
        $label = 'Running';
        $cls = 'ok';
        $detail = 'Heartbeat ' . (int)$hbAge . 's ago'
            . (isset($hb['live']) ? (', live=' . (int)$hb['live']) : '')
            . (isset($hb['in_flight']) ? (', in_flight=' . (int)$hb['in_flight']) : '') . '.';
    } elseif ($running) {
        $label = 'Process up, heartbeat stale';
        $cls = 'warn';
        $detail = 'PID ' . $pid . ' is running but last heartbeat is '
            . ($hbAge === null ? 'missing' : ((int)$hbAge . 's ago')) . '.';
    } elseif ($hbAge !== null && $hbAge < 600) {
        $label = 'Recently active';
        $cls = 'warn';
        $detail = 'No live PID; last heartbeat ' . (int)$hbAge . 's ago.';
    }
    return [
        'pid' => $pid,
        'running' => $running,
        'fresh' => $fresh,
        'label' => $label,
        'cls' => $cls,
        'detail' => $detail,
        'heartbeat' => $hb,
        'log_tail' => $logTail,
        'collector_task' => ba_schtask_info('BackAisleCollector'),
        'watch_task' => ba_schtask_info('BackAisleCollectorWatch'),
        'writer_task' => ba_schtask_info('BackAisleWriter'),
    ];
}

function ba_poll_devices_now(array $ids): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $i): bool => $i > 0)));
    if (!$ids) {
        throw new RuntimeException('No devices selected.');
    }
    if (count($ids) > 80) {
        throw new RuntimeException('Select 80 devices or fewer per poll.');
    }
    $script = BA_ROOT . DIRECTORY_SEPARATOR . 'collector' . DIRECTORY_SEPARATOR . 'collector.py';
    if (!is_file($script)) {
        throw new RuntimeException('collector.py is missing.');
    }
    $run = ba_python_run([$script, '--once', '--ids', implode(',', $ids)], BA_ROOT);
    $blob = trim($run['stderr'] . "\n" . $run['stdout']);
    if ($run['code'] !== 0) {
        throw new RuntimeException('Poll failed: ' . ($blob !== '' ? $blob : ('exit ' . $run['code'])));
    }
    return ['ids' => $ids, 'output' => $blob, 'code' => $run['code']];
}

function page_snmp(PDO $db, array $user): void
{
    try {
        page_snmp_body($db, $user);
    } catch (Throwable $e) {
        @file_put_contents(
            BA_ROOT . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR . 'php-error.log',
            date('c') . ' SNMP ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine() . "\n",
            FILE_APPEND
        );
        if (!headers_sent()) {
            header('Content-Type: text/html; charset=utf-8');
        }
        echo '<!doctype html><meta charset="utf-8"><pre style="white-space:pre-wrap;padding:24px">';
        echo 'SNMP page error: ' . htmlspecialchars($e->getMessage()) . "\n";
        echo htmlspecialchars($e->getFile() . ':' . $e->getLine());
        echo '</pre>';
    }
}

function page_snmp_body(PDO $db, array $user): void
{
    $admin = ($user['role'] ?? '') === 'admin';
    $msg = '';
    $flashCls = 'flash';
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $admin) {
        $act = (string)($_POST['act'] ?? '');
        try {
            if ($act === 'poll_one') {
                $id = (int)($_POST['id'] ?? 0);
                $r = ba_poll_devices_now([$id]);
                $msg = 'Polled device #' . $id . '.';
                ba_audit($db, 'snmp_poll_one', 'device', (string)$id, $r['output']);
            } elseif ($act === 'poll_selected') {
                $ids = $_POST['ids'] ?? [];
                if (!is_array($ids)) {
                    $ids = [];
                }
                $r = ba_poll_devices_now($ids);
                $msg = 'Polled ' . count($r['ids']) . ' selected device(s).';
                ba_audit($db, 'snmp_poll_selected', 'device', null, json_encode($r['ids']));
            } elseif ($act === 'poll_scheduled') {
                $ids = [];
                foreach ($db->query("SELECT id FROM devices WHERE enabled=1 AND IFNULL(kind,'ups')='ups'") as $row) {
                    $ids[] = (int)$row['id'];
                }
                $r = ba_poll_devices_now($ids);
                $msg = 'Polled ' . count($r['ids']) . ' scheduled UPS.';
                ba_audit($db, 'snmp_poll_scheduled', 'snmp', null, (string)count($r['ids']));
            } elseif ($act === 'schedule_on') {
                $id = (int)($_POST['id'] ?? 0);
                $db->prepare('UPDATE devices SET enabled=1 WHERE id=?')->execute([$id]);
                $msg = 'Device #' . $id . ' added to the polling schedule.';
                ba_audit($db, 'snmp_schedule_on', 'device', (string)$id);
            } elseif ($act === 'schedule_off') {
                $id = (int)($_POST['id'] ?? 0);
                $db->prepare('UPDATE devices SET enabled=0 WHERE id=?')->execute([$id]);
                $msg = 'Device #' . $id . ' removed from the polling schedule.';
                ba_audit($db, 'snmp_schedule_off', 'device', (string)$id);
            } elseif ($act === 'schedule_selected') {
                $ids = $_POST['ids'] ?? [];
                if (!is_array($ids)) {
                    $ids = [];
                }
                $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
                foreach ($ids as $id) {
                    $db->prepare('UPDATE devices SET enabled=1 WHERE id=?')->execute([$id]);
                }
                $msg = 'Added ' . count($ids) . ' device(s) to the polling schedule.';
                ba_audit($db, 'snmp_schedule_selected', 'snmp', null, json_encode($ids));
            } elseif ($act === 'bulk_snmp') {
                $sid = (int)($_POST['snmp_profile_id'] ?? 0);
                $scope = (string)($_POST['scope'] ?? 'selected');
                $gid = ($_POST['group_id'] ?? '') === '' ? null : (int)$_POST['group_id'];
                $ids = $_POST['ids'] ?? [];
                if (!is_array($ids)) {
                    $ids = [];
                }
                $n = ba_assign_snmp_profile($db, $sid, $scope, $ids, $gid);
                ba_audit($db, 'bulk_snmp', 'snmp_profile', (string)$sid, 'scope='.$scope.' devices='.$n);
                $msg = 'Assigned SNMPv3 profile to '.(int)$n.' UPS (database only; card slots unchanged)';
            } elseif ($act === 'push_snmpv3') {
                if (!function_exists('ba_create_job')) {
                    throw new RuntimeException('Writer helpers missing');
                }
                $simulate = isset($_POST['simulate']);
                if (!$simulate && trim((string)($_POST['confirm'] ?? '')) !== 'PUSH SNMPV3') {
                    throw new RuntimeException('Type PUSH SNMPV3 to write the RMCARD, or tick Simulate to preview slots only.');
                }
                $sid = (int)($_POST['snmp_profile_id'] ?? 0);
                if ($sid < 1) {
                    throw new RuntimeException('Choose an SNMPv3 profile');
                }
                $scope = (string)($_POST['scope'] ?? 'selected');
                $gid = ($_POST['group_id'] ?? '') === '' ? null : (int)$_POST['group_id'];
                $ids = $_POST['ids'] ?? [];
                if (!is_array($ids)) {
                    $ids = [];
                }
                $ids = ba_ups_ids_for_scope($db, $scope, $ids, $gid);
                $nms = trim((string)($_POST['nms_ip'] ?? ''));
                $acl = (string)($_POST['acl_mode'] ?? 'keep');
                $jid = ba_create_job($db, 'push_snmpv3', $ids, [
                    'snmp_profile_id' => $sid,
                    'acl_mode' => $acl,
                    'nms_ip' => $nms,
                ], (string)$user['username'], $simulate, false);
                $msg = ($simulate ? 'Simulate' : 'Push') . " SNMPv3 job #$jid queued (" . count($ids) . ' UPS). Watch Fleet writes. Empty slots only; matching username updates that slot; four occupied slots with other users are skipped.';
            }
            $_SESSION['ba_flash'] = $msg;
            header('Location: /snmp.php');
            exit;
        } catch (Throwable $e) {
            $msg = $e->getMessage();
        }
    }
    if ($msg === '' && !empty($_SESSION['ba_flash'])) {
        $msg = (string)$_SESSION['ba_flash'];
        unset($_SESSION['ba_flash']);
    }

    try {
        $st = ba_collector_status();
    } catch (Throwable $e) {
        $st = [
            'pid' => 0, 'running' => false, 'fresh' => false, 'label' => 'Unknown', 'cls' => 'warn',
            'detail' => $e->getMessage(), 'heartbeat' => null, 'log_tail' => '',
            'collector_task' => ba_schtask_info('BackAisleCollector'),
            'watch_task' => ba_schtask_info('BackAisleCollectorWatch'),
            'writer_task' => ba_schtask_info('BackAisleWriter'),
        ];
        $msg = trim($msg . ' Poller status: ' . $e->getMessage());
    }
    $scheduled = [];
    $unscheduled = [];
    try {
        $on = ba_db_driver() === 'sqlsrv'
            ? 'WHERE ISNULL(d.enabled,0)=1'
            : 'WHERE IFNULL(d.enabled,0)=1';
        $off = ba_db_driver() === 'sqlsrv'
            ? "WHERE ISNULL(d.enabled,0)=0 AND ISNULL(d.kind,'ups')='ups'"
            : "WHERE IFNULL(d.enabled,0)=0 AND IFNULL(d.kind,'ups')='ups'";
        $scheduled = $db->query(
            "SELECT d.id, d.ip, d.hostname, d.kind, d.enabled, d.snmp_profile_id, sp.name AS profile_name,
                    ps.last_success, ps.last_attempt, ps.comm_state, ps.consecutive_failures,
                    NULL AS capacity_pct, NULL AS temp_f, NULL AS load_pct
             FROM devices d
             LEFT JOIN snmp_profiles sp ON sp.id=d.snmp_profile_id
             LEFT JOIN poll_state ps ON ps.device_id=d.id
             $on
             ORDER BY d.hostname, d.ip"
        )->fetchAll();
        $unscheduled = $db->query(
            "SELECT d.id, d.ip, d.hostname, d.kind, d.snmp_profile_id, sp.name AS profile_name, ps.last_success, ps.comm_state
             FROM devices d
             LEFT JOIN snmp_profiles sp ON sp.id=d.snmp_profile_id
             LEFT JOIN poll_state ps ON ps.device_id=d.id
             $off
             ORDER BY d.hostname, d.ip"
        )->fetchAll();
    } catch (Throwable $e) {
        $msg = trim($msg . ' Device list failed: ' . $e->getMessage());
        try {
            $scheduled = $db->query('SELECT id, ip, hostname, kind, enabled, snmp_profile_id FROM devices')->fetchAll();
        } catch (Throwable $e2) {
            $msg = trim($msg . ' | ' . $e2->getMessage());
        }
    }

    ba_layout_start('SNMP', 'snmp');
    if ($msg) {
        echo '<div class="flash">'.h($msg).'</div>';
    }
    echo '<h1>SNMP polling</h1>';
    echo '<p class="muted">SNMPv3 authPriv collector. Scheduled devices have <code>enabled=1</code>. Manual poll talks to the unit now, even if it is not on the schedule.</p>';

    if ($admin) {
        $profiles = [];
        try {
            $profiles = $db->query('SELECT id, name FROM snmp_profiles ORDER BY name')->fetchAll();
        } catch (Throwable $e) {
            $profiles = [];
        }
        $groupOpts = function_exists('ba_group_options') ? ba_group_options(ba_groups($db)) : '';
        echo '<form method="post" action="/snmp.php" class="card stack" id="snmp-bulk">';
        echo '<h3>Bulk assign SNMPv3 profile</h3>';
        echo '<input type="hidden" name="act" value="bulk_snmp">';
        echo '<p class="muted">Applies the credential profile to UPS records (authPriv secrets stay in ProgramData). Tick rows below for Selected, or choose All UPS / On schedule / IDF group.</p>';
        echo '<label>Profile</label><select name="snmp_profile_id" required><option value="">choose</option>';
        foreach ($profiles as $p) {
            echo '<option value="'.(int)$p['id'].'">'.h((string)$p['name']).'</option>';
        }
        echo '</select>';
        echo '<label>Apply to</label><select name="scope">';
        echo '<option value="selected">Selected UPS (checkboxes below)</option>';
        echo '<option value="scheduled">All UPS on the poll schedule</option>';
        echo '<option value="all">All UPS</option>';
        echo '<option value="group">IDF group (and subgroups)</option>';
        echo '</select>';
        echo '<label>IDF group</label><select name="group_id"><option value="">(for IDF group scope)</option>'.$groupOpts.'</select>';
        echo '<button>Assign in BackAisle only</button></form>';
        echo '<form method="post" action="/snmp.php" class="card stack" id="snmp-pushv3">';
        echo '<h3>Write SNMPv3 slot on the RMCARD</h3>';
        echo '<input type="hidden" name="act" value="push_snmpv3">';
        echo '<p class="muted">CyberPower has <strong>4 SNMPv3 slots</strong>. This pulls the live config, then: if this username already exists, that slot is updated; else the first <em>empty</em> slot is used; if all four have other users, the unit is <strong>skipped</strong> (never overwritten). Each slot has one ACL IP/mask. Identity (card IP/hostname) is not changed. Requires the <strong>BackAisleWriter</strong> task. Config/firmware mass writes stay lab-gated; this SNMPv3 slot write does not.</p>';
        echo '<label>Profile</label><select name="snmp_profile_id" required><option value="">choose</option>';
        foreach ($profiles as $p) {
            echo '<option value="'.(int)$p['id'].'">'.h((string)$p['name']).'</option>';
        }
        echo '</select>';
        echo '<label>Apply to</label><select name="scope">';
        echo '<option value="selected">Selected UPS</option>';
        echo '<option value="scheduled">On poll schedule</option>';
        echo '<option value="all">All UPS</option>';
        echo '<option value="group">IDF group</option>';
        echo '</select>';
        echo '<label>IDF group</label><select name="group_id"><option value="">(for IDF group)</option>'.$groupOpts.'</select>';
        echo '<label>ACL / NMS IP on a new empty slot</label>';
        echo '<input name="nms_ip" placeholder="collector IPv4 or 192.168.20.255" value="'.h((string)($_SERVER['SERVER_ADDR'] ?? '')).'">';
        echo '<label>If the username already exists on the card</label><select name="acl_mode">';
        echo '<option value="keep">Keep the existing ACL IP (recommended)</option>';
        echo '<option value="nms">Replace ACL with the NMS IP above</option>';
        echo '</select>';
        echo '<label><input type="checkbox" name="simulate" value="1" checked> Simulate (pull and choose slot, do not restore)</label>';
        echo '<label>Type PUSH SNMPV3 to write (leave blank if simulating)</label>';
        echo '<input name="confirm" autocomplete="off">';
        echo '<button>Queue SNMPv3 write</button></form>';
        echo '<script>(function(){function wire(id){var f=document.getElementById(id);if(!f)return;f.addEventListener("submit",function(){document.querySelectorAll(".snmp-id:checked,.snmp-unsched:checked").forEach(function(c){var i=document.createElement("input");i.type="hidden";i.name="ids[]";i.value=c.value;f.appendChild(i);});});}wire("snmp-bulk");wire("snmp-pushv3");})();</script>';
    }

    echo '<div class="grid2">';
    echo '<div class="card"><h3>Poller</h3>';
    echo '<p><span class="pill '.$st['cls'].'">'.h(ba_txt($st['label'], '')).'</span> PID '.((int)$st['pid'] ?: '-').'</p>';
    echo '<p class="muted">'.h(ba_txt($st['detail'], '')).'</p>';
    if (!empty($st['heartbeat']['at'])) {
        echo '<p class="muted">Heartbeat at '.h((string)$st['heartbeat']['at']).' UTC</p>';
    }
    echo '</div>';
    echo '<div class="card"><h3>Windows tasks</h3><table><thead><tr><th>Task</th><th>Status</th><th>Last run</th><th>Next</th></tr></thead><tbody>';
    foreach (['collector_task' => 'BackAisleCollector', 'watch_task' => 'Watch', 'writer_task' => 'Writer'] as $k => $lab) {
        $t = $st[$k];
        echo '<tr><td>'.h($lab).'</td><td>'.h($t['ok'] ? ($t['status'] ?: 'registered') : 'not readable / missing').'</td>';
        echo '<td>'.h($t['last'] ?: '-').'</td><td>'.h($t['next'] ?: '-').'</td></tr>';
    }
    echo '</tbody></table>';
    if (!$st['collector_task']['ok']) {
        echo '<p class="muted">IIS often cannot query Task Scheduler. If the poller pill is green, the worker is running. To register tasks, run <code>scripts\\Register-BackAisle-CollectorTask.ps1</code> as Administrator.</p>';
    }
    echo '</div></div>';

    if ($st['log_tail'] !== '') {
        echo '<div class="card"><h3>Collector log (tail)</h3><pre class="update-notes">'.h($st['log_tail']).'</pre></div>';
    }

    if ($admin) {
        echo '<form method="post" action="/snmp.php" class="card" id="snmp-sched-form">';
        echo '<div class="dash-list-head"><h2>Scheduled polling</h2><div>';
        echo '<button type="submit" name="act" value="poll_selected">Poll selected</button> ';
        echo '<button type="submit" name="act" value="poll_scheduled">Poll all scheduled</button>';
        echo '</div></div>';
        echo '<p class="muted">'.count($scheduled).' device(s) on the schedule (collector ~60s status / 5 min climate).</p>';
        echo '<table><thead><tr><th><input type="checkbox" id="snmp-check-all"></th><th>Host</th><th>IP</th><th>Kind</th><th>Profile</th><th>Last OK</th><th>State</th><th></th></tr></thead><tbody>';
        foreach ($scheduled as $d) {
            $id = (int)$d['id'];
            echo '<tr>';
            echo '<td><input type="checkbox" name="ids[]" value="'.$id.'" class="snmp-id" form="snmp-sched-form"></td>';
            echo '<td><a href="'.h(ba_href('/device?id='.$id)).'">'.h(ba_txt($d['hostname'] ?: $d['ip'], '')).'</a></td>';
            echo '<td>'.h(ba_txt($d['ip'])).'</td><td>'.h(ba_txt($d['kind'], 'ups')).'</td>';
            echo '<td>'.h(ba_txt($d['profile_name'] ?? null)).'</td>';
            echo '<td>'.h(ba_txt($d['last_success'] ?? null)).'</td>';
            echo '<td><span class="pill">'.h(ba_txt($d['comm_state'] ?? null)).'</span>';
            if (($d['capacity_pct'] ?? null) !== null) {
                echo ' '.h((string)(int)$d['capacity_pct']).'%';
            }
            echo '</td><td style="white-space:nowrap">';
            echo '<button type="submit" form="snmp-one-'.$id.'" name="act" value="poll_one">Poll</button> ';
            echo '<button type="submit" form="snmp-off-'.$id.'" name="act" value="schedule_off">Remove</button>';
            echo '</td></tr>';
        }
        if (!$scheduled) {
            echo '<tr><td colspan="8" class="muted">Nothing scheduled. Add a UPS below or enable it on the device page.</td></tr>';
        }
        echo '</tbody></table></form>';
        foreach ($scheduled as $d) {
            $id = (int)$d['id'];
            echo '<form method="post" action="/snmp.php" id="snmp-one-'.$id.'"><input type="hidden" name="act" value="poll_one"><input type="hidden" name="id" value="'.$id.'"></form>';
            echo '<form method="post" action="/snmp.php" id="snmp-off-'.$id.'"><input type="hidden" name="act" value="schedule_off"><input type="hidden" name="id" value="'.$id.'"></form>';
        }

        echo '<form method="post" action="/snmp.php" class="card" id="snmp-unsched-form">';
        echo '<div class="dash-list-head"><h2>Not on schedule</h2><div>';
        echo '<button type="submit" name="act" value="schedule_selected">Add selected to schedule</button> ';
        echo '<button type="submit" name="act" value="poll_selected">Poll selected</button>';
        echo '</div></div>';
        echo '<p class="muted">UPS with enabled=0. Add them so the collector includes them every cycle.</p>';
        echo '<table><thead><tr><th><input type="checkbox" id="snmp-check-unsched"></th><th>Host</th><th>IP</th><th>Profile</th><th>Last</th><th></th></tr></thead><tbody>';
        foreach ($unscheduled as $d) {
            $id = (int)$d['id'];
            echo '<tr>';
            echo '<td><input type="checkbox" name="ids[]" value="'.$id.'" class="snmp-unsched" form="snmp-unsched-form"></td>';
            echo '<td><a href="'.h(ba_href('/device?id='.$id)).'">'.h(ba_txt($d['hostname'] ?: $d['ip'], '')).'</a></td>';
            echo '<td>'.h(ba_txt($d['ip'])).'</td><td>'.h(ba_txt($d['profile_name'])).'</td><td>'.h(ba_txt($d['last_success'])).'</td>';
            echo '<td><button type="submit" form="snmp-on-'.$id.'" name="act" value="schedule_on">Add to schedule</button></td>';
            echo '</tr>';
        }
        if (!$unscheduled) {
            echo '<tr><td colspan="6" class="muted">Every UPS is on the schedule.</td></tr>';
        }
        echo '</tbody></table></form>';
        foreach ($unscheduled as $d) {
            $id = (int)$d['id'];
            echo '<form method="post" action="/snmp.php" id="snmp-on-'.$id.'"><input type="hidden" name="act" value="schedule_on"><input type="hidden" name="id" value="'.$id.'"></form>';
        }
        echo '<script>
        (function(){
          function bind(allId, cls){
            var all = document.getElementById(allId);
            if(!all) return;
            all.addEventListener("change", function(){
              document.querySelectorAll("."+cls).forEach(function(c){ c.checked = all.checked; });
            });
          }
          bind("snmp-check-all","snmp-id");
          bind("snmp-check-unsched","snmp-unsched");
        })();
        </script>';
    } else {
        echo '<div class="card"><p class="muted">Viewer: schedule is read-only. Ask an admin to poll or change membership.</p>';
        echo '<table><thead><tr><th>Host</th><th>IP</th><th>Last OK</th><th>State</th></tr></thead><tbody>';
        foreach ($scheduled as $d) {
            echo '<tr><td>'.h(ba_txt($d['hostname'] ?: $d['ip'], '')).'</td><td>'.h(ba_txt($d['ip'])).'</td><td>'.h(ba_txt($d['last_success'])).'</td><td>'.h(ba_txt($d['comm_state'])).'</td></tr>';
        }
        echo '</tbody></table></div>';
    }
    ba_layout_end();
}
