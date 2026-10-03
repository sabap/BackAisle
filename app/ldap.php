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
        'default_role_id' => (int)ba_setting($db, 'ldap_default_role_id', '0'),
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
    if (array_key_exists('ldap_default_role_id', $post)) {
        $raw = trim((string)$post['ldap_default_role_id']);
        if ($raw === '' || $raw === '0') {
            ba_set_setting($db, 'ldap_default_role_id', '');
        } else {
            $roleId = (int)$raw;
            if (!ba_role_by_id($db, $roleId)) {
                throw new RuntimeException('Unknown default role.');
            }
            ba_set_setting($db, 'ldap_default_role_id', (string)$roleId);
        }
    }
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
    ba_ldap_prepare_tls($cfg['tls_insecure']);
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

    $mapped = ba_access_pick_role(
        ba_access_role_from_groups($db, $tokens),
        ba_access_role_from_legacy($db, $tokens)
    );
    $choice = ba_ldap_choose_role($db, $mapped, $existing ?: null, $cfg['require_group']);
    if ($choice['deny'] || $choice['role'] === null) {
        return false;
    }
    $resolved = $choice['role'];
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
        if ($choice['assign']) {
            ba_access_exec(
                $db,
                'UPDATE users SET role=?, display_name=?, source=?, role_id=?, department_id=?, email=? WHERE id=?',
                [ba_role_column($resolved['name'], (string)($resolved['code'] ?? '')), $display, 'ldap', $resolved['id'], $deptId, $email, (int)ba_col($row, 'id')]
            );
        } else {
            ba_access_exec(
                $db,
                'UPDATE users SET display_name=?, source=?, department_id=?, email=? WHERE id=?',
                [$display, 'ldap', $deptId, $email, (int)ba_col($row, 'id')]
            );
        }
        $id = (int)ba_col($row, 'id');
    } else {
        ba_access_exec(
            $db,
            'INSERT INTO users (username, password_hash, role, source, display_name, email, role_id, department_id, is_active) VALUES (?,?,?,?,?,?,?,?,1)',
            [$username, 'ldap', ba_role_column($resolved['name'], (string)($resolved['code'] ?? '')), 'ldap', $display, $mail !== '' ? $mail : null, $resolved['id'], $deptId]
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

/**
 * Mapped groups always win. The default role is only for a new account when
 * “Require a mapped security group” is off, or no role maps exist yet.
 * An existing account with no match keeps its current role.
 *
 * @param array{id:int,name:string,gate:string,permissions:list<string>}|null $mapped
 * @param array<string,mixed>|null $existing
 * @return array{deny:bool,assign:bool,role:?array}
 */
function ba_ldap_choose_role(PDO $db, ?array $mapped, ?array $existing, bool $requireGroup): array
{
    if ($mapped !== null) {
        return ['deny' => false, 'assign' => true, 'role' => $mapped];
    }
    if ($existing) {
        $keep = ba_ldap_pack_role(ba_role_by_id($db, (int)ba_col($existing, 'role_id')));
        if ($keep === null) {
            $code = ba_role_gate((string)ba_col($existing, 'role', 'viewer')) === 'admin' ? 'global' : 'view';
            $keep = ba_ldap_pack_role(ba_role_by_id($db, ba_role_id_by_code($db, $code)));
        }
        if ($keep !== null) {
            return ['deny' => false, 'assign' => false, 'role' => $keep];
        }
    }
    $fallback = !$requireGroup || !ba_ldap_role_maps_exist($db);
    if ($fallback) {
        $role = ba_ldap_default_role($db);
        if ($role !== null) {
            return ['deny' => false, 'assign' => true, 'role' => $role];
        }
    }
    return ['deny' => true, 'assign' => false, 'role' => null];
}

function ba_ldap_role_maps_exist(PDO $db): bool
{
    try {
        $n = (int)ba_access_exec(
            $db,
            "SELECT COUNT(*) FROM role_group_maps WHERE auth_source=? AND is_active=1",
            ['ldaps']
        )->fetchColumn();
        if ($n > 0) {
            return true;
        }
    } catch (Throwable $e) {
    }
    try {
        return (int)$db->query('SELECT COUNT(*) FROM ldap_role_maps')->fetchColumn() > 0;
    } catch (Throwable $e) {
        return false;
    }
}

/** @param array{id:int,name:string,permissions:list<string>}|null $role */
function ba_ldap_pack_role(?array $role): ?array
{
    if ($role === null) {
        return null;
    }
    return [
        'id' => $role['id'],
        'name' => $role['name'],
        'code' => (string)($role['code'] ?? ''),
        'gate' => ba_role_gate_for($role),
        'permissions' => $role['permissions'],
    ];
}

function ba_ldap_default_role(PDO $db): ?array
{
    $id = (int)ba_setting($db, 'ldap_default_role_id', '0');
    $role = $id > 0 ? ba_role_by_id($db, $id) : null;
    if ($role === null) {
        $role = ba_role_by_id($db, ba_role_id_by_code($db, 'view'));
    }
    return ba_ldap_pack_role($role);
}

function ba_ldap_config_dir(): string
{
    // The app pool can modify data/ and cannot modify config/. The site root is public/, so this is not served.
    return BA_ROOT . DIRECTORY_SEPARATOR . 'data';
}

function ba_ldap_ca_path(): string
{
    return ba_ldap_config_dir() . DIRECTORY_SEPARATOR . 'ldap-ca.pem';
}

function ba_ldap_trust_path(): string
{
    return ba_ldap_config_dir() . DIRECTORY_SEPARATOR . 'ldap-trust.pem';
}

/** @return array{installed:bool,path:?string,bytes:int,cert_count:int,subjects:list<string>} */
function ba_ldap_ca_status(): array
{
    $path = ba_ldap_ca_path();
    clearstatcache(true, $path);
    if (!is_file($path) || filesize($path) < 50) {
        return ['installed' => false, 'path' => null, 'bytes' => 0, 'cert_count' => 0, 'subjects' => []];
    }
    $pem = @file_get_contents($path);
    if ($pem === false || $pem === '') {
        return ['installed' => false, 'path' => null, 'bytes' => 0, 'cert_count' => 0, 'subjects' => []];
    }
    $subjects = ba_ldap_pem_subjects($pem);
    return [
        'installed' => true,
        'path' => $path,
        'bytes' => (int)filesize($path),
        'cert_count' => count($subjects),
        'subjects' => $subjects,
    ];
}

/**
 * @param array{name?:string,tmp_name?:string,error?:int,size?:int} $file
 * @return array{ok:bool,message:string,cert_count:int,subjects:list<string>}
 */
function ba_ldap_install_ca_upload(array $file, bool $append): array
{
    $err = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($err !== UPLOAD_ERR_OK) {
        throw new RuntimeException(ba_ldap_upload_error($err));
    }
    $tmp = (string)($file['tmp_name'] ?? '');
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        throw new RuntimeException('Invalid upload.');
    }
    $raw = (string)file_get_contents($tmp);
    $name = (string)($file['name'] ?? 'ca.cer');
    return ba_ldap_write_ca_bytes($raw, $name, $append);
}

/**
 * @return array{ok:bool,message:string,cert_count:int,subjects:list<string>}
 */
function ba_ldap_write_ca_bytes(string $raw, string $filename, bool $append): array
{
    if ($raw === '') {
        throw new RuntimeException('Uploaded file is empty.');
    }
    if (strlen($raw) > 1024 * 1024) {
        throw new RuntimeException('Certificate file is too large.');
    }
    $pem = ba_ldap_normalize_pem($raw, $filename);
    if (ba_ldap_pem_subjects($pem) === []) {
        throw new RuntimeException(
            'No X.509 certificates found. Upload a .pem / .crt / .cer file (Base-64 or DER). For AD CS, export the root CA certificate.'
        );
    }
    $path = ba_ldap_ca_path();
    $dir = ba_ldap_config_dir();
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('The site data folder is not writable — cannot save ldap-ca.pem.');
    }
    $out = $pem;
    if ($append && is_file($path) && filesize($path) > 50) {
        $out = rtrim((string)file_get_contents($path)) . "\n\n" . $pem;
    }
    if (!str_ends_with($out, "\n")) {
        $out .= "\n";
    }
    if (file_put_contents($path, $out) === false) {
        throw new RuntimeException('Could not write the enterprise CA file.');
    }
    @chmod($path, 0640);
    $trust = ba_ldap_trust_path();
    if (is_file($trust)) {
        @unlink($trust);
    }
    $subjects = ba_ldap_pem_subjects((string)file_get_contents($path));
    $safeName = preg_replace('/[^\w.\- ]+/', '', $filename) ?? '';
    $label = $safeName !== '' ? $safeName : 'certificate';
    return [
        'ok' => true,
        'message' => 'Enterprise CA installed (' . count($subjects) . ' certificate(s), from ' . $label . '). Uncheck certificate skip and run Test connection.',
        'cert_count' => count($subjects),
        'subjects' => $subjects,
    ];
}

