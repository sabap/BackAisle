<?php
declare(strict_types=1);

const BA_CONFIG_DIR = 'C:\\ProgramData\\BackAisle\\configs';
const BA_FIRMWARE_DIR = 'C:\\ProgramData\\BackAisle\\firmware';

function ba_lab_ip(): string {
    return trim((string)(ba_secrets()['UPS_HOST'] ?? ''));
}

function ba_allow_multi(): bool {
    $s = ba_secrets();
    $v = strtolower(trim($s['AllowMultiWrite'] ?? $s['ALLOW_MULTI_WRITE'] ?? ''));
    return in_array($v, ['1', 'true', 'yes', 'on'], true);
}

function ba_writable_devices(PDO $db): array {
    if (ba_allow_multi()) {
        return $db->query("SELECT id, ip, hostname, idf_closet, firmware, snmp_name FROM devices WHERE enabled=1 AND is_simulated=0 AND IFNULL(kind,'ups')='ups' ORDER BY hostname")->fetchAll();
    }
    $lab = ba_lab_ip();
    if ($lab === '') {
        return [];
    }
    $st = $db->prepare("SELECT id, ip, hostname, idf_closet, firmware, snmp_name FROM devices WHERE ip=? AND enabled=1");
    $st->execute([$lab]);
    return $st->fetchAll();
}

function ba_redact_config(string $text): string {
    $out = [];
    foreach (preg_split("/\r\n|\n|\r/", $text) as $line) {
        if (preg_match('/(password|passwd|passphrase|secret|community|authpass|privpass|auth_pass|priv_pass)/i', $line)) {
            $out[] = preg_replace('/([=:,]).*/', '$1********', $line, 1);
        } else {
            $out[] = $line;
        }
    }
    return implode("\n", $out);
}

function ba_all_ups_targets(PDO $db): array {
    return $db->query("SELECT id, ip, hostname, idf_closet, firmware, snmp_name FROM devices WHERE is_simulated=0 AND IFNULL(kind,'ups')='ups' ORDER BY hostname")->fetchAll();
}

function ba_create_job(PDO $db, string $kind, array $deviceIds, array $extra, string $user, bool $simulate = false, bool $labOnly = true): int {
    $allowed = $labOnly ? ba_writable_devices($db) : ba_all_ups_targets($db);
    $byId = [];
    foreach ($allowed as $d) {
        $byId[(int)$d['id']] = $d;
    }
    $targets = [];
    foreach ($deviceIds as $id) {
        $id = (int)$id;
        if (!isset($byId[$id])) {
            throw new RuntimeException('Target not allowed (lab-only until AllowMultiWrite=true)');
        }
        $targets[] = $byId[$id];
    }
    if (!$targets) {
        throw new RuntimeException('No targets');
    }
    $stop = ($kind === 'push_snmpv3') ? 0 : 1;
    $db->prepare("INSERT INTO write_jobs (kind, status, simulate, stop_on_error, created_by, payload_json, template_id, firmware_id) VALUES (?,?,?,?,?,?,?,?)")
        ->execute([
            $kind, 'queued', $simulate ? 1 : 0, $stop, $user,
            json_encode($extra, JSON_UNESCAPED_SLASHES),
            $extra['template_id'] ?? null,
            $extra['firmware_id'] ?? null,
        ]);
    $jid = ba_last_id($db);
    if ($jid < 1) {
        throw new RuntimeException('Could not get write job id from SQL Server (@@IDENTITY).');
    }
    $ins = $db->prepare("INSERT INTO write_job_targets (job_id, device_id, ip, hostname, status) VALUES (?,?,?,?, 'queued')");
    foreach ($targets as $t) {
        $ins->execute([$jid, $t['id'], $t['ip'], $t['hostname']]);
    }
    ba_audit($db, 'write_job', $kind, (string)$jid, json_encode(['targets' => array_column($targets, 'ip'), 'simulate' => $simulate]));
    return $jid;
}

