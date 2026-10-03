<?php
declare(strict_types=1);

function ba_users_redirect(string $extra = ''): void
{
    $url = ba_href('/users');
    if ($extra !== '') {
        $url .= (str_contains($url, '?') ? '&' : '?') . ltrim($extra, '?');
    }
    header('Location: ' . $url);
    exit;
}

function ba_users_fail(string $msg): void
{
    $_SESSION['ba_flash'] = $msg;
    $_SESSION['ba_flash_type'] = 'err';
}

function page_users(PDO $db, array $user): void
{
    ba_require_perm('manage_users');
    ba_ensure_access_schema($db);
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['act'] ?? '') === 'ldap_test') {
        ba_users_ldap_test_response($db);
        exit;
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $act = (string)($_POST['act'] ?? '');
        try {
            ba_users_post($db, $user, $act);
            if (empty($_SESSION['ba_flash'])) {
                $_SESSION['ba_flash'] = 'Saved';
                $_SESSION['ba_flash_type'] = 'ok';
            }
        } catch (Throwable $e) {
            ba_users_fail($e->getMessage());
        }
        $jump = (string)($_POST['jump'] ?? '');
        $failed = (($_SESSION['ba_flash_type'] ?? '') === 'err');
        $extra = '';
        if ($failed && $act === 'update_user') {
            $extra = 'edit_user=' . (int)($_POST['id'] ?? 0);
        } elseif ($failed && $act === 'update_department') {
            $extra = 'edit_dept=' . (int)($_POST['id'] ?? 0);
        } elseif ($failed && in_array($act, ['add_department', 'add_user', 'add_role_map', 'add_dept_map'], true)) {
            $extra = 'modal=' . rawurlencode($act);
        }
        $url = ba_href('/users' . ($extra !== '' ? '?' . $extra : ''));
        if ($jump !== '') {
            $url .= '#' . preg_replace('/[^a-z0-9_-]/i', '', $jump);
        }
        header('Location: ' . $url);
        exit;
    }

    $q = trim((string)($_GET['q'] ?? ''));
    $editUserId = (int)($_GET['edit_user'] ?? 0);
    $editDeptId = (int)($_GET['edit_dept'] ?? 0);
    $openModal = (string)($_GET['modal'] ?? '');
    $msg = '';
    $flashType = 'ok';
    if (!empty($_SESSION['ba_flash'])) {
        $msg = (string)$_SESSION['ba_flash'];
        unset($_SESSION['ba_flash']);
    }
    if (!empty($_SESSION['ba_flash_type'])) {
        $flashType = (string)$_SESSION['ba_flash_type'];
        unset($_SESSION['ba_flash_type']);
    }

    $roles = $db->query('SELECT * FROM roles ORDER BY name')->fetchAll();
    $roleOrder = ['global' => 1, 'idfm' => 2, 'department' => 3, 'view' => 4];
    usort($roles, static function ($a, $b) use ($roleOrder) {
        $oa = $roleOrder[ba_col($a, 'code') ?? ''] ?? 50;
        $ob = $roleOrder[ba_col($b, 'code') ?? ''] ?? 50;
        if ($oa !== $ob) {
            return $oa <=> $ob;
        }
        return strcasecmp((string)$a['name'], (string)$b['name']);
    });
    $depts = $db->query(
        'SELECT d.*,
            (SELECT COUNT(*) FROM users u WHERE u.department_id = d.id AND u.is_active = 1) AS user_count,
            (SELECT COUNT(*) FROM devices dev WHERE dev.department_id = d.id) AS device_count
         FROM departments d ORDER BY d.name'
    )->fetchAll();
    $userSql = 'SELECT u.*, r.name AS role_name, d.name AS department_name, d.color_hex AS department_color
        FROM users u
        LEFT JOIN roles r ON r.id = u.role_id
        LEFT JOIN departments d ON d.id = u.department_id';
    $userParams = [];
    if ($q !== '') {
        $like = '%' . $q . '%';
        $userSql .= " WHERE u.username LIKE ? OR IFNULL(u.display_name,'') LIKE ? OR IFNULL(u.email,'') LIKE ?
            OR IFNULL(d.name,'') LIKE ? OR IFNULL(r.name,'') LIKE ?";
        $userParams = [$like, $like, $like, $like, $like];
    }
    $userSql .= ' ORDER BY u.username';
    $users = ba_access_exec($db, $userSql, $userParams)->fetchAll();
    $roleMaps = $db->query(
        "SELECT m.*, r.name AS role_name FROM role_group_maps m
         INNER JOIN roles r ON r.id = m.role_id
         WHERE m.auth_source='ldaps' ORDER BY r.name, m.group_name"
    )->fetchAll();
    $deptMaps = $db->query(
        "SELECT m.*, d.name AS department_name, d.color_hex FROM department_group_maps m
         INNER JOIN departments d ON d.id = m.department_id
         WHERE m.auth_source='ldaps' ORDER BY d.name, m.group_name"
    )->fetchAll();
    $editUser = null;
    foreach ($users as $row) {
        if ((int)ba_col($row, 'id') === $editUserId) {
            $editUser = $row;
            break;
        }
    }
    $editDept = null;
    foreach ($depts as $row) {
        if ((int)ba_col($row, 'id') === $editDeptId) {
            $editDept = $row;
            break;
        }
    }
    $ldap = ba_ldap_cfg($db);
    $activeUsers = 0;
    foreach ($users as $row) {
        if ((int)ba_col($row, 'is_active', 1) === 1) {
            $activeUsers++;
        }
    }

    ba_layout_start('Users & Departments', 'users');
    if ($msg !== '') {
        $cls = in_array($flashType, ['ok', 'info', 'err'], true) ? $flashType : 'ok';
        echo '<div class="flash ' . $cls . '">' . h($msg) . '</div>';
    }
    echo '<h1>Users &amp; departments</h1>';
    echo '<p class="muted">Local accounts, platform roles, and departments. A directory user is created the first time they sign in. A matching security-group map sets the role. The default role is used only when group requirement is off, or no role maps exist yet.</p>';
    echo '<div class="kpis">';
    echo '<div class="kpi"><span>Users</span><b>' . count($users) . '</b></div>';
    echo '<div class="kpi"><span>Active</span><b>' . $activeUsers . '</b></div>';
    echo '<div class="kpi"><span>Departments</span><b>' . count($depts) . '</b></div>';
    echo '<div class="kpi"><span>Group maps</span><b>' . (count($roleMaps) + count($deptMaps)) . '</b></div>';
    echo '</div>';

    ba_users_ldap_card($ldap, $roles);
    ba_users_roles_card($db, $roles);
    echo '<div class="users-board">';
    ba_users_dept_card($depts, $editDept, $openModal);
    ba_users_people_card($db, $users, $roles, $depts, $editUser, $q, $openModal);
    echo '</div><div class="users-board">';
    ba_users_role_map_card($roles, $roleMaps, $openModal);
    ba_users_dept_map_card($depts, $deptMaps, $openModal);
    echo '</div>';
    ba_users_modal_script();
    ba_layout_end();
}

