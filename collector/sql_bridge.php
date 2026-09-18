<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "sql_bridge is CLI only\n");
    exit(1);
}

ini_set('display_errors', '0');
error_reporting(E_ALL);

require __DIR__ . '/../app/bootstrap.php';
require __DIR__ . '/../app/db.php';

function ba_bridge_out(array $payload): void
{
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    fflush(STDOUT);
}

try {
    $db = ba_db();
} catch (Throwable $e) {
    ba_bridge_out(['ok' => false, 'error' => 'connect: ' . $e->getMessage()]);
    exit(1);
}

ba_bridge_out(['ok' => true, 'hello' => 1]);

while (($line = fgets(STDIN)) !== false) {
    $line = trim($line);
    if ($line === '') {
        continue;
    }
    $req = json_decode($line, true);
    if (!is_array($req)) {
        ba_bridge_out(['ok' => false, 'error' => 'invalid json']);
        continue;
    }
    $op = (string)($req['op'] ?? '');
    if ($op === 'quit') {
        ba_bridge_out(['ok' => true]);
        break;
    }
    $sql = (string)($req['sql'] ?? '');
    $params = $req['params'] ?? [];
    if (!is_array($params)) {
        $params = [];
    }
    $params = array_values($params);
    try {
        if ($op === 'query' || $op === 'exec') {
            $isSelect = (bool)preg_match('/^\s*SELECT\b/i', $sql);
            $st = $db->prepare($sql);
            $st->execute($params);
            $rows = [];
            if ($isSelect) {
                $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
            }
            $rowcount = 0;
            try {
                $rowcount = (int)$st->rowCount();
            } catch (Throwable $e) {
                $rowcount = 0;
            }
            try {
                $st->closeCursor();
            } catch (Throwable $e) {
            }
            $id = null;
            if (!$isSelect && preg_match('/^\s*INSERT\b/i', $sql)) {
                try {
                    if (ba_db_driver() === 'sqlsrv') {
                        $idSt = $db->query('SELECT CONVERT(int, SCOPE_IDENTITY()) AS id');
                        $id = $idSt ? $idSt->fetchColumn() : null;
                        if ($idSt) {
                            $idSt->closeCursor();
                        }
                    } else {
                        $id = $db->query('SELECT last_insert_rowid()')->fetchColumn();
                    }
                } catch (Throwable $e) {
                    $id = null;
                }
            }
            foreach ($rows as &$row) {
                foreach ($row as $k => $v) {
                    if ($v instanceof DateTimeInterface) {
                        $row[$k] = $v->format('c');
                    }
                }
            }
            unset($row);
            ba_bridge_out(['ok' => true, 'rows' => $rows, 'id' => $id, 'rowcount' => $rowcount]);
        } else {
            ba_bridge_out(['ok' => false, 'error' => 'unknown op']);
        }
    } catch (Throwable $e) {
        ba_bridge_out(['ok' => false, 'error' => $e->getMessage()]);
    }
}
