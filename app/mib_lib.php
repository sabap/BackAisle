<?php
declare(strict_types=1);

/** Compile an uploaded vendor MIB into named OIDs. A MIB is a dictionary, not a poll list. */

function ba_mib_strip(string $text): string
{
    $out = '';
    $len = strlen($text);
    $quote = false;
    for ($i = 0; $i < $len; $i++) {
        $c = $text[$i];
        if ($quote) {
            $out .= $c;
            if ($c === '"') {
                $quote = false;
            }
            continue;
        }
        if ($c === '"') {
            $quote = true;
            $out .= $c;
            continue;
        }
        if ($c === '-' && ($i + 1) < $len && $text[$i + 1] === '-') {
            while ($i < $len && $text[$i] !== "\n") {
                $i++;
            }
            $out .= "\n";
            continue;
        }
        $out .= $c;
    }
    return $out;
}

/** @return array<string, string> */
function ba_mib_roots(): array
{
    return [
        'iso' => '1',
        'org' => '1.3',
        'dod' => '1.3.6',
        'internet' => '1.3.6.1',
        'directory' => '1.3.6.1.1',
        'mgmt' => '1.3.6.1.2',
        'mib-2' => '1.3.6.1.2.1',
        'experimental' => '1.3.6.1.3',
        'private' => '1.3.6.1.4',
        'enterprises' => '1.3.6.1.4.1',
        'security' => '1.3.6.1.5',
        'snmpV2' => '1.3.6.1.6',
        'snmpDomains' => '1.3.6.1.6.1',
        'snmpProxys' => '1.3.6.1.6.2',
        'snmpModules' => '1.3.6.1.6.3',
    ];
}

/**
 * @return array{name: string, objects: list<array{name: string, oid: string, syntax: string, units: string, access: string, enum_json: string}>}
 */
function ba_mib_compile(string $text): array
{
    $text = ba_mib_strip($text);
    $module = 'MIB';
    if (preg_match('/([A-Za-z][A-Za-z0-9-]*)\s+DEFINITIONS\s*::=\s*BEGIN/', $text, $m)) {
        $module = $m[1];
    }
    $parents = [];
    $nodes = [];
    $pending = [];
    $add = static function (string $name, string $parent, string $sub, array $meta) use (&$pending): void {
        $pending[] = ['name' => $name, 'parent' => $parent, 'sub' => $sub] + $meta;
    };
    if (preg_match_all('/([A-Za-z][A-Za-z0-9-]*)\s+OBJECT\s+IDENTIFIER\s*::=\s*\{\s*([A-Za-z][A-Za-z0-9-]*)\s+(\d+)\s*\}/', $text, $found, PREG_SET_ORDER)) {
        foreach ($found as $row) {
            $add($row[1], $row[2], $row[3], ['syntax' => '', 'units' => '', 'access' => 'node', 'enum_json' => '']);
        }
    }
    if (preg_match_all('/([A-Za-z][A-Za-z0-9-]*)\s+(OBJECT-TYPE|MODULE-IDENTITY)\b(.*?)\s*::=\s*\{\s*([A-Za-z][A-Za-z0-9-]*)\s+(\d+)\s*\}/s', $text, $found, PREG_SET_ORDER)) {
        foreach ($found as $row) {
            $body = $row[3];
            $syntax = '';
            $units = '';
            $access = $row[2] === 'MODULE-IDENTITY' ? 'node' : '';
            $enum = [];
            if (preg_match('/SYNTAX\s+(.+?)(?:\s+UNITS\b|\s+MAX-ACCESS\b|\s+ACCESS\b|\s+STATUS\b|\s+DESCRIPTION\b)/s', $body, $sm)) {
                $syntax = trim((string)preg_replace('/\s+/', ' ', $sm[1]));
                if (preg_match('/\{([^}]+)\}/', $syntax, $em)) {
                    if (preg_match_all('/([A-Za-z][A-Za-z0-9-]*)\s*\(\s*(-?\d+)\s*\)/', $em[1], $pairs, PREG_SET_ORDER)) {
                        foreach ($pairs as $pair) {
                            $enum[$pair[2]] = $pair[1];
                        }
                    }
                }
            }
            if (preg_match('/UNITS\s+"([^"]*)"/', $body, $um)) {
                $units = $um[1];
            }
            if (preg_match('/(?:MAX-ACCESS|ACCESS)\s+([A-Za-z-]+)/', $body, $am)) {
                $access = strtolower($am[1]);
            }
            if (strlen($syntax) > 240) {
                $syntax = substr($syntax, 0, 240);
            }
            $add($row[1], $row[4], $row[5], [
                'syntax' => $syntax,
                'units' => $units,
                'access' => $access,
                'enum_json' => $enum === [] ? '' : (string)json_encode($enum),
            ]);
        }
    }
    $known = ba_mib_roots();
    $left = $pending;
    for ($pass = 0; $pass < 12 && $left !== []; $pass++) {
        $next = [];
        foreach ($left as $item) {
            $parentOid = $known[$item['parent']] ?? '';
            if ($parentOid === '') {
                $next[] = $item;
                continue;
            }
            $oid = $parentOid . '.' . $item['sub'];
            $known[$item['name']] = $oid;
            $parents[$item['name']] = $item;
            $nodes[] = [
                'name' => $item['name'],
                'oid' => $oid,
                'syntax' => $item['syntax'],
                'units' => $item['units'],
                'access' => $item['access'],
                'enum_json' => $item['enum_json'],
            ];
        }
        if (count($next) === count($left)) {
            break;
        }
        $left = $next;
    }
    return ['name' => $module, 'objects' => $nodes];
}

