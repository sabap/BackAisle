<?php
declare(strict_types=1);

/** LDAPS auth mirrored from ColdAisle: service bind, user search, nested group matching rule, role maps. */

function ba_setting(PDO $db, string $k, string $default = ''): string {
    $st = $db->prepare('SELECT v FROM settings WHERE k=?');
    $st->execute([$k]);
    $r = $st->fetch();
    return $r ? (string)$r['v'] : $default;
}

function ba_set_setting(PDO $db, string $k, string $v): void {
    if (ba_db_driver() === 'sqlsrv') {
        $st = $db->prepare('UPDATE settings SET v=? WHERE k=?');
        $st->execute([$v, $k]);
        $chk = $db->prepare('SELECT COUNT(*) FROM settings WHERE k=?');
        $chk->execute([$k]);
        if ((int)$chk->fetchColumn() === 0) {
            $db->prepare('INSERT INTO settings (k,v) VALUES (?,?)')->execute([$k, $v]);
        }
        return;
    }
    $db->prepare('INSERT INTO settings (k,v) VALUES (?,?) ON CONFLICT(k) DO UPDATE SET v=excluded.v')->execute([$k, $v]);
}

function ba_ldap_cfg(PDO $db): array {
    return [
        'enabled' => ba_setting($db, 'ldap_enabled', '0') === '1',
        'host' => ba_setting($db, 'ldap_host'),
        'port' => (int)ba_setting($db, 'ldap_port', '636'),
        'base_dn' => ba_setting($db, 'ldap_base_dn'),
        'user_filter' => ba_setting($db, 'ldap_user_filter', '(sAMAccountName={username})'),
        'bind_dn' => ba_setting($db, 'ldap_bind_dn'),
        'bind_password' => ba_setting($db, 'ldap_bind_password'),
        'use_ssl' => ba_setting($db, 'ldap_use_ssl', '1') === '1',
        'tls_insecure' => ba_setting($db, 'ldap_tls_insecure', '1') === '1',
        'require_group' => ba_setting($db, 'ldap_require_group', '1') === '1',
    ];
}

function ba_ldap_escape(string $s): string {
    return function_exists('ldap_escape') ? ldap_escape($s, '', LDAP_ESCAPE_FILTER) : str_replace(['\\','*','(',')',"\x00"], ['\\5c','\\2a','\\28','\\29','\\00'], $s);
}

function ba_ldap_login(PDO $db, string $username, string $password): bool {
    $cfg = ba_ldap_cfg($db);
    if (!$cfg['enabled'] || $cfg['host'] === '' || !function_exists('ldap_connect')) {
        return false;
    }
    if ($cfg['tls_insecure']) {
        if (defined('LDAP_OPT_X_TLS_REQUIRE_CERT')) {
            @ldap_set_option(null, LDAP_OPT_X_TLS_REQUIRE_CERT, LDAP_OPT_X_TLS_NEVER);
        }
        @putenv('LDAPTLS_REQCERT=never');
    }
    $uri = ($cfg['use_ssl'] ? 'ldaps://' : 'ldap://') . $cfg['host'] . ':' . $cfg['port'];
    $conn = @ldap_connect($uri);
    if (!$conn) {
        return false;
    }
    ldap_set_option($conn, LDAP_OPT_PROTOCOL_VERSION, 3);
    ldap_set_option($conn, LDAP_OPT_REFERRALS, 0);
    if ($cfg['bind_dn'] !== '') {
        if (!@ldap_bind($conn, $cfg['bind_dn'], $cfg['bind_password'])) {
            @ldap_unbind($conn);
            return false;
        }
    } elseif (!@ldap_bind($conn)) {
        @ldap_unbind($conn);
        return false;
    }
    $esc = ba_ldap_escape($username);
    $filter = str_replace(['{username}', '{user}'], [$esc, $esc], $cfg['user_filter']);
    $search = @ldap_search($conn, $cfg['base_dn'], $filter, ['dn', 'cn', 'displayName', 'sAMAccountName', 'memberOf']);
    if (!$search) {
        @ldap_unbind($conn);
        return false;
    }
    $entries = ldap_get_entries($conn, $search);
    if (empty($entries['count'])) {
        @ldap_unbind($conn);
        return false;
    }
    $entry = $entries[0];
    $userDn = $entry['dn'];
    $tokens = [];
    $memberOf = $entry['memberof'] ?? [];
    $n = (int)($memberOf['count'] ?? 0);
    for ($i = 0; $i < $n; $i++) {
        $dn = (string)$memberOf[$i];
        $tokens[] = $dn;
        if (preg_match('/^CN=([^,]+)/i', $dn, $m)) {
            $tokens[] = $m[1];
        }
    }
    $maps = $db->query('SELECT group_token, role FROM ldap_role_maps')->fetchAll();
    $chain = '1.2.840.113556.1.4.1941';
    $escDn = ba_ldap_escape($userDn);
    foreach ($maps as $map) {
        $tok = trim($map['group_token']);
        if ($tok === '') continue;
        $escMap = ba_ldap_escape($tok);
        if (str_contains($tok, '=')) {
            $gf = '(&(objectClass=group)(distinguishedName=' . $escMap . ')(member:' . $chain . ':=' . $escDn . '))';
        } else {
            $gf = '(&(objectClass=group)(|(cn=' . $escMap . ')(sAMAccountName=' . $escMap . '))(member:' . $chain . ':=' . $escDn . '))';
        }
        $gs = @ldap_search($conn, $cfg['base_dn'], $gf, ['dn', 'cn'], 0, 5, 8);
        if ($gs) {
            $ge = @ldap_get_entries($conn, $gs);
            if (!empty($ge['count'])) {
                $tokens[] = $tok;
                if (!empty($ge[0]['cn'][0])) $tokens[] = $ge[0]['cn'][0];
            }
        }
    }
    if (!@ldap_bind($conn, $userDn, $password)) {
        @ldap_unbind($conn);
        return false;
    }
    @ldap_unbind($conn);

    $role = null;
    $have = array_map('strtolower', $tokens);
    foreach ($maps as $map) {
        if (in_array(strtolower($map['group_token']), $have, true)) {
            $role = $map['role'];
            if ($role === 'admin') break;
        }
    }
    if ($role === null) {
        if ($cfg['require_group']) {
            return false;
        }
        $role = 'viewer';
    }
    $display = $entry['displayname'][0] ?? ($entry['cn'][0] ?? $username);
    $st = $db->prepare('SELECT * FROM users WHERE username=?');
    $st->execute([$username]);
    $row = $st->fetch();
    if ($row) {
        $db->prepare('UPDATE users SET role=?, display_name=?, source=? WHERE id=?')->execute([$role, $display, 'ldap', $row['id']]);
        $id = (int)$row['id'];
    } else {
        $db->prepare('INSERT INTO users (username, password_hash, role, source, display_name) VALUES (?, ?, ?, ?, ?)')
            ->execute([$username, 'ldap', $role, 'ldap', $display]);
        $id = (int)$db->lastInsertId();
    }
    $_SESSION['user'] = ['id' => $id, 'username' => $username, 'role' => $role];
    ba_audit($db, 'login_ldap', 'user', (string)$id);
    return true;
}
