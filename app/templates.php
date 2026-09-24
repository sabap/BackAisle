<?php
declare(strict_types=1);

/** Device templates (ColdAisle-style catalog). BackAisle-only; no ColdAisle files. */

function ba_plug_types(): array {
    // Last entry is the locking 6-20. "L620" is accepted as the same plug.
    return ['NEMA 5-15', 'L5-15', '5-20', 'L5-20', '5-30', 'L5-30', '6-15', 'L6-15', '6-20', 'L6-20'];
}

function ba_plug_canon(string $plug): string {
    $plug = trim($plug);
    if (strcasecmp($plug, 'L620') === 0) {
        return 'L6-20';
    }
    foreach (ba_plug_types() as $p) {
        if (strcasecmp($plug, $p) === 0) {
            return $p;
        }
    }
    return '';
}

function ba_plug_select(string $name, string $current, string $blank = '(plug type)'): string {
    $cur = ba_plug_canon($current);
    $html = '<select name="'.h($name).'"><option value="">'.h($blank).'</option>';
    foreach (ba_plug_types() as $p) {
        $sel = $cur === $p ? ' selected' : '';
        $html .= '<option value="'.h($p).'"'.$sel.'>'.h($p).'</option>';
    }
    return $html . '</select>';
}

function ba_ensure_template_ports(PDO $db): void {
    if (ba_db_driver() === 'sqlsrv') {
        ba_ensure_column($db, 'device_templates', 'plug_type', 'NVARCHAR(32) NULL');
        ba_ensure_column($db, 'device_templates', 'outlet_count', 'INT NULL');
        ba_ensure_column($db, 'device_templates', 'data_port_count', 'INT NULL');
        ba_ensure_column($db, 'device_templates', 'env_port_count', 'INT NULL');
        $db->exec(
            "IF NOT EXISTS (SELECT * FROM sys.tables WHERE name = 'device_template_outlets')
             CREATE TABLE device_template_outlets (
               template_id INT NOT NULL,
               port_no INT NOT NULL,
               plug_type NVARCHAR(32) NULL,
               label NVARCHAR(128) NULL,
               PRIMARY KEY (template_id, port_no)
             )"
        );
        return;
    }
    ba_ensure_column($db, 'device_templates', 'plug_type', 'TEXT');
    ba_ensure_column($db, 'device_templates', 'outlet_count', 'INTEGER');
    ba_ensure_column($db, 'device_templates', 'data_port_count', 'INTEGER');
    ba_ensure_column($db, 'device_templates', 'env_port_count', 'INTEGER');
    $db->exec(
        'CREATE TABLE IF NOT EXISTS device_template_outlets (
            template_id INTEGER NOT NULL,
            port_no INTEGER NOT NULL,
            plug_type TEXT,
            label TEXT,
            PRIMARY KEY (template_id, port_no)
        )'
    );
}

function ba_template_outlets(PDO $db, int $id): array {
    $st = $db->prepare('SELECT port_no, plug_type, label FROM device_template_outlets WHERE template_id=? ORDER BY port_no');
    $st->execute([$id]);
    $out = [];
    foreach ($st->fetchAll() ?: [] as $row) {
        $row = array_change_key_case($row, CASE_LOWER);
        $out[(int)$row['port_no']] = $row;
    }
    return $out;
}

function ba_save_template_outlets(PDO $db, int $templateId, int $count, array $plugs, array $labels): void {
    $count = max(0, min(48, $count));
    ba_pp_exec($db, 'DELETE FROM device_template_outlets WHERE template_id=?', [$templateId]);
    for ($i = 1; $i <= $count; $i++) {
        $plug = ba_plug_canon((string)($plugs[$i] ?? $plugs[(string)$i] ?? ''));
        $label = trim((string)($labels[$i] ?? $labels[(string)$i] ?? ''));
        if (function_exists('mb_substr')) {
            $label = mb_substr($label, 0, 128);
        } elseif (strlen($label) > 128) {
            $label = substr($label, 0, 128);
        }
        ba_pp_exec(
            $db,
            'INSERT INTO device_template_outlets (template_id, port_no, plug_type, label) VALUES (?,?,?,?)',
            [$templateId, $i, $plug !== '' ? $plug : null, $label !== '' ? $label : null]
        );
    }
}

function ba_template(PDO $db, int $id): ?array {
    $st = $db->prepare('SELECT * FROM device_templates WHERE id=?');
    $st->execute([$id]);
    $row = $st->fetch();
    return $row ? array_change_key_case($row, CASE_LOWER) : null;
}