function ba_ldap_remove_ca(): bool
{
    $removed = false;
    $path = ba_ldap_ca_path();
    if (is_file($path)) {
        $removed = @unlink($path);
    }
    $trust = ba_ldap_trust_path();
    if (is_file($trust)) {
        @unlink($trust);
    }
    return $removed;
}

function ba_ldap_normalize_pem(string $raw, string $filename): string
{
    $trim = trim($raw);
    if (preg_match('/-----BEGIN [A-Z0-9 ]*PRIVATE KEY-----/i', $trim)) {
        throw new RuntimeException('This file is a private key. Upload the CA certificate only.');
    }
    if (function_exists('openssl_pkey_get_private') && @openssl_pkey_get_private($trim) !== false) {
        throw new RuntimeException('This file is a private key. Upload the CA certificate only.');
    }
    if (str_contains($trim, 'BEGIN CERTIFICATE')) {
        if (!preg_match_all('/-----BEGIN CERTIFICATE-----.*?-----END CERTIFICATE-----/s', $trim, $m)) {
            throw new RuntimeException('PEM file did not contain a CERTIFICATE block.');
        }
        return implode("\n", $m[0]) . "\n";
    }
    $compact = preg_replace('/\s+/', '', $trim) ?? '';
    if ($compact !== '' && preg_match('/^[A-Za-z0-9+\/=]+$/', $compact) && strlen($compact) > 100) {
        $der = base64_decode($compact, true);
        if ($der !== false && $der !== '') {
            $raw = $der;
        }
    }
    if (function_exists('openssl_x509_read')) {
        $x509 = @openssl_x509_read($raw);
        if ($x509 === false) {
            $x509 = @openssl_x509_read(
                "-----BEGIN CERTIFICATE-----\n" . chunk_split(base64_encode($raw), 64, "\n") . "-----END CERTIFICATE-----\n"
            );
        }
        if ($x509 !== false) {
            $out = '';
            if (!@openssl_x509_export($x509, $out) || $out === '') {
                throw new RuntimeException('Could not export certificate to PEM.');
            }
            return $out;
        }
    }
    $shown = preg_replace('/[^\w.\- ]+/', '', $filename) ?? '';
    throw new RuntimeException(
        'Could not parse certificate' . ($shown !== '' ? ' from ' . $shown : '') . '. Export as Base-64 X.509 (.CER) or PEM from AD Certificate Services.'
    );
}

