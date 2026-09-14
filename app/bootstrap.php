<?php
declare(strict_types=1);

const BA_ROOT = 'C:\\inetpub\\BackAisle';
const BA_DB = BA_ROOT . '\\data\\backaisle.db';

function ba_secrets(): array {
    $paths = [
        getenv('BACKAISLE_SECRETS') ?: '',
        'C:\\ProgramData\\BackAisle\\secrets.env',
        BA_ROOT . '\\secrets.env',
    ];
    $out = [];
    foreach ($paths as $p) {
        if ($p === '' || !is_file($p)) {
            continue;
        }
        foreach (file($p, FILE_IGNORE_NEW_LINES) as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
                continue;
            }
            [$k, $v] = explode('=', $line, 2);
            $out[trim($k)] = trim($v);
        }
        break;
    }
    return $out;
}

function ba_audit(PDO $db, string $action, ?string $entity = null, ?string $id = null, ?string $details = null): void {
    $st = $db->prepare('INSERT INTO audit_log (username, action, entity, entity_id, details) VALUES (?,?,?,?,?)');
    $st->execute([$_SESSION['user']['username'] ?? null, $action, $entity, $id, $details]);
}

session_name('BACKAISLE');
session_start();
