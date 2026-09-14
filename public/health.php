<?php
declare(strict_types=1);
header('Content-Type: text/plain; charset=utf-8');
echo "ok\n";
echo 'php=' . PHP_VERSION . "\n";
echo 'sapi=' . PHP_SAPI . "\n";
echo 'ini=' . (string)php_ini_loaded_file() . "\n";
$drivers = class_exists('PDO') ? PDO::getAvailableDrivers() : [];
echo 'pdo=' . implode(',', $drivers) . "\n";
foreach (['curl', 'mbstring', 'openssl', 'ldap', 'zip', 'pdo_sqlite', 'sqlite3'] as $ext) {
    echo $ext . '=' . (extension_loaded($ext) ? 'yes' : 'NO') . "\n";
}
echo 'root_writable=' . (is_writable(dirname(__DIR__)) ? 'yes' : 'NO') . "\n";
echo 'logs=' . (is_dir(dirname(__DIR__) . '/logs') && is_writable(dirname(__DIR__) . '/logs') ? 'yes' : 'NO') . "\n";
