<?php
declare(strict_types=1);

require_once __DIR__ . '/ldap.php';

/**
 * Notification catalog, delivery, Settings page, and Org mail.
 * A new alert is a catalog row. Channels are email, in-app, and NOC.
 */

function ba_notify_catalog(): array
{
    return [
        ['code' => 'system_cpu', 'group' => 'system', 'label' => 'CPU', 'detail' => 'The processor stays at or above 90%.', 'codes' => [], 'kinds' => []],
        ['code' => 'system_memory', 'group' => 'system', 'label' => 'Memory', 'detail' => 'Less than 10% of physical memory is free.', 'codes' => [], 'kinds' => []],
        ['code' => 'system_disk', 'group' => 'system', 'label' => 'Disk', 'detail' => 'The drive BackAisle is on is under 10% free, or under 2 GB free.', 'codes' => [], 'kinds' => []],
        ['code' => 'system_sql', 'group' => 'system', 'label' => 'Database', 'detail' => 'The application cannot query its database.', 'codes' => [], 'kinds' => []],
        ['code' => 'system_services', 'group' => 'system', 'label' => 'Services', 'detail' => 'The collector heartbeat is older than 3 minutes, or a write is queued while the writer log has been quiet for 10 minutes.', 'codes' => [], 'kinds' => []],
        ['code' => 'system_updates', 'group' => 'system', 'label' => 'Updates', 'detail' => 'The last update check found a newer BackAisle release. This does not contact the network by itself.', 'codes' => [], 'kinds' => []],
        ['code' => 'device_comm', 'group' => 'device', 'label' => 'Communication', 'detail' => 'The device does not answer. Applies to a UPS, PDU, switch, or any other unit.', 'codes' => ['poll_fail'], 'kinds' => []],
        ['code' => 'device_ups_power_loss', 'group' => 'device', 'label' => 'UPS power loss', 'detail' => 'Utility power is out and the UPS is on battery.', 'codes' => ['on_battery'], 'kinds' => ['ups']],
        ['code' => 'device_ups_on_battery', 'group' => 'device', 'label' => 'UPS on battery', 'detail' => 'The UPS has been on battery longer than its limit.', 'codes' => ['on_battery_long'], 'kinds' => ['ups']],
        ['code' => 'device_ups_battery_low', 'group' => 'device', 'label' => 'UPS battery low', 'detail' => 'Runtime or capacity is low, or the card is asking for a battery replacement.', 'codes' => ['capacity_low', 'runtime_low', 'replace_battery'], 'kinds' => ['ups']],
        ['code' => 'device_ups_temp', 'group' => 'device', 'label' => 'UPS environmental temperature', 'detail' => 'The closet sensor is above or below its temperature limit.', 'codes' => ['temp_high', 'temp_low'], 'kinds' => ['ups']],
        ['code' => 'device_humidity', 'group' => 'device', 'label' => 'Humidity', 'detail' => 'Humidity is above or below its limit.', 'codes' => ['humidity_high', 'humidity_low'], 'kinds' => ['ups']],
        ['code' => 'device_water', 'group' => 'device', 'label' => 'Water', 'detail' => 'A leak or water sensor is active. The box is ready for a device that reports water.', 'codes' => ['water', 'leak'], 'kinds' => []],
        ['code' => 'device_sensor', 'group' => 'device', 'label' => 'Sensor missing', 'detail' => 'A probe was expected and is not attached.', 'codes' => ['sensor_missing'], 'kinds' => ['ups']],
    ];
}

function ba_notify_kind(string $code): ?array
{
    foreach (ba_notify_catalog() as $item) {
        if ($item['code'] === $code) {
            return $item;
        }
    }
    return null;
}

function ba_notify_kind_for_alert(string $code, string $deviceKind): ?string
{
    $deviceKind = strtolower($deviceKind !== '' ? $deviceKind : 'ups');
    foreach (ba_notify_catalog() as $item) {
        if ($item['group'] !== 'device' || !in_array($code, $item['codes'], true)) {
            continue;
        }
        if ($item['kinds'] && !in_array($deviceKind, $item['kinds'], true)) {
            continue;
        }
        return $item['code'];
    }
    return null;
}

