<?php
declare(strict_types=1);

function ba_db(bool $reconnect = false): PDO {
    static $pdo = null;
    if ($reconnect) {
        $pdo = null;
    }
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    if (!is_file(BA_DB)) {
        throw new RuntimeException('Database not initialized. Run collector seed first.');
    }
    $pdo = new PDO('sqlite:' . BA_DB, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('PRAGMA journal_mode=WAL');
    $pdo->exec('PRAGMA foreign_keys=ON');
    $pdo->exec('PRAGMA busy_timeout=30000');
    ba_ensure_infra_schema($pdo);
    return $pdo;
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