function ba_mib_store(PDO $db, string $filename, string $text): int
{
    ba_ensure_model_schema($db);
    if (strlen($text) > 4 * 1024 * 1024) {
        throw new RuntimeException('MIB file is larger than 4 MB.');
    }
    $compiled = ba_mib_compile($text);
    if ($compiled['objects'] === []) {
        throw new RuntimeException('No OBJECT-TYPE or OBJECT IDENTIFIER assignments were found in ' . $filename . '.');
    }
    $name = $compiled['name'];
    $st = $db->prepare('SELECT id FROM mib_modules WHERE name=?');
    $st->execute([$name]);
    $existing = $st->fetch();
    $safe = preg_replace('/[^A-Za-z0-9._-]/', '_', $filename) ?: 'module.mib';
    $dir = BA_ROOT . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'mibs';
    if (!is_dir($dir) && !@mkdir($dir, 0770, true) && !is_dir($dir)) {
        throw new RuntimeException('Could not create the MIB folder.');
    }
    if ($existing) {
        $id = (int)$existing['id'];
        $db->prepare('DELETE FROM mib_objects WHERE module_id=?')->execute([$id]);
        $db->prepare('UPDATE mib_modules SET filename=?, object_count=?, status=?, error=?, uploaded_at=datetime(\'now\') WHERE id=?')
            ->execute([$safe, count($compiled['objects']), 'ok', '', $id]);
    } else {
        $db->prepare('INSERT INTO mib_modules (name, filename, object_count, status, error) VALUES (?,?,?,?,?)')
            ->execute([$name, $safe, count($compiled['objects']), 'ok', '']);
        $id = ba_last_id($db);
    }
    $path = $dir . DIRECTORY_SEPARATOR . $id . '-' . $safe;
    if (file_put_contents($path, $text) === false) {
        throw new RuntimeException('Could not store the MIB file.');
    }
    $ins = $db->prepare('INSERT INTO mib_objects (module_id, name, oid, syntax, units, access, enum_json) VALUES (?,?,?,?,?,?,?)');
    $db->beginTransaction();
    try {
        $n = 0;
        foreach ($compiled['objects'] as $obj) {
            if ($n >= 8000) {
                break;
            }
            $ins->execute([$id, $obj['name'], $obj['oid'], $obj['syntax'], $obj['units'], $obj['access'], $obj['enum_json']]);
            $n++;
        }
        $db->prepare('UPDATE mib_modules SET object_count=? WHERE id=?')->execute([$n, $id]);
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }
    return $id;
}

/** @return list<array<string, mixed>> */
function ba_mib_search(PDO $db, string $q): array
{
    ba_ensure_model_schema($db);
    $q = trim($q);
    if ($q === '') {
        return [];
    }
    $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';
    $st = $db->prepare(
        'SELECT o.name, o.oid, o.syntax, o.units, o.access, m.name AS module_name
         FROM mib_objects o JOIN mib_modules m ON m.id=o.module_id
         WHERE (o.name LIKE ? ESCAPE \'\\\' OR o.oid LIKE ? ESCAPE \'\\\') AND o.access NOT IN (\'node\', \'not-accessible\', \'accessible-for-notify\')
         ORDER BY o.name LIMIT 30'
    );
    $st->execute([$like, $like]);
    return $st->fetchAll() ?: [];
}
