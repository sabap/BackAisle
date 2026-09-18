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
        if ($parentNew === null) {
            $st = $db->prepare('SELECT id FROM groups WHERE name=? AND parent_id IS NULL');
            $st->execute([$name]);
        } else {
            $st = $db->prepare('SELECT id FROM groups WHERE name=? AND parent_id=?');
            $st->execute([$name, $parentNew]);
        }
        $exist = $st->fetch();
        if ($exist) {
            $nid = (int)$exist['id'];
        } else {
            $db->prepare('INSERT INTO groups (parent_id, name, notes) VALUES (?,?,?)')
                ->execute([$parentNew, $name, 'PowerPanel import']);
            $nid = ba_last_id($db);
            if ($nid < 1) {
                if ($parentNew === null) {
                    $st = $db->prepare('SELECT MAX(id) FROM groups WHERE name=? AND parent_id IS NULL');
                    $st->execute([$name]);
                } else {
                    $st = $db->prepare('SELECT MAX(id) FROM groups WHERE name=? AND parent_id=?');
                    $st->execute([$name, $parentNew]);
                }
                $nid = (int)$st->fetchColumn();
            }
            $stats['groups']++;
        }
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
        $sid = $snmpMap[(string)ba_pp_get($d, 'snmpId', '')] ?? null;
        $st = $db->prepare('SELECT id FROM devices WHERE ip=?');
        $st->execute([$ip]);
        $exist = $st->fetch();
        if ($exist) {
            $db->prepare(
                'UPDATE devices SET hostname=COALESCE(NULLIF(hostname,\'\'), ?), group_id=COALESCE(group_id, ?), '
                . 'snmp_profile_id=COALESCE(snmp_profile_id, ?), load_notes=COALESCE(load_notes, ?) WHERE id=?'
            )->execute([$host, $gid, $sid, $loc, $exist['id']]);
        } else {
            $db->prepare(
                'INSERT INTO devices (ip, hostname, site, building, idf_closet, load_notes, group_id, snmp_profile_id, '
                . 'sensor_expected, is_simulated, enabled, va_rating) VALUES (?,?,?,?,?,?,?,?,1,0,1,2000)'
            )->execute([$ip, $host, 'Imported', $loc, $host, $loc, $gid, $sid]);
            $stats['devices']++;
        }
    }
    return $stats;
}
