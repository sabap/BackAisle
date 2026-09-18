<?php
declare(strict_types=1);

/**
 * GitHub release check and one-click application update (same flow as ColdAisle).
 * Check API → full site backup + app-files zip → download zipball → overlay files
 * (preserving data/, logs/, secrets, php.ini, storage/) → schema ensure.
 */
class BackAisleUpdate
{
    public const GITHUB_OWNER = 'sabap';
    public const GITHUB_REPO = 'BackAisle';
    public const PENDING_SUFFIX = '.backaisle-new';
    public const PENDING_FLAG = 'has_pending_updates.flag';

    public static function githubUrl(): string
    {
        return 'https://github.com/' . self::GITHUB_OWNER . '/' . self::GITHUB_REPO;
    }

    public static function changelogUrl(): string
    {
        return self::githubUrl() . '/blob/main/CHANGELOG.md';
    }

    public static function installedVersion(): string
    {
        return ba_version();
    }

    public static function config(): array
    {
        $db = ba_db();
        $row = static function (string $k, string $d) use ($db): string {
            $st = $db->prepare('SELECT v FROM settings WHERE k=?');
            $st->execute([$k]);
            $v = $st->fetchColumn();
            return $v === false || $v === null || $v === '' ? $d : (string)$v;
        };
        return [
            'enabled' => $row('updates_enabled', '1') !== '0',
            'auto_check' => $row('updates_auto_check', '1') !== '0',
            'check_interval_hours' => max(1, (int)$row('updates_check_hours', '24')),
            'ssl_verify' => $row('updates_ssl_verify', '1') !== '0',
            'github_owner' => self::GITHUB_OWNER,
            'github_repo' => self::GITHUB_REPO,
            'github_token' => '',
        ];
    }

    public static function saveConfig(array $c): void
    {
        $db = ba_db();
        ba_set_setting($db, 'updates_enabled', !empty($c['enabled']) ? '1' : '0');
        ba_set_setting($db, 'updates_auto_check', !empty($c['auto_check']) ? '1' : '0');
        ba_set_setting($db, 'updates_check_hours', (string)max(1, (int)($c['check_interval_hours'] ?? 24)));
        ba_set_setting($db, 'updates_ssl_verify', !empty($c['ssl_verify']) ? '1' : '0');
    }

    /** @return array<string,mixed> */
    public static function checkForUpdate(bool $force = false): array
    {
        $cfg = self::config();
        $current = self::installedVersion();
        $now = date('c');
        if (empty($cfg['enabled'])) {
            return self::statusBase($current, $now) + ['ok' => true, 'error' => 'Updates are disabled.'];
        }
        if (!$force) {
            $cached = self::cachedStatus();
            if ($cached !== null) {
                $cached['cached'] = true;
                return $cached;
            }
        }
        try {
            $remote = self::resolveRemoteLatest();
            $tag = (string)$remote['tag'];
            $result = [
                'ok' => true,
                'current' => $current,
                'latest' => $tag,
                'update_available' => version_compare($tag, $current, '>'),
                'release_name' => $remote['name'],
                'html_url' => $remote['html'],
                'notes_url' => self::changelogUrl(),
                'notes' => $remote['notes'],
                'published_at' => $remote['published'],
                'checked_at' => $now,
                'cached' => false,
                'source' => $remote['source'],
            ];
            try { self::storeCache($result); } catch (Throwable $e) { /* still return the check */ }
            return $result;
        } catch (Throwable $e) {
            $result = self::statusBase($current, $now) + [
                'ok' => false,
                'error' => $e->getMessage(),
                'notes_url' => self::changelogUrl(),
            ];
            try { self::storeCache($result); } catch (Throwable $e2) { }
            return $result;
        }
    }

    public static function cachedStatus(): ?array
    {
        $db = ba_db();
        $st = $db->prepare("SELECT v FROM settings WHERE k='update_check_json'");
        $st->execute();
        $json = $st->fetchColumn();
        $st = $db->prepare("SELECT v FROM settings WHERE k='update_check_at'");
        $st->execute();
        $at = $st->fetchColumn();
        if (!$json) return null;
        $data = json_decode((string)$json, true);
        if (!is_array($data)) return null;
        $hours = max(1, (int)self::config()['check_interval_hours']);
        if ($at && (time() - strtotime((string)$at)) > $hours * 3600) {
            return null;
        }
        $data['checked_at'] = (string)$at;
        return $data;
    }

    public static function storeCache(array $data): void
    {
        $db = ba_db();
        ba_set_setting($db, 'update_check_json', json_encode($data, JSON_UNESCAPED_SLASHES));
        ba_set_setting($db, 'update_check_at', date('c'));
    }

    /**
     * Pre-update recovery: full site package (DB + secrets + pictures) then application-files zip.
     * @return array{code_zip:string,site_package:string,code_bytes:int,site_bytes:int}
     */
    public static function createRecoveryBackup(): array
    {
        $sitePath = BackAisleBackup::export([
            'include_audit' => true,
            'include_readings' => false,
        ]);
        $dir = BackAisleBackup::backupDir();
        $name = 'backup_' . date('Ymd_His') . '_v' . self::installedVersion() . '.zip';
        $path = $dir . DIRECTORY_SEPARATOR . $name;
        $root = realpath(BA_ROOT);
        if ($root === false) {
            throw new RuntimeException('Invalid application root.');
        }
        $filesAdded = 0;
        try {
            $filesAdded = self::zipAppTree($root, $path);
        } catch (Throwable $e) {
            $filesAdded = 0;
        }
        $zipOk = is_file($path) && filesize($path) >= 200;
        return [
            'code_zip' => $zipOk ? $path : '',
            'site_package' => $sitePath,
            'code_bytes' => $zipOk ? (int)filesize($path) : 0,
            'site_bytes' => (int)filesize($sitePath),
            'files_added' => $filesAdded,
        ];
    }

