<?php
declare(strict_types=1);

/**
 * Full site backup / restore packages (same roles as ColdAisle site packages).
 *
 * ZIP contents:
 *   manifest.json
 *   meta/secrets.env          — SNMPv3 / app secrets (never git; needed to restore polling)
 *   data/backaisle.db         — consistent SQLite snapshot
 *   uploads/tpl/…             — device-template pictures
 *
 * Optional whole-file encryption (AES-256-GCM + PBKDF2) → .baisle
 */
class BackAisleBackup
{
    public const FORMAT_VERSION = 1;
    public const PACKAGE_PREFIX = 'backaisle-site';
    public const ENC_MAGIC = 'BAISLE1';
    public const ENC_PBKDF2_ITERS = 200000;

    public static function backupDir(): string
    {
        $dir = BA_ROOT . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'backups';
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('Cannot create storage/backups. Grant Modify to the IIS app pool.');
        }
        return $dir;
    }

    /**
     * @param array{include_audit?:bool,include_readings?:bool,encrypt?:bool,password?:string} $options
     */
    public static function export(array $options = []): string
    {
        $includeAudit = array_key_exists('include_audit', $options) ? (bool)$options['include_audit'] : true;
        $includeReadings = array_key_exists('include_readings', $options) ? (bool)$options['include_readings'] : true;
        $encrypt = !empty($options['encrypt']);
        $password = (string)($options['password'] ?? '');
        if ($encrypt) {
            if (strlen($password) < 8) {
                throw new RuntimeException('Encryption password must be at least 8 characters.');
            }
            if (!function_exists('openssl_encrypt')) {
                throw new RuntimeException('OpenSSL is required to encrypt backups.');
            }
        }

        $dir = self::backupDir();
        $stamp = date('Ymd_His');
        $baseName = self::PACKAGE_PREFIX . '_' . $stamp . '_v' . ba_version();
        $staging = $dir . DIRECTORY_SEPARATOR . $baseName . '_staging';
        $zipPath = $dir . DIRECTORY_SEPARATOR . $baseName . '.zip';
        self::rrmdir($staging);
        foreach ([$staging, $staging . '/meta', $staging . '/data', $staging . '/uploads'] as $d) {
            if (!@mkdir($d, 0775, true) && !is_dir($d)) {
                throw new RuntimeException('Cannot create backup staging directories.');
            }
        }

        try {
            $dbFile = $staging . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'backaisle.db';
            $db = ba_db();
            $db->exec('PRAGMA wal_checkpoint(TRUNCATE)');
            try {
                $db->exec('VACUUM INTO ' . $db->quote(str_replace('\\', '/', $dbFile)));
            } catch (Throwable $e) {
                $live = BA_ROOT . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'backaisle.db';
                if (!@copy($live, $dbFile)) {
                    throw new RuntimeException('Could not snapshot database: ' . $e->getMessage());
                }
            }
            if (!$includeAudit || !$includeReadings) {
                $snap = new PDO('sqlite:' . $dbFile, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
                if (!$includeAudit) {
                    $snap->exec('DELETE FROM audit_log');
                }
                if (!$includeReadings) {
                    $snap->exec('DELETE FROM samples');
                    $snap->exec('DELETE FROM samples_hourly');
                    $snap->exec('DELETE FROM events');
                }
                $snap->exec('VACUUM');
                $snap = null;
            }

            $secretsSrc = '';
            foreach ([
                getenv('BACKAISLE_SECRETS') ?: '',
                'C:\\ProgramData\\BackAisle\\secrets.env',
                BA_ROOT . DIRECTORY_SEPARATOR . 'secrets.env',
            ] as $p) {
                if ($p !== '' && is_file($p)) {
                    $secretsSrc = $p;
                    break;
                }
            }
            if ($secretsSrc !== '') {
                @copy($secretsSrc, $staging . '/meta/secrets.env');
            }

            $tplSrc = BA_ROOT . '/public/assets/tpl';
            if (is_dir($tplSrc)) {
                self::copyTree($tplSrc, $staging . '/uploads/tpl');
            }

            $manifest = [
                'format' => 'backaisle-site-backup',
                'format_version' => self::FORMAT_VERSION,
                'app_version' => ba_version(),
                'created_at' => date('c'),
                'php_version' => PHP_VERSION,
                'options' => [
                    'include_audit' => $includeAudit,
                    'include_readings' => $includeReadings,
                    'encrypted' => $encrypt,
                ],
                'has_secrets' => $secretsSrc !== '',
                'db_bytes' => is_file($dbFile) ? filesize($dbFile) : 0,
            ];
            file_put_contents(
                $staging . '/manifest.json',
                json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            );

            self::zipDirectory($staging, $zipPath);
            $final = $zipPath;
            if ($encrypt) {
                $enc = $dir . DIRECTORY_SEPARATOR . $baseName . '.baisle';
                self::encryptPackageFile($zipPath, $enc, $password);
                @unlink($zipPath);
                $final = $enc;
            }
            return $final;
        } finally {
            self::rrmdir($staging);
        }
    }

    /**
     * @param array{password?:string,create_pre_backup?:bool} $options
     * @return array{ok:bool,message:string,pre_backup:?string}
     */
    public static function restoreLive(string $packagePath, array $options = []): array
    {
        if (!is_file($packagePath)) {
            throw new RuntimeException('Backup file not found.');
        }
        $createPre = array_key_exists('create_pre_backup', $options) ? (bool)$options['create_pre_backup'] : true;
        $password = (string)($options['password'] ?? '');
        $pre = null;
        if ($createPre) {
            $pre = self::export(['include_audit' => true, 'include_readings' => true]);
        }

        $work = self::backupDir() . DIRECTORY_SEPARATOR . 'restore-' . bin2hex(random_bytes(4));
        if (!@mkdir($work, 0700, true) && !is_dir($work)) {
            throw new RuntimeException('Cannot create restore work directory.');
        }
        try {
            $zipPath = $packagePath;
            if (self::isEncrypted($packagePath)) {
                if ($password === '') {
                    throw new RuntimeException('This backup is encrypted — enter the password.');
                }
                $zipPath = $work . DIRECTORY_SEPARATOR . 'plain.zip';
                self::decryptPackageFile($packagePath, $zipPath, $password);
            }
            $extract = $work . DIRECTORY_SEPARATOR . 'extract';
            @mkdir($extract, 0700, true);
            self::extractZip($zipPath, $extract);
            $root = self::findPackageRoot($extract);
            $manifestFile = $root . '/manifest.json';
            if (!is_file($manifestFile)) {
                throw new RuntimeException('Not a BackAisle site package (missing manifest.json).');
            }
            $manifest = json_decode((string)file_get_contents($manifestFile), true);
            if (!is_array($manifest) || ($manifest['format'] ?? '') !== 'backaisle-site-backup') {
                throw new RuntimeException('Unrecognized backup format.');
            }
            $dbSnap = $root . '/data/backaisle.db';
            if (!is_file($dbSnap)) {
                throw new RuntimeException('Package has no database snapshot.');
            }
            $live = BA_ROOT . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'backaisle.db';
            ba_db()->exec('PRAGMA wal_checkpoint(TRUNCATE)');
            ba_db(true);
            foreach ([$live, $live . '-wal', $live . '-shm'] as $p) {
                if (is_file($p)) {
                    @unlink($p);
                }
            }
            if (!@copy($dbSnap, $live)) {
                throw new RuntimeException('Could not replace live database. ' . BackAisleUpdate::aclHelpMessage());
            }
            $sec = $root . '/meta/secrets.env';
            if (is_file($sec)) {
                $dest = 'C:\\ProgramData\\BackAisle\\secrets.env';
                if (!is_dir(dirname($dest))) {
                    @mkdir(dirname($dest), 0770, true);
                }
                @copy($sec, $dest);
                @copy($sec, BA_ROOT . DIRECTORY_SEPARATOR . 'secrets.env');
            }
            $tpl = $root . '/uploads/tpl';
            if (is_dir($tpl)) {
                self::copyTree($tpl, BA_ROOT . '/public/assets/tpl');
            }
            ba_db(true);
            ba_ensure_infra_schema(ba_db());
            return [
                'ok' => true,
                'message' => 'Restored site package ' . basename($packagePath)
                    . ($pre ? '. Pre-restore backup: ' . basename($pre) : ''),
                'pre_backup' => $pre,
            ];
        } finally {
            self::rrmdir($work);
        }
    }

    /** @return list<array{name:string,path:string,bytes:int,mtime:int,kind:string}> */
    public static function listPackages(): array
    {
        $dir = self::backupDir();
        $out = [];
        foreach (scandir($dir) ?: [] as $n) {
            if ($n === '.' || $n === '..') continue;
            $path = $dir . DIRECTORY_SEPARATOR . $n;
            if (!is_file($path)) continue;
            $kind = str_starts_with($n, self::PACKAGE_PREFIX) ? 'site'
                : (str_starts_with($n, 'backup_') ? 'files' : 'other');
            $out[] = [
                'name' => $n,
                'path' => $path,
                'bytes' => (int)filesize($path),
                'mtime' => (int)filemtime($path),
                'kind' => $kind,
            ];
        }
        usort($out, static fn($a, $b) => $b['mtime'] <=> $a['mtime']);
        return $out;
    }

    public static function safeFile(string $name): ?string
    {
        $base = basename($name);
        if ($base !== $name || !preg_match('/^(backaisle-site_|backup_)[A-Za-z0-9._-]+\.(zip|baisle)$/', $base)) {
            return null;
        }
        $path = self::backupDir() . DIRECTORY_SEPARATOR . $base;
        return is_file($path) ? $path : null;
    }

    public static function isEncrypted(string $path): bool
    {
        $h = @fopen($path, 'rb');
        if ($h === false) return false;
        $magic = fread($h, 7);
        fclose($h);
        return $magic === self::ENC_MAGIC;
    }

    public static function encryptPackageFile(string $plainPath, string $outPath, string $password): void
    {
        $data = @file_get_contents($plainPath);
        if ($data === false || $data === '') {
            throw new RuntimeException('Cannot read backup zip for encryption.');
        }
        $salt = random_bytes(16);
        $key = hash_pbkdf2('sha256', $password, $salt, self::ENC_PBKDF2_ITERS, 32, true);
        $nonce = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt($data, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag, '', 16);
        if ($cipher === false || strlen($tag) !== 16) {
            throw new RuntimeException('Backup encryption failed.');
        }
        if (@file_put_contents($outPath, self::ENC_MAGIC . $salt . $nonce . $tag . $cipher) === false) {
            throw new RuntimeException('Could not write encrypted backup file.');
        }
    }

    public static function decryptPackageFile(string $encPath, string $outZipPath, string $password): void
    {
        $raw = @file_get_contents($encPath);
        if ($raw === false || strlen($raw) < 7 + 16 + 12 + 16 + 1) {
            throw new RuntimeException('Encrypted backup file is missing or truncated.');
        }
        if (substr($raw, 0, 7) !== self::ENC_MAGIC) {
            throw new RuntimeException('Not a BackAisle encrypted backup (.baisle).');
        }
        $salt = substr($raw, 7, 16);
        $nonce = substr($raw, 23, 12);
        $tag = substr($raw, 35, 16);
        $cipher = substr($raw, 51);
        $key = hash_pbkdf2('sha256', $password, $salt, self::ENC_PBKDF2_ITERS, 32, true);
        $plain = openssl_decrypt($cipher, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag);
        if ($plain === false) {
            throw new RuntimeException('Decryption failed — wrong password or corrupt file.');
        }
        if (@file_put_contents($outZipPath, $plain) === false) {
            throw new RuntimeException('Could not write decrypted backup zip.');
        }
    }

    public static function zipDirectory(string $source, string $zipPath): void
    {
        $source = realpath($source);
        if ($source === false) {
            throw new RuntimeException('Invalid zip source.');
        }
        if (class_exists('ZipArchive')) {
            $zip = new ZipArchive();
            if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException('Could not create zip.');
            }
            $len = strlen($source);
            $it = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::SELF_FIRST
            );
            foreach ($it as $file) {
                $full = $file->getPathname();
                $rel = str_replace('\\', '/', substr($full, $len + 1));
                if ($file->isDir()) {
                    $zip->addEmptyDir($rel);
                } else {
                    $zip->addFile($full, $rel);
                }
            }
            $zip->close();
            return;
        }
        if (PHP_OS_FAMILY === 'Windows') {
            $cmd = 'powershell -NoProfile -Command "Compress-Archive -LiteralPath '
                . escapeshellarg($source . '\\*') . ' -DestinationPath ' . escapeshellarg($zipPath) . ' -Force"';
            exec($cmd, $out, $code);
            if ($code !== 0 || !is_file($zipPath)) {
                throw new RuntimeException('Compress-Archive failed (enable PHP zip extension).');
            }
            return;
        }
        throw new RuntimeException('PHP ZipArchive is required.');
    }

    public static function extractZip(string $zipFile, string $destDir): void
    {
        if (class_exists('ZipArchive')) {
            $zip = new ZipArchive();
            if ($zip->open($zipFile) !== true) {
                throw new RuntimeException('Could not open zip.');
            }
            $zip->extractTo($destDir);
            $zip->close();
            return;
        }
        if (PHP_OS_FAMILY === 'Windows') {
            $cmd = 'powershell -NoProfile -Command "Expand-Archive -LiteralPath '
                . escapeshellarg($zipFile) . ' -DestinationPath ' . escapeshellarg($destDir) . ' -Force"';
            exec($cmd, $out, $code);
            if ($code !== 0) {
                throw new RuntimeException('Expand-Archive failed.');
            }
            return;
        }
        throw new RuntimeException('PHP ZipArchive is required.');
    }

    public static function findPackageRoot(string $extractDir): string
    {
        if (is_file($extractDir . '/manifest.json')) {
            return $extractDir;
        }
        foreach (scandir($extractDir) ?: [] as $e) {
            if ($e === '.' || $e === '..') continue;
            $p = $extractDir . DIRECTORY_SEPARATOR . $e;
            if (is_dir($p) && is_file($p . '/manifest.json')) {
                return $p;
            }
        }
        throw new RuntimeException('Could not find package root.');
    }

    public static function copyTree(string $src, string $dst): void
    {
        if (!is_dir($dst) && !@mkdir($dst, 0775, true) && !is_dir($dst)) {
            throw new RuntimeException('Cannot create ' . $dst);
        }
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($src, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        $len = strlen(realpath($src) ?: $src);
        foreach ($it as $file) {
            $rel = substr($file->getPathname(), $len + 1);
            $target = $dst . DIRECTORY_SEPARATOR . $rel;
            if ($file->isDir()) {
                if (!is_dir($target)) @mkdir($target, 0775, true);
            } else {
                $parent = dirname($target);
                if (!is_dir($parent)) @mkdir($parent, 0775, true);
                @copy($file->getPathname(), $target);
            }
        }
    }

    public static function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) return;
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $file) {
            $file->isDir() ? @rmdir($file->getPathname()) : @unlink($file->getPathname());
        }
        @rmdir($dir);
    }

    public static function formatBytes(int $n): string
    {
        if ($n < 1024) return $n . ' B';
        if ($n < 1048576) return round($n / 1024, 1) . ' KB';
        return round($n / 1048576, 1) . ' MB';
    }
}
