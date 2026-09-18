<?php
declare(strict_types=1);

/** Device templates (ColdAisle-style catalog). BackAisle-only; no ColdAisle files. */

function ba_template(PDO $db, int $id): ?array {
    $st = $db->prepare('SELECT * FROM device_templates WHERE id=?');
    $st->execute([$id]);
    $row = $st->fetch();
    return $row ?: null;
}

function ba_templates(PDO $db, bool $activeOnly = true): array {
    $sql = 'SELECT * FROM device_templates';
    if ($activeOnly) $sql .= ' WHERE is_active=1';
    $sql .= ' ORDER BY kind, manufacturer, model';
    return $db->query($sql)->fetchAll();
}

function ba_template_options(PDO $db, ?int $selected = null, ?string $kind = null): string {
    $html = '<option value="">(no template)</option>';
    foreach (ba_templates($db) as $t) {
        if ($kind && ($t['kind'] ?? '') !== $kind && $kind !== '') continue;
        $sel = ((int)$selected === (int)$t['id']) ? ' selected' : '';
        $label = trim(($t['manufacturer'] ? $t['manufacturer'].' ' : '').$t['model'].' · '.ba_kind_label($t['kind']).' · '.(int)$t['u_height'].'U');
        $html .= '<option value="'.(int)$t['id'].'"'.$sel
            .' data-kind="'.h($t['kind']).'" data-u="'.(int)$t['u_height'].'"'
            .' data-face="'.h($t['face']).'" data-ports="'.h((string)($t['port_count'] ?? '')).'"'
            .' data-mfr="'.h((string)$t['manufacturer']).'" data-model="'.h($t['model']).'">'
            .h($label).'</option>';
    }
    return $html;
}

function ba_apply_template(PDO $db, int $deviceId, int $templateId): void {
    $tpl = ba_template($db, $templateId);
    if (!$tpl || !(int)$tpl['is_active']) throw new RuntimeException('Template not found');
    $st = $db->prepare('SELECT * FROM devices WHERE id=?');
    $st->execute([$deviceId]);
    $dev = $st->fetch();
    if (!$dev) throw new RuntimeException('Device not found');
    $uh = max(1, (int)$tpl['u_height']);
    $face = $tpl['face'] ?: ($dev['face'] ?? 'both');
    if (!in_array($face, ['front', 'rear', 'both'], true)) $face = 'both';
    $kind = $tpl['kind'] ?: ($dev['kind'] ?? 'other');
    if ($dev['rack_id'] && $dev['position_u']) {
        $occupants = ba_rack_devices($db, (int)$dev['rack_id']);
        $hit = ba_slot_taken($occupants, (int)$dev['position_u'], $uh, $face, $deviceId);
        if ($hit) throw new RuntimeException('Template height overlaps '.$hit['hostname']);
    }
    $ports = $tpl['port_count'] !== null && $tpl['port_count'] !== '' ? (int)$tpl['port_count'] : $dev['port_count'];
    $va = $tpl['va_rating'] !== null && $tpl['va_rating'] !== '' ? (float)$tpl['va_rating'] : $dev['va_rating'];
    $sid = !empty($tpl['snmp_profile_id']) ? (int)$tpl['snmp_profile_id'] : ($dev['snmp_profile_id'] ?? null);
    $db->prepare(
        'UPDATE devices SET template_id=?, kind=?, model=?, manufacturer=?, u_height=?, face=?, port_count=?, va_rating=?, snmp_profile_id=?, updated_at=datetime(\'now\') WHERE id=?'
    )->execute([$templateId, $kind, $tpl['model'], $tpl['manufacturer'], $uh, $face, $ports, $va, $sid ?: null, $deviceId]);
}

