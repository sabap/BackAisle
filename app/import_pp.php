<?php
declare(strict_types=1);

/** PowerPanel Business profile.zip import using the live PHP PDO connection (no pyodbc). */

function ba_pp_get(array $row, string $key, mixed $default = null): mixed
{
    foreach ($row as $k => $v) {
        if (strcasecmp((string)$k, $key) === 0) {
            return $v;
        }
    }
    return $default;
}

function ba_pp_table(array $data, string $name): array
{
    foreach ($data as $k => $v) {
        if (strcasecmp((string)$k, $name) === 0 && is_array($v)) {
            return $v;
        }
    }
    return [];
}

/** pdo_odbc sends every PHP value as text. SQL Server then refuses that text for an INT column. */
function ba_pp_exec(PDO $db, string $sql, array $params): PDOStatement
{
    $st = $db->prepare($sql);
    $i = 1;
    foreach ($params as $p) {
        if ($p === null) {
            $st->bindValue($i, null, PDO::PARAM_NULL);
        } elseif (is_int($p)) {
            $st->bindValue($i, $p, PDO::PARAM_INT);
        } else {
            $st->bindValue($i, (string)$p, PDO::PARAM_STR);
        }
        $i++;
    }
    $st->execute();
    return $st;
}

function ba_pp_is_root(mixed $parent): bool
{
    $p = strtolower(trim((string)$parent));
    return $p === '' || $p === '-1' || $p === '0' || $p === 'none' || $p === 'null';
}

function ba_snmp_store_secret(int $profileId, string $auth, string $priv, ?string $web = null): void
{
    $path = 'C:\\ProgramData\\BackAisle\\snmp_profiles.json';
    $dir = dirname($path);
    if (!is_dir($dir)) {
        @mkdir($dir, 0770, true);
    }
    $data = [];
    if (is_file($path)) {
        $j = json_decode((string)file_get_contents($path), true);
        if (is_array($j)) {
            $data = $j;
        }
    }
    $rec = $data[(string)$profileId] ?? [];
    if ($auth !== '') {
        $rec['auth_pass'] = $auth;
    }
    if ($priv !== '') {
        $rec['priv_pass'] = $priv;
    }
    if ($web !== null && $web !== '') {
        $rec['web_pass'] = $web;
    }
    $data[(string)$profileId] = $rec;
    if (@file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) === false) {
        throw new RuntimeException('Could not write C:\\ProgramData\\BackAisle\\snmp_profiles.json');
    }
}