/** Stop a queued or running job. Units already ok or fail are left as they are. */
function ba_cancel_write_job(PDO $db, int $id, string $by): array
{
    $st = $db->prepare('SELECT id, kind, status FROM write_jobs WHERE id=?');
    $st->execute([$id]);
    $job = $st->fetch();
    if (!$job) {
        return ['ok' => false, 'message' => 'Job not found'];
    }
    $status = (string)$job['status'];
    if (!in_array($status, ['queued', 'running'], true)) {
        return ['ok' => false, 'message' => 'Job '.$id.' is '.$status.' (not running)'];
    }
    $now = gmdate('Y-m-d H:i:s');
    $note = 'stopped by '.$by;
    $db->prepare("UPDATE write_job_targets SET status='cancelled', ended_at=?, error=? WHERE job_id=? AND status IN ('queued','running')")
        ->execute([$now, $note, $id]);
    $db->prepare("UPDATE write_jobs SET status='cancelled', ended_at=?, error=? WHERE id=? AND status IN ('queued','running')")
        ->execute([$now, $note, $id]);
    $dir = BA_ROOT . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'tmp';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    @file_put_contents($dir . DIRECTORY_SEPARATOR . 'cancel_job_' . $id . '.flag', $now);
    if (function_exists('ba_audit')) {
        ba_audit($db, 'write_job_cancel', (string)$job['kind'], (string)$id, $note);
    }
    return ['ok' => true, 'message' => 'Job '.$id.' cancelled. Units already ok or fail were left unchanged.'];
}

