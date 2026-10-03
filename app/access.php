<?php
declare(strict_types=1);

/** Departments, platform roles, and LDAPS security-group maps. */

function ba_col(array $row, string $key, mixed $default = null): mixed
{
    if (array_key_exists($key, $row)) {
        return $row[$key];
    }
    foreach ($row as $k => $v) {
        if (strcasecmp((string)$k, $key) === 0) {
            return $v;
        }
    }
    return $default;
}

function ba_access_exec(PDO $db, string $sql, array $params): PDOStatement
{
    if (function_exists('ba_pp_exec')) {
        return ba_pp_exec($db, $sql, $params);
    }
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

function ba_color_hex(?string $hex): string
{
    $hex = trim((string)$hex);
    if (preg_match('/^#?[0-9A-Fa-f]{6}$/', $hex)) {
        return '#' . ltrim($hex, '#');
    }
    return '#3b82f6';
}

/** @return list<string> */
function ba_view_permissions(): array
{
    return ['view_dashboard', 'view_idfs', 'view_devices', 'view_fleet', 'view_alerts', 'view_climate', 'view_snmp'];
}

/**
 * Built-in platform roles. Names match ColdAisle. Permissions are BackAisle areas.
 *
 * @return array<string, array{description:string, permissions:list<string>}>
 */
function ba_system_roles(): array
{
    $view = ba_view_permissions();
    return [
        'Viewer' => [
            'description' => 'Read-only. Dashboard, inventory, fleet, climate, and alerts.',
            'permissions' => $view,
        ],
        'Department Admin' => [
            'description' => 'View the site. Edit devices that belong to their department, and ack or clear alerts.',
            'permissions' => array_merge($view, ['edit_devices_dept', 'edit_alerts']),
        ],
        'Data Center Admin' => [
            'description' => 'Edit racks, inventory, templates, SNMP, org data, and fleet writes. No users or site settings.',
            'permissions' => array_merge($view, [
                'edit_devices_all', 'edit_infrastructure', 'edit_templates', 'edit_snmp',
                'edit_writes', 'edit_alerts', 'edit_org',
            ]),
        ],
        'Global Admin' => [
            'description' => 'Full access, including users, departments, LDAPS, backups, and updates.',
            'permissions' => ['*'],
        ],
    ];
}

/** @return list<array{label:string, view:?string, edit:?string, edit_dept:?string}> */
function ba_permission_modules(): array
{
    return [
        ['label' => 'Dashboard', 'view' => 'view_dashboard', 'edit' => null, 'edit_dept' => null],
        ['label' => 'IDFs and racks', 'view' => 'view_idfs', 'edit' => 'edit_infrastructure', 'edit_dept' => null],
        ['label' => 'Inventory', 'view' => 'view_devices', 'edit' => 'edit_devices_all', 'edit_dept' => 'edit_devices_dept'],
        ['label' => 'Templates', 'view' => null, 'edit' => 'edit_templates', 'edit_dept' => null],
        ['label' => 'SNMP', 'view' => 'view_snmp', 'edit' => 'edit_snmp', 'edit_dept' => null],
        ['label' => 'Fleet writes', 'view' => 'view_fleet', 'edit' => 'edit_writes', 'edit_dept' => null],
        ['label' => 'Alerts', 'view' => 'view_alerts', 'edit' => 'edit_alerts', 'edit_dept' => null],
        ['label' => 'Climate', 'view' => 'view_climate', 'edit' => null, 'edit_dept' => null],
        ['label' => 'Org', 'view' => null, 'edit' => 'edit_org', 'edit_dept' => null],
        ['label' => 'Users', 'view' => 'manage_users', 'edit' => 'manage_users', 'edit_dept' => null],
        ['label' => 'Settings', 'view' => 'manage_settings', 'edit' => 'manage_settings', 'edit_dept' => null],
    ];
}

/** @return list<string> */
function ba_permission_catalog(): array
{
    $keys = [];
    foreach (ba_permission_modules() as $mod) {
        foreach (['view', 'edit', 'edit_dept'] as $slot) {
            if (!empty($mod[$slot])) {
                $keys[] = (string)$mod[$slot];
            }
        }
    }
    return array_values(array_unique($keys));
}

/** @return list<string> */
function ba_privileged_permissions(): array
{
    return ['manage_users', 'manage_settings'];
}

function ba_role_gate(string $roleName): string
{
    $n = strtolower(trim($roleName));
    if ($n === 'global admin' || $n === 'administrator' || $n === 'admin') {
        return 'admin';
    }
    if ($n === 'data center admin' || $n === 'operator') {
        return 'dc_admin';
    }
    if ($n === 'department admin') {
        return 'dept_admin';
    }
    return 'viewer';
}

/** users.role stays admin or viewer. SQLite installs reject any other value. The platform role lives in role_id. */
function ba_role_column(string $roleName): string
{
    return ba_role_gate($roleName) === 'admin' ? 'admin' : 'viewer';
}

function ba_role_rank(string $name, array $perms): int
{
    $rank = [
        'global admin' => 100,
        'administrator' => 100,
        'data center admin' => 80,
        'operator' => 70,
        'department admin' => 60,
        'auditor' => 30,
        'viewer' => 20,
    ];
    $score = $rank[strtolower(trim($name))] ?? 10;
    if (in_array('*', $perms, true)) {
        $score = max($score, 100);
    }
    return $score;
}

/** @return list<string> */
function ba_perm_list(mixed $json): array
{
    if (is_array($json)) {
        return array_values(array_map('strval', $json));
    }
    $decoded = json_decode((string)$json, true);
    if (!is_array($decoded)) {
        return [];
    }
    return array_values(array_map('strval', $decoded));
}

function ba_can(array $user, string $perm): bool
{
    if (($user['role'] ?? '') === 'admin') {
        return true;
    }
    $perms = $user['permissions'] ?? [];
    if (!is_array($perms)) {
        return false;
    }
    if (in_array('*', $perms, true)) {
        return true;
    }
    return in_array($perm, $perms, true);
}

function ba_editor(array $user, string $perm): bool
{
    return ba_can($user, $perm);
}

function ba_can_edit_device(array $user, ?array $device): bool
{
    if (ba_can($user, 'edit_devices_all')) {
        return true;
    }
    if (!ba_can($user, 'edit_devices_dept')) {
        return false;
    }
    $userDept = (int)($user['department_id'] ?? 0);
    if ($userDept < 1) {
        return false;
    }
    if ($device === null) {
        return true;
    }
    $raw = ba_col($device, 'department_id');
    if ($raw === null || $raw === '') {
        return true;
    }
    return (int)$raw === $userDept;
}

function ba_require_perm(string $perm): array
{
    $u = ba_require_login();
    if (ba_editor($u, $perm)) {
        return $u;
    }
    http_response_code(403);
    echo 'Not allowed';
    exit;
}

function ba_ensure_access_schema(PDO $db): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    if (ba_db_driver() === 'sqlsrv') {
        $db->exec(
            "IF NOT EXISTS (SELECT * FROM sys.tables WHERE name = 'departments')
             CREATE TABLE departments (
               id INT IDENTITY(1,1) PRIMARY KEY,
               name NVARCHAR(150) NOT NULL,
               code NVARCHAR(32) NULL,
               manager_name NVARCHAR(150) NULL,
               contact_email NVARCHAR(255) NULL,
               contact_phone NVARCHAR(64) NULL,
               color_hex NVARCHAR(7) NOT NULL CONSTRAINT DF_ba_dept_color DEFAULT '#3b82f6',
               notes NVARCHAR(MAX) NULL,
               is_active INT NOT NULL CONSTRAINT DF_ba_dept_active DEFAULT 1,
               created_at DATETIME2 NOT NULL CONSTRAINT DF_ba_dept_created DEFAULT SYSUTCDATETIME()
             )"
        );
        $db->exec(
            "IF NOT EXISTS (SELECT * FROM sys.tables WHERE name = 'roles')
             CREATE TABLE roles (
               id INT IDENTITY(1,1) PRIMARY KEY,
               name NVARCHAR(64) NOT NULL UNIQUE,
               description NVARCHAR(500) NULL,
               permissions NVARCHAR(MAX) NOT NULL,
               is_system INT NOT NULL CONSTRAINT DF_ba_roles_system DEFAULT 1
             )"
        );
        $db->exec(
            "IF NOT EXISTS (SELECT * FROM sys.tables WHERE name = 'role_group_maps')
             CREATE TABLE role_group_maps (
               id INT IDENTITY(1,1) PRIMARY KEY,
               role_id INT NOT NULL,
               auth_source NVARCHAR(20) NOT NULL,
               group_id NVARCHAR(512) NOT NULL,
               group_name NVARCHAR(255) NULL,
               notes NVARCHAR(255) NULL,
               is_active INT NOT NULL CONSTRAINT DF_ba_rgm_active DEFAULT 1
             )"
        );
        $db->exec(
            "IF NOT EXISTS (SELECT * FROM sys.tables WHERE name = 'department_group_maps')
             CREATE TABLE department_group_maps (
               id INT IDENTITY(1,1) PRIMARY KEY,
               department_id INT NOT NULL,
               auth_source NVARCHAR(20) NOT NULL,
               group_id NVARCHAR(512) NOT NULL,
               group_name NVARCHAR(255) NULL,
               notes NVARCHAR(255) NULL,
               is_active INT NOT NULL CONSTRAINT DF_ba_dgm_active DEFAULT 1
             )"
        );
        if (function_exists('ba_ensure_column') && ba_table_exists($db, 'users')) {
            ba_ensure_column($db, 'users', 'email', 'NVARCHAR(255) NULL');
            ba_ensure_column($db, 'users', 'department_id', 'INT NULL');
            ba_ensure_column($db, 'users', 'role_id', 'INT NULL');
            ba_ensure_column($db, 'users', 'is_active', 'INT NOT NULL CONSTRAINT DF_ba_users_active DEFAULT 1');
            ba_ensure_column($db, 'users', 'display_name', 'NVARCHAR(255) NULL');
            ba_ensure_column($db, 'users', 'source', "NVARCHAR(32) NOT NULL CONSTRAINT DF_ba_users_source DEFAULT 'local'");
        }
        if (function_exists('ba_ensure_column') && ba_table_exists($db, 'devices')) {
            ba_ensure_column($db, 'devices', 'department_id', 'INT NULL');
        }
    } else {
        $db->exec(
            "CREATE TABLE IF NOT EXISTS departments (
               id INTEGER PRIMARY KEY,
               name TEXT NOT NULL,
               code TEXT,
               manager_name TEXT,
               contact_email TEXT,
               contact_phone TEXT,
               color_hex TEXT NOT NULL DEFAULT '#3b82f6',
               notes TEXT,
               is_active INTEGER NOT NULL DEFAULT 1,
               created_at TEXT NOT NULL DEFAULT (datetime('now'))
             )"
        );
        $db->exec(
            "CREATE TABLE IF NOT EXISTS roles (
               id INTEGER PRIMARY KEY,
               name TEXT NOT NULL UNIQUE,
               description TEXT,
               permissions TEXT NOT NULL,
               is_system INTEGER NOT NULL DEFAULT 1
             )"
        );
        $db->exec(
            "CREATE TABLE IF NOT EXISTS role_group_maps (
               id INTEGER PRIMARY KEY,
               role_id INTEGER NOT NULL,
               auth_source TEXT NOT NULL,
               group_id TEXT NOT NULL,
               group_name TEXT,
               notes TEXT,
               is_active INTEGER NOT NULL DEFAULT 1
             )"
        );
        $db->exec(
            "CREATE TABLE IF NOT EXISTS department_group_maps (
               id INTEGER PRIMARY KEY,
               department_id INTEGER NOT NULL,
               auth_source TEXT NOT NULL,
               group_id TEXT NOT NULL,
               group_name TEXT,
               notes TEXT,
               is_active INTEGER NOT NULL DEFAULT 1
             )"
        );
        if (function_exists('ba_add_col') && ba_table_exists($db, 'users')) {
            ba_add_col($db, 'users', 'email', 'TEXT');
            ba_add_col($db, 'users', 'department_id', 'INTEGER');
            ba_add_col($db, 'users', 'role_id', 'INTEGER');
            ba_add_col($db, 'users', 'is_active', 'INTEGER NOT NULL DEFAULT 1');
            ba_add_col($db, 'users', 'display_name', 'TEXT');
            ba_add_col($db, 'users', 'source', "TEXT NOT NULL DEFAULT 'local'");
        }
        if (function_exists('ba_add_col') && ba_table_exists($db, 'devices')) {
            ba_add_col($db, 'devices', 'department_id', 'INTEGER');
        }
    }
    ba_seed_roles($db);
    ba_link_user_roles($db);
    ba_import_legacy_role_maps($db);
}