function ba_templates(PDO $db, bool $activeOnly = true): array {
    $sql = 'SELECT * FROM device_templates';
    if ($activeOnly) $sql .= ' WHERE is_active=1';
    $sql .= ' ORDER BY kind, manufacturer, model';
    $out = [];
    foreach ($db->query($sql)->fetchAll() ?: [] as $row) {
        $out[] = array_change_key_case($row, CASE_LOWER);
    }
    return $out;
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
    ba_ensure_template_ports($db);
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
                $outlets = max(0, min(48, (int)($_POST['outlet_count'] ?? 0)));
                $dataPorts = ($_POST['data_port_count'] ?? '') === '' ? null : max(0, (int)$_POST['data_port_count']);
                $envPorts = ($_POST['env_port_count'] ?? '') === '' ? null : max(0, (int)$_POST['env_port_count']);
                $plug = ba_plug_canon((string)($_POST['plug_type'] ?? ''));
                if ($kind !== 'ups') {
                    $outlets = 0;
                    $dataPorts = null;
                    $envPorts = null;
                    $plug = '';
                }
                ba_pp_exec(
                    $db,
                    'UPDATE device_templates SET plug_type=?, outlet_count=?, data_port_count=?, env_port_count=? WHERE id=?',
                    [$plug !== '' ? $plug : null, $kind === 'ups' ? $outlets : null, $dataPorts, $envPorts, $tid]
                );
                if ($kind === 'ups') {
                    $postedPlugs = is_array($_POST['outlet_plug'] ?? null) ? $_POST['outlet_plug'] : [];
                    $postedLabels = is_array($_POST['outlet_label'] ?? null) ? $_POST['outlet_label'] : [];
                    ba_save_template_outlets($db, $tid, $outlets, $postedPlugs, $postedLabels);
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
        echo '<p class="muted">Catalog entry for IDF gear. Apply it to a UPS, switch, or patch panel to fill model, U height, and ports. A UPS template also stores the plug type, output outlets, data ports, and environmental ports.</p>';
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
            $outlets = ba_template_outlets($db, (int)($tpl['id'] ?? 0));
            $outletN = (int)($tpl['outlet_count'] ?? count($outlets));
            $kindNow = (string)($tpl['kind'] ?? 'other');
            echo '<div id="ups-ports"'.($kindNow === 'ups' ? '' : ' hidden').'>';
            echo '<label>Plug type</label>'.ba_plug_select('plug_type', (string)($tpl['plug_type'] ?? ''));
            echo '<label>Number of outlets (Output)</label><input type="number" name="outlet_count" id="outlet-count" min="0" max="48" value="'.$outletN.'">';
            echo '<label>Number of Data ports</label><input type="number" name="data_port_count" min="0" max="48" value="'.h((string)($tpl['data_port_count'] ?? '')).'">';
            echo '<label>Number of Environmental ports</label><input type="number" name="env_port_count" min="0" max="48" value="'.h((string)($tpl['env_port_count'] ?? '')).'">';
            echo '<div id="outlet-rows">';
            if ($outletN > 0) {
                echo '<h3>Output outlets</h3>';
                for ($i = 1; $i <= $outletN; $i++) {
                    $orow = $outlets[$i] ?? [];
                    $num = str_pad((string)$i, 2, '0', STR_PAD_LEFT);
                    echo '<div data-port="'.$i.'" class="outlet-row"><label>Outlet '.h($num).'</label>';
                    echo ba_plug_select('outlet_plug['.$i.']', (string)($orow['plug_type'] ?? ''));
                    echo '<input name="outlet_label['.$i.']" value="'.h((string)($orow['label'] ?? '')).'" placeholder="label"></div>';
                }
            }
            echo '</div></div>';
            $outletJson = [];
            foreach ($outlets as $no => $row) {
                $outletJson[(string)$no] = ['plug' => (string)($row['plug_type'] ?? ''), 'label' => (string)($row['label'] ?? '')];
            }
            $flags = JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
            $plugJson = json_encode(ba_plug_types(), $flags);
            $savedJson = json_encode($outletJson, $flags);
            echo '<script>
(function () {
  var plugs = '.$plugJson.';
  var saved = '.$savedJson.';
  var kind = document.querySelector("select[name=kind]");
  var box = document.getElementById("ups-ports");
  var count = document.getElementById("outlet-count");
  var rows = document.getElementById("outlet-rows");
  function read() {
    var have = {};
    if (!rows) return have;
    rows.querySelectorAll("[data-port]").forEach(function (el) {
      var n = el.getAttribute("data-port");
      var sel = el.querySelector("select");
      var inp = el.querySelector("input");
      have[n] = { plug: sel ? sel.value : "", label: inp ? inp.value : "" };
    });
    return have;
  }
  function paint() {
    if (!count || !rows) return;
    var n = Math.max(0, Math.min(48, parseInt(count.value || "0", 10) || 0));
    var have = read();
    rows.textContent = "";
    if (n > 0) {
      var head = document.createElement("h3");
      head.textContent = "Output outlets";
      rows.appendChild(head);
    }
    for (var i = 1; i <= n; i++) {
      var key = String(i);
      var prev = have[key] || saved[key] || { plug: "", label: "" };
      var line = document.createElement("div");
      line.className = "outlet-row";
      line.setAttribute("data-port", key);
      var lab = document.createElement("label");
      lab.textContent = "Outlet " + (i < 10 ? "0" : "") + i;
      var sel = document.createElement("select");
      sel.name = "outlet_plug[" + key + "]";
      var blank = document.createElement("option");
      blank.value = "";
      blank.textContent = "(plug type)";
      sel.appendChild(blank);
      plugs.forEach(function (p) {
        var opt = document.createElement("option");
        opt.value = p;
        opt.textContent = p;
        if (p === prev.plug) opt.selected = true;
        sel.appendChild(opt);
      });
      var inp = document.createElement("input");
      inp.name = "outlet_label[" + key + "]";
      inp.value = prev.label || "";
      inp.placeholder = "label";
      line.appendChild(lab);
      line.appendChild(sel);
      line.appendChild(inp);
      rows.appendChild(line);
    }
    saved = {};
  }
  function toggle() {
    if (box) box.hidden = !kind || kind.value !== "ups";
  }
  if (count) {
    count.addEventListener("input", paint);
    count.addEventListener("change", paint);
  }
  if (kind) kind.addEventListener("change", function () { toggle(); });
  toggle();
  paint();
})();
</script>';
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
        } elseif ($tpl && (string)($tpl['kind'] ?? '') === 'ups') {
            $viewOutlets = ba_template_outlets($db, (int)$tpl['id']);
            $dash = static function ($v): string {
                return ($v === null || $v === '') ? '—' : (string)$v;
            };
            echo '<div class="card">';
            echo '<p>Plug type: <strong>'.h($dash($tpl['plug_type'] ?? null)).'</strong></p>';
            echo '<p>Number of outlets (Output): <strong>'.h($dash($tpl['outlet_count'] ?? null)).'</strong></p>';
            echo '<p>Number of Data ports: <strong>'.h($dash($tpl['data_port_count'] ?? null)).'</strong></p>';
            echo '<p>Number of Environmental ports: <strong>'.h($dash($tpl['env_port_count'] ?? null)).'</strong></p>';
            if ($viewOutlets) {
                echo '<table><thead><tr><th>Outlet</th><th>Plug type</th><th>Label</th></tr></thead><tbody>';
                foreach ($viewOutlets as $no => $row) {
                    echo '<tr><td>'.h(str_pad((string)$no, 2, '0', STR_PAD_LEFT)).'</td>';
                    echo '<td>'.h((string)($row['plug_type'] ?? '')).'</td><td>'.h((string)($row['label'] ?? '')).'</td></tr>';
                }
                echo '</tbody></table>';
            }
            echo '</div>';
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
    echo '<table><thead><tr><th>Kind</th><th>Manufacturer</th><th>Model</th><th>U</th><th>Face</th><th>Ports</th><th>Outlets</th><th>VA</th><th></th></tr></thead><tbody>';
    foreach ($rows as $t) {
        $t = array_change_key_case($t, CASE_LOWER);
        $dim = !(int)$t['is_active'] ? ' class="muted"' : '';
        $outletCell = '—';
        $oc = $t['outlet_count'] ?? null;
        if (($t['kind'] ?? '') === 'ups' && $oc !== null && $oc !== '') {
            $outletCell = (string)(int)$oc;
            if (!empty($t['plug_type'])) {
                $outletCell .= ' · '.$t['plug_type'];
            }
        }
        echo '<tr'.$dim.'><td>'.h(ba_kind_label($t['kind'])).'</td><td>'.h($t['manufacturer']).'</td>';
        echo '<td><a href="/templates.php?id='.(int)$t['id'].'">'.h($t['model']).'</a></td>';
        echo '<td>'.(int)$t['u_height'].'</td><td>'.h($t['face']).'</td><td>'.h((string)($t['port_count'] ?? '—')).'</td>';
        echo '<td>'.h($outletCell).'</td>';
        echo '<td>'.h($t['va_rating'] !== null ? (string)(int)$t['va_rating'] : '—').'</td><td>';
        if ($admin) {
            echo '<form method="post" class="inline"><input type="hidden" name="id" value="'.(int)$t['id'].'">';
            if ((int)$t['is_active']) echo '<button class="btn-quiet" name="act" value="deactivate">deactivate</button>';
            else echo '<button class="btn-quiet" name="act" value="activate">activate</button>';
            echo '</form>';
        }
        echo '</td></tr>';
    }
    if (!$rows) echo '<tr><td colspan="9" class="muted">No templates yet.</td></tr>';
    echo '</tbody></table>';
    ba_layout_end();
}