function ba_import_powerpanel_zip(PDO $db, string $zipPath): array
{
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('PHP zip extension is not loaded.');
    }
    $z = new ZipArchive();
    if ($z->open($zipPath) !== true) {
        throw new RuntimeException('Could not open profile.zip');
    }
    $jsonName = null;
    for ($i = 0; $i < $z->numFiles; $i++) {
        $n = $z->getNameIndex($i);
        if ($n !== false && str_ends_with(strtolower($n), 'profile.json')) {
            $jsonName = $n;
            break;
        }
    }
    if ($jsonName === null) {
        $z->close();
        throw new RuntimeException('profile.json not found in zip');
    }
    $raw = $z->getFromName($jsonName);
    $z->close();
    $data = json_decode((string)$raw, true);
    if (!is_array($data)) {
        throw new RuntimeException('profile.json is not valid JSON');
    }

    $authMap = ['0' => 'NONE', '1' => 'MD5', '2' => 'SHA', 'None' => 'SHA'];
    $privMap = ['0' => 'NONE', '1' => 'DES', '2' => 'AES', 'None' => 'AES'];
    $stats = ['groups' => 0, 'devices' => 0, 'snmp' => 0, 'skipped' => 0];
    $snmpMap = [];

    foreach (ba_pp_table($data, 'DbSNMPSetting') as $s) {
        if (!is_array($s)) {
            continue;
        }
        if ((string)ba_pp_get($s, 'snmpType', '') !== '1') {
            continue;
        }
        $name = trim((string)ba_pp_get($s, 'profileName', 'imported'));
        $user = (string)ba_pp_get($s, 'userName', 'cyber');
        if ($user === '' || strcasecmp($user, 'None') === 0) {
            $user = 'cyber';
        }
        $auth = $authMap[(string)ba_pp_get($s, 'authProtocol', '')] ?? 'SHA';
        $priv = $privMap[(string)ba_pp_get($s, 'privacyProtocol', '')] ?? 'AES';
        $st = $db->prepare('SELECT id FROM snmp_profiles WHERE name=?');
        $st->execute([$name]);
        $exist = $st->fetch();
        if ($exist) {
            $nid = (int)$exist['id'];
        } else {
            $db->prepare('INSERT INTO snmp_profiles (name, username, auth_proto, priv_proto, notes) VALUES (?,?,?,?,?)')
                ->execute([$name, $user, $auth, $priv, 'imported from PowerPanel']);
            $nid = ba_last_id($db);
        }
        $authKey = (string)ba_pp_get($s, 'authKey', '');
        $privKey = (string)ba_pp_get($s, 'privacyKey', '');
        if ($authKey !== '' && strcasecmp($authKey, 'None') !== 0 && strlen($authKey) < 80) {
            ba_snmp_store_secret($nid, $authKey, (strcasecmp($privKey, 'None') !== 0) ? $privKey : $authKey);
        }
        $snmpMap[(string)ba_pp_get($s, 'id', '')] = $nid;
        $stats['snmp']++;
    }

    $groupMap = [];
    $usedGroupIds = [];
    $pending = array_values(array_filter(ba_pp_table($data, 'DbGroup'), 'is_array'));
    $knownIds = [];
    foreach ($pending as $g) {
        $knownIds[(string)ba_pp_get($g, 'id', '')] = true;
    }
    $guard = 0;
    while ($pending && $guard < 20000) {
        $guard++;
        $g = array_shift($pending);
        $oid = (string)ba_pp_get($g, 'id', '');
        $parentOld = (string)ba_pp_get($g, 'parentId', ba_pp_get($g, 'parent_id', '-1'));
        $isRoot = ba_pp_is_root($parentOld) || empty($knownIds[$parentOld]);
        if (!$isRoot && !isset($groupMap[$parentOld])) {
            $pending[] = $g;
            continue;
        }
        $parentNew = $isRoot ? null : $groupMap[$parentOld];
        $name = trim((string)ba_pp_get($g, 'name', 'group-' . $oid));
        $note = $oid !== '' ? ('pp:' . $oid) : 'PowerPanel import';
        $nid = 0;
        if ($oid !== '') {
            $st = ba_pp_exec($db, 'SELECT id FROM groups WHERE notes=?', [$note]);
            $hit = $st->fetch();
            if ($hit) {
                $nid = (int)(is_array($hit) ? ($hit['id'] ?? $hit['ID'] ?? 0) : 0);
            }
        }
        if ($nid < 1) {
            $st = ba_pp_exec($db, 'SELECT id, parent_id FROM groups WHERE name=?', [$name]);
            $cands = $st->fetchAll() ?: [];
            foreach ($cands as $cand) {
                $cid = (int)($cand['id'] ?? $cand['ID'] ?? 0);
                if ($cid > 0 && !in_array($cid, $usedGroupIds, true)) {
                    $nid = $cid;
                    break;
                }
            }
        }
        if ($nid > 0) {
            ba_pp_exec(
                $db,
                'UPDATE groups SET parent_id=?, name=?, notes=? WHERE id=?',
                [$parentNew === null ? null : (int)$parentNew, $name, $note, (int)$nid]
            );
        } else {
            ba_pp_exec(
                $db,
                'INSERT INTO groups (parent_id, name, notes) VALUES (?,?,?)',
                [$parentNew === null ? null : (int)$parentNew, $name, $note]
            );
            $nid = ba_last_id($db);
            if ($nid < 1) {
                $st = ba_pp_exec($db, 'SELECT MAX(id) FROM groups WHERE name=? AND notes=?', [$name, $note]);
                $nid = (int)$st->fetchColumn();
            }
            $stats['groups']++;
        }
        $usedGroupIds[] = $nid;
        if ($oid !== '') {
            $groupMap[$oid] = $nid;
        }
    }

    foreach (ba_pp_table($data, 'DbDevice') as $d) {
        if (!is_array($d)) {
            continue;
        }
        $ip = trim((string)ba_pp_get($d, 'address', ''));
        if ($ip === '') {
            $stats['skipped']++;
            continue;
        }
        $host = trim((string)ba_pp_get($d, 'name', ''));
        $loc = trim((string)ba_pp_get($d, 'location', ''));
        $gid = $groupMap[(string)ba_pp_get($d, 'belongGroupId', ba_pp_get($d, 'groupId', ''))] ?? null;
        $closetName = $host;
        $gidInt = $gid ? (int)$gid : null;
        if ($gidInt) {
            $gn = ba_pp_exec($db, 'SELECT name FROM groups WHERE id=?', [$gidInt]);
            $got = trim((string)$gn->fetchColumn());
            if ($got !== '') {
                $closetName = $got;
            }
        }
        $sid = $snmpMap[(string)ba_pp_get($d, 'snmpId', '')] ?? null;
        $sidInt = $sid ? (int)$sid : null;
        $st = ba_pp_exec($db, 'SELECT id FROM devices WHERE ip=?', [$ip]);
        $exist = $st->fetch();
        if ($exist) {
            $existId = (int)(is_array($exist) ? ($exist['id'] ?? $exist['ID'] ?? 0) : 0);
            $sets = ['hostname=COALESCE(NULLIF(hostname,\'\'), ?)'];
            $params = [$host];
            if ($gidInt) {
                $sets[] = 'group_id=?';
                $params[] = $gidInt;
            }
            if ($sidInt) {
                $sets[] = 'snmp_profile_id=?';
                $params[] = $sidInt;
            }
            $sets[] = 'load_notes=COALESCE(load_notes, ?)';
            $params[] = $loc;
            $sets[] = 'idf_closet=CASE WHEN idf_closet IS NULL OR idf_closet=\'\' OR idf_closet=hostname THEN ? ELSE idf_closet END';
            $params[] = $closetName;
            $params[] = $existId;
            ba_pp_exec($db, 'UPDATE devices SET ' . implode(', ', $sets) . ' WHERE id=?', $params);
        } else {
            ba_pp_exec(
                $db,
                'INSERT INTO devices (ip, hostname, site, building, idf_closet, load_notes, group_id, snmp_profile_id, '
                . 'sensor_expected, is_simulated, enabled, va_rating) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)',
                [$ip, $host, 'Imported', $loc, $closetName, $loc, $gidInt, $sidInt, 1, 0, 1, 2000]
            );
            $stats['devices']++;
        }
    }
    return $stats;
}
