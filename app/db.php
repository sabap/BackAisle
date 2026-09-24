<?php
declare(strict_types=1);

function ba_db_driver(): string {
    $d = strtolower((string)(ba_config()['db']['driver'] ?? 'sqlite'));
    return $d === 'sqlsrv' || $d === 'sqlserver' ? 'sqlsrv' : 'sqlite';
}

function ba_last_id(PDO $db): int
{
    if (ba_db_driver() === 'sqlsrv') {
        try {
            $v = $db->query('SELECT CAST(@@IDENTITY AS INT)')->fetchColumn();
            if ($v !== false && $v !== null && (int)$v > 0) {
                return (int)$v;
            }
        } catch (Throwable $e) {
        }
        try {
            $v = $db->query('SELECT CONVERT(int, SCOPE_IDENTITY())')->fetchColumn();
            return (int)$v;
        } catch (Throwable $e) {
            return 0;
        }
    }
    try {
        return (int)$db->lastInsertId();
    } catch (Throwable $e) {
        return 0;
    }
}

function ba_adapt_sql(string $sql): string {
    if (ba_db_driver() !== 'sqlsrv') {
        return $sql;
    }
    if (preg_match('/^\s*PRAGMA\b/i', $sql) || preg_match('/^\s*VACUUM\b/i', $sql)) {
        return 'SELECT 1';
    }
    $sql = preg_replace("/datetime\('now'\s*,\s*'-(\d+)\s*days?'\)/i", 'DATEADD(day, -$1, SYSUTCDATETIME())', $sql) ?? $sql;
    $sql = str_ireplace("datetime('now')", 'SYSUTCDATETIME()', $sql);
    $sql = str_ireplace('IFNULL(', 'ISNULL(', $sql);
    $sql = str_ireplace('INSERT OR IGNORE INTO', 'INSERT INTO', $sql);
    $sql = str_ireplace('INSERT OR REPLACE INTO', 'INSERT INTO', $sql);
    $sql = str_ireplace("strftime('%Y-%m-%d %H:00:00', ts)", "CONVERT(varchar(13), ts, 120) + ':00:00'", $sql);
    $sql = preg_replace_callback(
        '/\(\s*SELECT\s+(?!TOP\b)([^()]*?)\s+LIMIT\s+(\d+)\s*\)/is',
        static function (array $m): string {
            return '(SELECT TOP ' . $m[2] . ' ' . trim($m[1]) . ')';
        },
        $sql
    ) ?? $sql;
    if (preg_match('/^(.*)\s+LIMIT\s+(\d+)\s*$/is', $sql, $m)) {
        $inner = $m[1];
        $n = (int)$m[2];
        if (preg_match('/^\s*SELECT\s+/i', $inner) && !preg_match('/^\s*SELECT\s+TOP\s+/i', $inner)) {
            $inner = preg_replace('/^\s*SELECT\s+/i', 'SELECT TOP ' . $n . ' ', $inner, 1) ?? $inner;
        }
        $sql = $inner;
    }
    return $sql;
}

class BaPdo extends PDO
{
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        return parent::prepare(ba_adapt_sql($query), $options);
    }

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        $q = ba_adapt_sql($query);
        if ($fetchMode === null) {
            return parent::query($q);
        }
        return parent::query($q, $fetchMode, ...$fetchModeArgs);
    }

    public function exec(string $statement): int|false
    {
        return parent::exec(ba_adapt_sql($statement));
    }
}

function ba_sqlsrv_dsn(array $db, bool $includeDatabase = true): string
{
    $driver = $db['odbc_driver'] ?? 'ODBC Driver 18 for SQL Server';
    $host = $db['host'] ?? 'localhost';
    $port = (int)($db['port'] ?? 1433);
    $server = $port && $port !== 1433 ? $host . ',' . $port : $host;
    $enc = !empty($db['encrypt']) ? 'yes' : 'no';
    $trust = !empty($db['trust_server_certificate']) ? 'yes' : 'no';
    $dsn = "odbc:Driver={{$driver}};Server={$server};Encrypt={$enc};TrustServerCertificate={$trust}";
    if ($includeDatabase && !empty($db['database'])) {
        $dsn .= ';Database=' . $db['database'];
    }
    return $dsn;
}