function page_fleet_writes(PDO $db, array $user): void {
    ba_require_admin();
    $msg = '';
    $devices = ba_writable_devices($db);
    $templates = $db->query("SELECT * FROM config_templates ORDER BY id DESC")->fetchAll();
    $images = $db->query("SELECT * FROM firmware_images ORDER BY id DESC")->fetchAll();
    $jobs = $db->query("SELECT * FROM write_jobs ORDER BY id DESC LIMIT 30")->fetchAll();

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        try {
            $act = $_POST['act'] ?? '';
            if ($act === 'pull') {
                $id = (int)($_POST['source_id'] ?? 0);
                $name = trim($_POST['template_name'] ?? '') ?: ('lab-' . date('Ymd_His'));
                $jid = ba_create_job($db, 'pull_config', [$id], ['device_id' => $id, 'template_name' => $name], $user['username']);
                $msg = "Pull job #$jid queued";
            } elseif ($act === 'push_config') {
                if (trim($_POST['confirm'] ?? '') !== 'PUSH CONFIG') {
                    throw new RuntimeException('Type PUSH CONFIG to confirm');
                }
                $tid = (int)$_POST['template_id'];
                $ids = array_map('intval', (array)($_POST['targets'] ?? []));
                $overlays = [];
                if (trim($_POST['location'] ?? '') !== '') {
                    $overlays['Location'] = trim($_POST['location']);
                    $overlays['sysLocation'] = trim($_POST['location']);
                }
                $jid = ba_create_job($db, 'push_config', $ids, [
                    'template_id' => $tid,
                    'overlays' => $overlays,
                    'identity_maps' => [],
                ], $user['username'], false);
                $db->prepare('UPDATE write_jobs SET template_id=? WHERE id=?')->execute([$tid, $jid]);
                $msg = "Push config job #$jid queued (one card at a time)";
            } elseif ($act === 'mass_edit') {
                if (trim($_POST['confirm'] ?? '') !== 'PUSH CONFIG') {
                    throw new RuntimeException('Type PUSH CONFIG to confirm');
                }
                $ids = array_map('intval', (array)($_POST['targets'] ?? []));
                $sets = [];
                if (trim($_POST['sysLocation'] ?? '') !== '') $sets['sysLocation'] = trim($_POST['sysLocation']);
                if (trim($_POST['upsName'] ?? '') !== '') $sets['upsName'] = trim($_POST['upsName']);
                if (trim($_POST['envirTempHigh'] ?? '') !== '') $sets['envirTempHigh'] = (int)$_POST['envirTempHigh'];
                if (trim($_POST['envirTempLow'] ?? '') !== '') $sets['envirTempLow'] = (int)$_POST['envirTempLow'];
                if (trim($_POST['envirHumidHigh'] ?? '') !== '') $sets['envirHumidHigh'] = (int)$_POST['envirHumidHigh'];
                if (trim($_POST['envirHumidLow'] ?? '') !== '') $sets['envirHumidLow'] = (int)$_POST['envirHumidLow'];
                if (!$sets) throw new RuntimeException('No allow-listed fields to set');
                $jid = ba_create_job($db, 'mass_edit', $ids, ['snmp_set' => $sets], $user['username']);
                $msg = "Mass edit job #$jid queued";
            } elseif ($act === 'upload_fw') {
                if (empty($_FILES['fw']['tmp_name']) || empty($_FILES['data']['tmp_name'])) {
                    throw new RuntimeException('Need both cpsrm2scfw_XXX.bin and cpsrm2scdata_XXX.bin');
                }
                $fwName = $_FILES['fw']['name'];
                $dataName = $_FILES['data']['name'];
                if (!preg_match('/^cpsrm2scfw_.*\.bin$/i', $fwName) || !preg_match('/^cpsrm2scdata_.*\.bin$/i', $dataName)) {
                    throw new RuntimeException('Filenames must be cpsrm2scfw_XXX.bin and cpsrm2scdata_XXX.bin');
                }
                if (!is_dir(BA_FIRMWARE_DIR)) mkdir(BA_FIRMWARE_DIR, 0770, true);
                $fwDest = BA_FIRMWARE_DIR . DIRECTORY_SEPARATOR . preg_replace('/[^A-Za-z0-9._-]/', '_', $fwName);
                $dataDest = BA_FIRMWARE_DIR . DIRECTORY_SEPARATOR . preg_replace('/[^A-Za-z0-9._-]/', '_', $dataName);
                if (!move_uploaded_file($_FILES['fw']['tmp_name'], $fwDest) || !move_uploaded_file($_FILES['data']['tmp_name'], $dataDest)) {
                    throw new RuntimeException('Failed to store firmware outside web root');
                }
                $ver = trim($_POST['version'] ?? '') ?: $fwName;
                $db->prepare('INSERT INTO firmware_images (version, fw_path, data_path, uploaded_at, uploaded_by) VALUES (?,?,?,?,?)')
                    ->execute([$ver, $fwDest, $dataDest, gmdate('Y-m-d H:i:s'), $user['username']]);
                ba_audit($db, 'firmware_upload', 'firmware', $ver, $fwName . ' + ' . $dataName);
                $msg = 'Firmware pair stored under ProgramData (not downloadable)';
            } elseif ($act === 'push_fw') {
                if (trim($_POST['confirm'] ?? '') !== 'PUSH FIRMWARE') {
                    throw new RuntimeException('Type PUSH FIRMWARE to confirm');
                }
                $fid = (int)$_POST['firmware_id'];
                $ids = array_map('intval', (array)($_POST['targets'] ?? []));
                $sim = isset($_POST['simulate']);
                $jid = ba_create_job($db, 'firmware', $ids, ['firmware_id' => $fid], $user['username'], $sim);
                $db->prepare('UPDATE write_jobs SET firmware_id=? WHERE id=?')->execute([$fid, $jid]);
                $msg = $sim ? "Firmware SIMULATE job #$jid queued (no STOR)" : "Firmware job #$jid queued";
            }
        } catch (Throwable $e) {
            $msg = 'Error: ' . $e->getMessage();
        }
    }

    ba_layout_start('Fleet writes', 'writes');
    if ($msg) echo '<div class="flash">'.h($msg).'</div>';
    $multi = ba_allow_multi();
    ?>
    <h1>Fleet config &amp; firmware</h1>
    <p class="muted">Admin only. Writes go one card at a time. Default target is UPS_HOST from secrets.env<?= ba_lab_ip() !== '' ? ' ('.h(ba_lab_ip()).')' : '' ?>.
      <?= $multi ? 'AllowMultiWrite is ON.' : 'Multi-select is disabled until AllowMultiWrite=true in secrets.env.' ?>
      Config files live in C:\ProgramData\BackAisle\configs (never served by IIS). Firmware bins in C:\ProgramData\BackAisle\firmware.
      Do not power off the UPS during RMCARD firmware. UPS-body firmware is out of scope.</p>

    <div class="grid2">
      <form method="post" class="card stack">
        <h3>1. Clone config</h3>
        <label>Source</label>
        <select name="source_id"><?php foreach ($devices as $d) echo '<option value="'.(int)$d['id'].'">'.h($d['hostname'].' '.$d['ip']).'</option>'; ?></select>
        <label>Template name</label>
        <input name="template_name" placeholder="lab-baseline">
        <input type="hidden" name="act" value="pull">
        <button>Pull config from card</button>
        <p class="muted">FTP GET (fw ≥ 1.4.0) or web Save. File is stored outside the web root. UI shows a redacted copy only.</p>
      </form>

      <form method="post" class="card stack">
        <h3>2. Push config</h3>
        <label>Template</label>
        <select name="template_id"><?php foreach ($templates as $t) echo '<option value="'.(int)$t['id'].'">'.h($t['name'].' '.$t['pulled_at']).'</option>'; ?></select>
        <label>Targets</label>
        <?php foreach ($devices as $d): ?>
          <label><input type="checkbox" name="targets[]" value="<?= (int)$d['id'] ?>" <?= $multi ? '' : 'checked' ?> <?= $multi ? '' : '' ?>><?= h($d['hostname'].' '.$d['ip']) ?></label>
        <?php endforeach; ?>
        <?php if (!$multi): ?><p class="muted">Only the lab unit is selectable.</p><?php endif; ?>
        <label>Optional location overlay (harmless field)</label>
        <input name="location" placeholder="leave blank to keep template">
        <label>Type PUSH CONFIG</label>
        <input name="confirm" autocomplete="off">
        <input type="hidden" name="act" value="push_config">
        <button>Queue config push</button>
      </form>
    </div>

    <div class="grid2">
      <form method="post" class="card stack">
        <h3>3. Mass edit (SNMP SET allow-list)</h3>
        <p class="muted">Name, location, env thresholds only. Not IP/gateway/SNMP ACL.</p>
        <?php foreach ($devices as $d): ?>
          <label><input type="checkbox" name="targets[]" value="<?= (int)$d['id'] ?>" checked><?= h($d['hostname']) ?></label>
        <?php endforeach; ?>
        <label>sysLocation</label><input name="sysLocation">
        <label>Device name (upsBaseIdentName)</label><input name="upsName">
        <label>Temp high / low °F (card units, not tenths)</label>
        <input name="envirTempHigh" type="number"><input name="envirTempLow" type="number">
        <label>Humidity high / low %</label>
        <input name="envirHumidHigh" type="number"><input name="envirHumidLow" type="number">
        <label>Type PUSH CONFIG</label>
        <input name="confirm" autocomplete="off">
        <input type="hidden" name="act" value="mass_edit">
        <button>Queue SNMP mass edit</button>
      </form>

      <div class="card">
        <h3>4. RMCARD firmware</h3>
        <form method="post" enctype="multipart/form-data" class="stack">
          <label>cpsrm2scfw_XXX.bin</label><input type="file" name="fw" accept=".bin">
          <label>cpsrm2scdata_XXX.bin</label><input type="file" name="data" accept=".bin">
          <label>Version label</label><input name="version" placeholder="1.4.x">
          <input type="hidden" name="act" value="upload_fw">
          <button>Store pair in ProgramData</button>
        </form>
        <form method="post" class="stack">
          <label>Stored image</label>
          <select name="firmware_id"><?php foreach ($images as $im) echo '<option value="'.(int)$im['id'].'">'.h($im['version']).'</option>'; ?></select>
          <?php foreach ($devices as $d): ?>
            <label><input type="checkbox" name="targets[]" value="<?= (int)$d['id'] ?>" checked><?= h($d['hostname']) ?></label>
          <?php endforeach; ?>
          <label><input type="checkbox" name="simulate" checked> simulate (no FTP STOR — default)</label>
          <label>Type PUSH FIRMWARE</label>
          <input name="confirm" autocomplete="off">
          <input type="hidden" name="act" value="push_fw">
          <button>Queue firmware job</button>
        </form>
      </div>
    </div>

    <div class="card">
      <h3>Templates (redacted preview)</h3>
      <?php if (!$templates) echo '<p class="muted">None yet.</p>'; ?>
      <ul><?php foreach ($templates as $t): ?>
        <li><a href="<?= h(ba_href('/writes/template?id='.(int)$t['id'])) ?>"><?= h($t['name']) ?></a> · <?= h($t['source_ip']) ?> · <?= h($t['pulled_at']) ?></li>
      <?php endforeach; ?></ul>
    </div>

    <div class="card">
      <h3>Jobs</h3>
      <table><thead><tr><th>ID</th><th>Kind</th><th>Status</th><th>Sim</th><th>By</th><th>Started</th><th>Ended</th></tr></thead><tbody>
      <?php foreach ($jobs as $j): ?>
        <tr>
          <td><a href="<?= h(ba_href('/writes/job?id='.(int)$j['id'])) ?>"><?= (int)$j['id'] ?></a></td>
          <td><?= h($j['kind']) ?></td>
          <td><?= h($j['status']) ?></td>
          <td><?= $j['simulate'] ? 'yes' : 'no' ?></td>
          <td><?= h($j['created_by']) ?></td>
          <td><?= h($j['started_at']) ?></td>
          <td><?= h($j['ended_at']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody></table>
    </div>
    <?php
    ba_layout_end();
}

