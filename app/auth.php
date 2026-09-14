<?php
declare(strict_types=1);

function ba_verify_password(string $hash, string $password): bool {
    if (str_starts_with($hash, 'sha256:')) {
        return hash_equals($hash, 'sha256:' . hash('sha256', $password));
    }
    return password_verify($password, $hash);
}

function ba_user(): ?array {
    return $_SESSION['user'] ?? null;
}

function ba_require_login(): array {
    $u = ba_user();
    if (!$u) {
        header('Location: /login');
        exit;
    }
    return $u;
}

function ba_require_admin(): array {
    $u = ba_require_login();
    if (($u['role'] ?? '') !== 'admin') {
        http_response_code(403);
        echo 'Admin only';
        exit;
    }
    return $u;
}

function ba_login(PDO $db, string $username, string $password): bool {
    $st = $db->prepare('SELECT * FROM users WHERE username = ?');
    $st->execute([$username]);
    $row = $st->fetch();
    if ($row && ($row['source'] ?? 'local') === 'ldap') {
        return ba_ldap_login($db, $username, $password);
    }
    if (!$row) {
        return ba_ldap_login($db, $username, $password);
    }
    if (!ba_verify_password($row['password_hash'], $password)) {
        return false;
    }
    if (str_starts_with($row['password_hash'], 'sha256:')) {
        $new = password_hash($password, PASSWORD_DEFAULT);
        $db->prepare('UPDATE users SET password_hash=? WHERE id=?')->execute([$new, $row['id']]);
        $row['password_hash'] = $new;
    }
    $_SESSION['user'] = ['id' => (int)$row['id'], 'username' => $row['username'], 'role' => $row['role']];
    ba_audit($db, 'login', 'user', (string)$row['id']);
    return true;
}
