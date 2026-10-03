<?php
declare(strict_types=1);

require_once __DIR__ . '/access.php';

/** LDAPS auth mirrored from ColdAisle: service bind, user search, nested group matching rule, role maps. */

function ba_setting(PDO $db, string $k, string $default = ''): string {
    $st = $db->prepare('SELECT v FROM settings WHERE k=?');
    $st->execute([$k]);
    $r = $st->fetch();
    return $r ? (string)$r['v'] : $default;
}

function ba_ensure_column(PDO $db, string $table, string $col, string $spec): void {
    $table = preg_replace('/[^a-zA-Z0-9_]/', '', $table) ?? $table;
    $col = preg_replace('/[^a-zA-Z0-9_]/', '', $col) ?? $col;
    if (ba_db_driver() === 'sqlsrv') {
        $db->exec("IF COL_LENGTH('$table', '$col') IS NULL ALTER TABLE $table ADD $col $spec");
        return;
    }
    ba_add_col($db, $table, $col, $spec);
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

function ba_save_ldap_settings(PDO $db, array $post): void {
    foreach (['ldap_host', 'ldap_port', 'ldap_base_dn', 'ldap_user_filter', 'ldap_bind_dn'] as $k) {
        ba_set_setting($db, $k, trim((string)($post[$k] ?? '')));
    }
    if (trim((string)($post['ldap_bind_password'] ?? '')) !== '') {
        ba_set_setting($db, 'ldap_bind_password', (string)$post['ldap_bind_password']);
    }
    ba_set_setting($db, 'ldap_enabled', isset($post['ldap_enabled']) ? '1' : '0');
    ba_set_setting($db, 'ldap_use_ssl', isset($post['ldap_use_ssl']) ? '1' : '0');
    ba_set_setting($db, 'ldap_tls_insecure', isset($post['ldap_tls_insecure']) ? '1' : '0');
    ba_set_setting($db, 'ldap_require_group', isset($post['ldap_require_group']) ? '1' : '0');
}

function ba_ldap_login(PDO $db, string $username, string $password): bool {
    $cfg = ba_ldap_cfg($db);
    if (!$cfg['enabled'] || $cfg['host'] === '' || !function_exists('ldap_connect')) {
        return false;
    }
    if (function_exists('ba_ensure_access_schema')) {
        ba_ensure_access_schema($db);
    }
    $existing = ba_access_exec($db, 'SELECT * FROM users WHERE username=?', [$username])->fetch();
    if ($existing && (int)ba_col($existing, 'is_active', 1) === 0) {
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
    $chain = '1.2.840.113556.1.4.1941';
    $escDn = ba_ldap_escape($userDn);
    foreach (ba_access_map_group_tokens($db) as $tok) {
        $tok = trim($tok);
        if ($tok === '') {
            continue;
        }
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
                if (!empty($ge[0]['cn'][0])) {
                    $tokens[] = $ge[0]['cn'][0];
                }
                if (!empty($ge[0]['dn'])) {
                    $tokens[] = $ge[0]['dn'];
                }
            }
        }
    }
    if (!@ldap_bind($conn, $userDn, $password)) {
        @ldap_unbind($conn);
        return false;
    }
    @ldap_unbind($conn);

    $resolved = ba_access_pick_role(
        ba_access_role_from_groups($db, $tokens),
        ba_access_role_from_legacy($db, $tokens)
    );
    if ($resolved === null) {
        if ($cfg['require_group']) {
            return false;
        }
        $fallback = ba_role_by_id($db, ba_role_id_by_name($db, 'Viewer'));
        if (!$fallback) {
            return false;
        }
        $resolved = [
            'id' => $fallback['id'],
            'name' => $fallback['name'],
            'gate' => 'viewer',
            'permissions' => $fallback['permissions'],
        ];
    }
    $display = $entry['displayname'][0] ?? ($entry['cn'][0] ?? $username);
    $mail = trim((string)($entry['mail'][0] ?? ''));
    $deptId = ba_access_department_from_groups($db, $tokens);
    $row = $existing ?: null;
    if ($row) {
        if ($deptId === null) {
            $keep = ba_col($row, 'department_id');
            $deptId = ($keep === null || $keep === '') ? null : (int)$keep;
        }
        $email = $mail !== '' ? $mail : (ba_col($row, 'email') !== null ? (string)ba_col($row, 'email') : null);
        if ($email === '') {
            $email = null;
        }
        ba_access_exec(
            $db,
            'UPDATE users SET role=?, display_name=?, source=?, role_id=?, department_id=?, email=? WHERE id=?',
            [ba_role_column($resolved['name']), $display, 'ldap', $resolved['id'], $deptId, $email, (int)ba_col($row, 'id')]
        );
        $id = (int)ba_col($row, 'id');
    } else {
        ba_access_exec(
            $db,
            'INSERT INTO users (username, password_hash, role, source, display_name, email, role_id, department_id, is_active) VALUES (?,?,?,?,?,?,?,?,1)',
            [$username, 'ldap', ba_role_column($resolved['name']), 'ldap', $display, $mail !== '' ? $mail : null, $resolved['id'], $deptId]
        );
        $id = ba_last_id($db);
    }
    $fresh = ba_access_exec($db, 'SELECT * FROM users WHERE id=?', [$id])->fetch();
    $_SESSION['user'] = $fresh ? ba_session_user($db, $fresh) : [
        'id' => $id,
        'username' => $username,
        'role' => $resolved['gate'],
        'role_id' => $resolved['id'],
        'role_name' => $resolved['name'],
        'department_id' => $deptId,
        'permissions' => $resolved['permissions'],
    ];
    ba_audit($db, 'login_ldap', 'user', (string)$id, $resolved['name']);
    return true;
}
