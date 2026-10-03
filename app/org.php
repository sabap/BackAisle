<?php
declare(strict_types=1);

function ba_group_norm(array $g): array {
    $n = [];
    foreach ($g as $k => $v) {
        $n[strtolower((string)$k)] = $v;
    }
    $n['id'] = (int)($n['id'] ?? 0);
    $raw = $n['parent_id'] ?? null;
    if ($raw === null || $raw === '' || $raw === false || (int)$raw === 0) {
        $n['parent_id'] = null;
    } else {
        $n['parent_id'] = (int)$raw;
    }
    return $n;
}

function ba_groups(PDO $db): array {
    $rows = $db->query('SELECT * FROM groups ORDER BY name')->fetchAll() ?: [];
    $out = [];
    foreach ($rows as $g) {
        if (is_array($g)) {
            $out[] = ba_group_norm($g);
        }
    }
    return $out;
}

function ba_group_children(array $groups, ?int $parent): array {
    $ids = [];
    foreach ($groups as $g) {
        $ids[(int)$g['id']] = true;
    }
    $out = [];
    foreach ($groups as $g) {
        $pid = $g['parent_id'] ?? null;
        if ($pid !== null && !isset($ids[(int)$pid])) {
            $pid = null;
        }
        if ($pid === $parent) {
            $out[] = $g;
        }
    }
    return $out;
}

function ba_group_options(array $groups, ?int $parent = null, string $prefix = '', ?int $skip = null, ?int $selected = null): string {
    $html = '';
    foreach (ba_group_children($groups, $parent) as $g) {
        if ($skip && (int)$g['id'] === $skip) continue;
        $sel = ($selected !== null && (int)$g['id'] === $selected) ? ' selected' : '';
        $html .= '<option value="'.(int)$g['id'].'"'.$sel.'>'.h($prefix.$g['name']).'</option>';
        $html .= ba_group_options($groups, (int)$g['id'], $prefix.'— ', $skip, $selected);
    }
    return $html;
}

function ba_group_descendant_ids(array $groups, int $root): array {
    $ids = [$root];
    $queue = [$root];
    while ($queue) {
        $cur = array_shift($queue);
        foreach (ba_group_children($groups, $cur) as $g) {
            $cid = (int)$g['id'];
            if (!in_array($cid, $ids, true)) {
                $ids[] = $cid;
                $queue[] = $cid;
            }
        }
    }
    return $ids;
}

function ba_group_path(PDO $db, ?int $id): string {
    if (!$id) return '—';
    $parts = [];
    $guard = 0;
    while ($id && $guard++ < 20) {
        $st = $db->prepare('SELECT id, parent_id, name FROM groups WHERE id=?');
        $st->execute([$id]);
        $g = $st->fetch();
        if (!$g) break;
        array_unshift($parts, $g['name']);
        $id = $g['parent_id'] ? (int)$g['parent_id'] : null;
    }
    return implode(' / ', $parts);
}