function ba_users_post(PDO $db, array $actor, string $act): void
{
    if ($act === 'ldap_save') {
        ba_save_ldap_settings($db, $_POST);
        $note = 'LDAPS settings saved';
        if (!empty($_POST['ldap_remove_ca'])) {
            $note = ba_ldap_remove_ca()
                ? 'Removed the enterprise CA. LDAPS settings saved.'
                : 'No enterprise CA file to remove. LDAPS settings saved.';
            ba_audit($db, 'ldap_ca_remove', 'ldap', null);
        } elseif (!empty($_FILES['ldap_ca_file']['name'])) {
            $install = ba_ldap_install_ca_upload($_FILES['ldap_ca_file'], !empty($_POST['ldap_ca_append']));
            $note = (string)$install['message'];
            ba_audit($db, 'ldap_ca_install', 'ldap', null, (string)($install['cert_count'] ?? 0) . ' certs');
        }
        ba_audit($db, 'ldap_save', 'ldap', null);
        $_SESSION['ba_flash'] = $note;
        $_SESSION['ba_flash_type'] = 'ok';
        return;
    }
    if ($act === 'save_roles' || $act === 'reset_roles') {
        ba_users_save_roles($db, $act);
        ba_audit($db, $act, 'roles', null);
        $_SESSION['ba_flash'] = $act === 'reset_roles' ? 'Role permissions restored' : 'Role permissions saved';
        $_SESSION['ba_flash_type'] = 'ok';
        return;
    }
    if ($act === 'add_department' || $act === 'update_department') {
        ba_users_save_department($db, $act);
        return;
    }
    if ($act === 'add_user' || $act === 'update_user') {
        ba_users_save_user($db, $actor, $act);
        return;
    }
    if ($act === 'add_role_map') {
        $rid = (int)($_POST['role_id'] ?? 0);
        $gid = trim((string)($_POST['group_id'] ?? ''));
        if ($rid < 1 || $gid === '') {
            throw new RuntimeException('Role and group id are required.');
        }
        if (!ba_role_by_id($db, $rid)) {
            throw new RuntimeException('Unknown role.');
        }
        $name = trim((string)($_POST['group_name'] ?? ''));
        ba_access_exec(
            $db,
            'INSERT INTO role_group_maps (role_id, auth_source, group_id, group_name, notes, is_active) VALUES (?,?,?,?,?,1)',
            [$rid, 'ldaps', $gid, $name !== '' ? $name : null, ba_users_notes()]
        );
        ba_audit($db, 'add_role_map', 'role_group_map', $gid);
        $_SESSION['ba_flash'] = 'Security group role map added. It applies the next time that group signs in.';
        $_SESSION['ba_flash_type'] = 'ok';
        return;
    }
    if ($act === 'delete_role_map') {
        $id = (int)($_POST['id'] ?? 0);
        ba_access_exec($db, 'DELETE FROM role_group_maps WHERE id=?', [$id]);
        ba_audit($db, 'delete_role_map', 'role_group_map', (string)$id);
        $_SESSION['ba_flash'] = 'Role map removed';
        $_SESSION['ba_flash_type'] = 'ok';
        return;
    }
    if ($act === 'add_dept_map') {
        $did = (int)($_POST['department_id'] ?? 0);
        $gid = trim((string)($_POST['group_id'] ?? ''));
        if ($did < 1 || $gid === '') {
            throw new RuntimeException('Department and group id are required.');
        }
        $name = trim((string)($_POST['group_name'] ?? ''));
        ba_access_exec(
            $db,
            'INSERT INTO department_group_maps (department_id, auth_source, group_id, group_name, notes, is_active) VALUES (?,?,?,?,?,1)',
            [$did, 'ldaps', $gid, $name !== '' ? $name : null, ba_users_notes()]
        );
        ba_audit($db, 'add_dept_map', 'department_group_map', $gid);
        $_SESSION['ba_flash'] = 'Security group department map added. It applies the next time that group signs in.';
        $_SESSION['ba_flash_type'] = 'ok';
        return;
    }
    if ($act === 'delete_dept_map') {
        $id = (int)($_POST['id'] ?? 0);
        ba_access_exec($db, 'DELETE FROM department_group_maps WHERE id=?', [$id]);
        ba_audit($db, 'delete_dept_map', 'department_group_map', (string)$id);
        $_SESSION['ba_flash'] = 'Department map removed';
        $_SESSION['ba_flash_type'] = 'ok';
        return;
    }
    throw new RuntimeException('Unknown action');
}

function ba_users_notes(): ?string
{
    $n = trim((string)($_POST['notes'] ?? ''));
    return $n === '' ? null : $n;
}

