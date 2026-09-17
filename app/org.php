<?php
declare(strict_types=1);

function ba_groups(PDO $db): array {
    return $db->query('SELECT * FROM groups ORDER BY name')->fetchAll();
}

function ba_group_children(array $groups, ?int $parent): array {
    $out = [];
    foreach ($groups as $g) {
        $pid = $g['parent_id'] === null ? null : (int)$g['parent_id'];
        if ($pid === $parent) $out[] = $g;
    }
    return $out;
}

function ba_group_options(array $groups, ?int $parent = null, string $prefix = '', ?int $skip = null): string {
    $html = '';
    foreach (ba_group_children($groups, $parent) as $g) {
        if ($skip && (int)$g['id'] === $skip) continue;
        $html .= '<option value="'.(int)$g['id'].'">'.h($prefix.$g['name']).'</option>';
        $html .= ba_group_options($groups, (int)$g['id'], $prefix.'— ', $skip);
    }
    return $html;
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
    ba_require_admin();
    $tab = $_GET['tab'] ?? 'groups';
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
                $id = (int)$db->lastInsertId();
                $code = 'import sys; sys.path.insert(0, r"C:\\inetpub\\BackAisle\\collector"); from profiles import set_secret; set_secret(int(sys.argv[1]), sys.argv[2], sys.argv[3], sys.argv[4] if len(sys.argv)>4 else "")';
                ba_python_run(['-c', $code, (string)$id, (string)($_POST['auth_pass'] ?? ''), (string)($_POST['priv_pass'] ?? ''), (string)($_POST['web_pass'] ?? '')]);
                ba_audit($db, 'add_snmp_profile', 'snmp_profile', (string)$id);
                $msg = 'SNMPv3 profile saved (secrets outside web root)';
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
                $did = (int)$db->lastInsertId();
                $db->prepare("INSERT INTO write_jobs (kind,status,simulate,stop_on_error,created_by,payload_json) VALUES ('provision','queued',0,1,?,?)")
                    ->execute([$user['username'], json_encode(['device_id'=>$did,'config_id'=>$cfgId,'web_user'=>'cyber','web_pass'=>'cyber'])]);
                $jid = (int)$db->lastInsertId();
                $db->prepare("INSERT INTO write_job_targets (job_id,device_id,ip,hostname,status) VALUES (?,?,?,?,'queued')")
                    ->execute([$jid, $did, $ip, $_POST['hostname'] ?? $ip]);
                ba_audit($db, 'add_default_ups', 'device', (string)$did, $ip);
                $msg = "Default UPS $ip queued (cyber/cyber + config profile). Job #$jid";
            } elseif ($act === 'ldap_save') {
                foreach (['ldap_host','ldap_port','ldap_base_dn','ldap_user_filter','ldap_bind_dn'] as $k) {
                    ba_set_setting($db, $k, trim($_POST[$k] ?? ''));
                }
                if (trim($_POST['ldap_bind_password'] ?? '') !== '') {
                    ba_set_setting($db, 'ldap_bind_password', $_POST['ldap_bind_password']);
                }
                ba_set_setting($db, 'ldap_enabled', isset($_POST['ldap_enabled']) ? '1' : '0');
                ba_set_setting($db, 'ldap_use_ssl', isset($_POST['ldap_use_ssl']) ? '1' : '0');
                ba_set_setting($db, 'ldap_tls_insecure', isset($_POST['ldap_tls_insecure']) ? '1' : '0');
                ba_set_setting($db, 'ldap_require_group', isset($_POST['ldap_require_group']) ? '1' : '0');
                ba_set_setting($db, 'alert_hold_sec', (string)(int)($_POST['alert_hold_sec'] ?? 180));
                ba_audit($db, 'ldap_save', 'ldap', null);
                $msg = 'LDAPS settings saved';
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
                $jid = (int)$db->lastInsertId();
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

    ba_layout_start('Organization', 'org');
    if ($msg) echo '<div class="flash">'.h($msg).'</div>';
    $tabs = ['groups'=>'Groups','snmp'=>'SNMPv3 profiles','configs'=>'Config profiles','import'=>'PowerPanel import','ldap'=>'LDAPS','certs'=>'SSL/TLS certs'];
    echo '<nav class="filters">';
    foreach ($tabs as $k=>$lab) {
        echo '<a class="btn '.($tab===$k?'on':'').'" href="/org?tab='.$k.'">'.h($lab).'</a> ';
    }
    echo '</nav>';

    if ($tab === 'groups') {
        echo '<div class="grid2"><form method="post" class="card stack"><h3>New group / subgroup</h3>';
        echo '<label>Name</label><input name="name" required>';
        echo '<label>Parent</label><select name="parent_id"><option value="">(top level)</option>'.ba_group_options($groups).'</select>';
        echo '<label>Alert hold (seconds)</label><input type="number" name="alert_hold_sec" value="180">';
        echo '<input type="hidden" name="act" value="add_group"><button>Create</button></form>';
        echo '<div class="card"><h3>Tree</h3>';
        $walk = function ($parent, $prefix) use (&$walk, $groups, $db) {
            foreach (ba_group_children($groups, $parent) as $g) {
                $n = $db->prepare('SELECT COUNT(*) c FROM devices WHERE group_id=?');
                $n->execute([$g['id']]);
                echo '<div style="margin:.25rem 0 0 '.strlen($prefix)*8 .'px">'.h($prefix.$g['name']).' <span class="muted">'.$n->fetch()['c'].' units · hold '.$g['alert_hold_sec'].'s</span> ';
                echo '<a href="/idfs?group='.(int)$g['id'].'">racks</a> ';
                echo '<form method="post" style="display:inline"><input type="hidden" name="act" value="del_group"><input type="hidden" name="id" value="'.(int)$g['id'].'"><button>remove</button></form></div>';
                $walk((int)$g['id'], $prefix.'— ');
            }
        };
        $walk(null, '');
        echo '</div></div>';
        echo '<div class="card"><h3>Move a unit</h3><form method="post" class="filters">';
        echo '<select name="device_id">';
        foreach ($db->query('SELECT id, hostname, ip, group_id FROM devices ORDER BY hostname') as $d) {
            echo '<option value="'.(int)$d['id'].'">'.h(($d['hostname']?:$d['ip']).' — '.ba_group_path($db, $d['group_id']? (int)$d['group_id']:null)).'</option>';
        }
        echo '</select><select name="group_id"><option value="">(none)</option>'.ba_group_options($groups).'</select>';
        echo '<input type="hidden" name="act" value="move_device"><button>Move</button></form></div>';
    }

    if ($tab === 'snmp') {
        echo '<div class="grid2"><form method="post" class="card stack"><h3>New SNMPv3 profile</h3>';
        echo '<label>Name</label><input name="name" required>';
        echo '<label>Username</label><input name="username" required>';
        echo '<label>Auth / Priv</label><select name="auth_proto"><option>SHA</option><option>MD5</option></select>';
        echo '<select name="priv_proto"><option>AES</option><option>DES</option></select>';
        echo '<label>Auth passphrase</label><input type="password" name="auth_pass">';
        echo '<label>Priv passphrase</label><input type="password" name="priv_pass">';
        echo '<label>Web user (optional)</label><input name="web_user" placeholder="Infrastructure or cyber">';
        echo '<label>Web password</label><input type="password" name="web_pass">';
        echo '<input type="hidden" name="act" value="add_snmp"><button>Save profile</button>';
        echo '<p class="muted">Secrets go to C:\\ProgramData\\BackAisle\\snmp_profiles.json — never shown again.</p></form>';
        echo '<div class="card"><h3>Profiles</h3><table><thead><tr><th>Name</th><th>User</th><th>Auth</th><th>Priv</th><th>Web</th></tr></thead><tbody>';
        foreach ($db->query('SELECT * FROM snmp_profiles ORDER BY name') as $p) {
            echo '<tr><td>'.h($p['name']).($p['is_default']?' <span class="muted">default</span>':'').'</td><td>'.h($p['username']).'</td><td>'.h($p['auth_proto']).'</td><td>'.h($p['priv_proto']).'</td><td>'.h($p['web_user']).'</td></tr>';
        }
        echo '</tbody></table></div></div>';
    }

    if ($tab === 'configs') {
        echo '<div class="card"><h3>Config profiles</h3><p class="muted">Pulled RMCARD YYYY_MM_DD_HHMM.txt files. Clone more from Fleet writes.</p><table><thead><tr><th>Name</th><th>Source</th><th>When</th><th></th></tr></thead><tbody>';
        foreach ($db->query('SELECT * FROM config_templates ORDER BY id DESC') as $t) {
            echo '<tr><td>'.h($t['name']).($t['is_default']?' <span class="muted">default</span>':'').'</td><td>'.h($t['source_ip']).'</td><td>'.h($t['pulled_at']).'</td><td>';
            echo '<a href="/writes/template?id='.(int)$t['id'].'">preview</a> ';
            echo '<form method="post" style="display:inline"><input type="hidden" name="act" value="mark_default_cfg"><input type="hidden" name="id" value="'.(int)$t['id'].'"><button>set default</button></form></td></tr>';
        }
        echo '</tbody></table></div>';
        echo '<div class="card"><h3>Add default UPS</h3><p class="muted">Connects with factory <code>cyber</code>/<code>cyber</code>, applies a config profile, then polls with the selected SNMPv3 profile.</p>';
        echo '<form method="post" class="filters">';
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
        echo '</select><input type="hidden" name="act" value="add_default"><button>Add default UPS</button></form></div>';
    }

    if ($tab === 'import') {
        echo '<form method="post" enctype="multipart/form-data" class="card stack"><h3>Import PowerPanel site</h3>';
        echo '<p class="muted">Accepts PowerPanel Business <code>profile.zip</code> (DbGroup, DbDevice, DbSNMPSetting). Nested groups preserved. Existing IPs are updated in place, not duplicated.</p>';
        echo '<input type="file" name="zip" accept=".zip" required>';
        echo '<input type="hidden" name="act" value="import_pp"><button>Import</button></form>';
    }

    if ($tab === 'ldap') {
        $c = ba_ldap_cfg($db);
        echo '<form method="post" class="card stack"><h3>LDAPS (Active Directory)</h3>';
        echo '<p class="muted">Same model as ColdAisle: service bind, user search, nested group matching (LDAP_MATCHING_RULE_IN_CHAIN), map security group CN/DN to viewer or admin. PHP ldap extension is enabled for this site.</p>';
        echo '<label><input type="checkbox" name="ldap_enabled" '.($c['enabled']?'checked':'').'> Enable LDAPS</label>';
        echo '<label>Host</label><input name="ldap_host" value="'.h($c['host']).'" placeholder="dc.example.org">';
        echo '<label>Port</label><input name="ldap_port" value="'.h((string)$c['port']).'">';
        echo '<label>Base DN</label><input name="ldap_base_dn" value="'.h($c['base_dn']).'" placeholder="DC=example,DC=org">';
        echo '<label>User filter</label><input name="ldap_user_filter" value="'.h($c['user_filter']).'">';
        echo '<label>Bind DN</label><input name="ldap_bind_dn" value="'.h($c['bind_dn']).'">';
        echo '<label>Bind password (blank = keep)</label><input type="password" name="ldap_bind_password">';
        echo '<label><input type="checkbox" name="ldap_use_ssl" '.($c['use_ssl']?'checked':'').'> Use SSL (ldaps://)</label>';
        echo '<label><input type="checkbox" name="ldap_tls_insecure" '.($c['tls_insecure']?'checked':'').'> Do not verify LDAPS cert (internal CA)</label>';
        echo '<label><input type="checkbox" name="ldap_require_group" '.($c['require_group']?'checked':'').'> Require mapped security group to create accounts</label>';
        echo '<label>Alert hold seconds (group vs individual)</label><input type="number" name="alert_hold_sec" value="'.h(ba_setting($db,'alert_hold_sec','180')).'">';
        echo '<input type="hidden" name="act" value="ldap_save"><button>Save LDAPS</button></form>';
        echo '<div class="card"><h3>Role maps</h3><form method="post" class="filters">';
        echo '<input name="group_token" placeholder="CN or full DN of AD group" required>';
        echo '<select name="role"><option value="viewer">viewer</option><option value="admin">admin</option></select>';
        echo '<input type="hidden" name="act" value="ldap_map"><button>Add map</button></form><table>';
        foreach ($db->query('SELECT * FROM ldap_role_maps') as $m) {
            echo '<tr><td>'.h($m['group_token']).'</td><td>'.h($m['role']).'</td><td><form method="post"><input type="hidden" name="act" value="ldap_map_del"><input type="hidden" name="id" value="'.(int)$m['id'].'"><button>remove</button></form></td></tr>';
        }
        echo '</table></div>';
    }

    if ($tab === 'certs') {
        echo '<div class="grid2"><form method="post" class="card stack"><h3>Generate key + CSR</h3>';
        echo '<p class="muted">For your internal AD PKI. Key stays in ProgramData; download CSR and sign it, then upload the .crt/.pem/.p12.</p>';
        echo '<label>CN (hostname or IP)</label><input name="cn" placeholder="rmcard.example.org" required>';
        echo '<input type="hidden" name="act" value="csr"><button>Generate</button></form>';
        echo '<div class="card"><h3>Stored material</h3><table><thead><tr><th>Name</th><th>CSR</th><th>Cert</th></tr></thead><tbody>';
        foreach ($db->query('SELECT * FROM certs ORDER BY id DESC') as $c) {
            echo '<tr><td>'.h($c['name']).'</td><td>'.($c['csr_path']?'yes':'—').'</td><td>'.($c['cert_path']?'yes':'—').'</td></tr>';
        }
        echo '</tbody></table>';
        echo '<form method="post" enctype="multipart/form-data" class="stack"><label>Attach signed cert to</label><select name="cert_id">';
        foreach ($db->query('SELECT id,name FROM certs') as $c) echo '<option value="'.(int)$c['id'].'">'.h($c['name']).'</option>';
        echo '</select><input type="file" name="cert" accept=".crt,.pem,.cer,.p12,.pfx,.pkcs12,.pksc12">';
        echo '<input type="hidden" name="act" value="cert_upload"><button>Store cert</button></form></div></div>';
        echo '<form method="post" class="card stack"><h3>Mass push to RMCARD web SSL</h3>';
        echo '<p class="muted">Default is simulate. Live push attempts the card HTTPS certificate upload; one card at a time. Confirm on the job log.</p>';
        echo '<select name="cert_id">';
        foreach ($db->query('SELECT id,name FROM certs') as $c) echo '<option value="'.(int)$c['id'].'">'.h($c['name']).'</option>';
        echo '</select>';
        foreach ($db->query("SELECT id,hostname,ip FROM devices WHERE enabled=1 AND is_simulated=0") as $d) {
            echo '<label><input type="checkbox" name="targets[]" value="'.(int)$d['id'].'">'.h($d['hostname'].' '.$d['ip']).'</label>';
        }
        echo '<label><input type="checkbox" name="simulate" checked> simulate</label>';
        echo '<input type="hidden" name="act" value="push_cert"><button>Queue cert job</button></form>';
    }

    ba_layout_end();
}

function page_battery_report(PDO $db): void {
    $rows = $db->query("SELECT d.*, g.name AS group_name FROM devices d LEFT JOIN groups g ON g.id=d.group_id ORDER BY CASE WHEN d.warranty_replace_by IS NULL OR d.warranty_replace_by='' THEN 1 ELSE 0 END, d.warranty_replace_by, d.last_battery_replacement")->fetchAll();
    ba_layout_start('Battery replacement', 'battery');
    echo '<h1>Battery replacement report</h1>';
    echo '<p class="muted">Uses last replacement date and warranty/replace-by. If replace-by is empty, due is last replacement + 4 years (typical VRLA).</p>';
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
        echo '<tr class="'.$cls.'"><td>'.h($due ?: '—').'</td><td><a href="/device?id='.(int)$r['id'].'">'.h($r['hostname']).'</a></td><td>'.h($r['ip']).'</td><td>'.h($r['group_name'] ?: '—').'</td><td>'.h($last ?: '—').'</td><td>'.h($r['warranty_replace_by'] ?: '—').'</td><td>'.h($age).'</td></tr>';
    }
    echo '</tbody></table>';
    ba_layout_end();
}