    public static function createBackup(): string
    {
        return self::createRecoveryBackup()['code_zip'];
    }

    /** @return array{ok:bool,message:string,backup:?string,version:?string,site_package:?string} */
    public static function applyUpdate(?string $targetVersion = null): array
    {
        @ini_set('max_execution_time', '600');
        @set_time_limit(600);
        if (PHP_SAPI !== 'cli') {
            @ignore_user_abort(true);
        }
        $cfg = self::config();
        if (empty($cfg['enabled'])) {
            throw new RuntimeException('Updates are disabled.');
        }
        $status = self::checkForUpdate(true);
        if (!$status['ok']) {
            throw new RuntimeException($status['error'] ?? 'Update check failed.');
        }
        $latest = $status['latest'] ?? null;
        if ($latest === null) {
            throw new RuntimeException('No remote version found.');
        }
        $version = $targetVersion !== null && $targetVersion !== '' ? ltrim($targetVersion, 'vV') : $latest;
        $current = self::installedVersion();
        if (version_compare($version, $current, '<=')) {
            throw new RuntimeException("Already on {$current}; remote {$version} is not newer.");
        }
        try {
            $backup = self::createRecoveryBackup();
        } catch (Throwable $e) {
            $backup = [
                'code_zip' => '',
                'site_package' => '',
                'code_bytes' => 0,
                'site_bytes' => 0,
                'files_added' => 0,
            ];
        }
        $tmpDir = self::makeWorkDir('upd');
        try {
            $extractDir = $tmpDir . DIRECTORY_SEPARATOR . 'extract';
            $sourceRoot = null;
            $fetchErrors = [];
            try {
                $zipFile = $tmpDir . DIRECTORY_SEPARATOR . 'release.zip';
                $url = 'https://api.github.com/repos/' . rawurlencode(self::GITHUB_OWNER) . '/'
                    . rawurlencode(self::GITHUB_REPO) . '/zipball/v' . rawurlencode($version);
                self::githubDownload($url, $zipFile);
                BackAisleBackup::extractZip($zipFile, $extractDir);
                $sourceRoot = self::findExtractedRoot($extractDir);
            } catch (Throwable $e) {
                $fetchErrors[] = $e->getMessage();
                try {
                    $jsDir = $tmpDir . DIRECTORY_SEPARATOR . 'jsd';
                    $sourceRoot = self::fetchJsDelivrTree($version, $jsDir);
                } catch (Throwable $e2) {
                    $fetchErrors[] = $e2->getMessage();
                }
            }
            if ($sourceRoot === null) {
                throw new RuntimeException(
                    'Could not download the release. ' . implode('; ', $fetchErrors)
                );
            }
            $srcVer = self::parseSemver((string)@file_get_contents($sourceRoot . DIRECTORY_SEPARATOR . 'VERSION'));
            if ($srcVer === null || version_compare($srcVer, $version, '<')) {
                throw new RuntimeException(
                    'Downloaded tree VERSION is ' . ($srcVer ?? 'missing') . ', expected ' . $version . '.'
                );
            }
            $stats = self::applyTree($sourceRoot, BA_ROOT);
            $verSrc = $sourceRoot . DIRECTORY_SEPARATOR . 'VERSION';
            if (is_file($verSrc)) {
                self::copyFileRobust($verSrc, BA_ROOT . DIRECTORY_SEPARATOR . 'VERSION');
            } else {
                @file_put_contents(BA_ROOT . DIRECTORY_SEPARATOR . 'VERSION', $version . "\n");
            }
            $pendingLeft = self::applyPendingReplacements();
            if ($pendingLeft > 0) {
                register_shutdown_function(static function (): void {
                    try { self::applyPendingReplacements(); } catch (Throwable $e) { }
                });
            }
            $onDisk = self::parseSemver((string)@file_get_contents(BA_ROOT . DIRECTORY_SEPARATOR . 'VERSION'));
            if ($onDisk === null || version_compare($onDisk, $version, '<')) {
                throw new RuntimeException(
                    'Update copied files but VERSION on disk is ' . ($onDisk ?? 'missing')
                    . ' (wanted ' . $version . '). Grant Modify on the site folder to the IIS app pool and click Update again.'
                );
            }
            try {
                ba_ensure_infra_schema(ba_db(true));
                $py = ba_python();
                if ($py !== '') {
                    $script = BA_ROOT . '\\collector\\db.py';
                    if (is_file($script)) {
                        exec(escapeshellarg($py) . ' -c ' . escapeshellarg(
                            'import sys; sys.path.insert(0, r"' . BA_ROOT . '\\collector"); from db import init_db; init_db()'
                        ), $out, $code);
                    }
                }
            } catch (Throwable $e) {
                // schema ensure is best-effort after files land
            }
            if (function_exists('opcache_reset')) {
                @opcache_reset();
            }
            $fresh = [
                'ok' => true,
                'current' => $version,
                'latest' => $version,
                'update_available' => false,
                'release_name' => 'v' . $version,
                'html_url' => $status['html_url'] ?? null,
                'notes' => null,
                'published_at' => null,
                'checked_at' => date('c'),
                'cached' => false,
            ];
            self::storeCache($fresh);
            $msg = "Updated from {$current} to {$version}. Site package: "
                . (basename((string)($backup['site_package'] ?? '')) ?: 'none')
                . '. App files: ' . (basename((string)($backup['code_zip'] ?? '')) ?: 'none')
                . " ({$stats['copied']} files";
            if (($stats['deferred'] ?? 0) > 0 || $pendingLeft > 0) {
                $msg .= ', some files finish on the next page load';
            }
            $msg .= ').';
            return [
                'ok' => true,
                'message' => $msg,
                'backup' => $backup['code_zip'],
                'site_package' => $backup['site_package'],
                'version' => $version,
            ];
        } finally {
            BackAisleBackup::rrmdir($tmpDir);
        }
    }

