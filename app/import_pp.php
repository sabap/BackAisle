<?php
declare(strict_types=1);

/** PowerPanel Business profile.zip import using the live PHP PDO connection (no pyodbc). */

function ba_last_id(PDO $db): int
{
    if (ba_db_driver() === 'sqlsrv') {
        $v = $db->query('SELECT SCOPE_IDENTITY()')->fetchColumn();
        return (int)$v;
    }
    return (int)$db->lastInsertId();
}

function ba_snmp_store_secret(int $profileId, string $auth, string $priv): void
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

    foreach ($data['DbSNMPSetting'] ?? [] as $s) {
        if ((string)($s['snmpType'] ?? '') !== '1') {
            continue;
        }
        $name = trim((string)($s['profileName'] ?? 'imported'));
        $user = (string)($s['userName'] ?? 'cyber');
        if ($user === '' || $user === 'None') {
            $user = 'cyber';
        }
        $auth = $authMap[(string)($s['authProtocol'] ?? '')] ?? 'SHA';
        $priv = $privMap[(string)($s['privacyProtocol'] ?? '')] ?? 'AES';
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
        $authKey = (string)($s['authKey'] ?? '');
        $privKey = (string)($s['privacyKey'] ?? '');
        if ($authKey !== '' && $authKey !== 'None' && strlen($authKey) < 80) {
            ba_snmp_store_secret($nid, $authKey, ($privKey !== 'None') ? $privKey : $authKey);
        }
        $snmpMap[(string)($s['id'] ?? '')] = $nid;
        $stats['snmp']++;
    }

    $groupMap = [];
    $pending = array_values($data['DbGroup'] ?? []);
    $guard = 0;
    while ($pending && $guard < 10000) {
        $guard++;
        $g = array_shift($pending);
        $oid = (string)($g['id'] ?? '');
        $parentOld = (string)($g['parentId'] ?? '');
        if (!in_array($parentOld, ['-1', 'None', 'none', ''], true) && !isset($groupMap[$parentOld])) {
            $pending[] = $g;
            continue;
        }
        $parentNew = in_array($parentOld, ['-1', 'None', 'none', ''], true) ? null : $groupMap[$parentOld];
        $name = trim((string)($g['name'] ?? ('group-' . $oid)));
        $st = $db->prepare('SELECT id FROM groups WHERE name=? AND IFNULL(parent_id,-1)=IFNULL(?, -1)');
        $st->execute([$name, $parentNew]);
        $exist = $st->fetch();
        if ($exist) {
            $nid = (int)$exist['id'];
        } else {
            $db->prepare('INSERT INTO groups (parent_id, name, notes) VALUES (?,?,?)')
                ->execute([$parentNew, $name, 'PowerPanel import']);
            $nid = ba_last_id($db);
            $stats['groups']++;
        }
        $groupMap[$oid] = $nid;
    }

    foreach ($data['DbDevice'] ?? [] as $d) {
        $ip = trim((string)($d['address'] ?? ''));
        if ($ip === '') {
            $stats['skipped']++;
            continue;
        }
        $host = trim((string)($d['name'] ?? ''));
        $loc = trim((string)($d['location'] ?? ''));
        $gid = $groupMap[(string)($d['belongGroupId'] ?? '')] ?? null;
        $sid = $snmpMap[(string)($d['snmpId'] ?? '')] ?? null;
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