function ba_notify_ensure(PDO $db): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    $sqlsrv = ba_db_driver() === 'sqlsrv';
    if ($sqlsrv) {
        $db->exec("IF NOT EXISTS (SELECT * FROM sys.tables WHERE name = 'notify_rules')
            CREATE TABLE notify_rules (
              scope NVARCHAR(16) NOT NULL,
              scope_id INT NOT NULL,
              kind NVARCHAR(64) NOT NULL,
              email INT NOT NULL CONSTRAINT DF_ba_nr_email DEFAULT 0,
              in_app INT NOT NULL CONSTRAINT DF_ba_nr_app DEFAULT 0,
              noc INT NOT NULL CONSTRAINT DF_ba_nr_noc DEFAULT 0,
              CONSTRAINT PK_ba_notify_rules PRIMARY KEY (scope, scope_id, kind)
            )");
        $db->exec("IF NOT EXISTS (SELECT * FROM sys.tables WHERE name = 'notify_claims')
            CREATE TABLE notify_claims (
              dedupe_key NVARCHAR(255) NOT NULL,
              audience NVARCHAR(64) NOT NULL,
              channel NVARCHAR(16) NOT NULL,
              announced INT NOT NULL CONSTRAINT DF_ba_nc_ann DEFAULT 0,
              note NVARCHAR(MAX) NULL,
              created_at NVARCHAR(32) NOT NULL,
              CONSTRAINT PK_ba_notify_claims PRIMARY KEY (dedupe_key, audience, channel)
            )");
        $db->exec("IF NOT EXISTS (SELECT * FROM sys.tables WHERE name = 'notifications')
            CREATE TABLE notifications (
              id INT IDENTITY(1,1) PRIMARY KEY,
              user_id INT NULL,
              kind NVARCHAR(64) NOT NULL,
              title NVARCHAR(255) NOT NULL,
              message NVARCHAR(MAX) NOT NULL,
              severity NVARCHAR(16) NOT NULL,
              noc INT NOT NULL CONSTRAINT DF_ba_notes_noc DEFAULT 0,
              department_id INT NULL,
              group_id INT NULL,
              device_id INT NULL,
              dedupe_key NVARCHAR(255) NOT NULL,
              created_at NVARCHAR(32) NOT NULL,
              read_at NVARCHAR(32) NULL
            )");
        $db->exec("IF NOT EXISTS (SELECT * FROM sys.tables WHERE name = 'notify_system')
            CREATE TABLE notify_system (
              kind NVARCHAR(64) NOT NULL PRIMARY KEY,
              is_open INT NOT NULL,
              message NVARCHAR(MAX) NULL,
              updated_at NVARCHAR(32) NOT NULL
            )");
    } else {
        $db->exec("CREATE TABLE IF NOT EXISTS notify_rules (
            scope TEXT NOT NULL,
            scope_id INTEGER NOT NULL,
            kind TEXT NOT NULL,
            email INTEGER NOT NULL DEFAULT 0,
            in_app INTEGER NOT NULL DEFAULT 0,
            noc INTEGER NOT NULL DEFAULT 0,
            PRIMARY KEY (scope, scope_id, kind)
        )");
        $db->exec("CREATE TABLE IF NOT EXISTS notify_claims (
            dedupe_key TEXT NOT NULL,
            audience TEXT NOT NULL,
            channel TEXT NOT NULL,
            announced INTEGER NOT NULL DEFAULT 0,
            note TEXT,
            created_at TEXT NOT NULL,
            PRIMARY KEY (dedupe_key, audience, channel)
        )");
        $db->exec("CREATE TABLE IF NOT EXISTS notifications (
            id INTEGER PRIMARY KEY,
            user_id INTEGER,
            kind TEXT NOT NULL,
            title TEXT NOT NULL,
            message TEXT NOT NULL,
            severity TEXT NOT NULL,
            noc INTEGER NOT NULL DEFAULT 0,
            department_id INTEGER,
            group_id INTEGER,
            device_id INTEGER,
            dedupe_key TEXT NOT NULL,
            created_at TEXT NOT NULL,
            read_at TEXT
        )");
        $db->exec("CREATE TABLE IF NOT EXISTS notify_system (
            kind TEXT NOT NULL PRIMARY KEY,
            is_open INTEGER NOT NULL,
            message TEXT,
            updated_at TEXT NOT NULL
        )");
    }
    ba_ensure_column($db, 'users', 'notify_enroll', $sqlsrv
        ? 'INT NOT NULL CONSTRAINT DF_ba_user_enroll DEFAULT 0'
        : 'INTEGER NOT NULL DEFAULT 0');
    ba_ensure_column($db, 'departments', 'notify_group_idf', $sqlsrv
        ? 'INT NOT NULL CONSTRAINT DF_ba_dept_group_idf DEFAULT 0'
        : 'INTEGER NOT NULL DEFAULT 0');
    if (ba_setting($db, 'notify_ready', '') !== '1') {
        ba_set_setting($db, 'notify_ready', '1');
    }
}

function ba_notify_is_global(array $user): bool
{
    if (($user['role'] ?? '') === 'admin') {
        return true;
    }
    $perms = $user['permissions'] ?? [];
    return is_array($perms) && (in_array('*', $perms, true) || in_array('manage_settings', $perms, true));
}

function ba_notify_rules(PDO $db, string $scope, int $scopeId): array
{
    ba_notify_ensure($db);
    $st = $db->prepare('SELECT kind, email, in_app, noc FROM notify_rules WHERE scope=? AND scope_id=?');
    $st->execute([$scope, $scopeId]);
    $out = [];
    foreach ($st->fetchAll() as $row) {
        $row = array_change_key_case($row, CASE_LOWER);
        $out[(string)$row['kind']] = [
            'email' => (int)$row['email'] === 1,
            'in_app' => (int)$row['in_app'] === 1,
            'noc' => (int)$row['noc'] === 1,
        ];
    }
    return $out;
}

function ba_notify_rule_on(array $rules, string $kind): array
{
    return $rules[$kind] ?? ['email' => false, 'in_app' => false, 'noc' => false];
}

function ba_notify_save_rules(PDO $db, string $scope, int $scopeId, array $posted, string $group): void
{
    ba_notify_ensure($db);
    $before = ba_notify_rules($db, $scope, $scopeId);
    $sql = ba_db_driver() === 'sqlsrv'
        ? null
        : 'INSERT INTO notify_rules (scope, scope_id, kind, email, in_app, noc) VALUES (?,?,?,?,?,?)
            ON CONFLICT(scope, scope_id, kind) DO UPDATE SET email=excluded.email, in_app=excluded.in_app, noc=excluded.noc';
    foreach (ba_notify_catalog() as $item) {
        if ($item['group'] !== $group) {
            continue;
        }
        $code = $item['code'];
        $slot = is_array($posted[$code] ?? null) ? $posted[$code] : [];
        $email = !empty($slot['email']) ? 1 : 0;
        $inApp = !empty($slot['in_app']) ? 1 : 0;
        $noc = !empty($slot['noc']) ? 1 : 0;
        if ($sql !== null) {
            $db->prepare($sql)->execute([$scope, $scopeId, $code, $email, $inApp, $noc]);
        } else {
            $up = $db->prepare('UPDATE notify_rules SET email=?, in_app=?, noc=? WHERE scope=? AND scope_id=? AND kind=?');
            $up->execute([$email, $inApp, $noc, $scope, $scopeId, $code]);
            if ($up->rowCount() < 1) {
                $db->prepare('INSERT INTO notify_rules (scope, scope_id, kind, email, in_app, noc) VALUES (?,?,?,?,?,?)')
                    ->execute([$scope, $scopeId, $code, $email, $inApp, $noc]);
            }
        }
        $prev = ba_notify_rule_on($before, $code);
        foreach (['email' => $email, 'in_app' => $inApp, 'noc' => $noc] as $channel => $now) {
            $was = !empty($prev[$channel]);
            if ($now === 1 && !$was && $group === 'device') {
                ba_notify_baseline($db, $scope, $scopeId, $code, $channel);
            }
        }
    }
}

function ba_notify_open_alerts(PDO $db): array
{
    $sql = "SELECT a.device_id, a.code, a.severity, a.message, a.status,
            d.hostname, d.ip, d.group_id, d.department_id, d.kind, d.idf_closet,
            g.name AS group_name
            FROM alerts a
            JOIN devices d ON d.id = a.device_id
            LEFT JOIN groups g ON g.id = d.group_id
            WHERE a.status='open'";
    $rows = [];
    foreach ($db->query($sql) as $row) {
        $row = array_change_key_case($row, CASE_LOWER);
        $kindName = strtolower(trim((string)($row['kind'] ?? '')));
        $kind = ba_notify_kind_for_alert((string)$row['code'], $kindName);
        if ($kind === null) {
            continue;
        }
        $gid = (int)($row['group_id'] ?? 0);
        $rows[] = [
            'device_id' => (int)$row['device_id'],
            'code' => (string)$row['code'],
            'kind' => $kind,
            'severity' => (string)($row['severity'] ?? 'warn'),
            'message' => (string)($row['message'] ?? ''),
            'hostname' => trim((string)($row['hostname'] ?? '')),
            'ip' => trim((string)($row['ip'] ?? '')),
            'group_id' => $gid,
            'department_id' => (int)($row['department_id'] ?? 0),
            'group_name' => trim((string)($row['group_name'] ?? '')),
            'closet' => trim((string)($row['idf_closet'] ?? '')),
        ];
    }
    return $rows;
}

function ba_notify_device_label(array $alert): string
{
    if ($alert['hostname'] !== '') {
        return $alert['hostname'];
    }
    if ($alert['ip'] !== '') {
        return $alert['ip'];
    }
    return 'Device ' . $alert['device_id'];
}

function ba_notify_idf_label(array $alert): string
{
    if ($alert['group_name'] !== '') {
        return $alert['group_name'];
    }
    if ($alert['closet'] !== '') {
        return $alert['closet'];
    }
    return 'Ungrouped';
}

function ba_notify_baseline(PDO $db, string $scope, int $scopeId, string $kind, string $channel): void
{
    $audience = $scope === 'department' ? 'dept:' . $scopeId : 'global';
    foreach (ba_notify_open_alerts($db) as $alert) {
        if ($alert['kind'] !== $kind) {
            continue;
        }
        if ($scope === 'department' && $alert['department_id'] !== $scopeId) {
            continue;
        }
        $key = 'open:' . $kind . ':dev:' . $alert['device_id'];
        ba_notify_claim($db, $key, $audience, $channel, 0, (string)$alert['device_id']);
    }
}

function ba_notify_claim(PDO $db, string $key, string $audience, string $channel, int $announced, string $note): bool
{
    try {
        $db->prepare('INSERT INTO notify_claims (dedupe_key, audience, channel, announced, note, created_at) VALUES (?,?,?,?,?,?)')
            ->execute([$key, $audience, $channel, $announced, $note, gmdate('Y-m-d H:i:s')]);
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

function ba_notify_drop_claim(PDO $db, string $key, string $audience, string $channel): void
{
    $db->prepare('DELETE FROM notify_claims WHERE dedupe_key=? AND audience=? AND channel=?')
        ->execute([$key, $audience, $channel]);
}

function ba_notify_global_users(PDO $db): array
{
    $sql = ba_adapt_sql("SELECT u.id, u.email, u.role, r.code AS role_code
        FROM users u LEFT JOIN roles r ON r.id = u.role_id
        WHERE IFNULL(u.is_active,1)=1");
    $out = [];
    foreach ($db->query($sql) as $row) {
        $row = array_change_key_case($row, CASE_LOWER);
        $code = strtolower(trim((string)($row['role_code'] ?? '')));
        $gate = strtolower(trim((string)($row['role'] ?? '')));
        if ($code !== 'global' && $gate !== 'admin') {
            continue;
        }
        $out[] = ['id' => (int)$row['id'], 'email' => trim((string)($row['email'] ?? ''))];
    }
    return $out;
}

function ba_notify_enrolled(PDO $db, int $departmentId): array
{
    if ($departmentId < 1) {
        return [];
    }
    $sql = ba_adapt_sql('SELECT id, email FROM users WHERE department_id=? AND IFNULL(is_active,1)=1 AND IFNULL(notify_enroll,0)=1');
    $st = $db->prepare($sql);
    $st->execute([$departmentId]);
    $out = [];
    foreach ($st->fetchAll() as $row) {
        $row = array_change_key_case($row, CASE_LOWER);
        $out[] = ['id' => (int)$row['id'], 'email' => trim((string)($row['email'] ?? ''))];
    }
    return $out;
}

function ba_notify_dept_contact(PDO $db, int $departmentId): string
{
    if ($departmentId < 1) {
        return '';
    }
    $st = $db->prepare('SELECT contact_email, is_active FROM departments WHERE id=?');
    $st->execute([$departmentId]);
    $row = $st->fetch();
    if (!$row) {
        return '';
    }
    $row = array_change_key_case($row, CASE_LOWER);
    if ((int)($row['is_active'] ?? 1) === 0) {
        return '';
    }
    return trim((string)($row['contact_email'] ?? ''));
}

function ba_notify_insert(PDO $db, ?int $userId, array $event, int $noc): void
{
    $db->prepare('INSERT INTO notifications (user_id, kind, title, message, severity, noc, department_id, group_id, device_id, dedupe_key, created_at)
        VALUES (?,?,?,?,?,?,?,?,?,?,?)')->execute([
        $userId,
        $event['kind'],
        $event['title'],
        $event['message'],
        $event['severity'],
        $noc,
        $event['department_id'] ?: null,
        $event['group_id'] ?: null,
        $event['device_id'] ?: null,
        $event['key'],
        gmdate('Y-m-d H:i:s'),
    ]);
}

function ba_smtp_cfg(PDO $db): array
{
    ba_notify_ensure($db);
    $sec = function_exists('ba_secrets') ? ba_secrets() : [];
    $pick = static function (string $key, string $env, string $default = '') use ($db, $sec): string {
        $v = trim(ba_setting($db, $key, ''));
        if ($v !== '') {
            return $v;
        }
        $fallback = trim((string)($sec[$env] ?? ''));
        return $fallback !== '' ? $fallback : $default;
    };
    $tlsSaved = ba_setting($db, 'smtp_tls', '');
    $tls = $tlsSaved !== '' ? $tlsSaved === '1' : trim((string)($sec['SMTP_USER'] ?? '')) !== '';
    return [
        'host' => $pick('smtp_host', 'SMTP_HOST'),
        'port' => (int)($pick('smtp_port', 'SMTP_PORT', '25') ?: 25),
        'from' => $pick('smtp_from', 'SMTP_FROM', 'backaisle@localhost'),
        'to' => $pick('smtp_to', 'SMTP_TO'),
        'user' => $pick('smtp_user', 'SMTP_USER'),
        'pass' => $pick('smtp_pass', 'SMTP_PASS'),
        'tls' => $tls,
        'pass_saved' => trim(ba_setting($db, 'smtp_pass', '')) !== '' || trim((string)($sec['SMTP_PASS'] ?? '')) !== '',
    ];
}

function ba_smtp_save(PDO $db, array $post): void
{
    ba_notify_ensure($db);
    ba_set_setting($db, 'smtp_host', trim((string)($post['smtp_host'] ?? '')));
    $port = (int)($post['smtp_port'] ?? 25);
    if ($port < 1 || $port > 65535) {
        $port = 25;
    }
    ba_set_setting($db, 'smtp_port', (string)$port);
    ba_set_setting($db, 'smtp_from', trim((string)($post['smtp_from'] ?? '')));
    ba_set_setting($db, 'smtp_to', trim((string)($post['smtp_to'] ?? '')));
    ba_set_setting($db, 'smtp_user', trim((string)($post['smtp_user'] ?? '')));
    ba_set_setting($db, 'smtp_tls', !empty($post['smtp_tls']) ? '1' : '0');
    $pass = (string)($post['smtp_pass'] ?? '');
    if ($pass !== '') {
        ba_set_setting($db, 'smtp_pass', $pass);
    }
}

function ba_mail_addresses(string $raw): array
{
    $out = [];
    foreach (preg_split('/[,\s;]+/', $raw) ?: [] as $part) {
        $part = trim($part);
        if ($part !== '' && filter_var($part, FILTER_VALIDATE_EMAIL)) {
            $out[strtolower($part)] = $part;
        }
    }
    return array_values($out);
}

function ba_smtp_read(mixed $fp): string
{
    $buf = '';
    while (!feof($fp)) {
        $line = fgets($fp, 515);
        if ($line === false) {
            break;
        }
        $buf .= $line;
        if (isset($line[3]) && $line[3] === ' ') {
            break;
        }
    }
    return $buf;
}

function ba_smtp_expect(mixed $fp, array $codes): void
{
    $reply = ba_smtp_read($fp);
    $code = (int)substr($reply, 0, 3);
    if (!in_array($code, $codes, true)) {
        $brief = trim(preg_replace('/\s+/', ' ', $reply) ?? $reply);
        throw new RuntimeException('Mail server replied ' . substr($brief, 0, 180));
    }
}

function ba_smtp_cmd(mixed $fp, string $cmd, array $codes): void
{
    fwrite($fp, $cmd . "\r\n");
    ba_smtp_expect($fp, $codes);
}

function ba_smtp_send(PDO $db, array $recipients, string $subject, string $body, ?array $cfg = null): void
{
    $cfg = $cfg ?? ba_smtp_cfg($db);
    $recipients = array_values(array_unique(array_filter($recipients, static fn (string $a): bool => filter_var($a, FILTER_VALIDATE_EMAIL) !== false)));
    if ($recipients === []) {
        throw new RuntimeException('No mail recipient is set.');
    }
    if ($cfg['host'] === '') {
        throw new RuntimeException('Mail host is empty. Set it under Org, on the Mail tab.');
    }
    $remote = ($cfg['port'] === 465 ? 'ssl://' : 'tcp://') . $cfg['host'] . ':' . (int)$cfg['port'];
    $fp = @stream_socket_client($remote, $errno, $errstr, 12, STREAM_CLIENT_CONNECT);
    if (!$fp) {
        throw new RuntimeException('Could not reach the mail host.');
    }
    stream_set_timeout($fp, 12);
    try {
        ba_smtp_expect($fp, [220]);
        $ehlo = 'BackAisle';
        ba_smtp_cmd($fp, 'EHLO ' . $ehlo, [250]);
        if (!empty($cfg['tls']) && $cfg['port'] !== 465) {
            ba_smtp_cmd($fp, 'STARTTLS', [220]);
            $crypto = stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
            if ($crypto !== true) {
                throw new RuntimeException('The mail server did not start encryption.');
            }
            ba_smtp_cmd($fp, 'EHLO ' . $ehlo, [250]);
        }
        if ($cfg['user'] !== '') {
            ba_smtp_cmd($fp, 'AUTH LOGIN', [334]);
            ba_smtp_cmd($fp, base64_encode($cfg['user']), [334]);
            ba_smtp_cmd($fp, base64_encode((string)$cfg['pass']), [235]);
        }
        $from = filter_var($cfg['from'], FILTER_VALIDATE_EMAIL) ? $cfg['from'] : 'backaisle@localhost';
        ba_smtp_cmd($fp, 'MAIL FROM:<' . $from . '>', [250]);
        foreach ($recipients as $rcpt) {
            ba_smtp_cmd($fp, 'RCPT TO:<' . $rcpt . '>', [250, 251]);
        }
        ba_smtp_cmd($fp, 'DATA', [354]);
        $safeSubject = str_replace(["\r", "\n"], ' ', $subject);
        $headers = 'From: ' . $from . "\r\n"
            . 'To: ' . implode(', ', $recipients) . "\r\n"
            . 'Subject: ' . $safeSubject . "\r\n"
            . "MIME-Version: 1.0\r\n"
            . "Content-Type: text/plain; charset=UTF-8\r\n"
            . "\r\n";
        $payload = $headers . str_replace("\n.", "\n..", str_replace(["\r\n", "\r"], "\n", $body));
        $payload = str_replace("\n", "\r\n", $payload) . "\r\n.";
        fwrite($fp, $payload . "\r\n");
        ba_smtp_expect($fp, [250]);
        fwrite($fp, "QUIT\r\n");
    } finally {
        fclose($fp);
    }
}

function ba_notify_buckets(array $alerts, bool $groupByIdf): array
{
    if (!$groupByIdf) {
        $out = [];
        foreach ($alerts as $alert) {
            $out[] = [$alert];
        }
        return $out;
    }
    $buckets = [];
    foreach ($alerts as $alert) {
        $idf = $alert['group_id'] > 0 ? 'g' . $alert['group_id'] : 'd' . $alert['device_id'];
        $buckets[$alert['kind'] . '|' . $idf][] = $alert;
    }
    return array_values($buckets);
}

function ba_notify_event_from_bucket(array $bucket, bool $clear): array
{
    $first = $bucket[0];
    $item = ba_notify_kind($first['kind']);
    $label = $item['label'] ?? $first['kind'];
    $names = [];
    foreach ($bucket as $alert) {
        $names[] = ba_notify_device_label($alert);
    }
    $names = array_values(array_unique($names));
    $grouped = count($bucket) > 1 && $first['group_id'] > 0;
    if ($grouped) {
        $title = $label . ' · ' . ba_notify_idf_label($first);
        $message = count($names) . ' devices in ' . ba_notify_idf_label($first) . ': ' . implode(', ', $names);
        $key = ($clear ? 'clear:' : 'open:') . $first['kind'] . ':idf:' . $first['group_id'];
    } else {
        $title = $label . ' · ' . ba_notify_device_label($first);
        $message = $first['message'] !== '' ? $first['message'] : $label;
        if ($first['ip'] !== '' && !str_contains($message, $first['ip'])) {
            $message .= ' (' . ba_notify_device_label($first) . ')';
        }
        $key = ($clear ? 'clear:' : 'open:') . $first['kind'] . ':dev:' . $first['device_id'];
    }
    if ($clear) {
        $title = 'Cleared: ' . $title;
        $message = 'Recovered. ' . $message;
    }
    $sev = 'warn';
    foreach ($bucket as $alert) {
        if (in_array(strtolower($alert['severity']), ['crit', 'critical'], true)) {
            $sev = 'crit';
        }
    }
    if ($clear) {
        $sev = 'ok';
    }
    return [
        'key' => $key,
        'kind' => $first['kind'],
        'title' => $title,
        'message' => $message,
        'severity' => $sev,
        'department_id' => $first['department_id'],
        'group_id' => $grouped ? $first['group_id'] : $first['group_id'],
        'device_id' => $grouped ? 0 : $first['device_id'],
        'device_ids' => array_map(static fn (array $a): int => $a['device_id'], $bucket),
    ];
}

function ba_notify_release_opposite(PDO $db, string $key, string $audience): void
{
    if (str_starts_with($key, 'open:')) {
        $other = 'clear:' . substr($key, 5);
    } elseif (str_starts_with($key, 'clear:')) {
        $other = 'open:' . substr($key, 6);
    } else {
        return;
    }
    $db->prepare('DELETE FROM notify_claims WHERE dedupe_key=? AND audience=?')->execute([$other, $audience]);
}

function ba_notify_has_claim(PDO $db, string $key, string $audience, string $channel): bool
{
    $st = $db->prepare('SELECT 1 FROM notify_claims WHERE dedupe_key=? AND audience=? AND channel=?');
    $st->execute([$key, $audience, $channel]);
    return (bool)$st->fetchColumn();
}

function ba_notify_deliver(PDO $db, string $audience, array $event, array $channels, array $users, array $extraMail): void
{
    ba_notify_release_opposite($db, $event['key'], $audience);
    $note = implode(',', $event['device_ids']);
    if (!empty($channels['in_app'])) {
        if (ba_notify_claim($db, $event['key'], $audience, 'in_app', 1, $note)) {
            try {
                foreach ($users as $person) {
                    ba_notify_insert($db, (int)$person['id'], $event, !empty($channels['noc']) ? 1 : 0);
                }
            } catch (Throwable $e) {
                ba_notify_drop_claim($db, $event['key'], $audience, 'in_app');
                throw $e;
            }
        }
    }
    if (!empty($channels['noc'])) {
        if (ba_notify_claim($db, $event['key'], $audience, 'noc', 1, $note)) {
            try {
                ba_notify_insert($db, null, $event, 1);
            } catch (Throwable $e) {
                ba_notify_drop_claim($db, $event['key'], $audience, 'noc');
                throw $e;
            }
        }
    }
    if (!empty($channels['email'])) {
        $mail = $extraMail;
        foreach ($users as $person) {
            if ($person['email'] !== '') {
                $mail[] = $person['email'];
            }
        }
        $mail = array_values(array_unique(ba_mail_addresses(implode(',', $mail))));
        if ($mail !== [] && ba_notify_claim($db, $event['key'], $audience, 'email', 1, $note)) {
            try {
                ba_smtp_send($db, $mail, 'BackAisle: ' . $event['title'], $event['message'] . "\n\nOpen Alerts in BackAisle for the current list.");
            } catch (Throwable $e) {
                ba_notify_drop_claim($db, $event['key'], $audience, 'email');
                @file_put_contents(BA_ROOT . '\\logs\\php-error.log', date('c') . ' notify mail ' . $e->getMessage() . "\n", FILE_APPEND);
            }
        }
    }
}

function ba_notify_channels_for(array $rules, string $kind): array
{
    $rule = ba_notify_rule_on($rules, $kind);
    return [
        'email' => $rule['email'],
        'in_app' => $rule['in_app'],
        'noc' => $rule['noc'],
    ];
}

function ba_notify_pending(PDO $db, string $audience, array $channels, string $kind, int $deviceId): bool
{
    $devKey = 'open:' . $kind . ':dev:' . $deviceId;
    foreach (['email', 'in_app', 'noc'] as $channel) {
        if (empty($channels[$channel])) {
            continue;
        }
        if (!ba_notify_has_claim($db, $devKey, $audience, $channel)) {
            return true;
        }
    }
    return false;
}

function ba_notify_mark_devices(PDO $db, string $audience, array $channels, string $kind, array $bucket, string $eventKey, int $announced): void
{
    foreach (['email', 'in_app', 'noc'] as $channel) {
        if (empty($channels[$channel]) || !ba_notify_has_claim($db, $eventKey, $audience, $channel)) {
            continue;
        }
        foreach ($bucket as $alert) {
            ba_notify_claim($db, 'open:' . $kind . ':dev:' . $alert['device_id'], $audience, $channel, $announced, (string)$alert['device_id']);
        }
    }
}

function ba_notify_sweep_devices(PDO $db, string $audience, array $rules, bool $groupBy, array $alerts, array $users, array $extraMail): void
{
    $matched = [];
    foreach ($alerts as $alert) {
        $channels = ba_notify_channels_for($rules, $alert['kind']);
        if (!$channels['email'] && !$channels['in_app'] && !$channels['noc']) {
            continue;
        }
        $matched[$alert['kind']][] = $alert;
    }
    foreach ($matched as $kind => $list) {
        $channels = ba_notify_channels_for($rules, $kind);
        foreach (ba_notify_buckets($list, $groupBy) as $bucket) {
            $fresh = [];
            foreach ($bucket as $alert) {
                if (ba_notify_pending($db, $audience, $channels, $kind, $alert['device_id'])) {
                    $fresh[] = $alert;
                }
            }
            if (!$fresh) {
                continue;
            }
            $wholeIdf = $groupBy && count($bucket) > 1 && $bucket[0]['group_id'] > 0 && count($fresh) === count($bucket);
            if ($wholeIdf) {
                $event = ba_notify_event_from_bucket($fresh, false);
                ba_notify_deliver($db, $audience, $event, $channels, $users, $extraMail);
                ba_notify_mark_devices($db, $audience, $channels, $kind, $fresh, $event['key'], 0);
                continue;
            }
            foreach ($fresh as $one) {
                $event = ba_notify_event_from_bucket([$one], false);
                ba_notify_deliver($db, $audience, $event, $channels, $users, $extraMail);
                ba_notify_mark_devices($db, $audience, $channels, $kind, [$one], $event['key'], 1);
            }
        }
    }
    ba_notify_sweep_clears($db, $audience, $rules, $alerts, $users, $extraMail);
}

function ba_notify_sweep_clears(PDO $db, string $audience, array $rules, array $openAlerts, array $users, array $extraMail): void
{
    $openIds = [];
    foreach ($openAlerts as $alert) {
        $openIds[$alert['kind'] . ':' . $alert['device_id']] = $alert;
    }
    $st = $db->prepare("SELECT dedupe_key, channel, announced, note FROM notify_claims WHERE audience=? AND dedupe_key LIKE 'open:%' AND announced=1");
    $st->execute([$audience]);
    $seen = [];
    foreach ($st->fetchAll() as $claim) {
        $claim = array_change_key_case($claim, CASE_LOWER);
        $key = (string)$claim['dedupe_key'];
        if (isset($seen[$key])) {
            continue;
        }
        $parts = explode(':', $key);
        if (count($parts) < 4) {
            continue;
        }
        $kind = $parts[1];
        $mode = $parts[2];
        $id = (int)$parts[3];
        $still = false;
        if ($mode === 'dev') {
            $still = isset($openIds[$kind . ':' . $id]);
            $sample = $openIds[$kind . ':' . $id] ?? null;
        } else {
            $sample = null;
            foreach ($openAlerts as $alert) {
                if ($alert['kind'] === $kind && $alert['group_id'] === $id) {
                    $still = true;
                    $sample = $alert;
                    break;
                }
            }
        }
        if ($still) {
            continue;
        }
        $seen[$key] = true;
        $channels = ba_notify_channels_for($rules, $kind);
        if (!$channels['email'] && !$channels['in_app'] && !$channels['noc']) {
            continue;
        }
        $ids = array_values(array_filter(array_map('intval', explode(',', (string)($claim['note'] ?? '')))));
        if (!$ids && $mode === 'dev') {
            $ids = [$id];
        }
        $names = ba_notify_device_names($db, $ids);
        $ghosts = [];
        foreach ($ids as $deviceId) {
            $ghosts[] = [
                'device_id' => $deviceId,
                'code' => '',
                'kind' => $kind,
                'severity' => 'ok',
                'message' => '',
                'hostname' => $names[$deviceId] ?? ('Device ' . $deviceId),
                'ip' => '',
                'group_id' => $mode === 'idf' ? $id : 0,
                'department_id' => 0,
                'group_name' => $mode === 'idf' ? ba_notify_group_name($db, $id) : '',
                'closet' => '',
            ];
        }
        if (!$ghosts) {
            continue;
        }
        if ($sample) {
            $ghosts[0]['group_name'] = $sample['group_name'];
        }
        $event = ba_notify_event_from_bucket($ghosts, true);
        ba_notify_deliver($db, $audience, $event, $channels, $users, $extraMail);
        foreach ($ids as $deviceId) {
            foreach (['email', 'in_app', 'noc'] as $channel) {
                $db->prepare('DELETE FROM notify_claims WHERE dedupe_key=? AND audience=? AND channel=? AND announced=0')
                    ->execute(['open:' . $kind . ':dev:' . $deviceId, $audience, $channel]);
            }
        }
    }
}

function ba_notify_device_names(PDO $db, array $ids): array
{
    $ids = array_values(array_filter(array_map('intval', $ids)));
    if (!$ids) {
        return [];
    }
    $marks = implode(',', array_fill(0, count($ids), '?'));
    $st = $db->prepare("SELECT id, hostname, ip FROM devices WHERE id IN ($marks)");
    $st->execute($ids);
    $out = [];
    foreach ($st->fetchAll() as $row) {
        $row = array_change_key_case($row, CASE_LOWER);
        $name = trim((string)($row['hostname'] ?? ''));
        if ($name === '') {
            $name = trim((string)($row['ip'] ?? ''));
        }
        $out[(int)$row['id']] = $name !== '' ? $name : ('Device ' . (int)$row['id']);
    }
    return $out;
}

function ba_notify_group_name(PDO $db, int $id): string
{
    if ($id < 1) {
        return 'Ungrouped';
    }
    $st = $db->prepare('SELECT name FROM groups WHERE id=?');
    $st->execute([$id]);
    $name = trim((string)($st->fetchColumn() ?: ''));
    return $name !== '' ? $name : ('IDF ' . $id);
}

function ba_notify_system_state(PDO $db, string $kind): ?array
{
    $st = $db->prepare('SELECT is_open, message FROM notify_system WHERE kind=?');
    $st->execute([$kind]);
    $row = $st->fetch();
    if (!$row) {
        return null;
    }
    $row = array_change_key_case($row, CASE_LOWER);
    return ['open' => (int)$row['is_open'] === 1, 'message' => (string)($row['message'] ?? '')];
}

function ba_notify_system_set(PDO $db, string $kind, bool $open, string $message): void
{
    $flag = $open ? 1 : 0;
    $now = gmdate('Y-m-d H:i:s');
    if (ba_db_driver() === 'sqlsrv') {
        $up = $db->prepare('UPDATE notify_system SET is_open=?, message=?, updated_at=? WHERE kind=?');
        $up->execute([$flag, $message, $now, $kind]);
        if ($up->rowCount() < 1) {
            $db->prepare('INSERT INTO notify_system (kind, is_open, message, updated_at) VALUES (?,?,?,?)')
                ->execute([$kind, $flag, $message, $now]);
        }
        return;
    }
    $db->prepare('INSERT INTO notify_system (kind, is_open, message, updated_at) VALUES (?,?,?,?)
        ON CONFLICT(kind) DO UPDATE SET is_open=excluded.is_open, message=excluded.message, updated_at=excluded.updated_at')
        ->execute([$kind, $flag, $message, $now]);
}

function ba_notify_wmi_numbers(): array
{
    if (!class_exists('COM')) {
        return ['cpu' => null, 'memory_free_pct' => null];
    }
    try {
        $wmi = new COM('winmgmts:{impersonationLevel=impersonate}//./root/cimv2');
        $cpu = null;
        foreach ($wmi->ExecQuery('SELECT LoadPercentage FROM Win32_Processor') as $cpuRow) {
            $load = (int)$cpuRow->LoadPercentage;
            $cpu = $cpu === null ? $load : max($cpu, $load);
        }
        $freePct = null;
        foreach ($wmi->ExecQuery('SELECT FreePhysicalMemory, TotalVisibleMemorySize FROM Win32_OperatingSystem') as $os) {
            $total = (float)$os->TotalVisibleMemorySize;
            $free = (float)$os->FreePhysicalMemory;
            if ($total > 0) {
                $freePct = ($free / $total) * 100;
            }
            break;
        }
        return ['cpu' => $cpu, 'memory_free_pct' => $freePct];
    } catch (Throwable $e) {
        return ['cpu' => null, 'memory_free_pct' => null];
    }
}

function ba_notify_system_problems(PDO $db): array
{
    $out = [];
    $wmi = ba_notify_wmi_numbers();
    if ($wmi['cpu'] !== null) {
        $out['system_cpu'] = [
            'open' => $wmi['cpu'] >= 90,
            'message' => 'Processor load is ' . $wmi['cpu'] . '%.',
            'severity' => 'warn',
        ];
    }
    if ($wmi['memory_free_pct'] !== null) {
        $out['system_memory'] = [
            'open' => $wmi['memory_free_pct'] < 10,
            'message' => 'Free physical memory is ' . number_format($wmi['memory_free_pct'], 0) . '%.',
            'severity' => 'warn',
        ];
    }
    $total = @disk_total_space(BA_ROOT);
    $free = @disk_free_space(BA_ROOT);
    if ($total && $free !== false) {
        $pct = ($free / $total) * 100;
        $freeGb = $free / 1073741824;
        $out['system_disk'] = [
            'open' => $pct < 10 || $free < 2 * 1073741824,
            'message' => 'Disk free ' . number_format($freeGb, 1) . ' GB (' . number_format($pct, 0) . '%).',
            'severity' => 'warn',
        ];
    }
    try {
        $db->query('SELECT 1')->fetchColumn();
        $out['system_sql'] = ['open' => false, 'message' => 'Database query succeeded.', 'severity' => 'crit'];
    } catch (Throwable $e) {
        $out['system_sql'] = ['open' => true, 'message' => 'Database query failed.', 'severity' => 'crit'];
    }
    $serviceBits = [];
    $hbPath = BA_ROOT . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR . 'collector.heartbeat.json';
    $hbAge = is_file($hbPath) ? time() - (int)filemtime($hbPath) : null;
    if ($hbAge === null || $hbAge > 180) {
        $serviceBits[] = $hbAge === null
            ? 'The collector has no heartbeat.'
            : 'Collector heartbeat is ' . (int)round($hbAge / 60) . ' minutes old.';
    }
    $queued = 0;
    try {
        $queued = (int)$db->query(ba_adapt_sql("SELECT COUNT(*) FROM write_jobs WHERE status IN ('queued','running')"))->fetchColumn();
    } catch (Throwable $e) {
        $queued = 0;
    }
    $writerLog = BA_ROOT . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR . 'writer.log';
    $writerAge = is_file($writerLog) ? time() - (int)filemtime($writerLog) : null;
    if ($queued > 0 && ($writerAge === null || $writerAge > 600)) {
        $serviceBits[] = $queued . ' write job' . ($queued === 1 ? ' is' : 's are') . ' still queued, and the writer log is quiet.';
    }
    $out['system_services'] = [
        'open' => $serviceBits !== [],
        'message' => $serviceBits ? implode(' ', $serviceBits) : 'Collector heartbeat is current.',
        'severity' => 'warn',
    ];
    $updateOpen = false;
    $updateMsg = 'No newer release is waiting from the last check.';
    try {
        if (class_exists('BackAisleUpdate')) {
            $cached = BackAisleUpdate::cachedStatus();
            if (is_array($cached) && !empty($cached['update_available'])) {
                $updateOpen = true;
                $updateMsg = 'BackAisle ' . (string)($cached['latest'] ?? '') . ' is available. This server is on ' . (string)($cached['current'] ?? ba_version()) . '.';
            }
        }
    } catch (Throwable $e) {
        $updateOpen = false;
    }
    $out['system_updates'] = ['open' => $updateOpen, 'message' => $updateMsg, 'severity' => 'info'];
    return $out;
}

function ba_notify_sweep_system(PDO $db): void
{
    $last = (int)ba_setting($db, 'notify_system_at', '0');
    if ($last > 0 && (time() - $last) < 60) {
        return;
    }
    ba_set_setting($db, 'notify_system_at', (string)time());
    $rules = ba_notify_rules($db, 'global', 0);
    $users = ba_notify_global_users($db);
    $siteTo = ba_mail_addresses(ba_smtp_cfg($db)['to']);
    foreach (ba_notify_system_problems($db) as $kind => $problem) {
        ba_notify_system_set($db, $kind, $problem['open'], $problem['message']);
        $channels = ba_notify_channels_for($rules, $kind);
        if (!$channels['email'] && !$channels['in_app'] && !$channels['noc']) {
            continue;
        }
        $item = ba_notify_kind($kind);
        $openKey = 'open:' . $kind . ':sys';
        if ($problem['open']) {
            $event = [
                'key' => $openKey,
                'kind' => $kind,
                'title' => $item['label'] ?? 'System',
                'message' => $problem['message'],
                'severity' => $problem['severity'],
                'department_id' => 0,
                'group_id' => 0,
                'device_id' => 0,
                'device_ids' => [],
            ];
            ba_notify_deliver($db, 'global', $event, $channels, $users, $siteTo);
            continue;
        }
        $announced = false;
        foreach (['email', 'in_app', 'noc'] as $channel) {
            if (!empty($channels[$channel]) && ba_notify_has_claim($db, $openKey, 'global', $channel)) {
                $announced = true;
            }
        }
        if (!$announced) {
            continue;
        }
        $event = [
            'key' => 'clear:' . $kind . ':sys',
            'kind' => $kind,
            'title' => 'Cleared: ' . ($item['label'] ?? 'System'),
            'message' => $problem['message'],
            'severity' => 'ok',
            'department_id' => 0,
            'group_id' => 0,
            'device_id' => 0,
            'device_ids' => [],
        ];
        ba_notify_deliver($db, 'global', $event, $channels, $users, $siteTo);
    }
}

function ba_notify_sweep(PDO $db): void
{
    ba_notify_ensure($db);
    $lockPath = BA_ROOT . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR . 'notify.lock';
    $dir = dirname($lockPath);
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    $fh = @fopen($lockPath, 'c');
    if (!$fh || !flock($fh, LOCK_EX | LOCK_NB)) {
        if ($fh) {
            fclose($fh);
        }
        return;
    }
    try {
        $last = (int)ba_setting($db, 'notify_sweep_at', '0');
        if ($last > 0 && (time() - $last) < 20) {
            return;
        }
        ba_set_setting($db, 'notify_sweep_at', (string)time());
        ba_notify_sweep_system($db);
        $alerts = ba_notify_open_alerts($db);
        $globalRules = ba_notify_rules($db, 'global', 0);
        $groupGlobal = ba_setting($db, 'notify_group_global', '0') === '1';
        ba_notify_sweep_devices($db, 'global', $globalRules, $groupGlobal, $alerts, ba_notify_global_users($db), ba_mail_addresses(ba_smtp_cfg($db)['to']));
        $deptSql = ba_adapt_sql('SELECT id, notify_group_idf, is_active FROM departments');
        foreach ($db->query($deptSql) as $dept) {
            $dept = array_change_key_case($dept, CASE_LOWER);
            if ((int)($dept['is_active'] ?? 1) === 0) {
                continue;
            }
            $id = (int)$dept['id'];
            $mine = array_values(array_filter($alerts, static fn (array $a): bool => $a['department_id'] === $id));
            $rules = ba_notify_rules($db, 'department', $id);
            $contact = ba_notify_dept_contact($db, $id);
            ba_notify_sweep_devices(
                $db,
                'dept:' . $id,
                $rules,
                (int)($dept['notify_group_idf'] ?? 0) === 1,
                $mine,
                ba_notify_enrolled($db, $id),
                $contact !== '' ? ba_mail_addresses($contact) : []
            );
        }
    } catch (Throwable $e) {
        @file_put_contents(BA_ROOT . '\\logs\\php-error.log', date('c') . ' notify sweep ' . $e->getMessage() . "\n", FILE_APPEND);
    } finally {
        flock($fh, LOCK_UN);
        fclose($fh);
    }
}

function ba_notify_matrix(array $rules, string $group): void
{
    echo '<div class="notify-scroll"><table class="notify-matrix"><thead><tr><th>Alert</th><th>Email</th><th>In-app</th><th>NOC</th></tr></thead><tbody>';
    foreach (ba_notify_catalog() as $item) {
        if ($item['group'] !== $group) {
            continue;
        }
        $code = $item['code'];
        $rule = ba_notify_rule_on($rules, $code);
        echo '<tr><td><strong>' . h($item['label']) . '</strong><div class="muted">' . h($item['detail']) . '</div></td>';
        foreach (['email', 'in_app', 'noc'] as $channel) {
            $checked = !empty($rule[$channel]) ? ' checked' : '';
            echo '<td class="notify-check"><label><input type="checkbox" name="rule[' . h($code) . '][' . $channel . ']" value="1"' . $checked . '><span class="sr-only">' . h($item['label'] . ' ' . $channel) . '</span></label></td>';
        }
        echo '</tr>';
    }
    echo '</tbody></table></div>';
}

function ba_notify_group_box(bool $on): void
{
    echo '<label class="notify-group"><input type="checkbox" name="group_by_idf" value="1"' . ($on ? ' checked' : '') . '> Group by IDF. Send one notice when several devices in the same IDF have the same alert. Leave this off to send a notice for every device.</label>';
}

function page_account(PDO $db, array $user): void
{
    ba_notify_ensure($db);
    $uid = (int)($user['id'] ?? 0);
    $msg = '';
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        $act = (string)($_POST['act'] ?? '');
        try {
            if ($act === 'save_profile') {
                $email = trim((string)($_POST['email'] ?? ''));
                if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    throw new RuntimeException('Enter a valid email address.');
                }
                $enroll = !empty($_POST['notify_enroll']) ? 1 : 0;
                $db->prepare('UPDATE users SET email=?, notify_enroll=? WHERE id=?')
                    ->execute([$email !== '' ? $email : null, $enroll, $uid]);
                $_SESSION['user']['notify_enroll'] = $enroll;
                ba_audit($db, 'notify_profile', 'user', (string)$uid, $enroll ? 'enrolled' : 'not enrolled');
                $msg = $enroll
                    ? 'You are enrolled in department notifications.'
                    : 'Department notifications are off for your account.';
                if ($enroll && $email === '') {
                    $msg .= ' Add an email address to receive mail. In-app notices still appear.';
                }
            } elseif ($act === 'save_department') {
                $deptId = (int)($_POST['department_id'] ?? 0);
                if (!ba_notify_can_dept($user, $deptId)) {
                    throw new RuntimeException('That department is not yours.');
                }
                $db->prepare('UPDATE departments SET notify_group_idf=? WHERE id=?')
                    ->execute([!empty($_POST['group_by_idf']) ? 1 : 0, $deptId]);
                ba_notify_save_rules($db, 'department', $deptId, is_array($_POST['rule'] ?? null) ? $_POST['rule'] : [], 'device');
                ba_audit($db, 'notify_department', 'department', (string)$deptId);
                $msg = 'Department notification choices saved. Alerts already open stay quiet. The next new alert uses these boxes.';
            } elseif ($act === 'save_global') {
                if (!ba_notify_is_global($user)) {
                    throw new RuntimeException('Global Admin only.');
                }
                ba_set_setting($db, 'notify_group_global', !empty($_POST['group_by_idf']) ? '1' : '0');
                $posted = is_array($_POST['rule'] ?? null) ? $_POST['rule'] : [];
                ba_notify_save_rules($db, 'global', 0, $posted, 'system');
                ba_notify_save_rules($db, 'global', 0, $posted, 'device');
                ba_audit($db, 'notify_global', 'settings', '0');
                $msg = 'Site notification choices saved. Alerts already open stay quiet. The next new alert uses these boxes.';
            }
        } catch (Throwable $e) {
            $msg = $e->getMessage();
        }
        if (function_exists('ba_refresh_session')) {
            try {
                $fresh = ba_refresh_session($db);
                if (is_array($fresh)) {
                    $user = $fresh;
                }
            } catch (Throwable $e) {
            }
        }
    }
    $me = $db->prepare('SELECT email, notify_enroll, department_id FROM users WHERE id=?');
    $me->execute([$uid]);
    $row = $me->fetch() ?: [];
    $row = array_change_key_case($row, CASE_LOWER);
    $email = (string)($row['email'] ?? '');
    $enrolled = (int)($row['notify_enroll'] ?? 0) === 1;
    $myDept = (int)($row['department_id'] ?? 0);
    $deptName = '';
    if ($myDept > 0) {
        $st = $db->prepare('SELECT name FROM departments WHERE id=?');
        $st->execute([$myDept]);
        $deptName = (string)($st->fetchColumn() ?: '');
    }
    ba_layout_start('Settings', 'account');
    echo '<div class="dash-hero"><div><h1>Settings</h1><p class="muted">Your notification choices live here. More personal settings can be added on this page.</p></div></div>';
    if ($msg !== '') {
        echo '<div class="flash">' . h($msg) . '</div>';
    }
    ba_card_open('Your notifications');
    echo '<form method="post" class="stack notify-form">';
    echo '<input type="hidden" name="act" value="save_profile">';
    echo '<label>Email</label><input name="email" type="email" value="' . h($email) . '" autocomplete="email">';
    if ($myDept > 0) {
        echo '<label class="notify-group"><input type="checkbox" name="notify_enroll" value="1"' . ($enrolled ? ' checked' : '') . '> Enroll me in notifications for ' . h($deptName !== '' ? $deptName : 'my department') . '. My email is added to that department\'s recipient list.</label>';
    } else {
        echo '<p class="muted">You are not in a department yet, so there is no department list to join. A Global Admin or IDFM Admin assigns the department.</p>';
    }
    echo '<button>Save</button></form>';
    ba_card_close();

    $editDept = 0;
    if (($user['role'] ?? '') === 'dept_admin' && $myDept > 0) {
        $editDept = $myDept;
    } elseif (ba_notify_is_global($user)) {
        $editDept = (int)($_GET['department'] ?? 0);
        if ($editDept < 1) {
            $editDept = $myDept;
        }
        if ($editDept < 1) {
            $editDept = (int)$db->query(ba_adapt_sql('SELECT id FROM departments WHERE IFNULL(is_active,1)=1 ORDER BY name LIMIT 1'))->fetchColumn();
        }
    }
    if ($editDept > 0 && ba_notify_can_dept($user, $editDept)) {
        $depts = [];
        if (ba_notify_is_global($user)) {
            foreach ($db->query(ba_adapt_sql('SELECT id, name FROM departments WHERE IFNULL(is_active,1)=1 ORDER BY name')) as $d) {
                $d = array_change_key_case($d, CASE_LOWER);
                $depts[] = $d;
            }
        }
        $nameSt = $db->prepare('SELECT name, notify_group_idf FROM departments WHERE id=?');
        $nameSt->execute([$editDept]);
        $deptRow = array_change_key_case($nameSt->fetch() ?: [], CASE_LOWER);
        ba_card_open('Department alerts');
        echo '<p class="muted">These choices apply only to devices owned by ' . h((string)($deptRow['name'] ?? 'this department')) . '. Email goes to the department contact and to people who enroll. In-app notices pop up for people who enroll. NOC keeps the notice for the future NOC view.</p>';
        if ($depts) {
            echo '<form method="get" class="filters" action="' . h(ba_href('/account')) . '">';
            echo '<label>Department</label><select name="department" onchange="this.form.submit()">';
            foreach ($depts as $d) {
                $id = (int)$d['id'];
                echo '<option value="' . $id . '"' . ($id === $editDept ? ' selected' : '') . '>' . h((string)$d['name']) . '</option>';
            }
            echo '</select></form>';
        }
        echo '<form method="post" class="stack notify-form">';
        echo '<input type="hidden" name="act" value="save_department">';
        echo '<input type="hidden" name="department_id" value="' . $editDept . '">';
        ba_notify_group_box((int)($deptRow['notify_group_idf'] ?? 0) === 1);
        ba_notify_matrix(ba_notify_rules($db, 'department', $editDept), 'device');
        echo '<button>Save department alerts</button></form>';
        ba_card_close();
    } elseif (($user['role'] ?? '') === 'dept_admin') {
        ba_card_open('Department alerts');
        echo '<p class="muted">Your sign-in is a Department Admin, and it is not assigned to a department. Ask a Global Admin to set that department before these boxes can be saved.</p>';
        ba_card_close();
    } elseif (ba_notify_is_global($user)) {
        ba_card_open('Department alerts');
        echo '<p class="muted">No department is available yet. Add one under Users, assign its people and devices, then choose the alerts here. Those alerts cover only that department\'s devices.</p>';
        ba_card_close();
    }

    if (ba_notify_is_global($user)) {
        ba_card_open('System and site alerts');
        echo '<p class="muted">Global Admins choose which application problems and device alerts are announced for the whole site. Email goes to the site notification address on Org → Mail. In-app notices pop up for Global Admins. NOC is the list the future NOC view will show.</p>';
        echo '<form method="post" class="stack notify-form">';
        echo '<input type="hidden" name="act" value="save_global">';
        $rules = ba_notify_rules($db, 'global', 0);
        echo '<h3 class="notify-sub">System</h3>';
        ba_notify_matrix($rules, 'system');
        echo '<h3 class="notify-sub">Devices</h3>';
        ba_notify_group_box(ba_setting($db, 'notify_group_global', '0') === '1');
        ba_notify_matrix($rules, 'device');
        echo '<button>Save site alerts</button></form>';
        $recent = $db->query(ba_adapt_sql("SELECT title, message, created_at FROM notifications WHERE user_id IS NULL AND noc=1 ORDER BY id DESC LIMIT 8"))->fetchAll();
        if ($recent) {
            echo '<h3 class="notify-sub">Latest NOC notices</h3><ul class="notify-noc">';
            foreach ($recent as $n) {
                $n = array_change_key_case($n, CASE_LOWER);
                echo '<li><strong>' . h((string)$n['title']) . '</strong> <span class="muted">' . h((string)$n['created_at']) . '</span><div>' . h((string)$n['message']) . '</div></li>';
            }
            echo '</ul>';
        }
        ba_card_close();
    }
    ba_layout_end();
}

function ba_notify_can_dept(array $user, int $deptId): bool
{
    if ($deptId < 1) {
        return false;
    }
    if (ba_notify_is_global($user)) {
        return true;
    }
    return ($user['role'] ?? '') === 'dept_admin' && (int)($user['department_id'] ?? 0) === $deptId;
}

function ba_notify_org_post(PDO $db, string $act, array $post): string
{
    if ($act === 'save_smtp') {
        ba_smtp_save($db, $post);
        ba_audit($db, 'smtp_save', 'settings', null, 'host ' . trim((string)($post['smtp_host'] ?? '')));
        return 'Mail settings saved.';
    }
    if ($act === 'smtp_test') {
        ba_smtp_save($db, $post);
        $cfg = ba_smtp_cfg($db);
        $to = ba_mail_addresses((string)($post['smtp_test_to'] ?? ''));
        if (!$to) {
            $to = ba_mail_addresses($cfg['to']);
        }
        ba_smtp_send($db, $to, 'BackAisle mail test', 'This is a test message from BackAisle Org → Mail.', $cfg);
        ba_audit($db, 'smtp_test', 'settings', null);
        return 'Test message sent.';
    }
    return '';
}

function ba_notify_org_mail(PDO $db): void
{
    $cfg = ba_smtp_cfg($db);
    ba_card_open('Mail server');
    echo '<p class="muted">BackAisle sends notification mail through this server. A password saved here overrides the SMTP password in secrets.env. Leave the password blank to keep the one already stored. The site notification address receives mail for alerts a Global Admin checks.</p>';
    echo '<form method="post" class="stack notify-form" autocomplete="off">';
    echo '<input type="hidden" name="tab" value="mail">';
    echo '<input type="hidden" name="act" value="save_smtp">';
    echo '<label>Host</label><input name="smtp_host" value="' . h($cfg['host']) . '">';
    echo '<label>Port</label><input name="smtp_port" type="number" min="1" max="65535" value="' . (int)$cfg['port'] . '">';
    echo '<label>From address</label><input name="smtp_from" type="email" value="' . h($cfg['from']) . '">';
    echo '<label>Site notification address</label><input name="smtp_to" value="' . h($cfg['to']) . '" placeholder="noc@example.org, ops@example.org">';
    echo '<label>Username</label><input name="smtp_user" value="' . h($cfg['user']) . '" autocomplete="off">';
    echo '<label>Password</label><input name="smtp_pass" type="password" value="" autocomplete="new-password" placeholder="' . ($cfg['pass_saved'] ? 'Saved. Leave blank to keep it.' : 'Optional') . '">';
    echo '<label class="notify-group"><input type="checkbox" name="smtp_tls" value="1"' . (!empty($cfg['tls']) ? ' checked' : '') . '> Use STARTTLS</label>';
    echo '<button>Save mail settings</button></form>';
    echo '<form method="post" class="stack notify-form">';
    echo '<input type="hidden" name="tab" value="mail">';
    echo '<input type="hidden" name="act" value="smtp_test">';
    echo '<input type="hidden" name="smtp_host" value="' . h($cfg['host']) . '">';
    echo '<input type="hidden" name="smtp_port" value="' . (int)$cfg['port'] . '">';
    echo '<input type="hidden" name="smtp_from" value="' . h($cfg['from']) . '">';
    echo '<input type="hidden" name="smtp_to" value="' . h($cfg['to']) . '">';
    echo '<input type="hidden" name="smtp_user" value="' . h($cfg['user']) . '">';
    echo '<input type="hidden" name="smtp_tls" value="' . (!empty($cfg['tls']) ? '1' : '0') . '">';
    echo '<label>Send a test to</label><input name="smtp_test_to" type="email" placeholder="Defaults to the site notification address">';
    echo '<button class="btn">Send test</button></form>';
    ba_card_close();
}

function page_api_notify(PDO $db, array $user): void
{
    ba_notify_ensure($db);
    $uid = (int)($user['id'] ?? 0);
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        $action = (string)($_POST['action'] ?? '');
        if ($action === 'mark_read') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id > 0) {
                $db->prepare('UPDATE notifications SET read_at=? WHERE id=? AND user_id=? AND read_at IS NULL')
                    ->execute([gmdate('Y-m-d H:i:s'), $id, $uid]);
            }
        } elseif ($action === 'mark_all') {
            $db->prepare('UPDATE notifications SET read_at=? WHERE user_id=? AND read_at IS NULL')
                ->execute([gmdate('Y-m-d H:i:s'), $uid]);
        }
        header('Content-Type: application/json');
        echo json_encode(['ok' => true]);
        return;
    }
    try {
        ba_notify_sweep($db);
    } catch (Throwable $e) {
    }
    $since = (int)($_GET['since_id'] ?? 0);
    if ($since > 0) {
        $st = $db->prepare(ba_adapt_sql('SELECT id, title, message, severity, created_at FROM notifications WHERE user_id=? AND id>? ORDER BY id ASC LIMIT 8'));
        $st->execute([$uid, $since]);
    } else {
        $st = $db->prepare(ba_adapt_sql('SELECT id, title, message, severity, created_at FROM notifications WHERE user_id=? AND read_at IS NULL ORDER BY id DESC LIMIT 8'));
        $st->execute([$uid]);
    }
    $rows = $st->fetchAll() ?: [];
    if ($since < 1) {
        $rows = array_reverse($rows);
    }
    $items = [];
    $max = $since;
    foreach ($rows as $row) {
        $row = array_change_key_case($row, CASE_LOWER);
        $id = (int)$row['id'];
        if ($id > $max) {
            $max = $id;
        }
        $sev = strtolower((string)$row['severity']);
        $toast = 'info';
        if ($sev === 'crit' || $sev === 'critical') {
            $toast = 'error';
        } elseif ($sev === 'warn' || $sev === 'warning') {
            $toast = 'warning';
        } elseif ($sev === 'ok') {
            $toast = 'success';
        }
        $items[] = [
            'id' => $id,
            'title' => (string)$row['title'],
            'message' => (string)$row['message'],
            'toast_type' => $toast,
            'created_at' => (string)$row['created_at'],
        ];
    }
    $unreadSt = $db->prepare('SELECT COUNT(*) FROM notifications WHERE user_id=? AND read_at IS NULL');
    $unreadSt->execute([$uid]);
    header('Content-Type: application/json');
    echo json_encode(['ok' => true, 'unread' => (int)$unreadSt->fetchColumn(), 'max_id' => $max, 'items' => $items]);
}