function ba_tpl_save_picture(int $id, string $field, array $file): ?string {
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return null;
    if (($file['error'] ?? 0) !== UPLOAD_ERR_OK) throw new RuntimeException('Upload failed');
    $ext = strtolower(pathinfo((string)$file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true)) throw new RuntimeException('Pictures must be JPG, PNG, or WebP');
    if (($file['size'] ?? 0) > 4 * 1024 * 1024) throw new RuntimeException('Picture too large (4 MB max)');
    $dir = BA_ROOT . '\\public\\assets\\tpl\\' . $id;
    if (!is_dir($dir) && !mkdir($dir, 0770, true) && !is_dir($dir)) throw new RuntimeException('Cannot store picture');
    $stem = $field === 'rear_picture' ? 'rear' : 'front';
    $name = $stem . '.' . ($ext === 'jpeg' ? 'jpg' : $ext);
    $dest = $dir . '\\' . $name;
    if (!move_uploaded_file((string)$file['tmp_name'], $dest)) throw new RuntimeException('Could not save picture');
    return '/assets/tpl/' . $id . '/' . $name;
}

function page_templates(PDO $db, array $user): void {
    ba_ensure_column($db, 'device_templates', 'snmp_profile_id', 'INT NULL');
    $admin = ($user['role'] ?? '') === 'admin';
    $id = (int)($_GET['id'] ?? 0);
    $action = $_GET['action'] ?? '';
    $msg = '';

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $admin) {
        $act = $_POST['act'] ?? '';
        try {
            if ($act === 'save') {
                $tid = (int)($_POST['id'] ?? 0);
                $kind = $_POST['kind'] ?? 'other';
                if (!in_array($kind, ['ups', 'switch', 'patch_panel', 'other'], true)) $kind = 'other';
                $face = $_POST['face'] ?? 'both';
                if (!in_array($face, ['front', 'rear', 'both'], true)) $face = 'both';
                $model = trim($_POST['model'] ?? '');
                if ($model === '') throw new RuntimeException('Model is required');
                $row = [
                    trim($_POST['manufacturer'] ?? ''),
                    $model,
                    $kind,
                    max(1, min(60, (int)($_POST['u_height'] ?? 1))),
                    $face,
                    ($_POST['port_count'] ?? '') === '' ? null : (int)$_POST['port_count'],
                    ($_POST['va_rating'] ?? '') === '' ? null : (float)$_POST['va_rating'],
                    ($_POST['watts'] ?? '') === '' ? null : (float)$_POST['watts'],
                    ($_POST['weight_kg'] ?? '') === '' ? null : (float)$_POST['weight_kg'],
                    trim($_POST['notes'] ?? ''),
                    ($_POST['snmp_profile_id'] ?? '') === '' ? null : (int)$_POST['snmp_profile_id'],
                ];
                if ($tid) {
                    $row[] = $tid;
                    $db->prepare(
                        'UPDATE device_templates SET manufacturer=?, model=?, kind=?, u_height=?, face=?, port_count=?, va_rating=?, watts=?, weight_kg=?, notes=?, snmp_profile_id=?, updated_at=datetime(\'now\') WHERE id=?'
                    )->execute($row);
                } else {
                    $db->prepare(
                        'INSERT INTO device_templates (manufacturer, model, kind, u_height, face, port_count, va_rating, watts, weight_kg, notes, snmp_profile_id) VALUES (?,?,?,?,?,?,?,?,?,?,?)'
                    )->execute($row);
                    $tid = ba_last_id($db);
                }
                foreach (['front_picture', 'rear_picture'] as $field) {
                    if (!empty($_POST['clear_'.$field])) {
                        $db->prepare("UPDATE device_templates SET $field=NULL WHERE id=?")->execute([$tid]);
                    }
                    if (!empty($_FILES[$field]) && ($_FILES[$field]['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                        $rel = ba_tpl_save_picture($tid, $field, $_FILES[$field]);
                        if ($rel) $db->prepare("UPDATE device_templates SET $field=? WHERE id=?")->execute([$rel, $tid]);
                    }
                }
                ba_audit($db, $tid ? 'save_template' : 'add_template', 'device_template', (string)$tid);
                header('Location: ' . ba_href('/templates?id='.$tid));
                exit;
            } elseif ($act === 'deactivate') {
                $tid = (int)$_POST['id'];
                $db->prepare('UPDATE device_templates SET is_active=0 WHERE id=?')->execute([$tid]);
                ba_audit($db, 'deactivate_template', 'device_template', (string)$tid);
                $msg = 'Template deactivated';
            } elseif ($act === 'activate') {
                $tid = (int)$_POST['id'];
                $db->prepare('UPDATE device_templates SET is_active=1 WHERE id=?')->execute([$tid]);
                ba_audit($db, 'activate_template', 'device_template', (string)$tid);
                $msg = 'Template activated';
            } elseif ($act === 'apply') {
                ba_apply_template($db, (int)$_POST['device_id'], (int)$_POST['template_id']);
                ba_audit($db, 'apply_template', 'device', (string)$_POST['device_id']);
                header('Location: ' . ba_href('/device?id='.(int)$_POST['device_id']));
                exit;
            }
        } catch (Throwable $e) {
            $msg = $e->getMessage();
        }
    }

    if ($action === 'new' || $id) {
        $tpl = $id ? ba_template($db, $id) : null;
        if ($id && !$tpl) {
            http_response_code(404);
            echo 'Template not found';
            return;
        }
        ba_layout_start($tpl ? ('Template: '.$tpl['model']) : 'New template', 'templates');
        if ($msg) echo '<div class="flash">'.h($msg).'</div>';
        echo '<p class="muted"><a href="/templates.php">All templates</a></p>';
        echo '<h1>'.h($tpl ? $tpl['model'] : 'New device template').'</h1>';
        echo '<p class="muted">Catalog entry for IDF gear. Apply it to a UPS, switch, or patch panel to fill model, U height, and ports.</p>';
        if ($admin) {
            echo '<form method="post" enctype="multipart/form-data" class="card stack">';
            echo '<input type="hidden" name="act" value="save">';
            if ($tpl) echo '<input type="hidden" name="id" value="'.(int)$tpl['id'].'">';
            echo '<label>Manufacturer</label><input name="manufacturer" value="'.h($tpl['manufacturer'] ?? '').'" placeholder="CyberPower, Cisco, Panduit">';
            echo '<label>Model</label><input name="model" required value="'.h($tpl['model'] ?? '').'">';
            echo '<label>Kind</label><select name="kind">'.ba_kind_options($tpl['kind'] ?? 'other').'</select>';
            echo '<label>Height (U)</label><input type="number" name="u_height" min="1" max="60" value="'.(int)($tpl['u_height'] ?? 1).'">';
            echo '<label>Default face</label><select name="face">';
            foreach (['both' => 'both (full depth)', 'front' => 'front', 'rear' => 'rear'] as $k => $lab) {
                $sel = (($tpl['face'] ?? 'both') === $k) ? ' selected' : '';
                echo '<option value="'.$k.'"'.$sel.'>'.$lab.'</option>';
            }
            echo '</select>';
            echo '<label>Port count</label><input type="number" name="port_count" min="0" value="'.h((string)($tpl['port_count'] ?? '')).'" placeholder="24 or 48 for patch panels">';
            echo '<label>VA rating</label><input type="number" name="va_rating" step="1" value="'.h((string)($tpl['va_rating'] ?? '')).'" placeholder="UPS VA">';
            echo '<label>Watts</label><input type="number" name="watts" step="0.1" value="'.h((string)($tpl['watts'] ?? '')).'">';
            echo '<label>Weight (kg)</label><input type="number" name="weight_kg" step="0.01" value="'.h((string)($tpl['weight_kg'] ?? '')).'">';
            echo '<label>Notes</label><textarea name="notes">'.h($tpl['notes'] ?? '').'</textarea>';
            echo '<label>SNMPv3 profile (applied to devices using this template)</label><select name="snmp_profile_id"><option value="">(none)</option>';
            $curSid = (int)($tpl['snmp_profile_id'] ?? 0);
            foreach ($db->query('SELECT id, name FROM snmp_profiles ORDER BY name') as $sp) {
                $sel = $curSid === (int)$sp['id'] ? ' selected' : '';
                echo '<option value="'.(int)$sp['id'].'"'.$sel.'>'.h($sp['name']).'</option>';
            }
            echo '</select>';
            echo '<label>Front picture</label><input type="file" name="front_picture" accept="image/jpeg,image/png,image/webp">';
            if (!empty($tpl['front_picture'])) {
                echo '<p><img class="idf-tpl-preview" src="'.h($tpl['front_picture']).'" alt="front"> <label><input type="checkbox" name="clear_front_picture"> clear</label></p>';
            }
            echo '<label>Rear picture</label><input type="file" name="rear_picture" accept="image/jpeg,image/png,image/webp">';
            if (!empty($tpl['rear_picture'])) {
                echo '<p><img class="idf-tpl-preview" src="'.h($tpl['rear_picture']).'" alt="rear"> <label><input type="checkbox" name="clear_rear_picture"> clear</label></p>';
            }
            echo '<button>Save template</button></form>';
        }
        if ($tpl) {
            $used = $db->prepare('SELECT id, hostname, ip, kind FROM devices WHERE template_id=? ORDER BY hostname');
            $used->execute([(int)$tpl['id']]);
            $devs = $used->fetchAll();
            echo '<div class="card"><h3>Applied to</h3>';
            if (!$devs) echo '<p class="muted">Not linked to any device yet.</p>';
            else {
                echo '<table><thead><tr><th>Host</th><th>IP</th><th>Kind</th></tr></thead><tbody>';
                foreach ($devs as $d) {
                    echo '<tr><td><a href="/device.php?id='.(int)$d['id'].'">'.h($d['hostname'] ?: $d['ip']).'</a></td><td>'.h($d['ip']).'</td><td>'.h(ba_kind_label($d['kind'])).'</td></tr>';
                }
                echo '</tbody></table>';
            }
            echo '</div>';
        }
        ba_layout_end();
        return;
    }

    $rows = $db->query('SELECT * FROM device_templates ORDER BY is_active DESC, kind, manufacturer, model')->fetchAll();
    ba_layout_start('Device templates', 'templates');
    if ($msg) echo '<div class="flash">'.h($msg).'</div>';
    echo '<h1>Device templates</h1>';
    echo '<p class="muted">Reusable models for IDF racks — UPS, switches, and patch panels. Apply a template to fill U height, manufacturer, model, and ports.</p>';
    if ($admin) echo '<p><a class="btn" href="/templates.php?action=new">+ New template</a></p>';
    echo '<table><thead><tr><th>Kind</th><th>Manufacturer</th><th>Model</th><th>U</th><th>Face</th><th>Ports</th><th>VA</th><th></th></tr></thead><tbody>';
    foreach ($rows as $t) {
        $dim = !(int)$t['is_active'] ? ' class="muted"' : '';
        echo '<tr'.$dim.'><td>'.h(ba_kind_label($t['kind'])).'</td><td>'.h($t['manufacturer']).'</td>';
        echo '<td><a href="/templates.php?id='.(int)$t['id'].'">'.h($t['model']).'</a></td>';
        echo '<td>'.(int)$t['u_height'].'</td><td>'.h($t['face']).'</td><td>'.h((string)($t['port_count'] ?? '—')).'</td>';
        echo '<td>'.h($t['va_rating'] !== null ? (string)(int)$t['va_rating'] : '—').'</td><td>';
        if ($admin) {
            echo '<form method="post" class="inline"><input type="hidden" name="id" value="'.(int)$t['id'].'">';
            if ((int)$t['is_active']) echo '<button class="btn-quiet" name="act" value="deactivate">deactivate</button>';
            else echo '<button class="btn-quiet" name="act" value="activate">activate</button>';
            echo '</form>';
        }
        echo '</td></tr>';
    }
    if (!$rows) echo '<tr><td colspan="8" class="muted">No templates yet.</td></tr>';
    echo '</tbody></table>';
    ba_layout_end();
}
