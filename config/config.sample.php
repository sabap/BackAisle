<?php
/**
 * Copy to config.php via the web setup wizard (setup.php).
 * Do not commit config.php.
 */
declare(strict_types=1);

return [
    'installed' => false,
    'org_name' => 'My Organization',
    'db' => [
        // sqlite (local file) or sqlsrv (SQL Server via ODBC)
        'driver' => 'sqlite',
        'path' => 'C:\\inetpub\\BackAisle\\data\\backaisle.db',
        'host' => 'localhost',
        'port' => 1433,
        'database' => 'BackAisle',
        'username' => 'sa',
        'password' => '',
        'encrypt' => false,
        'trust_server_certificate' => true,
        'odbc_driver' => 'ODBC Driver 18 for SQL Server',
    ],
];