/** @return list<string> */
function ba_ldap_pem_subjects(string $pem): array
{
    if (!preg_match_all('/-----BEGIN CERTIFICATE-----.*?-----END CERTIFICATE-----/s', $pem, $blocks)) {
        return [];
    }
    if (!function_exists('openssl_x509_parse')) {
        return array_fill(0, count($blocks[0]), 'certificate');
    }
    $subjects = [];
    foreach ($blocks[0] as $block) {
        $x509 = @openssl_x509_read($block);
        if ($x509 === false) {
            continue;
        }
        $info = @openssl_x509_parse($x509);
        $name = 'certificate';
        if (is_array($info)) {
            $name = $info['subject']['CN'] ?? $info['subject']['OU'] ?? $info['name'] ?? 'certificate';
            if (is_array($name)) {
                $name = implode(', ', $name);
            }
        }
        $subjects[] = (string)$name;
    }
    return $subjects;
}

/** Windows PHP applies TLS options only after a new context, and only if that happens before connect. */
function ba_ldap_prepare_tls(bool $insecure): array
{
    $parts = [];
    $caFile = null;
    if ($insecure) {
        if (defined('LDAP_OPT_X_TLS_REQUIRE_CERT') && defined('LDAP_OPT_X_TLS_NEVER')) {
            @ldap_set_option(null, LDAP_OPT_X_TLS_REQUIRE_CERT, LDAP_OPT_X_TLS_NEVER);
            $parts[] = 'certificate check off';
        }
        @putenv('LDAPTLS_REQCERT=never');
        if (defined('LDAP_OPT_X_TLS_NEWCTX')) {
            @ldap_set_option(null, LDAP_OPT_X_TLS_NEWCTX, 0);
            $parts[] = 'NEWCTX';
        }
        return ['detail' => 'Skip verify: ' . implode(', ', $parts), 'ca_file' => null];
    }
    if (defined('LDAP_OPT_X_TLS_REQUIRE_CERT') && defined('LDAP_OPT_X_TLS_DEMAND')) {
        @ldap_set_option(null, LDAP_OPT_X_TLS_REQUIRE_CERT, LDAP_OPT_X_TLS_DEMAND);
        $parts[] = 'certificate check on';
    }
    @putenv('LDAPTLS_REQCERT=demand');
    $caFile = ba_ldap_trust_bundle();
    if ($caFile !== null) {
        $caNorm = str_replace('\\', '/', $caFile);
        if (defined('LDAP_OPT_X_TLS_CACERTFILE')) {
            @ldap_set_option(null, LDAP_OPT_X_TLS_CACERTFILE, $caNorm);
        }
        $caDir = str_replace('\\', '/', dirname($caFile));
        if (defined('LDAP_OPT_X_TLS_CACERTDIR')) {
            @ldap_set_option(null, LDAP_OPT_X_TLS_CACERTDIR, $caDir);
        }
        @putenv('LDAPTLS_CACERT=' . $caNorm);
        @putenv('LDAPTLS_CACERTDIR=' . $caDir);
        $conf = ba_ldap_write_conf($caNorm);
        if ($conf !== null) {
            @putenv('LDAPCONF=' . str_replace('\\', '/', $conf));
            $parts[] = 'ldap.conf';
        }
        $parts[] = 'CA file ' . $caNorm;
    } else {
        $parts[] = 'no CA file (upload an enterprise CA, or leave certificate checks off)';
    }
    if (defined('LDAP_OPT_X_TLS_NEWCTX')) {
        @ldap_set_option(null, LDAP_OPT_X_TLS_NEWCTX, 0);
        $parts[] = 'NEWCTX';
    }
    return ['detail' => implode(' · ', $parts), 'ca_file' => $caFile];
}

