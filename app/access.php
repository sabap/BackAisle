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
 * Built-in platform roles, keyed by a stable code. The name is the editable label.
 *
 * @return array<string, array{name:string, description:string, permissions:list<string>}>
 */
function ba_system_roles(): array
{
    $view = ba_view_permissions();
    return [
        'global' => [
            'name' => 'Global Admin',
            'description' => 'Full access, including users, departments, LDAPS, backups, and updates.',
            'permissions' => ['*'],
        ],
        'idfm' => [
            'name' => 'IDFM Admin',
            'description' => 'Assign which department owns each device. Edit every device, racks, templates, SNMP, org, and fleet writes. No users or site settings.',
            'permissions' => array_merge($view, [
                'edit_devices_all', 'edit_infrastructure', 'edit_templates', 'edit_snmp',
                'edit_writes', 'edit_alerts', 'edit_org',
            ]),
        ],
        'department' => [
            'name' => 'Department Admin',
            'description' => 'View the site. Add, edit, and decommission devices their department owns, and ack or clear alerts for those devices.',
            'permissions' => array_merge($view, ['edit_devices_dept', 'edit_alerts']),
        ],
        'view' => [
            'name' => 'View Only',
            'description' => 'Read-only. Dashboard, inventory, fleet, climate, and alerts.',
            'permissions' => $view,
        ],
    ];
}

/** Older labels that should become the current default name once. */
function ba_role_legacy_names(string $code): array
{
    return match ($code) {
        'global' => ['Global Admin'],
        'idfm' => ['IDFM Admin', 'Data Center Admin'],
        'department' => ['Department Admin'],
        'view' => ['View Only', 'Viewer'],
        default => [],
    };
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

function ba_role_code_gate(string $code): string
{
    return match (strtolower(trim($code))) {
        'global' => 'admin',
        'idfm' => 'dc_admin',
        'department' => 'dept_admin',
        'view' => 'viewer',
        default => '',
    };
}

function ba_role_gate(string $roleName): string
{
    $n = strtolower(trim($roleName));
    if ($n === 'global admin' || $n === 'administrator' || $n === 'admin') {
        return 'admin';
    }
    if ($n === 'idfm admin' || $n === 'data center admin' || $n === 'operator') {
        return 'dc_admin';
    }
    if ($n === 'department admin') {
        return 'dept_admin';
    }
    return 'viewer';
}

function ba_role_gate_for(array $role): string
{
    $byCode = ba_role_code_gate((string)($role['code'] ?? ''));
    if ($byCode !== '') {
        return $byCode;
    }
    return ba_role_gate((string)($role['name'] ?? ''));
}

/** users.role stays admin or viewer. SQLite installs reject any other value. The platform role lives in role_id. */
function ba_role_column(string $roleName, string $code = ''): string
{
    $gate = $code !== '' ? ba_role_code_gate($code) : '';
    if ($gate === '') {
        $gate = ba_role_gate($roleName);
    }
    return $gate === 'admin' ? 'admin' : 'viewer';
}

function ba_role_rank(string $name, array $perms, string $code = ''): int
{
    $byCode = ['global' => 100, 'idfm' => 80, 'department' => 60, 'view' => 20];
    $rank = [
        'global admin' => 100,
        'administrator' => 100,
        'idfm admin' => 80,
        'data center admin' => 80,
        'operator' => 70,
        'department admin' => 60,
        'auditor' => 30,
        'view only' => 20,
        'viewer' => 20,
    ];
    $codeKey = strtolower(trim($code));
    $score = $byCode[$codeKey] ?? ($rank[strtolower(trim($name))] ?? 10);
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
        return false;
    }
    return (int)$raw === $userDept;
}