function page_template_view(PDO $db): void {
    ba_require_admin();
    $id = (int)($_GET['id'] ?? 0);
    $t = $db->prepare('SELECT * FROM config_templates WHERE id=?');
    $t->execute([$id]);
    $row = $t->fetch();
    if (!$row || !is_file($row['path'])) { http_response_code(404); echo 'not found'; return; }
    $raw = file_get_contents($row['path']);
    $red = ba_redact_config($raw);
    ba_layout_start('Template '.$row['name'], 'writes');
    echo '<h1>'.h($row['name']).'</h1><p class="muted">'.h($row['path']).' · secrets redacted · original never served</p>';
    echo '<pre class="card" style="white-space:pre-wrap;max-height:70vh;overflow:auto">'.h($red).'</pre>';
    ba_layout_end();
}

function page_job_view(PDO $db): void {
    $user = ba_require_admin();
    $id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
    $cancelMsg = '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['act'] ?? '') === 'cancel') {
        if (trim((string)($_POST['confirm'] ?? '')) !== 'STOP JOB') {
            $cancelMsg = 'Type STOP JOB to cancel the remaining units.';
        } else {
            $r = ba_cancel_write_job($db, $id, (string)($user['username'] ?? 'admin'));
            header('Location: ' . ba_href('/writes/job?id=' . $id . '&note=' . rawurlencode((string)$r['message'])));
            exit;
        }
    }
    $j = $db->prepare('SELECT * FROM write_jobs WHERE id=?');
    $j->execute([$id]);
    $job = $j->fetch();
    if (!$job) { http_response_code(404); echo 'not found'; return; }
    $tg = $db->prepare('SELECT * FROM write_job_targets WHERE job_id=? ORDER BY id');
    $tg->execute([$id]);
    $targets = $tg->fetchAll();
    ba_layout_start('Job '.$id, 'writes');
    $live = in_array((string)$job['status'], ['queued', 'running'], true);
    if ($live) {
        echo '<script>setTimeout(function(){ location.reload(); }, 4000);</script>';
    }
    echo '<p><a href="'.h(ba_href('/writes')).'">All jobs</a> · <a href="'.h(ba_href('/snmp')).'">SNMP page</a></p>';
    echo '<h1>Job '.$id.' · '.h($job['kind']).' · '.h($job['status']).($job['simulate']?' · simulate':'').'</h1>';
    if (!empty($_GET['note'])) {
        echo '<div class="flash">'.h((string)$_GET['note']).'</div>';
    }
    if ($cancelMsg !== '') {
        echo '<div class="flash">'.h($cancelMsg).'</div>';
    }
    if ($live) {
        echo '<p class="muted">Writer is still working. This page reloads every 4 seconds. There is no extra toast when it finishes — status here is the result (ok / fail per UPS, slot choice in the step detail).</p>';
        echo '<form method="post" action="'.h(ba_href('/writes/job?id='.$id)).'" class="card">';
        echo '<input type="hidden" name="act" value="cancel"><input type="hidden" name="id" value="'.$id.'">';
        echo '<label>Stop remaining units</label> <input name="confirm" placeholder="STOP JOB" autocomplete="off"> ';
        echo '<button type="submit">Stop job</button>';
        echo '<p class="muted">Units already ok or fail stay that way. Queued units are cancelled. A card already restoring is left to finish on writer 0.5.36+. An older writer keeps going until the BackAisleWriter task is stopped.</p>';
        echo '</form>';
    } else {
        echo '<p class="muted">Job finished. Per-UPS result and SNMPv3 slot notes are in the tables below.</p>';
    }
    if ($job['error']) echo '<div class="flash">'.h($job['error']).'</div>';
    foreach ($targets as $t) {
        echo '<div class="card"><h3>'.h($t['hostname'].' '.$t['ip']).' · '.h($t['status']).' · '.h($t['step']).'</h3>';
        echo '<p class="muted">start '.h($t['started_at']).' end '.h($t['ended_at']).'</p>';
        if ($t['error']) echo '<p class="pill batt">'.h($t['error']).'</p>';
        echo '<p>post poll: model '.h($t['post_model']).' fw '.h($t['post_firmware']).' name '.h($t['post_name']).' loc '.h($t['post_location']).'</p>';
        $st = $db->prepare('SELECT * FROM write_job_steps WHERE target_id=? ORDER BY id');
        $st->execute([$t['id']]);
        echo '<table><thead><tr><th>#</th><th>Step</th><th>Status</th><th>Time</th><th>Detail</th></tr></thead><tbody>';
        foreach ($st as $s) {
            echo '<tr><td>'.(int)$s['seq'].'</td><td>'.h($s['name']).'</td><td>'.h($s['status']).'</td><td>'.h($s['ts']).'</td><td>'.h($s['detail']).'</td></tr>';
        }
        echo '</tbody></table></div>';
    }
    ba_layout_end();
}