function ba_ldap_trust_bundle(): ?string
{
    $chunks = [];
    $enterprise = ba_ldap_ca_path();
    if (is_file($enterprise) && filesize($enterprise) > 50) {
        $chunks[] = trim((string)file_get_contents($enterprise));
    }
    $public = BA_ROOT . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'cacert.pem';
    if (is_file($public) && filesize($public) > 1000) {
        $chunks[] = trim((string)file_get_contents($public));
    }
    if ($chunks === []) {
        $iniCa = trim((string)ini_get('openssl.cafile'));
        if ($iniCa !== '' && is_file($iniCa) && filesize($iniCa) > 500) {
            return $iniCa;
        }
        return null;
    }
    $combined = implode("\n\n", $chunks) . "\n";
    $out = ba_ldap_trust_path();
    $dir = ba_ldap_config_dir();
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        return is_file($enterprise) ? $enterprise : null;
    }
    if (@file_put_contents($out, $combined) === false) {
        return is_file($enterprise) ? $enterprise : null;
    }
    @chmod($out, 0640);
    return $out;
}

function ba_ldap_write_conf(string $caFileForwardSlashes): ?string
{
    $path = ba_ldap_config_dir() . DIRECTORY_SEPARATOR . 'ldap.conf';
    $body = "TLS_CACERT {$caFileForwardSlashes}\nTLS_REQCERT demand\n";
    if (@file_put_contents($path, $body) === false) {
        return null;
    }
    return $path;
}

