<?php
declare(strict_types=1);

/** Logins BackAisle uses for SSH, the web card, and FTP. Not SNMPv3 users. */

function ba_webcred_path(): string
{
    return 'C:\\ProgramData\\BackAisle\\web_credentials.json';
}

function ba_ensure_webcred_schema(PDO $db): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    if (ba_db_driver() === 'sqlsrv') {
        $db->exec("IF OBJECT_ID('dbo.web_credentials','U') IS NULL CREATE TABLE web_credentials (
            id INT IDENTITY(1,1) PRIMARY KEY,
            name NVARCHAR(128) NOT NULL,
            username NVARCHAR(128) NOT NULL,
            notes NVARCHAR(MAX) NULL
        )");
    } else {
        $db->exec("CREATE TABLE IF NOT EXISTS web_credentials (
            id INTEGER PRIMARY KEY,
            name TEXT NOT NULL,
            username TEXT NOT NULL,
            notes TEXT
        )");
    }
    ba_ensure_column($db, 'devices', 'web_credential_id', 'INT NULL');
}

function ba_webcred_read(): array
{
    $path = ba_webcred_path();
    if (!is_file($path)) {
        return [];
    }
    $data = json_decode((string)file_get_contents($path), true);
    return is_array($data) ? $data : [];
}

function ba_webcred_write(array $data): void
{
    $path = ba_webcred_path();
    $dir = dirname($path);
    if (!is_dir($dir) && !@mkdir($dir, 0770, true) && !is_dir($dir)) {
        throw new RuntimeException('Could not store the Web/SSH/FTP password');
    }
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false || @file_put_contents($path, $json, LOCK_EX) === false) {
        throw new RuntimeException('Could not store the Web/SSH/FTP password');
    }
}

function ba_webcred_store(int $id, string $password): void
{
    if ($id < 1 || $password === '') {
        return;
    }
    $data = ba_webcred_read();
    $rec = $data[(string)$id] ?? [];
    if (!is_array($rec)) {
        $rec = [];
    }
    $rec['password'] = $password;
    $data[(string)$id] = $rec;
    ba_webcred_write($data);
}

function ba_webcred_forget(int $id): void
{
    if ($id < 1 || !is_file(ba_webcred_path())) {
        return;
    }
    $data = ba_webcred_read();
    unset($data[(string)$id]);
    ba_webcred_write($data);
}

function ba_webcred_has_secret(int $id): bool
{
    if ($id < 1) {
        return false;
    }
    $rec = ba_webcred_read()[(string)$id] ?? null;
    if (!is_array($rec)) {
        return false;
    }
    $pw = $rec['password'] ?? '';
    return is_string($pw) && $pw !== '';
}

function ba_webcred_text(string $value, string $label, int $max): string
{
    $value = trim($value);
    if ($value === '') {
        throw new RuntimeException($label . ' is required');
    }
    if (strlen($value) > $max || preg_match('/[\x00-\x1F]/', $value)) {
        throw new RuntimeException($label . ' is not valid');
    }
    return $value;
}

function ba_webcred_password(string $value, bool $required): string
{
    if ($value === '') {
        if ($required) {
            throw new RuntimeException('Password is required');
        }
        return '';
    }
    if (strlen($value) > 128 || preg_match('/[\x00\r\n]/', $value)) {
        throw new RuntimeException('Password is not valid');
    }
    return $value;
}

function ba_webcred_posted(PDO $db, mixed $raw): ?int
{
    ba_ensure_webcred_schema($db);
    $text = trim((string)$raw);
    if ($text === '') {
        return null;
    }
    if (!ctype_digit($text)) {
        throw new RuntimeException('Unknown Web/SSH/FTP login');
    }
    $id = (int)$text;
    if ($id < 1) {
        return null;
    }
    $st = $db->prepare('SELECT id FROM web_credentials WHERE id=?');
    $st->execute([$id]);
    if (!$st->fetch()) {
        throw new RuntimeException('Unknown Web/SSH/FTP login');
    }
    return $id;
}

function ba_webcred_options(PDO $db, ?int $selected): string
{
    ba_ensure_webcred_schema($db);
    $html = '';
    foreach ($db->query('SELECT id, name, username FROM web_credentials ORDER BY name') as $row) {
        $id = (int)$row['id'];
        $sel = ($selected !== null && $selected === $id) ? ' selected' : '';
        $label = (string)$row['name'];
        $user = trim((string)$row['username']);
        if ($user !== '') {
            $label .= ' (' . $user . ')';
        }
        $html .= '<option value="' . $id . '"' . $sel . '>' . h($label) . '</option>';
    }
    return $html;
}

function ba_assign_web_credential(PDO $db, int $credId, ?int $groupId): int
{
    ba_ensure_webcred_schema($db);
    if (ba_webcred_posted($db, (string)$credId) === null) {
        throw new RuntimeException('Choose a Web/SSH/FTP login');
    }
    if ($groupId) {
        $groups = function_exists('ba_groups') ? ba_groups($db) : $db->query('SELECT * FROM groups ORDER BY name')->fetchAll();
        $gids = function_exists('ba_group_descendant_ids') ? ba_group_descendant_ids($groups, $groupId) : [$groupId];
        $in = implode(',', array_map('intval', $gids));
        if ($in === '') {
            return 0;
        }
        $db->exec('UPDATE devices SET web_credential_id=' . $credId . ' WHERE group_id IN (' . $in . ')');
        return (int)$db->query('SELECT COUNT(*) FROM devices WHERE group_id IN (' . $in . ') AND web_credential_id=' . $credId)->fetchColumn();
    }
    $db->exec('UPDATE devices SET web_credential_id=' . $credId);
    return (int)$db->query('SELECT COUNT(*) FROM devices WHERE web_credential_id=' . $credId)->fetchColumn();
}