function ba_table_exists(PDO $db, string $table): bool
{
    $table = preg_replace('/[^a-zA-Z0-9_]/', '', $table) ?? $table;
    try {
        if (ba_db_driver() === 'sqlsrv') {
            $st = ba_access_exec($db, 'SELECT 1 FROM sys.tables WHERE name=?', [$table]);
            return (bool)$st->fetchColumn();
        }
        $st = ba_access_exec($db, "SELECT 1 FROM sqlite_master WHERE type='table' AND name=?", [$table]);
        return (bool)$st->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

function ba_seed_roles(PDO $db): void
{
    foreach (ba_system_roles() as $name => $def) {
        $st = ba_access_exec($db, 'SELECT id, permissions FROM roles WHERE name=?', [$name]);
        $row = $st->fetch();
        $json = json_encode($def['permissions'], JSON_UNESCAPED_UNICODE);
        if ($row) {
            $fields = [$def['description'], (int)ba_col($row, 'id')];
            if ($name === 'Global Admin') {
                ba_access_exec($db, 'UPDATE roles SET description=?, permissions=?, is_system=1 WHERE id=?', [$def['description'], '["*"]', (int)ba_col($row, 'id')]);
            } else {
                ba_access_exec($db, 'UPDATE roles SET description=?, is_system=1 WHERE id=?', $fields);
            }
            continue;
        }
        ba_access_exec(
            $db,
            'INSERT INTO roles (name, description, permissions, is_system) VALUES (?,?,?,1)',
            [$name, $def['description'], $json]
        );
    }
}

function ba_link_user_roles(PDO $db): void
{
    if (!ba_table_exists($db, 'users')) {
        return;
    }
    $missing = (int)$db->query('SELECT COUNT(*) FROM users WHERE role_id IS NULL')->fetchColumn();
    if ($missing < 1) {
        return;
    }
    $rows = $db->query('SELECT id, role, role_id FROM users WHERE role_id IS NULL')->fetchAll();
    foreach ($rows as $u) {
        $gate = strtolower(trim((string)ba_col($u, 'role')));
        $name = match ($gate) {
            'admin' => 'Global Admin',
            'dc_admin' => 'Data Center Admin',
            'dept_admin' => 'Department Admin',
            default => 'Viewer',
        };
        $rid = ba_role_id_by_name($db, $name);
        if ($rid > 0) {
            ba_access_exec($db, 'UPDATE users SET role_id=? WHERE id=?', [$rid, (int)ba_col($u, 'id')]);
        }
    }
}

function ba_import_legacy_role_maps(PDO $db): void
{
    if (!function_exists('ba_setting') || !function_exists('ba_set_setting')) {
        return;
    }
    if (ba_setting($db, 'ldap_maps_imported', '0') === '1') {
        return;
    }
    try {
        $legacy = $db->query('SELECT group_token, role FROM ldap_role_maps')->fetchAll();
    } catch (Throwable $e) {
        ba_set_setting($db, 'ldap_maps_imported', '1');
        return;
    }
    foreach ($legacy as $m) {
        $token = trim((string)ba_col($m, 'group_token'));
        if ($token === '') {
            continue;
        }
        $roleName = strtolower((string)ba_col($m, 'role')) === 'admin' ? 'Global Admin' : 'Viewer';
        $rid = ba_role_id_by_name($db, $roleName);
        if ($rid < 1) {
            continue;
        }
        $dup = ba_access_exec(
            $db,
            'SELECT id FROM role_group_maps WHERE role_id=? AND auth_source=? AND group_id=?',
            [$rid, 'ldaps', $token]
        );
        if ($dup->fetch()) {
            continue;
        }
        ba_access_exec(
            $db,
            'INSERT INTO role_group_maps (role_id, auth_source, group_id, group_name, is_active) VALUES (?,?,?,?,1)',
            [$rid, 'ldaps', $token, $token]
        );
    }
    ba_set_setting($db, 'ldap_maps_imported', '1');
}

function ba_role_id_by_name(PDO $db, string $name): int
{
    $st = ba_access_exec($db, 'SELECT id FROM roles WHERE name=?', [$name]);
    return (int)$st->fetchColumn();
}

/** @return array{id:int, name:string, permissions:list<string>}|null */
function ba_role_by_id(PDO $db, int $id): ?array
{
    if ($id < 1) {
        return null;
    }
    $st = ba_access_exec($db, 'SELECT id, name, permissions FROM roles WHERE id=?', [$id]);
    $row = $st->fetch();
    if (!$row) {
        return null;
    }
    return [
        'id' => (int)ba_col($row, 'id'),
        'name' => (string)ba_col($row, 'name'),
        'permissions' => ba_perm_list(ba_col($row, 'permissions')),
    ];
}

/** @return array{id:int, username:string, role:string, role_id:int, role_name:string, department_id:?int, permissions:list<string>} */
function ba_session_user(PDO $db, array $row): array
{
    $roleId = (int)ba_col($row, 'role_id');
    $role = ba_role_by_id($db, $roleId);
    $roleName = $role['name'] ?? '';
    $perms = $role['permissions'] ?? [];
    $gate = $roleName !== '' ? ba_role_gate($roleName) : (string)ba_col($row, 'role', 'viewer');
    if ($gate === '') {
        $gate = 'viewer';
    }
    $dept = ba_col($row, 'department_id');
    return [
        'id' => (int)ba_col($row, 'id'),
        'username' => (string)ba_col($row, 'username'),
        'role' => $gate,
        'role_id' => $roleId,
        'role_name' => $roleName !== '' ? $roleName : $gate,
        'department_id' => ($dept === null || $dept === '') ? null : (int)$dept,
        'permissions' => $perms,
    ];
}

function ba_refresh_session(PDO $db): ?array
{
    $u = ba_user();
    if (!$u || empty($u['id'])) {
        return $u;
    }
    try {
        $st = ba_access_exec($db, 'SELECT * FROM users WHERE id=?', [(int)$u['id']]);
        $row = $st->fetch();
    } catch (Throwable $e) {
        return $u;
    }
    if (!$row) {
        return null;
    }
    if ((int)ba_col($row, 'is_active', 1) === 0) {
        return null;
    }
    $fresh = ba_session_user($db, $row);
    $_SESSION['user'] = $fresh;
    return $fresh;
}

/**
 * Lowercase tokens plus the CN of any DN, for security-group matching.
 *
 * @param list<string> $raw
 * @return array<string, true>
 */
function ba_group_token_set(array $raw): array
{
    $want = [];
    foreach ($raw as $item) {
        $text = trim((string)$item);
        if ($text === '') {
            continue;
        }
        $want[strtolower($text)] = true;
        if (preg_match('/cn=([^,]+)/i', $text, $m)) {
            $cn = strtolower(trim($m[1]));
            if ($cn !== '') {
                $want[$cn] = true;
            }
        }
    }
    return $want;
}

function ba_group_matches(array $want, string $groupId, ?string $groupName): bool
{
    foreach ([$groupId, (string)$groupName] as $g) {
        $g = trim($g);
        if ($g === '') {
            continue;
        }
        if (isset($want[strtolower($g)])) {
            return true;
        }
        if (preg_match('/cn=([^,]+)/i', $g, $m) && isset($want[strtolower(trim($m[1]))])) {
            return true;
        }
    }
    return false;
}

/** @return list<string> */
function ba_access_map_group_tokens(PDO $db): array
{
    $out = [];
    try {
        foreach ($db->query("SELECT group_id, group_name FROM role_group_maps WHERE is_active=1 AND auth_source='ldaps'") as $m) {
            foreach ([(string)ba_col($m, 'group_id'), (string)ba_col($m, 'group_name')] as $g) {
                if (trim($g) !== '') {
                    $out[] = trim($g);
                }
            }
        }
        foreach ($db->query("SELECT group_id, group_name FROM department_group_maps WHERE is_active=1 AND auth_source='ldaps'") as $m) {
            foreach ([(string)ba_col($m, 'group_id'), (string)ba_col($m, 'group_name')] as $g) {
                if (trim($g) !== '') {
                    $out[] = trim($g);
                }
            }
        }
    } catch (Throwable $e) {
    }
    try {
        foreach ($db->query('SELECT group_token FROM ldap_role_maps') as $m) {
            $g = trim((string)ba_col($m, 'group_token'));
            if ($g !== '') {
                $out[] = $g;
            }
        }
    } catch (Throwable $e) {
    }
    return array_values(array_unique($out));
}

/**
 * Highest-privilege LDAPS role map that matches the user's groups.
 *
 * @param list<string> $tokens
 * @return array{id:int, name:string, gate:string, permissions:list<string>}|null
 */
function ba_access_role_from_groups(PDO $db, array $tokens): ?array
{
    $want = ba_group_token_set($tokens);
    if (!$want) {
        return null;
    }
    $sql = 'SELECT m.role_id, m.group_id, m.group_name, r.name AS role_name, r.permissions
            FROM role_group_maps m
            INNER JOIN roles r ON r.id = m.role_id
            WHERE m.is_active=1 AND m.auth_source=?';
    try {
        $maps = ba_access_exec($db, $sql, ['ldaps'])->fetchAll();
    } catch (Throwable $e) {
        return null;
    }
    $best = null;
    $bestScore = -1;
    foreach ($maps as $m) {
        if (!ba_group_matches($want, (string)ba_col($m, 'group_id'), (string)ba_col($m, 'group_name'))) {
            continue;
        }
        $name = (string)ba_col($m, 'role_name');
        $perms = ba_perm_list(ba_col($m, 'permissions'));
        $score = ba_role_rank($name, $perms);
        if ($score > $bestScore) {
            $bestScore = $score;
            $best = [
                'id' => (int)ba_col($m, 'role_id'),
                'name' => $name,
                'gate' => ba_role_gate($name),
                'permissions' => $perms,
            ];
        }
    }
    return $best;
}

/**
 * Older Org → LDAPS maps (group token → admin|viewer), if the new table has no hit.
 *
 * @param list<string> $tokens
 * @return array{id:int, name:string, gate:string, permissions:list<string>}|null
 */
function ba_access_role_from_legacy(PDO $db, array $tokens): ?array
{
    $want = ba_group_token_set($tokens);
    if (!$want) {
        return null;
    }
    try {
        $maps = $db->query('SELECT group_token, role FROM ldap_role_maps')->fetchAll();
    } catch (Throwable $e) {
        return null;
    }
    $pick = null;
    foreach ($maps as $m) {
        $token = trim((string)ba_col($m, 'group_token'));
        if ($token === '' || !isset($want[strtolower($token)])) {
            continue;
        }
        $pick = strtolower((string)ba_col($m, 'role')) === 'admin' ? 'Global Admin' : ($pick ?? 'Viewer');
        if ($pick === 'Global Admin') {
            break;
        }
    }
    if ($pick === null) {
        return null;
    }
    $role = ba_role_by_id($db, ba_role_id_by_name($db, $pick));
    if (!$role) {
        return null;
    }
    return [
        'id' => $role['id'],
        'name' => $role['name'],
        'gate' => ba_role_gate($role['name']),
        'permissions' => $role['permissions'],
    ];
}

/**
 * @param array{id:int, name:string, gate:string, permissions:list<string>}|null $a
 * @param array{id:int, name:string, gate:string, permissions:list<string>}|null $b
 * @return array{id:int, name:string, gate:string, permissions:list<string>}|null
 */
function ba_access_pick_role(?array $a, ?array $b): ?array
{
    if ($a === null) {
        return $b;
    }
    if ($b === null) {
        return $a;
    }
    $sa = ba_role_rank($a['name'], $a['permissions']);
    $sb = ba_role_rank($b['name'], $b['permissions']);
    return $sa >= $sb ? $a : $b;
}

/** @param list<string> $tokens */
function ba_access_department_from_groups(PDO $db, array $tokens): ?int
{
    $want = ba_group_token_set($tokens);
    if (!$want) {
        return null;
    }
    try {
        $maps = ba_access_exec(
            $db,
            'SELECT department_id, group_id, group_name FROM department_group_maps WHERE is_active=1 AND auth_source=? ORDER BY id',
            ['ldaps']
        )->fetchAll();
    } catch (Throwable $e) {
        return null;
    }
    foreach ($maps as $m) {
        if (ba_group_matches($want, (string)ba_col($m, 'group_id'), (string)ba_col($m, 'group_name'))) {
            return (int)ba_col($m, 'department_id');
        }
    }
    return null;
}

function ba_device_department_choice(array $user): ?int
{
    if (ba_can($user, 'edit_devices_dept') && !ba_can($user, 'edit_devices_all')) {
        $id = (int)($user['department_id'] ?? 0);
        return $id > 0 ? $id : null;
    }
    $posted = (int)($_POST['department_id'] ?? 0);
    return $posted > 0 ? $posted : null;
}

function ba_department_field(PDO $db, array $user, ?int $selected): void
{
    if (ba_can($user, 'edit_devices_dept') && !ba_can($user, 'edit_devices_all')) {
        $id = (int)($user['department_id'] ?? 0);
        $name = '';
        if ($id > 0) {
            $st = ba_access_exec($db, 'SELECT name FROM departments WHERE id=?', [$id]);
            $name = (string)$st->fetchColumn();
        }
        echo '<label>Department</label><input value="' . h($name) . '" disabled>';
        echo '<input type="hidden" name="department_id" value="' . $id . '">';
        return;
    }
    echo '<label>Department</label><select name="department_id"><option value="">(none)</option>';
    foreach ($db->query('SELECT id, name FROM departments WHERE is_active=1 ORDER BY name') as $d) {
        $id = (int)ba_col($d, 'id');
        $sel = $id === (int)$selected ? ' selected' : '';
        echo '<option value="' . $id . '"' . $sel . '>' . h((string)ba_col($d, 'name')) . '</option>';
    }
    echo '</select>';
}

function ba_active_global_admins(PDO $db, int $exceptId = 0): int
{
    $gid = ba_role_id_by_name($db, 'Global Admin');
    if ($gid < 1) {
        $st = ba_access_exec($db, "SELECT COUNT(*) FROM users WHERE is_active=1 AND role='admin' AND id<>?", [$exceptId]);
        return (int)$st->fetchColumn();
    }
    $st = ba_access_exec(
        $db,
        'SELECT COUNT(*) FROM users WHERE is_active=1 AND (role_id=? OR role=?) AND id<>?',
        [$gid, 'admin', $exceptId]
    );
    return (int)$st->fetchColumn();
}