function ba_users_save_roles(PDO $db, string $act): void
{
    $defs = ba_system_roles();
    $rows = ba_access_exec($db, 'SELECT id, name, code FROM roles', [])->fetchAll();
    $system = [];
    foreach ($rows as $row) {
        $code = (string)ba_col($row, 'code');
        if (!isset($defs[$code])) {
            continue;
        }
        $system[] = $row;
    }
    if ($act === 'reset_roles') {
        foreach ($system as $row) {
            $code = (string)ba_col($row, 'code');
            $def = $defs[$code];
            $json = $code === 'global' ? '["*"]' : json_encode($def['permissions'], JSON_UNESCAPED_UNICODE);
            ba_access_exec(
                $db,
                'UPDATE roles SET name=?, description=?, permissions=? WHERE id=?',
                [$def['name'], $def['description'], $json, (int)ba_col($row, 'id')]
            );
        }
        return;
    }
    $postedNames = $_POST['role_name'] ?? [];
    if (!is_array($postedNames)) {
        $postedNames = [];
    }
    $chosen = [];
    $seen = [];
    foreach ($system as $row) {
        $id = (int)ba_col($row, 'id');
        $name = trim((string)($postedNames[$id] ?? $postedNames[(string)$id] ?? ba_col($row, 'name')));
        if ($name === '') {
            throw new RuntimeException('Each role needs a name.');
        }
        if (strlen($name) > 64) {
            throw new RuntimeException('Role names must be 64 characters or less.');
        }
        $key = strtolower($name);
        if (isset($seen[$key])) {
            throw new RuntimeException('Role names must be different.');
        }
        $seen[$key] = $id;
        $chosen[$id] = $name;
    }
    $allowed = array_flip(ba_permission_catalog());
    $privileged = array_flip(ba_privileged_permissions());
    $posted = $_POST['perm'] ?? [];
    if (!is_array($posted)) {
        $posted = [];
    }
    foreach ($system as $row) {
        $id = (int)ba_col($row, 'id');
        $code = (string)ba_col($row, 'code');
        $name = $chosen[$id];
        if ($code === 'global') {
            ba_access_exec($db, 'UPDATE roles SET name=?, permissions=? WHERE id=?', [$name, '["*"]', $id]);
            continue;
        }
        $keys = $posted[$id] ?? $posted[(string)$id] ?? [];
        if (!is_array($keys)) {
            $keys = [];
        }
        $clean = [];
        foreach ($keys as $k) {
            $k = (string)$k;
            if (!isset($allowed[$k]) || isset($privileged[$k])) {
                continue;
            }
            $clean[$k] = true;
        }
        foreach (ba_permission_modules() as $mod) {
            $view = $mod['view'] ?? null;
            if ($view && (
                (!empty($mod['edit']) && isset($clean[$mod['edit']]))
                || (!empty($mod['edit_dept']) && isset($clean[$mod['edit_dept']]))
            )) {
                $clean[$view] = true;
            }
        }
        ba_access_exec(
            $db,
            'UPDATE roles SET name=?, permissions=? WHERE id=?',
            [$name, json_encode(array_keys($clean), JSON_UNESCAPED_UNICODE), $id]
        );
    }
}

function ba_users_save_department(PDO $db, string $act): void
{
    $name = trim((string)($_POST['name'] ?? ''));
    if ($name === '') {
        throw new RuntimeException('Department name is required.');
    }
    $code = trim((string)($_POST['code'] ?? ''));
    $manager = trim((string)($_POST['manager_name'] ?? ''));
    $email = trim((string)($_POST['contact_email'] ?? ''));
    $phone = trim((string)($_POST['contact_phone'] ?? ''));
    $notes = ba_users_notes();
    $color = ba_color_hex((string)($_POST['color_hex'] ?? ''));
    $blank = static function (string $v): ?string {
        return $v === '' ? null : $v;
    };
    if ($act === 'add_department') {
        ba_access_exec(
            $db,
            'INSERT INTO departments (name, code, manager_name, contact_email, contact_phone, color_hex, notes, is_active) VALUES (?,?,?,?,?,?,?,1)',
            [$name, $blank($code), $blank($manager), $blank($email), $blank($phone), $color, $notes]
        );
        ba_audit($db, 'add_department', 'department', $name);
        $_SESSION['ba_flash'] = 'Department added';
        $_SESSION['ba_flash_type'] = 'ok';
        return;
    }
    $id = (int)($_POST['id'] ?? 0);
    if ($id < 1) {
        throw new RuntimeException('Department required.');
    }
    $active = isset($_POST['is_active']) ? 1 : 0;
    ba_access_exec(
        $db,
        'UPDATE departments SET name=?, code=?, manager_name=?, contact_email=?, contact_phone=?, color_hex=?, notes=?, is_active=? WHERE id=?',
        [$name, $blank($code), $blank($manager), $blank($email), $blank($phone), $color, $notes, $active, $id]
    );
    ba_audit($db, 'update_department', 'department', (string)$id, $name);
    $_SESSION['ba_flash'] = 'Department saved';
    $_SESSION['ba_flash_type'] = 'ok';
}

function ba_users_password(bool $required): ?string
{
    $password = (string)($_POST['password'] ?? '');
    $confirm = (string)($_POST['password_confirm'] ?? '');
    if ($password === '' && $confirm === '') {
        if ($required) {
            throw new RuntimeException('Password and confirmation are required for local accounts.');
        }
        return null;
    }
    if ($password !== $confirm) {
        throw new RuntimeException('Password and confirmation do not match.');
    }
    if (strlen($password) < 8) {
        throw new RuntimeException('Password must be at least 8 characters.');
    }
    return $password;
}

function ba_users_save_user(PDO $db, array $actor, string $act): void
{
    $roleId = (int)($_POST['role_id'] ?? 0);
    $role = ba_role_by_id($db, $roleId);
    if (!$role) {
        throw new RuntimeException('Select a platform role.');
    }
    $deptRaw = (int)($_POST['department_id'] ?? 0);
    $dept = $deptRaw > 0 ? $deptRaw : null;
    $display = trim((string)($_POST['display_name'] ?? ''));
    $email = trim((string)($_POST['email'] ?? ''));
    $source = (string)($_POST['source'] ?? 'local') === 'ldap' ? 'ldap' : 'local';
    $active = isset($_POST['is_active']) ? 1 : 0;
    $gate = ba_role_gate_for($role);
    if ($act === 'add_user') {
        $username = trim((string)($_POST['username'] ?? ''));
        if ($username === '') {
            throw new RuntimeException('Username is required.');
        }
        $dup = ba_access_exec($db, 'SELECT id FROM users WHERE username=?', [$username]);
        if ($dup->fetch()) {
            throw new RuntimeException('That username already exists.');
        }
        $hash = 'ldap';
        if ($source === 'local') {
            $hash = password_hash((string)ba_users_password(true), PASSWORD_DEFAULT);
        }
        ba_access_exec(
            $db,
            'INSERT INTO users (username, password_hash, role, source, display_name, email, role_id, department_id, is_active) VALUES (?,?,?,?,?,?,?,?,?)',
            [$username, $hash, ba_role_column($role['name'], (string)($role['code'] ?? '')), $source, $display !== '' ? $display : null, $email !== '' ? $email : null, $roleId, $dept, $active]
        );
        ba_audit($db, 'create_user', 'user', $username, $role['name']);
        $_SESSION['ba_flash'] = 'User created';
        $_SESSION['ba_flash_type'] = 'ok';
        return;
    }
    $id = (int)($_POST['id'] ?? 0);
    $existing = ba_access_exec($db, 'SELECT * FROM users WHERE id=?', [$id])->fetch();
    if (!$existing) {
        throw new RuntimeException('User not found.');
    }
    if ($id === (int)$actor['id'] && $active !== 1) {
        throw new RuntimeException('You cannot turn off your own account.');
    }
    $wasGlobal = ba_role_gate((string)ba_col($existing, 'role')) === 'admin'
        || (int)ba_col($existing, 'role_id') === ba_role_id_by_code($db, 'global');
    if ($wasGlobal && ($gate !== 'admin' || $active !== 1) && ba_active_global_admins($db, $id) < 1) {
        throw new RuntimeException('Keep at least one active Global Admin.');
    }
    $source = (string)ba_col($existing, 'source', 'local') === 'ldap' ? 'ldap' : $source;
    ba_access_exec(
        $db,
        'UPDATE users SET role=?, source=?, display_name=?, email=?, role_id=?, department_id=?, is_active=? WHERE id=?',
        [ba_role_column($role['name'], (string)($role['code'] ?? '')), $source, $display !== '' ? $display : null, $email !== '' ? $email : null, $roleId, $dept, $active, $id]
    );
    if ($source === 'local') {
        $plain = ba_users_password(false);
        if ($plain !== null) {
            ba_access_exec($db, 'UPDATE users SET password_hash=? WHERE id=?', [password_hash($plain, PASSWORD_DEFAULT), $id]);
        }
    }
    ba_audit($db, 'update_user', 'user', (string)$id, $role['name']);
    $_SESSION['ba_flash'] = 'User saved';
    $_SESSION['ba_flash_type'] = 'ok';
}