    public static function applyPendingReplacements(): int
    {
        $root = realpath(BA_ROOT);
        if ($root === false) {
            return 0;
        }
        $left = 0;
        $flag = BA_ROOT . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'tmp' . DIRECTORY_SEPARATOR . self::PENDING_FLAG;
        try {
            $it = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::LEAVES_ONLY
            );
            foreach ($it as $file) {
                $full = $file->getPathname();
                if (!str_ends_with($full, self::PENDING_SUFFIX)) {
                    continue;
                }
                $rel = str_replace('\\', '/', substr($full, strlen($root) + 1));
                if (str_starts_with($rel, 'storage/') || str_starts_with($rel, '.git/')) {
                    continue;
                }
                $dest = substr($full, 0, -strlen(self::PENDING_SUFFIX));
                if (!self::promotePendingFile($full, $dest)) {
                    $left++;
                }
            }
        } catch (Throwable $e) {
            return $left;
        }
        if ($left === 0 && is_file($flag)) {
            @unlink($flag);
        }
        return $left;
    }

    private static function promotePendingFile(string $pending, string $dest): bool
    {
        if (!is_file($pending)) {
            return true;
        }
        $parent = dirname($dest);
        if (!is_dir($parent)) {
            @mkdir($parent, 0755, true);
        }
        if (is_file($dest)) {
            @chmod($dest, 0666);
            if (PHP_OS_FAMILY === 'Windows') {
                @exec('attrib -R ' . escapeshellarg($dest) . ' 2>NUL');
            }
            if (@copy($pending, $dest)) {
                @unlink($pending);
                return true;
            }
            $bak = $dest . '.backaisle-bak';
            @unlink($bak);
            if (@rename($dest, $bak)) {
                if (@rename($pending, $dest) || @copy($pending, $dest)) {
                    @unlink($pending);
                    @unlink($bak);
                    return true;
                }
                @rename($bak, $dest);
                return false;
            }
            return false;
        }
        if (@rename($pending, $dest) || @copy($pending, $dest)) {
            @unlink($pending);
            return true;
        }
        return false;
    }

    public static function aclHelpMessage(): string
    {
        $pool = (string)($_SERVER['APP_POOL_ID'] ?? 'BackAisle');
        $root = str_replace('/', '\\', BA_ROOT);
        return 'Grant Modify on the site folder to the app pool identity. Elevated PowerShell: '
            . 'icacls "' . $root . '" /grant "IIS AppPool\\' . $pool . ':(OI)(CI)M" /T';
    }

    public static function caBundlePath(): string
    {
        return BA_ROOT . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'cacert.pem';
    }

    /** Locate a CA bundle, or download Mozilla's list into config/cacert.pem once. */
    public static function ensureCaBundle(bool $forceDownload = false): ?string
    {
        static $triedAuto = false;
        $local = self::caBundlePath();
        if (!$forceDownload) {
            $existing = self::resolveCaBundle();
            if ($existing !== null) {
                return $existing;
            }
        }
        if ($triedAuto && !$forceDownload) {
            return is_file($local) ? $local : null;
        }
        $triedAuto = true;
        $dir = dirname($local);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return self::resolveCaBundle();
        }
        try {
            self::downloadCaBundleTo($local);
            if (is_file($local) && filesize($local) > 50000) {
                return $local;
            }
        } catch (Throwable $e) {
            // fall through to whatever bundle already exists
        }
        return self::resolveCaBundle();
    }

    /**
     * Download Mozilla CA certificates (curl.se) into config/cacert.pem.
     * @return array{ok:bool,path:string,bytes:int,message:string}
     */
    public static function installCaBundle(): array
    {
        $local = self::caBundlePath();
        $dir = dirname($local);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('config/ is not writable — cannot install cacert.pem.');
        }
        self::downloadCaBundleTo($local);
        if (!is_file($local) || filesize($local) < 50000) {
            throw new RuntimeException('Downloaded CA bundle looks invalid or incomplete.');
        }
        return [
            'ok' => true,
            'path' => $local,
            'bytes' => (int)filesize($local),
            'message' => 'CA certificates installed at config/cacert.pem ('
                . number_format((int)filesize($local)) . ' bytes). Keep “Verify TLS certificates” enabled.',
        ];
    }

    /** @return array{found:bool,path:?string,app_local:bool,php_curl_cainfo:string,php_openssl_cafile:string} */
    public static function caBundleStatus(): array
    {
        $path = self::resolveCaBundle();
        return [
            'found' => $path !== null,
            'path' => $path,
            'app_local' => is_file(self::caBundlePath()),
            'php_curl_cainfo' => trim((string)ini_get('curl.cainfo')),
            'php_openssl_cafile' => trim((string)ini_get('openssl.cafile')),
        ];
    }

    private static function resolveCaBundle(): ?string
    {
        $candidates = [
            self::caBundlePath(),
            BA_ROOT . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'cacert.pem',
            (string)ini_get('curl.cainfo'),
            (string)ini_get('openssl.cafile'),
            'C:/PHP/extras/ssl/cacert.pem',
            'C:/php/extras/ssl/cacert.pem',
            'C:/Windows/System32/curl-ca-bundle.crt',
        ];
        foreach ($candidates as $p) {
            $p = trim((string)$p);
            if ($p !== '' && is_file($p) && filesize($p) > 1000) {
                return $p;
            }
        }
        return null;
    }

    /** Bootstrap only: peer verify off so a host with no CA list can still fetch the Mozilla pack. */
    private static function downloadCaBundleTo(string $dest): void
    {
        $url = 'https://curl.se/ca/cacert.pem';
        $body = null;
        $err = '';
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            if ($ch !== false) {
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_FOLLOWLOCATION => true,
                    CURLOPT_TIMEOUT => 60,
                    CURLOPT_SSL_VERIFYPEER => false,
                    CURLOPT_SSL_VERIFYHOST => 0,
                    CURLOPT_USERAGENT => 'BackAisle-CA-Installer',
                ]);
                $raw = curl_exec($ch);
                $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $err = curl_error($ch);
                curl_close($ch);
                if (is_string($raw) && $code >= 200 && $code < 300) {
                    $body = $raw;
                }
            }
        }
        if ($body === null) {
            try {
                $body = self::httpRequestCurlExe($url, false, false, false, 60);
            } catch (Throwable $e) {
                throw new RuntimeException(
                    'Could not download CA bundle from curl.se'
                    . ($err !== '' ? ': ' . $err : '')
                    . ' (' . $e->getMessage() . '). Allow outbound HTTPS to curl.se or copy cacert.pem into config/.'
                );
            }
        }
        if ($body === null || !str_contains($body, 'BEGIN CERTIFICATE')) {
            throw new RuntimeException('CA download did not look like a PEM certificate bundle.');
        }
        if (@file_put_contents($dest, $body) === false) {
            throw new RuntimeException('Could not write ' . $dest);
        }
    }

    /** @return array{ok:bool,current:string,latest:?string,update_available:bool,release_name:?string,html_url:?string,notes_url:?string,notes:?string,published_at:?string,checked_at:string,cached:bool} */
    private static function statusBase(string $current, string $now): array
    {
        return [
            'ok' => true,
            'current' => $current,
            'latest' => null,
            'update_available' => false,
            'release_name' => null,
            'html_url' => null,
            'notes_url' => null,
            'notes' => null,
            'published_at' => null,
            'checked_at' => $now,
            'cached' => false,
        ];
    }

    private static function makeWorkDir(string $prefix): string
    {
        $base = BA_ROOT . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'tmp';
        if (!is_dir($base)) @mkdir($base, 0775, true);
        $work = $base . DIRECTORY_SEPARATOR . 'backaisle-' . preg_replace('/[^a-z0-9_-]/i', '', $prefix)
            . '-' . bin2hex(random_bytes(4));
        if (!@mkdir($work, 0700, true) && !is_dir($work)) {
            throw new RuntimeException('Could not create temp directory under storage/tmp.');
        }
        return $work;
    }

    private static function shouldPreserve(string $relNorm): bool
    {
        $relNorm = ltrim($relNorm, '/');
        foreach ([
            'data/', 'logs/', 'storage/backups/', 'storage/tmp/',
            '.git/', 'secrets.env', 'php.ini',
            'config/config.php', 'config/collector.json', 'config/cacert.pem',
            'public/web.config', 'public/assets/tpl/',
        ] as $p) {
            if ($relNorm === rtrim($p, '/') || str_starts_with($relNorm, $p)) {
                return true;
            }
        }
        if (str_ends_with($relNorm, '.db') || str_ends_with($relNorm, '.db-wal') || str_ends_with($relNorm, '.db-shm')) {
            return true;
        }
        return false;
    }

    private static function shouldSkipBackupPath(string $relNorm): bool
    {
        $relNorm = ltrim(str_replace('\\', '/', $relNorm), '/');
        foreach (['storage/backups/', 'storage/tmp/', 'logs/', '.git/', 'data/backaisle.db'] as $p) {
            if ($relNorm === rtrim($p, '/') || str_starts_with($relNorm, $p)) {
                return true;
            }
        }
        if (str_ends_with($relNorm, self::PENDING_SUFFIX)) return true;
        if (str_ends_with($relNorm, '.pyc') || str_contains($relNorm, '__pycache__/')) return true;
        return false;
    }

    /** @return array{copied:int,skipped:int,deferred:int} */
    private static function applyTree(string $sourceRoot, string $destRoot): array
    {
        $sourceRoot = realpath($sourceRoot);
        if ($sourceRoot === false) {
            throw new RuntimeException('Invalid source root after extract.');
        }
        $copied = 0;
        $skipped = 0;
        $deferred = 0;
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($sourceRoot, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($iterator as $file) {
            $full = $file->getPathname();
            $rel = substr($full, strlen($sourceRoot) + 1);
            $relNorm = str_replace('\\', '/', $rel);
            if (self::shouldPreserve($relNorm)) {
                continue;
            }
            $target = $destRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
            if ($file->isDir()) {
                if (!is_dir($target)) @mkdir($target, 0755, true);
                continue;
            }
            $parent = dirname($target);
            if (!is_dir($parent)) @mkdir($parent, 0755, true);
            if (self::isCurrentlyExecuting($target)) {
                if (self::stagePending($full, $target)) {
                    $deferred++;
                    $copied++;
                } else {
                    throw new RuntimeException('Failed to stage active file: ' . $relNorm . '. ' . self::aclHelpMessage());
                }
                continue;
            }
            $result = self::copyFileRobust($full, $target);
            if ($result === 'ok') {
                $copied++;
            } elseif ($result === 'deferred') {
                $copied++;
                $deferred++;
            } else {
                throw new RuntimeException('Failed to copy: ' . $relNorm . '. ' . self::aclHelpMessage());
            }
        }
        return ['copied' => $copied, 'skipped' => $skipped, 'deferred' => $deferred];
    }

    private static function isCurrentlyExecuting(string $dest): bool
    {
        $script = (string)($_SERVER['SCRIPT_FILENAME'] ?? '');
        if ($script === '') {
            return false;
        }
        $a = realpath($dest);
        $b = realpath($script);
        if ($a && $b) {
            return strcasecmp($a, $b) === 0;
        }
        return strcasecmp(str_replace('\\', '/', $dest), str_replace('\\', '/', $script)) === 0;
    }

    /** @return 'ok'|'deferred'|false */
    private static function copyFileRobust(string $src, string $dest): string|false
    {
        if (!is_file($src) || !is_readable($src)) {
            return false;
        }
        if (is_file($dest)) {
            @chmod($dest, 0666);
            if (PHP_OS_FAMILY === 'Windows') {
                @exec('attrib -R ' . escapeshellarg($dest) . ' 2>NUL');
            }
        }
        if (@copy($src, $dest)) {
            $want = @filesize($src);
            $got = @filesize($dest);
            if ($want !== false && $got !== false && $got === $want) {
                return 'ok';
            }
        }
        $data = @file_get_contents($src);
        if ($data !== false && @file_put_contents($dest, $data) !== false) {
            return 'ok';
        }
        $tmp = $dest . '.upd.' . bin2hex(random_bytes(3));
        $wroteTmp = $data !== false ? (@file_put_contents($tmp, $data) !== false) : @copy($src, $tmp);
        if ($wroteTmp) {
            if (!is_file($dest)) {
                if (@rename($tmp, $dest) || @copy($tmp, $dest)) {
                    @unlink($tmp);
                    return 'ok';
                }
            } else {
                $bak = $dest . '.backaisle-bak';
                @unlink($bak);
                if (@rename($dest, $bak)) {
                    if (@rename($tmp, $dest) || @copy($tmp, $dest)) {
                        @unlink($tmp);
                        @unlink($bak);
                        return 'ok';
                    }
                    @rename($bak, $dest);
                }
            }
            @unlink($tmp);
        }
        if (self::stagePending($src, $dest)) {
            return 'deferred';
        }
        return false;
    }

    private static function stagePending(string $src, string $dest): bool
    {
        $pending = $dest . self::PENDING_SUFFIX;
        @chmod($pending, 0666);
        if (@copy($src, $pending) || (($data = @file_get_contents($src)) !== false && @file_put_contents($pending, $data) !== false)) {
            $flagDir = BA_ROOT . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'tmp';
            if (!is_dir($flagDir)) {
                @mkdir($flagDir, 0775, true);
            }
            @file_put_contents($flagDir . DIRECTORY_SEPARATOR . self::PENDING_FLAG, '1');
            return true;
        }
        return false;
    }

    private static function zipAppTree(string $root, string $zipPath): int
    {
        if (class_exists('ZipArchive')) {
            $zip = new ZipArchive();
            if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException('Could not create application-files zip.');
            }
            $added = 0;
            $len = strlen($root);
            $it = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::SELF_FIRST
            );
            foreach ($it as $file) {
                $full = $file->getPathname();
                $rel = substr($full, $len + 1);
                $relNorm = str_replace('\\', '/', $rel);
                if (self::shouldSkipBackupPath($relNorm)) continue;
                if ($file->isDir()) {
                    $zip->addEmptyDir($relNorm);
                } elseif ($file->isFile()) {
                    if ($zip->addFile($full, $relNorm)) {
                        $added++;
                    } else {
                        $data = @file_get_contents($full);
                        if ($data !== false && $zip->addFromString($relNorm, $data)) {
                            $added++;
                        }
                    }
                }
            }
            $zip->close();
            return $added;
        }
        throw new RuntimeException('PHP zip extension is required for application-files backups.');
    }

    private static function findExtractedRoot(string $extractDir): ?string
    {
        foreach (scandir($extractDir) ?: [] as $e) {
            if ($e === '.' || $e === '..') continue;
            $path = $extractDir . DIRECTORY_SEPARATOR . $e;
            if (is_dir($path) && (is_file($path . '/VERSION') || is_file($path . '/public/index.php'))) {
                return $path;
            }
        }
        if (is_file($extractDir . '/VERSION') || is_file($extractDir . '/public/index.php')) {
            return $extractDir;
        }
        return null;
    }

    private static function parseSemver(string $raw): ?string
    {
        $tv = ltrim(trim($raw), "vV \t\r\n");
        if (!preg_match('/^(\d+\.\d+(?:\.\d+)?)/', $tv, $m)) {
            return null;
        }
        return $m[1];
    }

    /**
     * @param array{tag:?string,name:string,html:?string,notes:?string,published:?string,source:string} $acc
     */
    private static function considerVersion(array &$acc, ?string $raw, string $source, ?string $name = null, ?string $html = null, ?string $notes = null, ?string $published = null): void
    {
        if ($raw === null || $raw === '') {
            return;
        }
        $tv = self::parseSemver($raw);
        if ($tv === null) {
            return;
        }
        if ($acc['tag'] !== null && version_compare($tv, $acc['tag'], '<=')) {
            return;
        }
        $acc['tag'] = $tv;
        $acc['source'] = $source;
        $acc['name'] = $name !== null && $name !== '' ? $name : ('v' . $tv);
        $acc['html'] = $html !== null && $html !== '' ? $html : (self::githubUrl() . '/releases/tag/v' . rawurlencode($tv));
        $acc['notes'] = $notes;
        $acc['published'] = $published;
    }

    private static function jsDelivrTextFile(string $ref, string $file): ?string
    {
        $url = 'https://cdn.jsdelivr.net/gh/' . self::GITHUB_OWNER . '/' . self::GITHUB_REPO
            . '@' . rawurlencode($ref) . '/' . ltrim($file, '/');
        try {
            $body = self::httpRequest($url, false, true, false, 6);
        } catch (Throwable $e) {
            return null;
        }
        if ($body === null || $body === '') {
            return null;
        }
        return self::parseSemver($body);
    }

    private static function jsDelivrVersionFile(string $ref): ?string
    {
        $ver = self::jsDelivrTextFile($ref, 'VERSION');
        if ($ver !== null) {
            return $ver;
        }
        return self::jsDelivrTextFile($ref, 'LATEST');
    }

    /** Walk patch/minor tags on jsDelivr so a lagging versions[] catalog cannot hide a GitHub tag. */
    private static function probeJsDelivrNewer(?string $floor): ?string
    {
        $parts = [0, 0, 0];
        if ($floor !== null && preg_match('/^(\d+)\.(\d+)(?:\.(\d+))?$/', $floor, $m)) {
            $parts = [(int)$m[1], (int)$m[2], (int)($m[3] ?? 0)];
        }
        $best = $floor;
        $probes = 0;
        $max = 4;
        $try = function (string $ver) use (&$best, &$probes, $max): bool {
            if ($probes >= $max) {
                return false;
            }
            $probes++;
            $got = self::jsDelivrTextFile('v' . $ver, 'VERSION');
            if ($got === null) {
                return false;
            }
            if ($best === null || version_compare($got, $best, '>')) {
                $best = $got;
            }
            return true;
        };
        $miss = 0;
        for ($p = $parts[2] + 1; $p <= $parts[2] + 4; $p++) {
            if ($probes >= $max) {
                break;
            }
            if ($try($parts[0] . '.' . $parts[1] . '.' . $p)) {
                $miss = 0;
            } else {
                $miss++;
                if ($miss >= 2) {
                    break;
                }
            }
        }
        return $best;
    }

    /** @return array{tag:string,name:string,html:?string,notes:?string,published:?string,source:string} */
    private static function resolveRemoteLatest(): array
    {
        $errors = [];
        $acc = [
            'tag' => null,
            'name' => '',
            'html' => null,
            'notes' => null,
            'published' => null,
            'source' => 'jsdelivr',
        ];
        $owner = rawurlencode(self::GITHUB_OWNER);
        $repo = rawurlencode(self::GITHUB_REPO);
        // jsDelivr first (this network). GitHub is often blocked and used to stall Check for ~45s.
        try {
            self::considerVersion($acc, self::jsDelivrTextFile('main', 'LATEST'), 'jsdelivr-latest');
        } catch (Throwable $e) {
            $errors[] = $e->getMessage();
        }
        try {
            self::considerVersion($acc, self::jsDelivrTextFile('main', 'VERSION'), 'jsdelivr-main');
        } catch (Throwable $e) {
            $errors[] = $e->getMessage();
        }
        try {
            $body = self::httpRequest(
                'https://data.jsdelivr.com/v1/packages/gh/' . self::GITHUB_OWNER . '/' . self::GITHUB_REPO,
                false,
                false,
                false,
                8
            );
            $j = json_decode((string)$body, true);
            if (is_array($j)) {
                foreach ($j['versions'] ?? [] as $row) {
                    $tv = is_array($row) ? (string)($row['version'] ?? '') : (string)$row;
                    self::considerVersion($acc, $tv, 'jsdelivr');
                }
            }
        } catch (Throwable $e) {
            $errors[] = $e->getMessage();
        }
        $floor = $acc['tag'] ?? self::installedVersion();
        $probed = self::probeJsDelivrNewer($floor);
        if ($probed !== null) {
            self::considerVersion($acc, $probed, 'jsdelivr-tag');
        }
        try {
            $release = self::githubGetJson("https://api.github.com/repos/{$owner}/{$repo}/releases/latest", true);
            if (is_array($release) && !empty($release['tag_name']) && empty($release['message'])) {
                self::considerVersion(
                    $acc,
                    (string)$release['tag_name'],
                    'github',
                    (string)($release['name'] ?? $release['tag_name']),
                    (string)($release['html_url'] ?? ''),
                    trim((string)($release['body'] ?? '')) ?: null,
                    (string)($release['published_at'] ?? $release['created_at'] ?? '')
                );
            }
        } catch (Throwable $e) {
            $errors[] = $e->getMessage();
        }
        if ($acc['tag'] === null) {
            throw new RuntimeException('Could not determine latest version. ' . implode('; ', $errors));
        }
        return $acc;
    }

    private static function flattenJsDelivrFiles(array $node, string $prefix = ''): array
    {
        $out = [];
        foreach ($node['files'] ?? [] as $f) {
            if (!is_array($f)) {
                continue;
            }
            $name = (string)($f['name'] ?? '');
            $p = $prefix === '' ? $name : $prefix . '/' . $name;
            if (preg_match('#^\.(git|grok)(/|$)#', $p)) {
                continue;
            }
            if (($f['type'] ?? '') === 'file') {
                $out[] = $p;
            } elseif (!empty($f['files']) && is_array($f['files'])) {
                $out = array_merge($out, self::flattenJsDelivrFiles($f, $p));
            }
        }
        return $out;
    }

    private static function fetchJsDelivrTree(string $version, string $dest): string
    {
        $version = ltrim($version, 'vV');
        $errors = [];
        $meta = null;
        $refUsed = null;
        foreach ([$version, 'v' . $version] as $ref) {
            try {
                $url = 'https://data.jsdelivr.com/v1/packages/gh/' . self::GITHUB_OWNER . '/' . self::GITHUB_REPO . '@' . rawurlencode($ref);
                $body = self::httpRequest($url, false, false, false);
                $j = json_decode((string)$body, true);
                if (is_array($j) && !empty($j['files'])) {
                    $meta = $j;
                    $refUsed = $ref;
                    break;
                }
            } catch (Throwable $e) {
                $errors[] = $e->getMessage();
            }
        }
        if ($meta === null || $refUsed === null) {
            throw new RuntimeException('jsDelivr package list failed. ' . implode('; ', $errors));
        }
        $files = self::flattenJsDelivrFiles($meta);
        if (count($files) < 10) {
            throw new RuntimeException('jsDelivr file list too small.');
        }
        if (!is_dir($dest)) {
            @mkdir($dest, 0755, true);
        }
        $jobs = [];
        $cdn = 'https://cdn.jsdelivr.net/gh/' . self::GITHUB_OWNER . '/' . self::GITHUB_REPO . '@' . rawurlencode($refUsed) . '/';
        foreach ($files as $rel) {
            if (self::shouldPreserve($rel)) {
                continue;
            }
            $jobs[] = ['rel' => $rel, 'url' => $cdn . str_replace(' ', '%20', $rel)];
        }
        $n = self::downloadJsDelivrBatch($jobs, $dest);
        if ($n < 10) {
            throw new RuntimeException('jsDelivr fetched too few files (' . $n . ').');
        }
        foreach (['VERSION', 'public/index.php', 'app/update.php'] as $need) {
            $p = $dest . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $need);
            if (!is_file($p)) {
                throw new RuntimeException('jsDelivr tree missing ' . $need);
            }
        }
        $root = self::findExtractedRoot($dest);
        return $root ?? $dest;
    }

    /** @param list<array{rel:string,url:string}> $jobs */
    private static function downloadJsDelivrBatch(array $jobs, string $dest): int
    {
        if (!function_exists('curl_multi_init')) {
            $n = 0;
            foreach ($jobs as $job) {
                try {
                    $body = self::httpRequest($job['url'], true, true, false, 60);
                    if ($body === null || $body === '') {
                        continue;
                    }
                    $out = $dest . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $job['rel']);
                    $parent = dirname($out);
                    if (!is_dir($parent)) {
                        @mkdir($parent, 0755, true);
                    }
                    if (@file_put_contents($out, $body) !== false) {
                        $n++;
                    }
                } catch (Throwable $e) {
                    continue;
                }
            }
            return $n;
        }
        $n = 0;
        $sslVerify = !empty(self::config()['ssl_verify']);
        $ca = $sslVerify ? (self::ensureCaBundle() ?? '') : '';
        $chunk = 8;
        $total = count($jobs);
        for ($i = 0; $i < $total; $i += $chunk) {
            $batch = array_slice($jobs, $i, $chunk);
            $mh = curl_multi_init();
            if ($mh === false) {
                throw new RuntimeException('curl_multi_init failed.');
            }
            $handles = [];
            foreach ($batch as $job) {
                $ch = curl_init($job['url']);
                if ($ch === false) {
                    continue;
                }
                $opts = [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_FOLLOWLOCATION => true,
                    CURLOPT_TIMEOUT => 60,
                    CURLOPT_CONNECTTIMEOUT => 10,
                    CURLOPT_HTTPHEADER => ['User-Agent: BackAisle-Updater'],
                    CURLOPT_SSL_VERIFYPEER => $sslVerify,
                    CURLOPT_SSL_VERIFYHOST => $sslVerify ? 2 : 0,
                ];
                if ($sslVerify && is_file($ca)) {
                    $opts[CURLOPT_CAINFO] = $ca;
                }
                curl_setopt_array($ch, $opts);
                curl_multi_add_handle($mh, $ch);
                $handles[] = ['ch' => $ch, 'rel' => $job['rel']];
            }
            $running = 0;
            do {
                $mc = curl_multi_exec($mh, $running);
                if ($running) {
                    curl_multi_select($mh, 1.0);
                }
            } while ($running && $mc === CURLM_OK);
            foreach ($handles as $item) {
                $ch = $item['ch'];
                $rel = $item['rel'];
                $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $body = curl_multi_getcontent($ch);
                curl_multi_remove_handle($mh, $ch);
                curl_close($ch);
                if ($code < 200 || $code >= 300 || !is_string($body) || $body === '') {
                    continue;
                }
                $out = $dest . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
                $parent = dirname($out);
                if (!is_dir($parent)) {
                    @mkdir($parent, 0755, true);
                }
                if (@file_put_contents($out, $body) !== false) {
                    $n++;
                }
            }
            curl_multi_close($mh);
        }
        return $n;
    }

    private static function githubGetJson(string $url, bool $allowNotFound): mixed
    {
        $body = self::httpRequest($url, false, $allowNotFound, true, 3);
        if ($body === null) return null;
        if (self::isHtmlPayload($body)) {
            throw new RuntimeException('GitHub API returned a web page (proxy).');
        }
        return json_decode($body, true);
    }

    private static function githubDownload(string $url, string $dest): void
    {
        $body = self::httpRequest($url, true, false, true);
        if ($body === null || $body === '') {
            throw new RuntimeException('Empty download from GitHub.');
        }
        if (strlen($body) >= 2 && $body[0] === '<' ) {
            throw new RuntimeException('GitHub zip download was a web page (proxy).');
        }
        if (@file_put_contents($dest, $body) === false) {
            throw new RuntimeException('Could not write release zip.');
        }
    }

    private static function isHtmlPayload(string $body): bool
    {
        $t = ltrim($body, "\xEF\xBB\xBF \t\r\n");
        return (bool)preg_match('/^(<!DOCTYPE|<html)/i', $t);
    }

    private static function httpRequest(string $url, bool $binary, bool $allowNotFound, bool $githubApi = true, int $timeoutSec = 0): ?string
    {
        $errors = [];
        if (function_exists('curl_init')) {
            try {
                return self::httpRequestPhpCurl($url, $binary, $allowNotFound, $githubApi, $timeoutSec);
            } catch (Throwable $e) {
                $errors[] = 'php-curl: ' . $e->getMessage();
            }
        } else {
            $errors[] = 'php-curl: extension not loaded';
        }
        try {
            return self::httpRequestCurlExe($url, $binary, $allowNotFound, $githubApi, $timeoutSec);
        } catch (Throwable $e) {
            $errors[] = 'curl.exe: ' . $e->getMessage();
        }
        throw new RuntimeException(implode('; ', $errors));
    }

    private static function httpRequestPhpCurl(string $url, bool $binary, bool $allowNotFound, bool $githubApi, int $timeoutSec, bool $caRetried = false): ?string
    {
        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('curl_init failed.');
        }
        $headers = [
            'User-Agent: BackAisle-Updater',
        ];
        if ($githubApi) {
            $headers[] = 'Accept: application/vnd.github+json';
            $headers[] = 'X-GitHub-Api-Version: 2022-11-28';
        }
        $sslVerify = !empty(self::config()['ssl_verify']);
        $timeout = $timeoutSec > 0 ? $timeoutSec : ($binary ? 300 : 45);
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => min(15, $timeout),
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_SSL_VERIFYPEER => $sslVerify,
            CURLOPT_SSL_VERIFYHOST => $sslVerify ? 2 : 0,
        ];
        if ($sslVerify && defined('CURLSSLOPT_NATIVE_CA')) {
            $opts[CURLOPT_SSL_OPTIONS] = CURLSSLOPT_NATIVE_CA;
        }
        if ($sslVerify) {
            $ca = self::ensureCaBundle($caRetried);
            if ($ca !== null) {
                $opts[CURLOPT_CAINFO] = $ca;
            }
        }
        curl_setopt_array($ch, $opts);
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($body === false) {
            if ($sslVerify && !$caRetried && (stripos($err, 'certificate') !== false || stripos($err, 'SSL') !== false)) {
                if (self::ensureCaBundle(true) !== null) {
                    return self::httpRequestPhpCurl($url, $binary, $allowNotFound, $githubApi, $timeoutSec, true);
                }
            }
            $hint = '';
            if (stripos($err, 'certificate') !== false || stripos($err, 'SSL') !== false) {
                $hint = ' PHP has no trusted CA list. Use Admin → Updates → Install CA certificates, '
                    . 'or set curl.cainfo in php.ini to a cacert.pem path. '
                    . 'Uncheck “Verify TLS certificates” only for lab/dev.';
            }
            throw new RuntimeException('HTTP request failed: ' . $err . $hint);
        }
        return self::httpFinish((string)$body, $code, $url, $binary, $allowNotFound);
    }

    private static function curlExePath(): string
    {
        $sys = 'C:\\Windows\\System32\\curl.exe';
        if (is_file($sys)) {
            return $sys;
        }
        return 'curl.exe';
    }

    private static function httpRequestCurlExe(string $url, bool $binary, bool $allowNotFound, bool $githubApi, int $timeoutSec): ?string
    {
        $timeout = $timeoutSec > 0 ? $timeoutSec : ($binary ? 300 : 45);
        $tmp = tempnam(sys_get_temp_dir(), 'bahttp');
        if ($tmp === false) {
            throw new RuntimeException('tempnam failed.');
        }
        $cmd = [
            self::curlExePath(),
            '-sS',
            '-L',
            '--ssl-no-revoke',
            '--max-time',
            (string)$timeout,
            '-A',
            'BackAisle-Updater',
            '-o',
            $tmp,
            '-w',
            '%{http_code}',
        ];
        if ($githubApi) {
            $cmd[] = '-H';
            $cmd[] = 'Accept: application/vnd.github+json';
            $cmd[] = '-H';
            $cmd[] = 'X-GitHub-Api-Version: 2022-11-28';
        }
        $cmd[] = $url;
        $dspec = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $p = @proc_open($cmd, $dspec, $pipes, null, null, ['bypass_shell' => true]);
        if (!is_resource($p)) {
            @unlink($tmp);
            throw new RuntimeException('proc_open curl.exe failed.');
        }
        fclose($pipes[0]);
        $stdout = (string)stream_get_contents($pipes[1]);
        $stderr = (string)stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($p);
        $code = (int)preg_replace('/\D/', '', $stdout);
        $body = is_file($tmp) ? (string)file_get_contents($tmp) : '';
        @unlink($tmp);
        if ($exit !== 0 && $code === 0) {
            throw new RuntimeException(trim($stderr) !== '' ? trim($stderr) : ('curl.exe exit ' . $exit));
        }
        return self::httpFinish($body, $code, $url, $binary, $allowNotFound);
    }

    private static function httpFinish(string $body, int $code, string $url, bool $binary, bool $allowNotFound): ?string
    {
        if ($allowNotFound && $code === 404) {
            return null;
        }
        if ($code < 200 || $code >= 300) {
            throw new RuntimeException('HTTP ' . $code . ' from ' . $url);
        }
        if (!$binary && self::isHtmlPayload($body)) {
            throw new RuntimeException('Received a web page instead of data (proxy).');
        }
        return $body;
    }
}