function page_org(PDO $db, array $user): void {
    ba_require_perm('edit_org');
    $tab = $_POST['tab'] ?? $_GET['tab'] ?? 'groups';
    $msg = '';
    $groups = ba_groups($db);

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $act = $_POST['act'] ?? '';
        try {
            if ($act === 'add_group') {
                $parent = $_POST['parent_id'] === '' ? null : (int)$_POST['parent_id'];
                $db->prepare('INSERT INTO groups (parent_id, name, alert_hold_sec, notes) VALUES (?,?,?,?)')
                    ->execute([$parent, trim($_POST['name']), (int)($_POST['alert_hold_sec'] ?? 180), trim($_POST['notes'] ?? '')]);
                ba_audit($db, 'add_group', 'group', trim($_POST['name']));
                $msg = 'Group created';
            } elseif ($act === 'save_group') {
                $id = (int)($_POST['id'] ?? 0);
                $name = trim((string)($_POST['name'] ?? ''));
                $parent = ($_POST['parent_id'] ?? '') === '' ? null : (int)$_POST['parent_id'];
                if ($id < 1 || $name === '') {
                    throw new RuntimeException('Name and group are required');
                }
                if ($parent === $id) {
                    throw new RuntimeException('A location cannot be its own parent');
                }
                if ($parent !== null) {
                    $all = ba_groups($db);
                    $desc = ba_group_descendant_ids($all, $id);
                    if (in_array($parent, $desc, true)) {
                        throw new RuntimeException('That parent is inside this location');
                    }
                }
                $db->prepare('UPDATE groups SET name=?, parent_id=?, alert_hold_sec=? WHERE id=?')
                    ->execute([$name, $parent, (int)($_POST['alert_hold_sec'] ?? 180), $id]);
                ba_audit($db, 'save_group', 'group', (string)$id, $name);
                $msg = 'Location updated';
            } elseif ($act === 'del_group') {
                $id = (int)$_POST['id'];
                $kids = $db->prepare('SELECT COUNT(*) n FROM groups WHERE parent_id=?');
                $kids->execute([$id]);
                if ((int)$kids->fetch()['n'] > 0) throw new RuntimeException('Remove subgroups first');
                $rk = $db->prepare('SELECT COUNT(*) n FROM racks WHERE group_id=?');
                $rk->execute([$id]);
                if ((int)$rk->fetch()['n'] > 0) throw new RuntimeException('Remove racks in this closet first');
                $db->prepare('UPDATE devices SET group_id=NULL WHERE group_id=?')->execute([$id]);
                $db->prepare('DELETE FROM groups WHERE id=?')->execute([$id]);
                $msg = 'Group removed';
            } elseif ($act === 'move_device') {
                $db->prepare('UPDATE devices SET group_id=? WHERE id=?')->execute([
                    $_POST['group_id'] === '' ? null : (int)$_POST['group_id'],
                    (int)$_POST['device_id'],
                ]);
                ba_audit($db, 'move_device', 'device', (string)$_POST['device_id'], 'group '.($_POST['group_id'] ?? ''));
                $msg = 'Unit moved';
            } elseif ($act === 'add_snmp') {
                $db->prepare('INSERT INTO snmp_profiles (name, username, auth_proto, priv_proto, web_user, notes) VALUES (?,?,?,?,?,?)')
                    ->execute([
                        trim($_POST['name']), trim($_POST['username']),
                        $_POST['auth_proto'] ?? 'SHA', $_POST['priv_proto'] ?? 'AES',
                        trim($_POST['web_user'] ?? ''), trim($_POST['notes'] ?? ''),
                    ]);
                $id = ba_last_id($db);
                ba_snmp_store_secret(
                    $id,
                    (string)($_POST['auth_pass'] ?? ''),
                    (string)($_POST['priv_pass'] ?? ''),
                    (string)($_POST['web_pass'] ?? '')
                );
                ba_audit($db, 'add_snmp_profile', 'snmp_profile', (string)$id);
                $msg = 'SNMPv3 profile saved (secrets outside web root)';
            } elseif ($act === 'save_snmp') {
                $id = (int)($_POST['id'] ?? 0);
                if ($id < 1) {
                    throw new RuntimeException('Missing SNMPv3 profile');
                }
                $db->prepare('UPDATE snmp_profiles SET name=?, username=?, auth_proto=?, priv_proto=?, web_user=?, notes=? WHERE id=?')
                    ->execute([
                        trim($_POST['name']), trim($_POST['username']),
                        $_POST['auth_proto'] ?? 'SHA', $_POST['priv_proto'] ?? 'AES',
                        trim($_POST['web_user'] ?? ''), trim($_POST['notes'] ?? ''),
                        $id,
                    ]);
                $auth = (string)($_POST['auth_pass'] ?? '');
                $priv = (string)($_POST['priv_pass'] ?? '');
                $web = (string)($_POST['web_pass'] ?? '');
                if ($auth !== '' || $priv !== '' || $web !== '') {
                    ba_snmp_store_secret($id, $auth, $priv !== '' ? $priv : $auth, $web !== '' ? $web : null);
                }
                ba_audit($db, 'edit_snmp_profile', 'snmp_profile', (string)$id);
                $msg = 'SNMPv3 profile updated';
            } elseif ($act === 'bulk_snmp') {
                $sid = (int)($_POST['snmp_profile_id'] ?? 0);
                $gid = ($_POST['group_id'] ?? '') === '' ? null : (int)$_POST['group_id'];
                $scope = $gid ? 'group' : 'all';
                $n = ba_assign_snmp_profile($db, $sid, $scope, [], $gid);
                ba_audit($db, 'bulk_snmp', 'snmp_profile', (string)$sid, 'devices='.$n);
                $msg = 'Assigned SNMPv3 profile to '.(int)$n.' UPS';
            } elseif ($act === 'import_pp') {
                if (empty($_FILES['zip']['tmp_name'])) throw new RuntimeException('Choose a profile.zip');
                $dest = sys_get_temp_dir().DIRECTORY_SEPARATOR.'pp_'.bin2hex(random_bytes(4)).'.zip';
                if (!move_uploaded_file($_FILES['zip']['tmp_name'], $dest)) throw new RuntimeException('upload failed');
                try {
                    $stats = ba_import_powerpanel_zip($db, $dest);
                } finally {
                    @unlink($dest);
                }
                $msg = 'Imported ' . json_encode($stats);
                ba_audit($db, 'import_powerpanel', 'import', null, $msg);
            } elseif ($act === 'add_default') {
                $ip = trim($_POST['ip'] ?? '');
                if (!filter_var($ip, FILTER_VALIDATE_IP)) throw new RuntimeException('Bad IP');
                $exist = $db->prepare('SELECT id FROM devices WHERE ip=?');
                $exist->execute([$ip]);
                if ($exist->fetch()) throw new RuntimeException('IP already in inventory');
                $gid = $_POST['group_id'] === '' ? null : (int)$_POST['group_id'];
                $cfgId = (int)($_POST['config_id'] ?? 0);
                $sid = (int)($_POST['snmp_profile_id'] ?? 0) ?: null;
                $db->prepare('INSERT INTO devices (ip, hostname, site, group_id, snmp_profile_id, sensor_expected, is_simulated, enabled, va_rating) VALUES (?,?,?,?,?,1,0,1,2000)')
                    ->execute([$ip, trim($_POST['hostname'] ?? $ip), 'Hospital', $gid, $sid]);
                $did = ba_last_id($db);
                if ($did > 0) {
                    ba_pp_exec($db, 'UPDATE devices SET department_id=? WHERE id=?', [ba_device_department_choice($user), $did]);
                }
                $db->prepare("INSERT INTO write_jobs (kind,status,simulate,stop_on_error,created_by,payload_json) VALUES ('provision','queued',0,1,?,?)")
                    ->execute([$user['username'], json_encode(['device_id'=>$did,'config_id'=>$cfgId,'web_user'=>'cyber','web_pass'=>'cyber'])]);
                $jid = ba_last_id($db);
                $db->prepare("INSERT INTO write_job_targets (job_id,device_id,ip,hostname,status) VALUES (?,?,?,?,'queued')")
                    ->execute([$jid, $did, $ip, $_POST['hostname'] ?? $ip]);
                ba_audit($db, 'add_default_ups', 'device', (string)$did, $ip);
                $msg = "Default UPS $ip queued (cyber/cyber + config profile). Job #$jid";
            } elseif ($act === 'ldap_save') {
                ba_save_ldap_settings($db, $_POST);
                if (isset($_POST['alert_hold_sec'])) {
                    ba_set_setting($db, 'alert_hold_sec', (string)(int)$_POST['alert_hold_sec']);
                }
                ba_audit($db, 'ldap_save', 'ldap', null);
                $msg = 'LDAPS settings saved';
            } elseif ($act === 'ldap_hold') {
                ba_set_setting($db, 'alert_hold_sec', (string)(int)($_POST['alert_hold_sec'] ?? 180));
                ba_audit($db, 'ldap_hold', 'settings', null);
                $msg = 'Alert hold saved';
            } elseif ($act === 'ldap_map') {
                $db->prepare('INSERT INTO ldap_role_maps (group_token, role) VALUES (?,?)')
                    ->execute([trim($_POST['group_token']), $_POST['role'] === 'admin' ? 'admin' : 'viewer']);
                $msg = 'Role map added';
            } elseif ($act === 'ldap_map_del') {
                $db->prepare('DELETE FROM ldap_role_maps WHERE id=?')->execute([(int)$_POST['id']]);
                $msg = 'Map removed';
            } elseif ($act === 'csr') {
                $cn = trim($_POST['cn'] ?? '');
                if ($cn === '') throw new RuntimeException('CN required');
                $dir = 'C:\\ProgramData\\BackAisle\\certs';
                if (!is_dir($dir)) mkdir($dir, 0770, true);
                $safe = preg_replace('/[^A-Za-z0-9._-]/', '_', $cn);
                $py = ba_python();
                $code = rtrim(<<<'PY'
import sys
from pathlib import Path
from cryptography.hazmat.primitives import serialization, hashes
from cryptography.hazmat.primitives.asymmetric import rsa
from cryptography import x509
from cryptography.x509.oid import NameOID
import datetime
cn, d = sys.argv[1], Path(sys.argv[2])
key = rsa.generate_private_key(public_exponent=65537, key_size=2048)
key_path = d / (cn.replace("*","_") + ".key")
csr_path = d / (cn.replace("*","_") + ".csr")
key_path.write_bytes(key.private_bytes(serialization.Encoding.PEM, serialization.PrivateFormat.TraditionalOpenSSL, serialization.NoEncryption()))
csr = x509.CertificateSigningRequestBuilder().subject_name(x509.Name([
    x509.NameAttribute(NameOID.COMMON_NAME, cn)
])).sign(key, hashes.SHA256())
csr_path.write_bytes(csr.public_bytes(serialization.Encoding.PEM))
print(key_path)
print(csr_path)
PY);
                $out = [];
                exec(escapeshellarg($py).' -c '.escapeshellarg($code).' '.escapeshellarg($cn).' '.escapeshellarg($dir), $out, $rc);
                if ($rc !== 0 || count($out) < 2) throw new RuntimeException('CSR failed: '.implode("\n", $out));
                $db->prepare('INSERT INTO certs (name, cn, key_path, csr_path, notes) VALUES (?,?,?,?,?)')
                    ->execute([$cn, $cn, $out[0], $out[1], 'AD PKI CSR']);
                ba_audit($db, 'csr', 'cert', $cn);
                $msg = 'Key + CSR written under ProgramData\\BackAisle\\certs (not web-accessible)';
            } elseif ($act === 'cert_upload') {
                $id = (int)$_POST['cert_id'];
                if (empty($_FILES['cert']['tmp_name'])) throw new RuntimeException('Choose .crt / .pem / .p12 / .pkcs12');
                $dir = 'C:\\ProgramData\\BackAisle\\certs';
                $name = preg_replace('/[^A-Za-z0-9._-]/', '_', $_FILES['cert']['name']);
                $dest = $dir.DIRECTORY_SEPARATOR.$name;
                move_uploaded_file($_FILES['cert']['tmp_name'], $dest);
                $db->prepare('UPDATE certs SET cert_path=? WHERE id=?')->execute([$dest, $id]);
                $msg = 'Certificate stored (not served by IIS)';
            } elseif ($act === 'push_cert') {
                $cid = (int)$_POST['cert_id'];
                $ids = array_map('intval', (array)($_POST['targets'] ?? []));
                if (!$ids) throw new RuntimeException('Select targets');
                $sim = isset($_POST['simulate']);
                $db->prepare("INSERT INTO write_jobs (kind,status,simulate,stop_on_error,created_by,payload_json) VALUES ('push_cert', 'queued', ?, 1, ?, ?)")
                    ->execute([$sim ? 1 : 0, $user['username'], json_encode(['cert_id'=>$cid])]);
                $jid = ba_last_id($db);
                $ins = $db->prepare("INSERT INTO write_job_targets (job_id, device_id, ip, hostname, status) VALUES (?,?,?,?,'queued')");
                foreach ($ids as $did) {
                    $d = $db->query('SELECT id,ip,hostname FROM devices WHERE id='.(int)$did)->fetch();
                    if ($d) $ins->execute([$jid, $d['id'], $d['ip'], $d['hostname']]);
                }
                $msg = "Cert job #$jid queued".($sim ? ' (simulate)' : '');
            } elseif ($act === 'mark_default_cfg') {
                $db->exec('UPDATE config_templates SET is_default=0');
                $db->prepare('UPDATE config_templates SET is_default=1 WHERE id=?')->execute([(int)$_POST['id']]);
                $msg = 'Default config profile set';
            }
        } catch (Throwable $e) {
            $msg = 'Error: '.$e->getMessage();
        }
        $groups = ba_groups($db);
    }

    if ($tab === 'ldap') {
        $tab = 'groups';
    }
    ba_layout_start('Organization', 'org');
    if ($msg) echo '<div class="flash">'.h($msg).'</div>';
    echo '<h1>Organization</h1>';
    $tabs = ['groups'=>'Groups','snmp'=>'SNMPv3 profiles','configs'=>'Config profiles','import'=>'PowerPanel import','certs'=>'SSL/TLS certs'];
    echo '<nav class="page-tabs">';
    foreach ($tabs as $k=>$lab) {
        echo '<a class="'.($tab===$k?'on':'').'" href="'.h(ba_href('/org?tab='.$k)).'">'.h($lab).'</a>';
    }
    echo '</nav>';

    if ($tab === 'groups') {
        $groupActions = '<button type="button" class="btn" data-open-modal="modal-add-group">New group</button>';
        $groupActions .= '<button type="button" class="btn" data-open-modal="modal-move-unit">Move a unit</button>';
        $groupActions .= '<button type="button" class="btn" data-open-modal="modal-alert-hold">Alert hold</button>';
        ba_card_open('IDF tree', $groupActions, 'groups');
        echo '<p class="muted">Campuses and buildings are the top of the tree. IDFs sit under them. The IDFs page only shows this tree. Edit a location to rename it, move it, or change how long a repeated problem waits before it alerts.</p>';
        echo '<div class="org-tree">';
        $editId = (str_starts_with($msg, 'Error') && ($_POST['act'] ?? '') === 'save_group') ? (int)($_POST['id'] ?? 0) : 0;
        $walk = function ($parent) use (&$walk, $groups, $db, $editId) {
            foreach (ba_group_children($groups, $parent) as $g) {
                $id = (int)$g['id'];
                $n = $db->prepare('SELECT COUNT(*) FROM devices WHERE group_id=?');
                $n->execute([$id]);
                $units = (int)$n->fetchColumn();
                $kids = ba_group_children($groups, $id);
                $childCount = count($kids);
                $meta = $units.' unit'.($units === 1 ? '' : 's');
                if ($childCount) {
                    $meta .= ' · '.$childCount.' below';
                }
                $editOpen = $editId === $id;
                $name = (string)$g['name'];
                echo '<div class="org-node">';
                echo '<div class="org-node-row">';
                if ($childCount) {
                    echo '<button type="button" class="org-twist" data-org-toggle aria-expanded="true" aria-label="Collapse '.h($name).'"><span class="loc-chev" aria-hidden="true"></span></button>';
                } else {
                    echo '<span class="org-twist-spacer" aria-hidden="true"></span>';
                }
                echo '<span class="org-node-name">'.h($name).'</span>';
                echo '<span class="org-node-meta">'.h($meta).'</span>';
                echo '<a class="org-view" href="'.h(ba_href('/idfs?group='.$id)).'">View</a>';
                echo '<button type="button" class="org-edit" data-org-edit aria-expanded="'.($editOpen ? 'true' : 'false').'">'.($editOpen ? 'Close' : 'Edit').'</button>';
                echo '</div>';
                echo '<div class="org-node-edit"'.($editOpen ? '' : ' hidden').'>';
                echo '<form id="org-save-'.$id.'" method="post">';
                echo '<input type="hidden" name="act" value="save_group"><input type="hidden" name="id" value="'.$id.'">';
                echo '<div class="org-fields">';
                echo '<label for="org-name-'.$id.'">Name</label>';
                echo '<input id="org-name-'.$id.'" name="name" value="'.h($name).'" required>';
                $topSel = empty($g['parent_id']) ? ' selected' : '';
                echo '<label for="org-parent-'.$id.'">Parent</label><div>';
                echo '<select id="org-parent-'.$id.'" name="parent_id"><option value=""'.$topSel.'>(top level)</option>'.ba_group_options($groups, null, '', $id, $g['parent_id'] ?? null).'</select>';
                echo '<p class="hint">The location above this one. Top level is a campus or site that does not sit inside anything else. A location cannot be moved under itself.</p></div>';
                echo '<label for="org-hold-'.$id.'">Alert hold</label><div>';
                echo '<span class="org-hold"><input id="org-hold-'.$id.'" type="number" min="0" name="alert_hold_sec" value="'.(int)($g['alert_hold_sec'] ?? 180).'"><span class="muted">seconds</span></span>';
                echo '<p class="hint">How long a repeated problem waits before devices in this location open an alert. This replaces the site default from the Alert hold button.</p></div>';
                echo '</div></form>';
                $confirm = json_encode('Remove '.$name.'? Locations under it, and racks in it, have to be removed first. Devices stay in inventory.', JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG);
                echo '<form id="org-del-'.$id.'" method="post" onsubmit="return confirm('.h($confirm).')">';
                echo '<input type="hidden" name="act" value="del_group"><input type="hidden" name="id" value="'.$id.'">';
                echo '</form>';
                echo '<div class="org-node-actions">';
                echo '<button type="submit" form="org-save-'.$id.'">Save</button>';
                echo '<button type="submit" class="btn-quiet" form="org-del-'.$id.'">Remove</button>';
                echo '</div></div>';
                if ($childCount) {
                    echo '<div class="org-node-kids">';
                    $walk($id);
                    echo '</div>';
                }
                echo '</div>';
            }
        };
        if (!$groups) {
            echo '<p class="empty">No locations yet. Use New group to add a campus or site.</p>';
        }
        $walk(null);
        echo '</div>';
        echo <<<'JS'
<script>
(function () {
  function child(node, className) {
    var list = node.children;
    for (var i = 0; i < list.length; i++) {
      if (list[i].classList.contains(className)) return list[i];
    }
    return null;
  }
  document.querySelectorAll('[data-org-toggle]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var node = btn.closest('.org-node');
      var kids = node ? child(node, 'org-node-kids') : null;
      if (!kids) return;
      var open = kids.hasAttribute('hidden');
      if (open) kids.removeAttribute('hidden');
      else kids.setAttribute('hidden', '');
      btn.setAttribute('aria-expanded', open ? 'true' : 'false');
      var name = node.querySelector('.org-node-name');
      btn.setAttribute('aria-label', (open ? 'Collapse ' : 'Expand ') + (name ? name.textContent : 'location'));
    });
  });
  document.querySelectorAll('[data-org-edit]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var node = btn.closest('.org-node');
      var panel = node ? child(node, 'org-node-edit') : null;
      if (!panel) return;
      var open = panel.hasAttribute('hidden');
      if (open) panel.removeAttribute('hidden');
      else panel.setAttribute('hidden', '');
      btn.setAttribute('aria-expanded', open ? 'true' : 'false');
      btn.textContent = open ? 'Close' : 'Edit';
      if (open) {
        var field = panel.querySelector('input[name="name"]');
        if (field) field.focus();
      }
    });
  });
})();
</script>
JS;
        ba_card_close();
        ba_users_modal_open('modal-add-group', 'New group / subgroup', false);
        echo '<form method="post" class="stack">';
        echo '<label>Name</label><input name="name" required>';
        echo '<label>Parent</label><select name="parent_id"><option value="">(top level)</option>'.ba_group_options($groups).'</select>';
        echo '<p class="muted">The location this one sits under. Top level starts a campus or site.</p>';
        echo '<label>Alert hold (seconds)</label><input type="number" min="0" name="alert_hold_sec" value="180">';
        echo '<p class="muted">How long a repeated problem waits before devices here open an alert.</p>';
        echo '<input type="hidden" name="act" value="add_group"><button>Create</button></form>';
        ba_users_modal_close();
        ba_users_modal_open('modal-move-unit', 'Move a unit', false, '', true);
        echo '<form method="post" class="stack">';
        echo '<label>Device</label><select name="device_id">';
        foreach ($db->query('SELECT id, hostname, ip, group_id FROM devices ORDER BY hostname') as $d) {
            echo '<option value="'.(int)$d['id'].'">'.h(($d['hostname']?:$d['ip']).' — '.ba_group_path($db, $d['group_id']? (int)$d['group_id']:null)).'</option>';
        }
        echo '</select><label>Group</label><select name="group_id"><option value="">(none)</option>'.ba_group_options($groups).'</select>';
        echo '<input type="hidden" name="act" value="move_device"><button>Move</button></form>';
        ba_users_modal_close();
        ba_users_modal_open('modal-alert-hold', 'Alert hold', false);
        echo '<form method="post" class="stack">';
        echo '<p class="muted">How long a repeated reading waits before it becomes its own alert.</p>';
        echo '<label>Seconds</label><input type="number" name="alert_hold_sec" value="'.h(ba_setting($db,'alert_hold_sec','180')).'">';
        echo '<input type="hidden" name="act" value="ldap_hold"><button>Save alert hold</button></form>';
        ba_users_modal_close();
    }

    if ($tab === 'snmp') {
        $editId = (int)($_GET['edit'] ?? $_POST['id'] ?? 0);
        $edit = null;
        if ($editId > 0) {
            $st = $db->prepare('SELECT * FROM snmp_profiles WHERE id=?');
            $st->execute([$editId]);
            $edit = $st->fetch() ?: null;
        }
        $snmpActions = '<button type="button" class="btn" data-open-modal="modal-snmp-profile">' . ($edit ? 'Edit profile' : 'New profile') . '</button>';
        $snmpActions .= '<button type="button" class="btn" data-open-modal="modal-snmp-bulk">Bulk assign</button>';
        ba_card_open('Profiles', $snmpActions);
        echo '<table><thead><tr><th>Name</th><th>User</th><th>Auth</th><th>Priv</th><th>Web</th><th></th></tr></thead><tbody>';
        foreach ($db->query('SELECT * FROM snmp_profiles ORDER BY name') as $p) {
            echo '<tr><td>'.h($p['name']).($p['is_default']?' <span class="muted">default</span>':'').'</td><td>'.h($p['username']).'</td><td>'.h($p['auth_proto']).'</td><td>'.h($p['priv_proto']).'</td><td>'.h($p['web_user']).'</td>';
            echo '<td><a href="/org.php?tab=snmp&edit='.(int)$p['id'].'">edit</a></td></tr>';
        }
        echo '</tbody></table>';
        ba_card_close();
        ba_users_modal_open('modal-snmp-profile', $edit ? 'Edit SNMPv3 profile' : 'New SNMPv3 profile', (bool)$edit, $edit ? ba_href('/org?tab=snmp') : '');
        echo '<form method="post" action="/org.php?tab=snmp" class="stack">';
        if ($edit) {
            echo '<input type="hidden" name="act" value="save_snmp"><input type="hidden" name="id" value="'.(int)$edit['id'].'">';
        } else {
            echo '<input type="hidden" name="act" value="add_snmp">';
        }
        echo '<input type="hidden" name="tab" value="snmp">';
        echo '<label>Name</label><input name="name" required value="'.h($edit['name'] ?? '').'">';
        echo '<label>Username</label><input name="username" required value="'.h($edit['username'] ?? '').'">';
        $ap = $edit['auth_proto'] ?? 'SHA';
        $pp = $edit['priv_proto'] ?? 'AES';
        echo '<label>Auth / Priv</label><select name="auth_proto"><option'.($ap==='SHA'?' selected':'').'>SHA</option><option'.($ap==='MD5'?' selected':'').'>MD5</option></select>';
        echo '<select name="priv_proto"><option'.($pp==='AES'?' selected':'').'>AES</option><option'.($pp==='DES'?' selected':'').'>DES</option></select>';
        echo '<label>Auth passphrase'.($edit?' (blank = keep)':'').'</label><input type="password" name="auth_pass" autocomplete="new-password">';
        echo '<label>Priv passphrase'.($edit?' (blank = keep)':'').'</label><input type="password" name="priv_pass" autocomplete="new-password">';
        echo '<label>Web user (optional)</label><input name="web_user" value="'.h($edit['web_user'] ?? '').'" placeholder="Infrastructure or cyber">';
        echo '<label>Web password'.($edit?' (blank = keep)':'').'</label><input type="password" name="web_pass" autocomplete="new-password">';
        echo '<button>'.($edit ? 'Save changes' : 'Save profile').'</button>';
        echo '<p class="muted">Secrets go to C:\\ProgramData\\BackAisle\\snmp_profiles.json and are never shown again.</p></form>';
        ba_users_modal_close();
        ba_users_modal_open('modal-snmp-bulk', 'Bulk assign SNMPv3 profile', false);
        echo '<form method="post" action="/org.php?tab=snmp" class="stack">';
        echo '<input type="hidden" name="act" value="bulk_snmp"><input type="hidden" name="tab" value="snmp">';
        echo '<p class="muted">Sets snmp_profile_id on devices. Use a device template to assign the same profile whenever that template is applied.</p>';
        echo '<label>Profile</label><select name="snmp_profile_id" required><option value="">choose</option>';
        foreach ($db->query('SELECT id,name FROM snmp_profiles ORDER BY name') as $p) {
            echo '<option value="'.(int)$p['id'].'">'.h($p['name']).'</option>';
        }
        echo '</select><label>Location</label><select name="group_id"><option value="">All UPS</option>'.ba_group_options($groups).'</select>';
        echo '<button>Assign</button></form>';
        ba_users_modal_close();
    }

    if ($tab === 'configs') {
        ba_card_open('Config profiles', '<button type="button" class="btn" data-open-modal="modal-add-default">Add default UPS</button>');
        echo '<p class="muted">Pulled RMCARD YYYY_MM_DD_HHMM.txt files. Clone more from Fleet writes.</p><table><thead><tr><th>Name</th><th>Source</th><th>When</th><th></th></tr></thead><tbody>';
        foreach ($db->query('SELECT * FROM config_templates ORDER BY id DESC') as $t) {
            echo '<tr><td>'.h($t['name']).($t['is_default']?' <span class="muted">default</span>':'').'</td><td>'.h($t['source_ip']).'</td><td>'.h($t['pulled_at']).'</td><td>';
            echo '<a href="'.h(ba_href('/writes/template?id='.(int)$t['id'])).'">preview</a> ';
            echo '<form method="post" style="display:inline"><input type="hidden" name="act" value="mark_default_cfg"><input type="hidden" name="id" value="'.(int)$t['id'].'"><button>set default</button></form></td></tr>';
        }
        echo '</tbody></table>';
        ba_card_close();
        ba_users_modal_open('modal-add-default', 'Add default UPS', false, '', true);
        echo '<p class="muted">Connects with factory <code>cyber</code>/<code>cyber</code>, applies a config profile, then polls with the selected SNMPv3 profile.</p>';
        echo '<form method="post" class="stack">';
        echo '<input name="ip" placeholder="IP" required>';
        echo '<input name="hostname" placeholder="hostname">';
        echo '<select name="group_id"><option value="">group</option>'.ba_group_options($groups).'</select>';
        echo '<select name="config_id"><option value="0">config profile</option>';
        foreach ($db->query('SELECT id,name,is_default FROM config_templates') as $t) {
            echo '<option value="'.(int)$t['id'].'" '.($t['is_default']?'selected':'').'>'.h($t['name']).'</option>';
        }
        echo '</select><select name="snmp_profile_id"><option value="">SNMPv3 after apply</option>';
        foreach ($db->query('SELECT id,name FROM snmp_profiles') as $p) {
            echo '<option value="'.(int)$p['id'].'">'.h($p['name']).'</option>';
        }
        echo '</select>';
        ba_department_field($db, $user, null);
        echo '<input type="hidden" name="act" value="add_default"><button>Add default UPS</button></form>';
        ba_users_modal_close();
    }

    if ($tab === 'import') {
        ba_card_open('Import PowerPanel site');
        echo '<form method="post" action="/org.php?tab=import" enctype="multipart/form-data" class="stack">';
        echo '<p class="muted">Upload the same PowerPanel <code>profile.zip</code> again to rebuild the tree. Campuses stay at the top. Each IDF is moved back under its campus, and each UPS is pointed at that IDF. Existing IPs are not duplicated.</p>';
        echo '<input type="file" name="zip" accept=".zip" required>';
        echo '<input type="hidden" name="tab" value="import">';
        echo '<input type="hidden" name="act" value="import_pp"><button>Import</button></form>';
        ba_card_close();
    }

    if ($tab === 'certs') {
        ba_card_open('Stored material', '<button type="button" class="btn" data-open-modal="modal-csr">Generate CSR</button><button type="button" class="btn" data-open-modal="modal-push-cert">Mass push cert</button>');
        echo '<table><thead><tr><th>Name</th><th>CSR</th><th>Cert</th></tr></thead><tbody>';
        foreach ($db->query('SELECT * FROM certs ORDER BY id DESC') as $c) {
            echo '<tr><td>'.h($c['name']).'</td><td>'.($c['csr_path']?'yes':'—').'</td><td>'.($c['cert_path']?'yes':'—').'</td></tr>';
        }
        echo '</tbody></table>';
        echo '<form method="post" enctype="multipart/form-data" class="stack"><label>Attach signed cert to</label><select name="cert_id">';
        foreach ($db->query('SELECT id,name FROM certs') as $c) echo '<option value="'.(int)$c['id'].'">'.h($c['name']).'</option>';
        echo '</select><input type="file" name="cert" accept=".crt,.pem,.cer,.p12,.pfx,.pkcs12,.pksc12">';
        echo '<input type="hidden" name="act" value="cert_upload"><button>Store cert</button></form>';
        ba_card_close();
        ba_users_modal_open('modal-csr', 'Generate key + CSR', false);
        echo '<form method="post" class="stack">';
        echo '<p class="muted">For your internal AD PKI. Key stays in ProgramData; download CSR and sign it, then upload the .crt/.pem/.p12.</p>';
        echo '<label>CN (hostname or IP)</label><input name="cn" placeholder="rmcard.example.org" required>';
        echo '<input type="hidden" name="act" value="csr"><button>Generate</button></form>';
        ba_users_modal_close();
        ba_users_modal_open('modal-push-cert', 'Mass push to RMCARD web SSL', false, '', true);
        echo '<form method="post" class="stack">';
        echo '<p class="muted">Default is simulate. Live push attempts the card HTTPS certificate upload; one card at a time. Confirm on the job log.</p>';
        echo '<select name="cert_id">';
        foreach ($db->query('SELECT id,name FROM certs') as $c) echo '<option value="'.(int)$c['id'].'">'.h($c['name']).'</option>';
        echo '</select>';
        foreach ($db->query("SELECT id,hostname,ip FROM devices WHERE enabled=1 AND is_simulated=0") as $d) {
            echo '<label><input type="checkbox" name="targets[]" value="'.(int)$d['id'].'">'.h($d['hostname'].' '.$d['ip']).'</label>';
        }
        echo '<label><input type="checkbox" name="simulate" checked> simulate</label>';
        echo '<input type="hidden" name="act" value="push_cert"><button>Queue cert job</button></form>';
        ba_users_modal_close();
    }

    ba_layout_end();
}