/**
 * Does not create a user and does not record the bind password.
 *
 * @param array{host?:string,port?:int,base_dn?:string,user_filter?:string,bind_dn?:string,bind_password?:string,use_ssl?:bool,tls_insecure?:bool} $cfg
 * @return array{ok:bool,summary:string,steps:list<array{name:string,ok:bool,detail:string}>}
 */
function ba_ldap_test_connection(array $cfg, ?string $testUsername = null, ?string $testPassword = null): array
{
    $steps = [];
    $add = static function (string $name, bool $ok, string $detail) use (&$steps): void {
        $steps[] = ['name' => $name, 'ok' => $ok, 'detail' => $detail];
    };
    $finish = static function (bool $ok, string $summary) use (&$steps): array {
        return ['ok' => $ok, 'summary' => $summary, 'steps' => $steps];
    };
    if (!function_exists('ldap_connect')) {
        $add('PHP LDAP extension', false, 'The ldap extension is not loaded. Enable extension=ldap in the site php.ini and recycle IIS.');
        return $finish(false, 'PHP LDAP extension missing.');
    }
    $add('PHP LDAP extension', true, 'ldap extension is loaded.');
    $host = trim((string)($cfg['host'] ?? ''));
    $port = (int)($cfg['port'] ?? 636);
    if ($port < 1 || $port > 65535) {
        $port = 636;
    }
    $base = trim((string)($cfg['base_dn'] ?? ''));
    $filterTpl = trim((string)($cfg['user_filter'] ?? '(sAMAccountName={username})'));
    if ($filterTpl === '') {
        $filterTpl = '(sAMAccountName={username})';
    }
    $bindDn = trim((string)($cfg['bind_dn'] ?? ''));
    $bindPassword = (string)($cfg['bind_password'] ?? '');
    $useSsl = !empty($cfg['use_ssl']);
    $insecure = !empty($cfg['tls_insecure']);
    if ($host === '') {
        $add('Configuration', false, 'Host is required.');
        return $finish(false, 'Host is required.');
    }
    if ($base === '') {
        $add('Configuration', false, 'Base DN is required.');
        return $finish(false, 'Base DN is required.');
    }
    $parsed = ['host' => $host, 'port' => $port];
    $add(
        'Configuration',
        true,
        ($useSsl ? 'ldaps://' : 'ldap://') . $host . ':' . $port
        . ' · Base DN: ' . $base
        . ($insecure ? ' · certificate check off' : ' · certificate check on')
    );
    $tlsPrep = ba_ldap_prepare_tls($insecure);
    $add('TLS setup', true, (string)$tlsPrep['detail']);
    if ($useSsl) {
        $probe = ba_ldap_tls_probe($host, $port, $insecure ? null : ($tlsPrep['ca_file'] ?? null));
        $add('TLS probe (OpenSSL)', $probe['ok'], $probe['detail']);
        if (!$probe['ok'] && !$insecure) {
            return $finish(false, 'TLS probe failed. Fix the CA or the hostname before the LDAP bind can succeed.');
        }
    }
    $uri = ($useSsl ? 'ldaps://' : 'ldap://') . $host . ':' . $port;
    $conn = @ldap_connect($uri);
    if (!$conn) {
        $add('LDAP handle', false, 'ldap_connect failed for ' . $uri);
        return $finish(false, 'Could not create an LDAP handle.');
    }
    ldap_set_option($conn, LDAP_OPT_PROTOCOL_VERSION, 3);
    ldap_set_option($conn, LDAP_OPT_REFERRALS, 0);
    if (defined('LDAP_OPT_NETWORK_TIMEOUT')) {
        @ldap_set_option($conn, LDAP_OPT_NETWORK_TIMEOUT, 12);
    }
    $add('LDAP handle', true, 'Handle created for ' . $uri . '. PHP LDAP confirms TCP and TLS on the first bind.');
    if ($bindDn !== '') {
        if ($bindPassword === '') {
            @ldap_unbind($conn);
            $add('Service bind', false, 'Bind password is empty. Type it on the form, or save LDAPS first so a blank field can reuse the saved password.');
            return $finish(false, 'Service bind password is empty.');
        }
        if (!@ldap_bind($conn, $bindDn, $bindPassword)) {
            $err = ldap_error($conn);
            $errno = ldap_errno($conn);
            @ldap_unbind($conn);
            $add('Service bind', false, ba_ldap_explain_error($err, $errno, $parsed, $tlsPrep['ca_file'] ?? null));
            return $finish(false, 'Service account bind failed.');
        }
        $add('Service bind', true, 'Bound as the service account.');
    } elseif (!@ldap_bind($conn)) {
        $err = ldap_error($conn);
        $errno = ldap_errno($conn);
        @ldap_unbind($conn);
        $add('Service bind', false, 'No Bind DN is set and anonymous bind failed: ' . ba_ldap_explain_error($err, $errno, $parsed, null));
        return $finish(false, 'Provide a Bind DN and password for the directory search.');
    } else {
        $add('Service bind', true, 'Anonymous bind succeeded.');
    }
    $probeSearch = @ldap_search($conn, $base, '(objectClass=*)', ['dn'], 0, 1, 8);
    if ($probeSearch === false) {
        $err = ldap_error($conn);
        $errno = ldap_errno($conn);
        @ldap_unbind($conn);
        $add('Base DN search', false, ba_ldap_explain_error($err, $errno, $parsed, null));
        return $finish(false, 'Could not search the Base DN.');
    }
    $add('Base DN search', true, 'Directory search against the Base DN succeeded.');
    $testUsername = trim((string)$testUsername);
    $testPassword = (string)$testPassword;
    if ($testUsername !== '') {
        $escaped = ba_ldap_escape($testUsername);
        $filter = str_replace(['{username}', '{user}'], [$escaped, $escaped], $filterTpl);
        $search = @ldap_search($conn, $base, $filter, ['dn', 'cn', 'displayName', 'sAMAccountName'], 0, 5, 8);
        if ($search === false) {
            $err = ldap_error($conn);
            $errno = ldap_errno($conn);
            @ldap_unbind($conn);
            $add('User lookup', false, ba_ldap_explain_error($err, $errno, $parsed, null) . ' · filter: ' . $filter);
            return $finish(false, 'User search failed.');
        }
        $entries = ldap_get_entries($conn, $search);
        if (empty($entries['count'])) {
            @ldap_unbind($conn);
            $add('User lookup', false, 'No entry matched filter: ' . $filter);
            return $finish(false, 'Test user was not found.');
        }
        $entry = $entries[0];
        $userDn = (string)($entry['dn'] ?? '');
        $display = $entry['displayname'][0] ?? ($entry['cn'][0] ?? $testUsername);
        $add('User lookup', true, 'Found ' . $display . ' · ' . $userDn);
        if ($testPassword !== '') {
            if (!@ldap_bind($conn, $userDn, $testPassword)) {
                $err = ldap_error($conn);
                $errno = ldap_errno($conn);
                @ldap_unbind($conn);
                $add('User password bind', false, ba_ldap_explain_error($err, $errno, $parsed, null));
                return $finish(false, 'Test user password was rejected.');
            }
            $add('User password bind', true, 'Test user credentials were accepted.');
        } else {
            $add('User password bind', true, 'Skipped (no test password).');
        }
    } else {
        $add('User lookup', true, 'Skipped. Enter a test username to check the user filter.');
    }
    @ldap_unbind($conn);
    return $finish(true, 'LDAPS connection test passed.');
}