function ba_connect_sqlserver(array $db, bool $includeDatabase = true): PDO
{
    $dsn = ba_sqlsrv_dsn($db, $includeDatabase);
    // Username/password are PDO constructor args so ; { } & and other punctuation are allowed.
    $pdo = new BaPdo($dsn, (string)($db['username'] ?? ''), (string)($db['password'] ?? ''), [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    return $pdo;
}

function ba_db(bool $reconnect = false): PDO {
    static $pdo = null;
    if ($reconnect) {
        $pdo = null;
    }
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    $cfg = ba_config();
    $db = $cfg['db'] ?? ['driver' => 'sqlite', 'path' => BA_DB];
    if (ba_db_driver() === 'sqlsrv') {
        $pdo = ba_connect_sqlserver($db, true);
        ba_ensure_infra_schema($pdo);
        return $pdo;
    }
    $path = (string)($db['path'] ?? BA_DB);
    if (!is_file($path)) {
        throw new RuntimeException('Database not initialized. Run setup.php or collector seed first.');
    }
    $pdo = new BaPdo('sqlite:' . $path, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('PRAGMA journal_mode=WAL');
    $pdo->exec('PRAGMA foreign_keys=ON');
    $pdo->exec('PRAGMA busy_timeout=30000');
    ba_ensure_infra_schema($pdo);
    return $pdo;
}

function ba_apply_sqlserver_schema(PDO $pdo): void {
    $file = BA_ROOT . DIRECTORY_SEPARATOR . 'sql' . DIRECTORY_SEPARATOR . 'schema.sql';
    $sql = @file_get_contents($file);
    if ($sql === false) {
        throw new RuntimeException('Missing sql/schema.sql');
    }
    $parts = preg_split('/^\s*GO\s*$/mi', $sql);
    foreach ($parts as $part) {
        $part = trim($part);
        if ($part === '') continue;
        $pdo->exec($part);
    }
}

function ba_add_col(PDO $db, string $table, string $col, string $spec): void {
    $have = [];
    foreach ($db->query('PRAGMA table_info(' . $table . ')') as $r) {
        $have[$r['name']] = true;
    }
    if (empty($have[$col])) {
        $db->exec("ALTER TABLE $table ADD COLUMN $col $spec");
    }
}

function ba_ensure_infra_schema(PDO $db): void {
    if (ba_db_driver() === 'sqlsrv') {
        return;
    }
    $db->exec(
        "CREATE TABLE IF NOT EXISTS racks (
            id INTEGER PRIMARY KEY,
            group_id INTEGER NOT NULL,
            name TEXT NOT NULL,
            u_height INTEGER NOT NULL DEFAULT 42,
            sort_order INTEGER NOT NULL DEFAULT 0,
            notes TEXT,
            created_at TEXT NOT NULL DEFAULT (datetime('now')),
            updated_at TEXT NOT NULL DEFAULT (datetime('now')),
            FOREIGN KEY (group_id) REFERENCES groups(id)
        )"
    );
    $db->exec('CREATE INDEX IF NOT EXISTS ix_racks_group ON racks(group_id)');
    ba_add_col($db, 'devices', 'kind', "TEXT NOT NULL DEFAULT 'ups'");
    ba_add_col($db, 'devices', 'rack_id', 'INTEGER');
    ba_add_col($db, 'devices', 'position_u', 'INTEGER');
    ba_add_col($db, 'devices', 'u_height', 'INTEGER NOT NULL DEFAULT 1');
    ba_add_col($db, 'devices', 'face', "TEXT NOT NULL DEFAULT 'both'");
    ba_add_col($db, 'devices', 'port_count', 'INTEGER');
    ba_add_col($db, 'devices', 'manufacturer', 'TEXT');
    ba_add_col($db, 'devices', 'template_id', 'INTEGER');
    $db->exec(
        "CREATE TABLE IF NOT EXISTS device_templates (
            id INTEGER PRIMARY KEY,
            manufacturer TEXT,
            model TEXT NOT NULL,
            kind TEXT NOT NULL DEFAULT 'other',
            u_height INTEGER NOT NULL DEFAULT 1,
            face TEXT NOT NULL DEFAULT 'both',
            port_count INTEGER,
            va_rating REAL,
            watts REAL,
            weight_kg REAL,
            notes TEXT,
            front_picture TEXT,
            rear_picture TEXT,
            is_active INTEGER NOT NULL DEFAULT 1,
            created_at TEXT NOT NULL DEFAULT (datetime('now')),
            updated_at TEXT NOT NULL DEFAULT (datetime('now'))
        )"
    );
    if ((int)$db->query('SELECT COUNT(*) FROM device_templates')->fetchColumn() === 0) {
        $seed = [
            ['CyberPower', 'PR2000RT2UC', 'ups', 2, 'both', null, 2000, null, 'Lab / campus PR-series UPS'],
            ['Panduit', '24-port Cat6', 'patch_panel', 1, 'front', 24, null, null, '1U 24-port patch panel'],
            ['Panduit', '48-port Cat6', 'patch_panel', 1, 'front', 48, null, null, '1U 48-port patch panel'],
            ['Cisco', '1U access switch', 'switch', 1, 'both', 24, null, null, 'Generic 1U closet switch'],
        ];
        $ins = $db->prepare('INSERT INTO device_templates (manufacturer, model, kind, u_height, face, port_count, va_rating, watts, notes) VALUES (?,?,?,?,?,?,?,?,?)');
        foreach ($seed as $row) {
            $ins->execute($row);
        }
    }
}