function page_battery_report(PDO $db): void {
    $rows = $db->query("SELECT d.*, g.name AS group_name FROM devices d LEFT JOIN groups g ON g.id=d.group_id ORDER BY CASE WHEN d.warranty_replace_by IS NULL OR d.warranty_replace_by='' THEN 1 ELSE 0 END, d.warranty_replace_by, d.last_battery_replacement")->fetchAll();
    ba_layout_start('Battery replacement', 'battery');
    echo '<h1>Battery replacement report</h1>';
    echo '<p class="muted">Uses last replacement date and warranty/replace-by. If replace-by is empty, due is last replacement + 4 years (typical VRLA).</p>';
    ba_card_open('Due batteries');
    echo '<table><thead><tr><th>Due</th><th>Host</th><th>IP</th><th>Group</th><th>Last replaced</th><th>Replace-by</th><th>Age (days)</th></tr></thead><tbody>';
    $today = new DateTime('today');
    foreach ($rows as $r) {
        $last = $r['last_battery_replacement'] ?: null;
        $due = $r['warranty_replace_by'] ?: null;
        if (!$due && $last) {
            try { $due = (new DateTime($last))->modify('+4 years')->format('Y-m-d'); } catch (Throwable $e) { $due = null; }
        }
        $age = '—';
        if ($last) {
            try { $age = (string)$today->diff(new DateTime($last))->days; } catch (Throwable $e) {}
        }
        $cls = '';
        if ($due) {
            try {
                $dd = new DateTime($due);
                if ($dd <= $today) $cls = 'st-batt';
                elseif ($dd <= (clone $today)->modify('+90 days')) $cls = 'st-warn';
            } catch (Throwable $e) {}
        }
        echo '<tr class="'.$cls.'"><td>'.h($due ?: '—').'</td><td><a href="'.h(ba_href('/device?id='.(int)$r['id'])).'">'.h($r['hostname']).'</a></td><td>'.h($r['ip']).'</td><td>'.h($r['group_name'] ?: '—').'</td><td>'.h($last ?: '—').'</td><td>'.h($r['warranty_replace_by'] ?: '—').'</td><td>'.h($age).'</td></tr>';
    }
    echo '</tbody></table>';
    ba_card_close();
    ba_layout_end();
}