/** @return array{ok:bool,detail:string} */
function ba_ldap_tls_probe(string $host, int $port, ?string $caFile): array
{
    if (!function_exists('stream_socket_client')) {
        return ['ok' => true, 'detail' => 'Skipped (streams unavailable).'];
    }
    $verify = $caFile !== null && is_file($caFile);
    $ssl = [
        'verify_peer' => $verify,
        'verify_peer_name' => $verify,
        'peer_name' => $host,
        'capture_peer_cert' => true,
        'allow_self_signed' => false,
    ];
    if ($verify) {
        $ssl['cafile'] = $caFile;
    }
    $ctx = stream_context_create(['ssl' => $ssl]);
    $errno = 0;
    $errstr = '';
    $fp = @stream_socket_client('ssl://' . $host . ':' . $port, $errno, $errstr, 12, STREAM_CLIENT_CONNECT, $ctx);
    if ($fp === false) {
        $detail = "OpenSSL could not complete TLS to {$host}:{$port} — {$errstr} (errno {$errno}).";
        if ($caFile !== null) {
            $detail .= ' Using CA file ' . $caFile . '.';
        }
        $detail .= ' Common causes: the uploaded CA is not the issuer of the domain controller certificate, the hostname does not match the certificate, or an intermediate is missing (upload it with Append).';
        return ['ok' => false, 'detail' => $detail];
    }
    $params = stream_context_get_params($fp);
    $peer = $params['options']['ssl']['peer_certificate'] ?? null;
    $cn = '';
    $issuer = '';
    $sans = '';
    if ($peer && function_exists('openssl_x509_parse')) {
        $info = @openssl_x509_parse($peer);
        if (is_array($info)) {
            $cn = (string)($info['subject']['CN'] ?? '');
            $issuer = (string)($info['issuer']['CN'] ?? '');
            $sans = (string)($info['extensions']['subjectAltName'] ?? '');
        }
    }
    fclose($fp);
    $detail = 'TLS handshake succeeded';
    if ($cn !== '') {
        $detail .= ' · peer CN=' . $cn;
    }
    if ($issuer !== '') {
        $detail .= ' · issuer=' . $issuer;
    }
    if ($sans !== '') {
        $detail .= ' · SAN=' . $sans;
    }
    if ($cn !== '' && strcasecmp($cn, $host) !== 0 && ($sans === '' || stripos($sans, $host) === false)) {
        $detail .= ' · hostname “' . $host . '” may not match the certificate name.';
    }
    return ['ok' => true, 'detail' => $detail];
}