function ba_users_ldap_test_response(PDO $db): void
{
    header('Content-Type: application/json; charset=utf-8');
    try {
        $saved = ba_ldap_cfg($db);
        $pass = (string)($_POST['ldap_bind_password'] ?? '');
        if ($pass === '') {
            $pass = (string)$saved['bind_password'];
        }
        $result = ba_ldap_test_connection(
            [
                'host' => trim((string)($_POST['ldap_host'] ?? '')),
                'port' => (int)($_POST['ldap_port'] ?? 636),
                'base_dn' => trim((string)($_POST['ldap_base_dn'] ?? '')),
                'user_filter' => trim((string)($_POST['ldap_user_filter'] ?? '(sAMAccountName={username})')),
                'bind_dn' => trim((string)($_POST['ldap_bind_dn'] ?? '')),
                'bind_password' => $pass,
                'use_ssl' => isset($_POST['ldap_use_ssl']),
                'tls_insecure' => isset($_POST['ldap_tls_insecure']),
            ],
            trim((string)($_POST['ldap_test_username'] ?? '')),
            (string)($_POST['ldap_test_password'] ?? '')
        );
    } catch (Throwable $e) {
        $result = ['ok' => false, 'summary' => 'Test error: ' . $e->getMessage(), 'steps' => []];
    }
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function ba_users_ldap_card(array $ldap, array $roles): void
{
    $ext = function_exists('ldap_connect') ? 'PHP LDAP is loaded.' : 'PHP LDAP is not loaded. LDAPS sign-in will not work until that extension is enabled.';
    $ca = ba_ldap_ca_status();
    $defaultId = (int)($ldap['default_role_id'] ?? 0);
    echo '<div class="ucard" id="ldaps">';
    echo '<div class="ucard-head"><h3>LDAPS authentication</h3></div>';
    echo '<form id="ldap-form" method="post" enctype="multipart/form-data" class="ucard-body">';
    echo '<p class="muted">Service account search, then the user\'s own password. Nested groups use the Active Directory matching rule. ' . h($ext) . '</p>';
    echo '<div class="ldap-cols">';
    echo '<div class="ldap-col"><h4>Connection</h4>';
    echo '<label><input type="checkbox" name="ldap_enabled" ' . ($ldap['enabled'] ? 'checked' : '') . '> Enable LDAPS</label>';
    echo '<div class="ldap-pair"><div><label>Host</label><input name="ldap_host" value="' . h($ldap['host']) . '" placeholder="dc.example.org"></div>';
    echo '<div><label>Port</label><input name="ldap_port" value="' . h((string)$ldap['port']) . '"></div></div>';
    echo '<label>Base DN</label><input name="ldap_base_dn" value="' . h($ldap['base_dn']) . '" placeholder="DC=example,DC=org">';
    echo '<label>User filter</label><input name="ldap_user_filter" value="' . h($ldap['user_filter']) . '">';
    echo '<label>Bind DN</label><input name="ldap_bind_dn" value="' . h($ldap['bind_dn']) . '">';
    echo '<label>Bind password (blank keeps the saved one)</label><input type="password" name="ldap_bind_password" autocomplete="new-password">';
    echo '<label><input type="checkbox" name="ldap_use_ssl" ' . ($ldap['use_ssl'] ? 'checked' : '') . '> Use LDAPS (SSL)</label>';
    echo '<label><input type="checkbox" name="ldap_require_group" ' . ($ldap['require_group'] ? 'checked' : '') . '> Require a mapped security group before creating an account</label>';
    echo '<label>Default Role (fallback)</label><select name="ldap_default_role_id">';
    echo '<option value=""' . ($defaultId === 0 ? ' selected' : '') . '>View Only</option>';
    foreach ($roles as $role) {
        $rid = (int)ba_col($role, 'id');
        echo '<option value="' . $rid . '"' . ($defaultId === $rid ? ' selected' : '') . '>' . h((string)ba_col($role, 'name')) . '</option>';
    }
    echo '</select>';
    echo '<p class="muted">Used only when “Require security group…” is off (or no role maps exist yet). Mapped groups always set the role when they match.</p>';
    echo '</div>';
    echo '<div class="ldap-col"><h4>Enterprise CA</h4>';
    echo '<p class="muted">Upload the root (and intermediate, if needed) so PHP can trust ldaps:// with verification on. Stored in the site data folder as ldap-ca.pem (not served by the site, and not in git). Export from AD Certificate Services as Base-64 X.509 (.CER) or PEM.</p>';
    if (!empty($ca['installed'])) {
        echo '<p>Status: <span class="pill ok">Installed</span> · ' . (int)$ca['cert_count'] . ' cert(s) · ' . number_format((int)$ca['bytes']) . ' bytes';
        if (!empty($ca['subjects'])) {
            echo '<br><span class="muted">Subject(s): ' . h(implode(' · ', $ca['subjects'])) . '</span>';
        }
        echo '</p>';
    } else {
        echo '<p>Status: <span class="pill down">Not installed</span> <span class="muted">— verification fails until a CA is uploaded, or certificate checks stay off.</span></p>';
    }
    echo '<label>Upload CA certificate (.pem / .crt / .cer)</label>';
    echo '<input type="file" name="ldap_ca_file" accept=".pem,.crt,.cer,.cert,application/x-x509-ca-cert,application/x-pem-file,text/plain">';
    echo '<label><input type="checkbox" name="ldap_ca_append" value="1"> Append to the existing chain</label>';
    if (!empty($ca['installed'])) {
        echo '<label><input type="checkbox" name="ldap_remove_ca" value="1"> Remove the installed enterprise CA</label>';
    }
    echo '<label><input type="checkbox" name="ldap_tls_insecure" ' . ($ldap['tls_insecure'] ? 'checked' : '') . '> Do not verify the LDAPS certificate (temporary / lab)</label>';
    echo '<p class="muted">Use this only until the enterprise CA above is uploaded. After Save, uncheck it and run Test connection.</p>';
    echo '</div>';
    echo '<div class="ldap-col"><h4>Connection test</h4>';
    echo '<p class="muted">Uses the values in this form (save is not required). Leave the bind password blank to use the saved password. An optional test user checks the filter, and the password if you provide one. This does not create a BackAisle user. A newly chosen CA file is trusted after Save.</p>';
    echo '<label>Test username (optional)</label><input type="text" name="ldap_test_username" id="ldap_test_username" autocomplete="off" placeholder="sAMAccountName">';
    echo '<label>Test password (optional)</label><input type="password" name="ldap_test_password" id="ldap_test_password" autocomplete="new-password">';
    echo '</div></div>';
    echo '<input type="hidden" name="act" value="ldap_save"><input type="hidden" name="jump" value="ldaps">';
    echo '<div class="ldap-actions"><button type="submit">Save LDAPS</button><button type="button" id="ldap_test_btn">Test connection</button></div>';
    echo '</form></div>';
    echo '<div id="ldap_test_modal" class="ldaps-modal" hidden aria-hidden="true">';
    echo '<div class="ldaps-modal-backdrop" data-close-modal></div>';
    echo '<div class="ldaps-modal-panel" role="dialog" aria-modal="true" aria-labelledby="ldap_test_title">';
    echo '<div class="ldaps-modal-head"><h3 id="ldap_test_title">LDAPS test</h3><button type="button" class="btn" data-close-modal>Close</button></div>';
    echo '<div id="ldap_test_body"></div></div></div>';
    echo <<<'JS'
<script>
(function () {
  var btn = document.getElementById('ldap_test_btn');
  var modal = document.getElementById('ldap_test_modal');
  var body = document.getElementById('ldap_test_body');
  var title = document.getElementById('ldap_test_title');
  if (!btn || !modal || !body || !title) return;
  var form = document.getElementById('ldap-form');
  var panel = modal.querySelector('.ldaps-modal-panel');
  function esc(s) {
    return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
  }
  function openModal() {
    modal.hidden = false;
    modal.setAttribute('aria-hidden', 'false');
  }
  function showResult(data) {
    var ok = !!(data && data.ok);
    panel.classList.remove('ldaps-pass', 'ldaps-fail', 'ldaps-pending');
    panel.classList.add(ok ? 'ldaps-pass' : 'ldaps-fail');
    title.textContent = ok ? 'LDAPS test passed' : 'LDAPS test failed';
    var html = '<p class="ldaps-summary">' + esc(data && data.summary ? data.summary : (ok ? 'OK' : 'Failed')) + '</p>';
    (data && data.steps ? data.steps : []).forEach(function (step) {
      html += '<div class="ldaps-step ' + (step.ok ? 'ok' : 'bad') + '"><b>' + esc(step.name || 'Step') + '</b><span>' + esc(step.detail || '') + '</span></div>';
    });
    body.innerHTML = html;
  }
  btn.addEventListener('click', function () {
    if (!form) return;
    panel.classList.remove('ldaps-pass', 'ldaps-fail');
    panel.classList.add('ldaps-pending');
    title.textContent = 'Testing LDAPS…';
    body.innerHTML = '<p class="muted">Contacting the directory. Binding and search can take a few seconds.</p>';
    openModal();
    btn.disabled = true;
    var fd = new FormData(form);
    fd.set('act', 'ldap_test');
    fd.delete('ldap_ca_file');
    fetch(window.location.pathname + window.location.search, {
      method: 'POST',
      body: fd,
      credentials: 'same-origin',
      headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
    }).then(function (r) {
      return r.json().then(function (j) { return j; }).catch(function () {
        return { ok: false, summary: 'The server did not return a test result.', steps: [] };
      });
    }).then(showResult).catch(function (err) {
      showResult({ ok: false, summary: 'Network error: ' + (err && err.message ? err.message : 'request failed'), steps: [] });
    }).finally(function () { btn.disabled = false; });
  });
})();
</script>
JS;
}

function ba_users_roles_card(PDO $db, array $roles): void
{
    $order = ['global' => 1, 'idfm' => 2, 'department' => 3, 'view' => 4];
    $matrix = [];
    foreach ($roles as $r) {
        if (isset($order[(string)ba_col($r, 'code')])) {
            $matrix[] = $r;
        }
    }
    usort($matrix, static function ($a, $b) use ($order) {
        return ($order[(string)ba_col($a, 'code')] ?? 50) <=> ($order[(string)ba_col($b, 'code')] ?? 50);
    });
    $privileged = array_flip(ba_privileged_permissions());
    echo '<div class="ucard" id="roles"><div class="ucard-head"><h3>Platform roles</h3></div><div class="ucard-body">';
    echo '<p class="muted">Change a name in the box at the top of its column. View Only is read-only. Department Admin edits devices in their department. IDFM Admin edits racks, inventory, SNMP, and fleet writes. Global Admin also manages users, LDAPS, backups, and updates. Users and Settings stay with Global Admin.</p>';
    echo '<form method="post">';
    echo '<input type="hidden" name="act" value="save_roles"><input type="hidden" name="jump" value="roles">';
    echo '<div class="users-scroll"><table class="role-matrix"><thead><tr><th class="role-area" rowspan="2">Area</th>';
    foreach ($matrix as $mr) {
        $rid = (int)ba_col($mr, 'id');
        echo '<th class="role-name" colspan="2"><input type="text" name="role_name[' . $rid . ']" value="' . h((string)ba_col($mr, 'name')) . '" maxlength="64" required autocomplete="off" aria-label="Role name"></th>';
    }
    echo '</tr><tr>';
    foreach ($matrix as $mr) {
        echo '<th>View</th><th>Edit</th>';
    }
    echo '</tr></thead><tbody>';
    foreach (ba_permission_modules() as $mod) {
        echo '<tr><td>' . h((string)$mod['label']) . '</td>';
        foreach ($matrix as $mr) {
            $rid = (int)ba_col($mr, 'id');
            $plist = ba_perm_list(ba_col($mr, 'permissions'));
            $star = in_array('*', $plist, true) || (string)ba_col($mr, 'code') === 'global';
            $viewKey = $mod['view'] ?? null;
            $editKey = $mod['edit'] ?? null;
            $deptKey = $mod['edit_dept'] ?? null;
            $hasView = $star || ($viewKey && in_array($viewKey, $plist, true));
            $hasEdit = $star || ($editKey && in_array($editKey, $plist, true));
            $hasDept = $star || ($deptKey && in_array($deptKey, $plist, true));
            $viewLock = $star || ($viewKey && isset($privileged[$viewKey]));
            $editLock = $star || ($editKey && isset($privileged[$editKey]));
            echo '<td>';
            if ($viewKey) {
                echo '<input type="checkbox" name="perm[' . $rid . '][]" value="' . h((string)$viewKey) . '"' . ($hasView ? ' checked' : '') . ($viewLock ? ' disabled' : '') . '>';
            } else {
                echo '<span class="muted">—</span>';
            }
            echo '</td><td>';
            if ($deptKey && $editKey) {
                echo '<label class="role-mini"><input type="checkbox" name="perm[' . $rid . '][]" value="' . h((string)$deptKey) . '"' . ($hasDept ? ' checked' : '') . ($editLock ? ' disabled' : '') . '> Own dept</label> ';
                echo '<label class="role-mini"><input type="checkbox" name="perm[' . $rid . '][]" value="' . h((string)$editKey) . '"' . ($hasEdit ? ' checked' : '') . ($editLock ? ' disabled' : '') . '> All</label>';
            } elseif ($editKey) {
                echo '<input type="checkbox" name="perm[' . $rid . '][]" value="' . h((string)$editKey) . '"' . ($hasEdit ? ' checked' : '') . ($editLock ? ' disabled' : '') . '>';
            } else {
                echo '<span class="muted">—</span>';
            }
            echo '</td>';
        }
        echo '</tr>';
    }
    echo '</tbody></table></div>';
    echo '<p><button>Save roles</button></p></form>';
    echo '<form method="post" onsubmit="return confirm(\'Restore Global Admin, IDFM Admin, Department Admin, and View Only, including their names?\')">';
    echo '<input type="hidden" name="act" value="reset_roles"><input type="hidden" name="jump" value="roles">';
    echo '<button>Restore defaults</button></form></div></div>';
}

function ba_users_dept_fields(?array $edit): void
{
    $name = (string)ba_col($edit ?? [], 'name', '');
    $code = (string)ba_col($edit ?? [], 'code', '');
    $manager = (string)ba_col($edit ?? [], 'manager_name', '');
    $email = (string)ba_col($edit ?? [], 'contact_email', '');
    $phone = (string)ba_col($edit ?? [], 'contact_phone', '');
    $notes = (string)ba_col($edit ?? [], 'notes', '');
    $color = ba_color_hex((string)ba_col($edit ?? [], 'color_hex', '#3b82f6'));
    echo '<label>Name</label><input name="name" required value="' . h($name) . '" placeholder="Infrastructure">';
    echo '<label>Code</label><input name="code" value="' . h($code) . '" placeholder="INFRA">';
    echo '<label>Manager</label><input name="manager_name" value="' . h($manager) . '">';
    echo '<label>Contact email</label><input name="contact_email" value="' . h($email) . '">';
    echo '<label>Contact phone</label><input name="contact_phone" value="' . h($phone) . '">';
    echo '<label>Color</label><input type="color" name="color_hex" value="' . h($color) . '">';
    echo '<label>Notes</label><input name="notes" value="' . h($notes) . '">';
}

function ba_users_modal_open(string $id, string $title, bool $open, string $closeHref = ''): void
{
    $titleId = $id . '-title';
    echo '<div id="' . h($id) . '" class="ldaps-modal"' . ($open ? '' : ' hidden') . ' aria-hidden="' . ($open ? 'false' : 'true') . '"';
    if ($closeHref !== '') {
        echo ' data-close-href="' . h($closeHref) . '"';
    }
    echo '>';
    echo '<div class="ldaps-modal-backdrop" data-close-modal></div>';
    echo '<div class="ldaps-modal-panel" role="dialog" aria-modal="true" aria-labelledby="' . h($titleId) . '">';
    echo '<div class="ldaps-modal-head"><h3 id="' . h($titleId) . '">' . h($title) . '</h3>';
    echo '<button type="button" class="btn" data-close-modal>Close</button></div>';
}

function ba_users_modal_close(): void
{
    echo '</div></div>';
}

function ba_users_dept_card(array $depts, ?array $edit, string $openModal): void
{
    echo '<div class="ucard" id="departments">';
    echo '<div class="ucard-head"><h3>Departments</h3>';
    echo '<button type="button" class="btn" data-open-modal="modal-add-dept">Add department</button></div>';
    echo '<div class="ucard-body"><div class="users-scroll"><table><thead><tr><th></th><th>Name</th><th>Code</th><th>Users</th><th>Devices</th><th></th></tr></thead><tbody>';
    foreach ($depts as $d) {
        $color = ba_color_hex((string)ba_col($d, 'color_hex'));
        echo '<tr><td><span class="dept-swatch" style="background:' . h($color) . '"></span></td>';
        echo '<td><strong>' . h((string)ba_col($d, 'name')) . '</strong>';
        if ((int)ba_col($d, 'is_active', 1) !== 1) {
            echo ' <span class="muted">off</span>';
        }
        $mgr = trim((string)ba_col($d, 'manager_name', ''));
        if ($mgr !== '') {
            echo '<div class="muted">' . h($mgr) . '</div>';
        }
        echo '</td><td>' . h((string)ba_col($d, 'code')) . '</td>';
        echo '<td>' . (int)ba_col($d, 'user_count') . '</td><td>' . (int)ba_col($d, 'device_count') . '</td>';
        echo '<td><a href="' . h(ba_href('/users?edit_dept=' . (int)ba_col($d, 'id'))) . '#departments">Edit</a></td></tr>';
    }
    if (!$depts) {
        echo '<tr><td colspan="6" class="muted">No departments yet.</td></tr>';
    }
    echo '</tbody></table></div></div></div>';

    ba_users_modal_open('modal-add-dept', 'Add department', $openModal === 'add_department');
    echo '<form method="post" class="stack">';
    echo '<input type="hidden" name="act" value="add_department"><input type="hidden" name="jump" value="departments">';
    ba_users_dept_fields(null);
    echo '<button>Add department</button></form>';
    ba_users_modal_close();

    if ($edit) {
        ba_users_modal_open('modal-edit-dept', 'Edit department', true, ba_href('/users#departments'));
        echo '<form method="post" class="stack">';
        echo '<input type="hidden" name="act" value="update_department"><input type="hidden" name="id" value="' . (int)ba_col($edit, 'id') . '">';
        echo '<input type="hidden" name="jump" value="departments">';
        ba_users_dept_fields($edit);
        $on = (int)ba_col($edit, 'is_active', 1) === 1;
        echo '<label><input type="checkbox" name="is_active"' . ($on ? ' checked' : '') . '> Active</label>';
        echo '<button>Save department</button></form>';
        ba_users_modal_close();
    }
}

function ba_users_role_select(array $roles, int $selected): void
{
    echo '<select name="role_id" required>';
    foreach ($roles as $r) {
        $id = (int)ba_col($r, 'id');
        $sel = $id === $selected ? ' selected' : '';
        echo '<option value="' . $id . '"' . $sel . '>' . h((string)ba_col($r, 'name')) . '</option>';
    }
    echo '</select>';
}

function ba_users_dept_select(array $depts, int $selected): void
{
    echo '<select name="department_id"><option value="">(none)</option>';
    foreach ($depts as $d) {
        $id = (int)ba_col($d, 'id');
        if ((int)ba_col($d, 'is_active', 1) !== 1 && $id !== $selected) {
            continue;
        }
        $sel = $id === $selected ? ' selected' : '';
        echo '<option value="' . $id . '"' . $sel . '>' . h((string)ba_col($d, 'name')) . '</option>';
    }
    echo '</select>';
}

function ba_users_user_fields(array $roles, array $depts, ?array $edit, bool $isEdit): void
{
    if ($isEdit) {
        echo '<input type="hidden" name="act" value="update_user"><input type="hidden" name="id" value="' . (int)ba_col($edit, 'id') . '">';
        echo '<label>Username</label><input value="' . h((string)ba_col($edit, 'username')) . '" disabled>';
        $src = (string)ba_col($edit, 'source', 'local') === 'ldap' ? 'ldap' : 'local';
    } else {
        echo '<input type="hidden" name="act" value="add_user">';
        echo '<label>Username</label><input name="username" required>';
        $src = 'local';
    }
    echo '<input type="hidden" name="jump" value="people">';
    echo '<label>Display name</label><input name="display_name" value="' . h((string)ba_col($edit ?? [], 'display_name', '')) . '">';
    echo '<label>Email</label><input name="email" value="' . h((string)ba_col($edit ?? [], 'email', '')) . '">';
    echo '<label>Platform role</label>';
    ba_users_role_select($roles, (int)ba_col($edit ?? [], 'role_id'));
    echo '<label>Department</label>';
    ba_users_dept_select($depts, (int)ba_col($edit ?? [], 'department_id'));
    if (!$isEdit) {
        echo '<label>Sign-in</label><select name="source"><option value="local">Local password</option><option value="ldap">LDAPS (no password stored)</option></select>';
    } else {
        echo '<input type="hidden" name="source" value="' . h($src) . '">';
        echo '<p class="muted">Sign-in: ' . h($src === 'ldap' ? 'LDAPS' : 'Local password') . '</p>';
    }
    if ($src === 'local' || !$isEdit) {
        $hint = $isEdit ? 'Leave blank to keep the current password.' : 'At least 8 characters.';
        echo '<label>Password</label><input type="password" name="password" autocomplete="new-password">';
        echo '<label>Confirm password</label><input type="password" name="password_confirm" autocomplete="new-password">';
        echo '<p class="muted">' . h($hint) . '</p>';
    }
    $on = $edit ? (int)ba_col($edit, 'is_active', 1) === 1 : true;
    echo '<label><input type="checkbox" name="is_active"' . ($on ? ' checked' : '') . '> Active</label>';
    echo '<button>' . ($isEdit ? 'Save user' : 'Add user') . '</button>';
}

function ba_users_people_card(PDO $db, array $users, array $roles, array $depts, ?array $edit, string $q, string $openModal): void
{
    $peopleHref = ba_href('/users' . ($q !== '' ? '?q=' . rawurlencode($q) : '') . '#people');
    echo '<div class="ucard" id="people">';
    echo '<div class="ucard-head"><h3>Users</h3>';
    echo '<button type="button" class="btn" data-open-modal="modal-add-user">Add user</button></div>';
    echo '<div class="ucard-body">';
    echo '<form method="get" action="' . h(ba_href('/users')) . '" class="filters">';
    echo '<input name="q" value="' . h($q) . '" placeholder="Search name, email, department">';
    echo '<button>Search</button>';
    if ($q !== '') {
        echo '<a href="' . h(ba_href('/users#people')) . '">Clear</a>';
    }
    echo '</form>';
    echo '<div class="users-scroll"><table><thead><tr><th>User</th><th>Role</th><th>Department</th><th>Status</th><th></th></tr></thead><tbody>';
    foreach ($users as $u) {
        echo '<tr><td><strong>' . h((string)ba_col($u, 'username')) . '</strong>';
        $dn = trim((string)ba_col($u, 'display_name', ''));
        if ($dn !== '') {
            echo '<div class="muted">' . h($dn) . '</div>';
        }
        $em = trim((string)ba_col($u, 'email', ''));
        if ($em !== '') {
            echo '<div class="muted">' . h($em) . '</div>';
        }
        echo '</td><td>' . h((string)(ba_col($u, 'role_name') ?: ba_col($u, 'role'))) . '</td><td>';
        $deptName = trim((string)ba_col($u, 'department_name', ''));
        if ($deptName !== '') {
            $color = ba_color_hex((string)ba_col($u, 'department_color'));
            echo '<span class="dept-swatch" style="background:' . h($color) . '"></span> ' . h($deptName);
        } else {
            echo '<span class="muted">—</span>';
        }
        echo '</td><td>' . h((string)ba_col($u, 'source', 'local'));
        echo (int)ba_col($u, 'is_active', 1) === 1 ? ' · active' : ' · off';
        $editHref = ba_href('/users?edit_user=' . (int)ba_col($u, 'id') . ($q !== '' ? '&q=' . rawurlencode($q) : '') . '#people');
        echo '</td><td><a href="' . h($editHref) . '">Edit</a></td></tr>';
    }
    if (!$users) {
        echo '<tr><td colspan="5" class="muted">' . ($q !== '' ? 'No users match that search.' : 'No users yet.') . '</td></tr>';
    }
    echo '</tbody></table></div></div></div>';

    ba_users_modal_open('modal-add-user', 'Add user', $openModal === 'add_user');
    echo '<form method="post" class="stack">';
    ba_users_user_fields($roles, $depts, null, false);
    echo '</form>';
    ba_users_modal_close();

    if ($edit) {
        ba_users_modal_open('modal-edit-user', 'Edit user', true, $peopleHref);
        echo '<form method="post" class="stack">';
        ba_users_user_fields($roles, $depts, $edit, true);
        echo '</form>';
        ba_users_modal_close();
    }
}

function ba_users_role_map_card(array $roles, array $maps, string $openModal): void
{
    echo '<div class="ucard" id="role-maps">';
    echo '<div class="ucard-head"><h3>Security group → role mapping</h3>';
    echo '<button type="button" class="btn" data-open-modal="modal-add-role-map">Add role mapping</button></div>';
    echo '<div class="ucard-body">';
    echo '<p class="muted">At LDAPS sign-in, group membership (including nested groups) is compared to these rows. The highest role wins: Global Admin, then IDFM Admin, then Department Admin, then View Only. Renaming a role does not change that order. The role is checked again on every sign-in. Group ID can be the group CN or the full DN. With “require a mapped security group” on, a first-time directory user is created only when one of these maps matches.</p>';
    echo '<div class="users-scroll"><table><thead><tr><th>Role</th><th>Group name</th><th>Group ID</th><th></th></tr></thead><tbody>';
    foreach ($maps as $m) {
        echo '<tr><td>' . h((string)ba_col($m, 'role_name')) . '</td>';
        echo '<td>' . h((string)ba_col($m, 'group_name')) . '</td>';
        echo '<td><code>' . h((string)ba_col($m, 'group_id')) . '</code></td><td>';
        echo '<form method="post" style="display:inline" onsubmit="return confirm(\'Remove this mapping?\')">';
        echo '<input type="hidden" name="act" value="delete_role_map"><input type="hidden" name="jump" value="role-maps">';
        echo '<input type="hidden" name="id" value="' . (int)ba_col($m, 'id') . '"><button>Remove</button></form></td></tr>';
    }
    if (!$maps) {
        echo '<tr><td colspan="4" class="muted">No role maps yet.</td></tr>';
    }
    echo '</tbody></table></div></div></div>';

    ba_users_modal_open('modal-add-role-map', 'Add role mapping', $openModal === 'add_role_map');
    echo '<form method="post" class="stack">';
    echo '<input type="hidden" name="act" value="add_role_map"><input type="hidden" name="jump" value="role-maps">';
    echo '<label>Platform role</label><select name="role_id" required>';
    foreach ($roles as $r) {
        echo '<option value="' . (int)ba_col($r, 'id') . '">' . h((string)ba_col($r, 'name')) . '</option>';
    }
    echo '</select>';
    echo '<label>Group name (optional)</label><input name="group_name">';
    echo '<label>Group ID</label><input name="group_id" placeholder="CN or full DN" required>';
    echo '<button>Add role mapping</button></form>';
    ba_users_modal_close();
}

function ba_users_dept_map_card(array $depts, array $maps, string $openModal): void
{
    echo '<div class="ucard" id="dept-maps">';
    echo '<div class="ucard-head"><h3>Security group → department mapping</h3>';
    echo '<button type="button" class="btn" data-open-modal="modal-add-dept-map">Add department mapping</button></div>';
    echo '<div class="ucard-body">';
    echo '<p class="muted">When LDAPS sign-in matches one of these groups, the user\'s department is set from the map. If several match, the first one saved is used. A sign-in that matches no department map leaves the current department as it is.</p>';
    echo '<div class="users-scroll"><table><thead><tr><th>Department</th><th>Group name</th><th>Group ID</th><th></th></tr></thead><tbody>';
    foreach ($maps as $m) {
        $color = ba_color_hex((string)ba_col($m, 'color_hex'));
        echo '<tr><td><span class="dept-swatch" style="background:' . h($color) . '"></span> ' . h((string)ba_col($m, 'department_name')) . '</td>';
        echo '<td>' . h((string)ba_col($m, 'group_name')) . '</td>';
        echo '<td><code>' . h((string)ba_col($m, 'group_id')) . '</code></td><td>';
        echo '<form method="post" style="display:inline" onsubmit="return confirm(\'Remove this mapping?\')">';
        echo '<input type="hidden" name="act" value="delete_dept_map"><input type="hidden" name="jump" value="dept-maps">';
        echo '<input type="hidden" name="id" value="' . (int)ba_col($m, 'id') . '"><button>Remove</button></form></td></tr>';
    }
    if (!$maps) {
        echo '<tr><td colspan="4" class="muted">No department maps yet.</td></tr>';
    }
    echo '</tbody></table></div></div></div>';

    ba_users_modal_open('modal-add-dept-map', 'Add department mapping', $openModal === 'add_dept_map');
    echo '<form method="post" class="stack">';
    echo '<input type="hidden" name="act" value="add_dept_map"><input type="hidden" name="jump" value="dept-maps">';
    echo '<label>Department</label><select name="department_id" required><option value="">Department</option>';
    foreach ($depts as $d) {
        if ((int)ba_col($d, 'is_active', 1) !== 1) {
            continue;
        }
        echo '<option value="' . (int)ba_col($d, 'id') . '">' . h((string)ba_col($d, 'name')) . '</option>';
    }
    echo '</select>';
    echo '<label>Group name (optional)</label><input name="group_name">';
    echo '<label>Group ID</label><input name="group_id" placeholder="CN or full DN" required>';
    echo '<button>Add department mapping</button></form>';
    ba_users_modal_close();
}

function ba_users_modal_script(): void
{
    echo <<<'JS'
<script>
(function () {
  function hide(m) {
    var href = m.getAttribute('data-close-href') || '';
    if (href) {
      window.location.href = href;
      return;
    }
    m.hidden = true;
    m.setAttribute('aria-hidden', 'true');
  }
  document.addEventListener('click', function (e) {
    var opener = e.target.closest('[data-open-modal]');
    if (opener) {
      var id = opener.getAttribute('data-open-modal');
      var modal = id ? document.getElementById(id) : null;
      if (!modal) return;
      modal.hidden = false;
      modal.setAttribute('aria-hidden', 'false');
      var field = modal.querySelector('input:not([type=hidden]), select, textarea');
      if (field) field.focus();
      return;
    }
    if (e.target.closest('[data-close-modal]')) {
      var modal = e.target.closest('.ldaps-modal');
      if (modal) hide(modal);
    }
  });
  document.addEventListener('keydown', function (e) {
    if (e.key !== 'Escape') return;
    document.querySelectorAll('.ldaps-modal:not([hidden])').forEach(hide);
  });
})();
</script>
JS;
}
