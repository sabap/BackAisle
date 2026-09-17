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
    return $st->fetchAll();
}

function ba_rack_devices(PDO $db, int $rackId): array {
    $st = $db->prepare(
        'SELECT d.*, p.comm_state, s.temp_f, s.on_battery, s.output_status, s.capacity_pct,
                t.front_picture, t.rear_picture
         FROM devices d
         LEFT JOIN poll_state p ON p.device_id=d.id
         LEFT JOIN samples s ON s.id = (SELECT id FROM samples WHERE device_id=d.id ORDER BY ts DESC LIMIT 1)
         LEFT JOIN device_templates t ON t.id=d.template_id
         WHERE d.rack_id=?
         ORDER BY d.position_u DESC'
    );
    $st->execute([$rackId]);
    return $st->fetchAll();
}

function ba_faces_overlap(string $a, string $b): bool {
    if ($a === 'both' || $b === 'both') return true;
    return $a === $b;
}

function ba_u_ranges_overlap(int $a0, int $ah, int $b0, int $bh): bool {
    return $a0 < ($b0 + $bh) && $b0 < ($a0 + $ah);
}

function ba_slot_taken(array $occupants, int $pos, int $uh, string $face, ?int $skipId = null): ?array {
    foreach ($occupants as $d) {
        if ($skipId && (int)$d['id'] === $skipId) continue;
        if ($d['position_u'] === null) continue;
        $of = (string)($d['face'] ?? 'both');
        if (!ba_faces_overlap($face, $of)) continue;
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
        $df = (string)($d['face'] ?? 'both');
        if (!ba_faces_overlap($face, $df)) continue;
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

function ba_render_elevation(array $rack, array $devices, string $face, bool $compact, bool $admin): void {
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
        $href = '/rack?id='.$rackId.'&u='.$u.'&face='.urlencode($face);
        if ($admin) {
            echo '<a class="idf-empty" style="bottom:'.$bottom.'%;height:'.$h.'%" href="'.h($href).'" title="Place at U'.$u.' ('.$face.')"></a>';
        } else {
            echo '<span class="idf-empty" style="bottom:'.$bottom.'%;height:'.$h.'%"></span>';
        }
    }

    foreach ($devices as $d) {
        if ($d['position_u'] === null) continue;
        $df = (string)($d['face'] ?? 'both');
        if (!ba_faces_overlap($face, $df)) continue;
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
        }
        $label = $d['hostname'] ?: ($d['ip'] ?: ba_kind_label($kind));
        $uTxt = $pos === $topU ? 'U'.$pos : 'U'.$pos.'–'.$topU;
        $href = (( $kind ?? 'ups') === 'ups' && (int)$d['id'] > 0 && ($d['ip'] ?? '') !== '')
            ? '/device?id='.(int)$d['id']
            : '/rack?id='.$rackId.'&item='.(int)$d['id'];
        echo '<a class="idf-dev kind-'.h($kind).$health.'" href="'.h($href).'" style="bottom:'.$bottom.'%;height:'.$h.'%" title="'.h($label.' · '.$uTxt.' · '.ba_kind_label($kind)).'">';
        $pic = ($face === 'rear') ? ($d['rear_picture'] ?? '') : ($d['front_picture'] ?? '');
        if ($pic === '' && $face === 'rear') $pic = $d['front_picture'] ?? '';
        if ($pic) echo '<img class="idf-dev-img" src="'.h($pic).'" alt="">';
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

function page_idfs(PDO $db, array $user): void {
    $admin = ($user['role'] ?? '') === 'admin';
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
        foreach ($racks as $rk) $maxU = max($maxU, (int)$rk['u_height']);
        echo '<div class="dash-hero"><div>';
        echo '<p class="muted"><a href="'.h(ba_href('/idfs')).'">Locations</a> / '.h(ba_group_path($db, $gid)).'</p>';
        echo '<h1>'.h($g['name']).'</h1>';
        echo '<p class="muted">Click an empty U to place a UPS, switch, or patch panel. U1 is the bottom of the rack.</p>';
        echo '</div></div>';

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
            echo '<form method="post" class="card stack"><h3>Add network rack</h3>';
            echo '<input type="hidden" name="act" value="add_rack">';
            echo '<input type="hidden" name="group_id" value="'.$gid.'">';
            echo '<label>Name</label><input name="name" value="Rack '.chr(65 + count($racks)).'" required>';
            echo '<label>Height (U)</label><input type="number" name="u_height" min="4" max="58" value="42">';
            echo '<label>Left-to-right order</label><input type="number" name="sort_order" value="'.(count($racks)+1).'">';
            echo '<label>Notes</label><input name="notes" placeholder="optional">';
            echo '<button>Add rack</button></form>';
        }
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
        <p class="muted">Expand a campus or building, then open a closet to see its racks.</p>
      </div>
    </div>
    <div class="kpis dash-kpis">
      <div class="kpi"><span>Campuses</span><b><?= $nSites ?></b></div>
      <div class="kpi"><span>Closets</span><b><?= $nClosets ?></b></div>
      <div class="kpi"><span>Racks</span><b><?= $nRacks ?></b></div>
      <div class="kpi"><span>UPS</span><b><?= $nUps ?></b></div>
    </div>
    <div class="loc-toolbar">
      <input type="search" id="loc-search" placeholder="Filter locations…" autocomplete="off">
      <button type="button" class="btn" id="loc-expand">Expand all</button>
      <button type="button" class="btn btn-quiet" id="loc-collapse">Collapse all</button>
    </div>
    <div class="card loc-tree" data-loc-tree="1">
      <?php
      if (!$sites) echo '<p class="empty">No locations yet. Add groups under Org.</p>';
      else ba_render_loc_tree($groups, null, $summaries, $memo, 0);
      ?>
    </div>
    <?php
    if ($admin) {
        echo '<form method="post" class="card stack"><h3>Add network rack</h3>';
        echo '<input type="hidden" name="act" value="add_rack">';
        echo '<label>Closet / group</label><select name="group_id" required><option value="">choose</option>'.ba_group_options($groups).'</select>';
        echo '<label>Name</label><input name="name" placeholder="Rack A" required>';
        echo '<label>Height (U)</label><input type="number" name="u_height" min="4" max="58" value="42">';
        echo '<label>Left-to-right order</label><input type="number" name="sort_order" value="1">';
        echo '<button>Add rack</button></form>';
    }
    ba_layout_end();
}

function page_rack(PDO $db, array $user): void {
    $admin = ($user['role'] ?? '') === 'admin';
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
                $uh = max(1, (int)($_POST['u_height'] ?? 2));
                $face = $_POST['face'] ?? 'both';
                if (!in_array($face, ['front', 'rear', 'both'], true)) $face = 'both';
                $st = $db->prepare('SELECT * FROM devices WHERE id=?');
                $st->execute([$did]);
                $dev = $st->fetch();
                if (!$dev) throw new RuntimeException('Unit not found');
                if ($pos < 1 || ($pos + $uh - 1) > (int)$rack['u_height']) {
                    throw new RuntimeException('U range does not fit this rack');
                }
                $hit = ba_slot_taken($occupants, $pos, $uh, $face, $did);
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
                $kind = $_POST['kind'] ?? ($tpl['kind'] ?? 'switch');
                if (!in_array($kind, ['switch', 'patch_panel', 'other', 'ups'], true)) $kind = 'other';
                $pos = (int)$_POST['position_u'];
                $uh = max(1, (int)($_POST['u_height'] ?? ($tpl['u_height'] ?? 1)));
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
                if ($kind !== 'ups') {
                    $ip = $ip ?: '';
                } elseif ($ip === '') {
                    throw new RuntimeException('UPS needs an IP so it can be polled');
                }
                $enabled = ($kind === 'ups') ? 1 : 0;
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
                $did = (int)$db->lastInsertId();
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
          <a class="btn" href="#place">+ Device</a>
        <?php endif; ?>
      </div>
    </div>

    <div class="idf-detail-grid">
      <div class="card idf-col">
        <div class="card-body">
          <?php ba_render_elevation($rack, $devices, 'front', false, $admin); ?>
        </div>
      </div>
      <div class="card idf-col">
        <div class="card-body">
          <?php ba_render_elevation($rack, $devices, 'rear', false, $admin); ?>
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
                $href = $kind === 'ups' ? '/device?id='.(int)$d['id'] : '/rack?id='.$id.'&item='.(int)$d['id'];
                $st = $kind === 'ups' ? (ba_output_text(isset($d['output_status']) ? (int)$d['output_status'] : null).' · '.($d['comm_state'] ?: 'unknown')) : ba_kind_label($kind);
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
    <div id="place" class="idf-place-grid">
      <form method="post" class="card stack">
        <h3>Place existing UPS</h3>
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
        <label>Height (U)</label><input type="number" name="u_height" min="1" max="8" value="2">
        <label>Face</label>
        <select name="face">
          <option value="both"<?= $focusFace==='both'?' selected':'' ?>>both (full depth)</option>
          <option value="front"<?= $focusFace==='front'?' selected':'' ?>>front</option>
          <option value="rear"<?= $focusFace==='rear'?' selected':'' ?>>rear</option>
        </select>
        <button>Place UPS</button>
        <p class="muted">Unplaced UPS in this closet. A 2U UPS at U1 occupies U1–U2.</p>
      </form>
      <form method="post" class="card stack">
        <h3>Add switch or patch panel</h3>
        <input type="hidden" name="act" value="add_item">
        <label>Template</label>
        <select name="template_id" data-tpl-fill="1"><?= ba_template_options($db) ?></select>
        <label>Kind</label>
        <select name="kind">
          <?php foreach (['switch' => 'Switch', 'patch_panel' => 'Patch panel', 'other' => 'Other'] as $k => $lab): ?>
            <option value="<?= h($k) ?>"><?= h($lab) ?></option>
          <?php endforeach; ?>
        </select>
        <label>Name</label><input name="hostname" placeholder="IDF2A-SW-01 or PP-A" required>
        <label>Manufacturer / model</label>
        <div class="filters"><input name="manufacturer" placeholder="Cisco, Panduit…"><input name="model" placeholder="model"></div>
        <label>Bottom U</label><input type="number" name="position_u" min="1" max="<?= $units ?>" value="<?= $preU ?>" required>
        <label>Height (U)</label><input type="number" name="u_height" min="1" max="8" value="1">
        <label>Face</label>
        <select name="face">
          <option value="front"<?= $focusFace==='front'?' selected':'' ?>>front</option>
          <option value="rear"<?= $focusFace==='rear'?' selected':'' ?>>rear</option>
          <option value="both"<?= $focusFace==='both'?' selected':'' ?>>both</option>
        </select>
        <label>Ports (patch panels)</label><input type="number" name="port_count" min="0" placeholder="24 or 48">
        <label>Management IP (optional)</label><input name="ip" placeholder="not polled unless kind is UPS">
        <button>Add to rack</button>
        <p class="muted">Inventory on the U grid only. SNMPv3 polling stays UPS-only.</p>
      </form>
    </div>
    <?php endif; ?>
    <?php
    ba_layout_end();
}