function ba_ldap_explain_error(string $err, int $errno, array $parsed, ?string $caFile): string
{
    $msg = ($err !== '' ? $err : 'unknown error') . ' (ldap_errno=' . $errno . ')';
    $lower = strtolower($err);
    if (str_contains($lower, "can't contact") || str_contains($lower, 'server is unavailable') || $errno === -1 || $errno === 81) {
        $msg .= '. The TCP or TLS handshake failed. Check that this server can reach '
            . ($parsed['host'] ?? '') . ':' . ($parsed['port'] ?? 636)
            . ', that the enterprise CA issued the domain controller certificate, and that the host name matches the certificate.';
        if ($caFile) {
            $msg .= ' CA file: ' . $caFile . '.';
        }
    } elseif (str_contains($lower, 'invalid credentials') || $errno === 49) {
        $msg .= '. The bind name or password was rejected, or the account is locked or expired.';
    } elseif (str_contains($lower, 'stronger auth') || $errno === 8) {
        $msg .= '. The server requires LDAPS. Leave Use LDAPS checked.';
    }
    return $msg;
}

function ba_ldap_upload_error(int $code): string
{
    return match ($code) {
        UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Upload exceeds the PHP size limit.',
        UPLOAD_ERR_PARTIAL => 'Upload was incomplete. Try again.',
        UPLOAD_ERR_NO_FILE => 'No file selected.',
        UPLOAD_ERR_NO_TMP_DIR => 'PHP has no temporary upload directory.',
        UPLOAD_ERR_CANT_WRITE => 'PHP could not write the upload.',
        default => 'Upload failed (error ' . $code . ').',
    };
}