/** null means every device. 0 means this person has no department, so none of the department-owned devices. */
function ba_alert_scope_id(array $user): ?int
{
    if (ba_can($user, 'edit_devices_all')) {
        return null;
    }
    if (ba_can($user, 'edit_devices_dept')) {
        return (int)($user['department_id'] ?? 0);
    }
    return null;
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
               is_system INT NOT NULL CONSTRAINT DF_ba_roles_system DEFAULT 1,
               code NVARCHAR(32) NULL
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
            ba_ensure_column($db, 'devices', 'decommissioned_at', 'NVARCHAR(32) NULL');
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
               is_system INTEGER NOT NULL DEFAULT 1,
               code TEXT
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
            ba_add_col($db, 'devices', 'decommissioned_at', 'TEXT');
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

function ba_roles_add_code(PDO $db): void
{
    if (!ba_table_exists($db, 'roles')) {
        return;
    }
    if (ba_db_driver() === 'sqlsrv') {
        $db->exec("IF COL_LENGTH('roles', 'code') IS NULL ALTER TABLE roles ADD code NVARCHAR(32) NULL");
        return;
    }
    if (function_exists('ba_add_col')) {
        ba_add_col($db, 'roles', 'code', 'TEXT');
    }
}

function ba_seed_roles(PDO $db): void
{
    ba_roles_add_code($db);
    foreach (ba_system_roles() as $code => $def) {
        $row = null;
        try {
            $row = ba_access_exec($db, 'SELECT id, name, code FROM roles WHERE code=?', [$code])->fetch();
        } catch (Throwable $e) {
            $row = null;
        }
        if (!$row) {
            foreach (ba_role_legacy_names($code) as $legacy) {
                $found = ba_access_exec($db, 'SELECT id, name, code FROM roles WHERE name=?', [$legacy])->fetch();
                if ($found) {
                    $row = $found;
                    break;
                }
            }
        }
        $json = json_encode($def['permissions'], JSON_UNESCAPED_UNICODE);
        if ($row) {
            $id = (int)ba_col($row, 'id');
            $name = (string)ba_col($row, 'name');
            $rename = ['view' => 'Viewer', 'idfm' => 'Data Center Admin'];
            if (isset($rename[$code]) && strcasecmp($name, $rename[$code]) === 0) {
                $name = $def['name'];
            }
            if ($code === 'global') {
                ba_access_exec(
                    $db,
                    'UPDATE roles SET name=?, description=?, permissions=?, is_system=1, code=? WHERE id=?',
                    [$name, $def['description'], '["*"]', $code, $id]
                );
            } else {
                ba_access_exec(
                    $db,
                    'UPDATE roles SET name=?, description=?, is_system=1, code=? WHERE id=?',
                    [$name, $def['description'], $code, $id]
                );
            }
            continue;
        }
        ba_access_exec(
            $db,
            'INSERT INTO roles (name, description, permissions, is_system, code) VALUES (?,?,?,1,?)',
            [$def['name'], $def['description'], $json, $code]
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
        $code = match ($gate) {
            'admin' => 'global',
            'dc_admin' => 'idfm',
            'dept_admin' => 'department',
            default => 'view',
        };
        $rid = ba_role_id_by_code($db, $code);
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
        $code = strtolower((string)ba_col($m, 'role')) === 'admin' ? 'global' : 'view';
        $rid = ba_role_id_by_code($db, $code);
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

function ba_role_id_by_code(PDO $db, string $code): int
{
    try {
        $st = ba_access_exec($db, 'SELECT id FROM roles WHERE code=?', [$code]);
        $id = (int)$st->fetchColumn();
        if ($id > 0) {
            return $id;
        }
    } catch (Throwable $e) {
    }
    foreach (ba_role_legacy_names($code) as $name) {
        $id = ba_role_id_by_name($db, $name);
        if ($id > 0) {
            return $id;
        }
    }
    return 0;
}

/** @return array{id:int, name:string, permissions:list<string>}|null */
function ba_role_by_id(PDO $db, int $id): ?array
{
    if ($id < 1) {
        return null;
    }
    $st = ba_access_exec($db, 'SELECT id, name, permissions, code FROM roles WHERE id=?', [$id]);
    $row = $st->fetch();
    if (!$row) {
        return null;
    }
    return [
        'id' => (int)ba_col($row, 'id'),
        'name' => (string)ba_col($row, 'name'),
        'code' => (string)ba_col($row, 'code'),
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
    $gate = $role ? ba_role_gate_for($role) : (string)ba_col($row, 'role', 'viewer');
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
    $sql = 'SELECT m.role_id, m.group_id, m.group_name, r.name AS role_name, r.permissions, r.code AS role_code
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
        $code = (string)ba_col($m, 'role_code');
        $perms = ba_perm_list(ba_col($m, 'permissions'));
        $score = ba_role_rank($name, $perms, $code);
        if ($score > $bestScore) {
            $bestScore = $score;
            $best = [
                'id' => (int)ba_col($m, 'role_id'),
                'name' => $name,
                'code' => $code,
                'gate' => ba_role_gate_for(['name' => $name, 'code' => $code]),
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
        $pick = strtolower((string)ba_col($m, 'role')) === 'admin' ? 'global' : ($pick ?? 'view');
        if ($pick === 'global') {
            break;
        }
    }
    if ($pick === null) {
        return null;
    }
    $role = ba_role_by_id($db, ba_role_id_by_code($db, $pick));
    if (!$role) {
        return null;
    }
    return [
        'id' => $role['id'],
        'name' => $role['name'],
        'code' => $role['code'],
        'gate' => ba_role_gate_for($role),
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
    $sa = ba_role_rank($a['name'], $a['permissions'], (string)($a['code'] ?? ''));
    $sb = ba_role_rank($b['name'], $b['permissions'], (string)($b['code'] ?? ''));
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
        echo '<label>Owning department</label><input value="' . h($name) . '" disabled>';
        echo '<input type="hidden" name="department_id" value="' . $id . '">';
        echo '<p class="muted owner-hint">New devices are owned by your department. You can edit and decommission those devices. Alerts for them go to your department.</p>';
        return;
    }
    echo '<label>Owning department</label><select name="department_id"><option value="">(none)</option>';
    foreach ($db->query('SELECT id, name FROM departments WHERE is_active=1 ORDER BY name') as $d) {
        $id = (int)ba_col($d, 'id');
        $sel = $id === (int)$selected ? ' selected' : '';
        echo '<option value="' . $id . '"' . $sel . '>' . h((string)ba_col($d, 'name')) . '</option>';
    }
    echo '</select>';
    echo '<p class="muted owner-hint">That department owns the device. Its manager and users are responsible for it, and they receive its alerts.</p>';
}

function ba_device_owner_card(PDO $db, array $user, array $device): void
{
    $deptId = (int)ba_col($device, 'department_id');
    $dept = null;
    if ($deptId > 0) {
        $dept = ba_access_exec($db, 'SELECT * FROM departments WHERE id=?', [$deptId])->fetch() ?: null;
    }
    $people = [];
    if ($dept) {
        $people = ba_access_exec(
            $db,
            'SELECT username, display_name, email FROM users WHERE department_id=? AND is_active=1 ORDER BY username',
            [$deptId]
        )->fetchAll();
    }
    $retired = trim((string)ba_col($device, 'decommissioned_at', '')) !== '';
    echo '<div class="card" id="owner"><h3>Owner</h3>';
    if ($dept) {
        $color = ba_color_hex((string)ba_col($dept, 'color_hex'));
        echo '<p><span class="dept-swatch" style="background:' . h($color) . '"></span> <strong>' . h((string)ba_col($dept, 'name')) . '</strong>';
        $code = trim((string)ba_col($dept, 'code', ''));
        if ($code !== '') {
            echo ' <span class="muted">' . h($code) . '</span>';
        }
        echo '</p>';
        $mgr = trim((string)ba_col($dept, 'manager_name', ''));
        $mail = trim((string)ba_col($dept, 'contact_email', ''));
        $phone = trim((string)ba_col($dept, 'contact_phone', ''));
        echo '<p class="muted">Responsible: ' . ($mgr !== '' ? h($mgr) : 'no manager listed');
        if ($mail !== '') {
            echo ' · ' . h($mail);
        }
        if ($phone !== '') {
            echo ' · ' . h($phone);
        }
        echo '</p>';
        if ($people) {
            echo '<div class="owner-people">';
            foreach ($people as $person) {
                $label = trim((string)ba_col($person, 'display_name', ''));
                if ($label === '') {
                    $label = (string)ba_col($person, 'username');
                }
                $em = trim((string)ba_col($person, 'email', ''));
                echo '<span>' . h($label) . ($em !== '' ? ' <span class="muted">' . h($em) . '</span>' : '') . '</span>';
            }
            echo '</div>';
        } else {
            echo '<p class="muted">No active users are in this department yet.</p>';
        }
        echo '<p class="muted">Alerts go to the department contact, to active users in the department who have an email, and to the site notification address.</p>';
    } else {
        echo '<p>No department owns this device. An IDFM Admin or Global Admin assigns one. Until then, only those roles can edit or decommission it, and alerts go to the site notification address.</p>';
    }
    if (ba_can($user, 'edit_devices_all')) {
        echo '<form method="post" class="filters">';
        echo '<input type="hidden" name="save_owner" value="1">';
        ba_department_field($db, $user, $deptId > 0 ? $deptId : null);
        echo '<button>Save owner</button></form>';
    }
    if (ba_can_edit_device($user, $device)) {
        if ($retired) {
            echo '<form method="post"><button name="recommission" value="1">Return to service</button></form>';
            echo '<p class="muted">Decommissioned ' . h((string)ba_col($device, 'decommissioned_at')) . '. Polling is off. The record is kept.</p>';
        } else {
            echo '<form method="post" onsubmit="return confirm(\'Decommission this device? Polling stops. The record stays.\')">';
            echo '<button name="decommission" value="1">Decommission</button></form>';
        }
    }
    echo '</div>';
}

function ba_active_global_admins(PDO $db, int $exceptId = 0): int
{
    $gid = ba_role_id_by_code($db, 'global');
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
