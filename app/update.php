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
        $owner = rawurlencode((string)$cfg['github_owner']);
        $repo = rawurlencode((string)$cfg['github_repo']);
        try {
            $release = self::githubGetJson("https://api.github.com/repos/{$owner}/{$repo}/releases/latest", true);
            $tag = null;
            $name = null;
            $html = null;
            $notes = null;
            $published = null;
            if (is_array($release) && !empty($release['tag_name']) && empty($release['message'])) {
                $tag = ltrim((string)$release['tag_name'], 'vV');
                $name = (string)($release['name'] ?? $release['tag_name']);
                $html = (string)($release['html_url'] ?? '');
                $notes = trim((string)($release['body'] ?? ''));
                $published = (string)($release['published_at'] ?? $release['created_at'] ?? '');
            } else {
                $tags = self::githubGetJson("https://api.github.com/repos/{$owner}/{$repo}/tags?per_page=30", false);
                if (!is_array($tags) || isset($tags['message'])) {
                    $msg = is_array($tags) ? (string)($tags['message'] ?? 'GitHub API error') : 'GitHub API error';
                    throw new RuntimeException($msg);
                }
                $best = null;
                foreach ($tags as $t) {
                    if (!is_array($t) || empty($t['name'])) continue;
                    $tv = ltrim((string)$t['name'], 'vV');
                    if (!preg_match('/^\d+\.\d+/', $tv)) continue;
                    if ($best === null || version_compare($tv, $best, '>')) {
                        $best = $tv;
                        $name = (string)$t['name'];
                    }
                }
                if ($best === null) {
                    throw new RuntimeException('No version tags found on the repository. Push a tag like v0.1.0 first.');
                }
                $tag = $best;
                $html = self::githubUrl() . '/releases/tag/v' . rawurlencode($tag);
            }
            $result = [
                'ok' => true,
                'current' => $current,
                'latest' => $tag,
                'update_available' => version_compare((string)$tag, $current, '>'),
                'release_name' => $name,
                'html_url' => $html,
                'notes_url' => self::changelogUrl(),
                'notes' => $notes !== '' ? $notes : null,
                'published_at' => $published,
                'checked_at' => $now,
                'cached' => false,
            ];
            self::storeCache($result);
            return $result;
        } catch (Throwable $e) {
            $result = self::statusBase($current, $now) + [
                'ok' => false,
                'error' => $e->getMessage(),
                'notes_url' => self::changelogUrl(),
            ];
            self::storeCache($result);
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
        $filesAdded = self::zipAppTree($root, $path);
        if (!is_file($path) || filesize($path) < 200) {
            throw new RuntimeException('Application-files zip was not created.');
        }
        return [
            'code_zip' => $path,
            'site_package' => $sitePath,
            'code_bytes' => (int)filesize($path),
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
        $backup = self::createRecoveryBackup();
        $tmpDir = self::makeWorkDir('upd');
        try {
            $zipFile = $tmpDir . DIRECTORY_SEPARATOR . 'release.zip';
            $url = 'https://api.github.com/repos/' . rawurlencode(self::GITHUB_OWNER) . '/'
                . rawurlencode(self::GITHUB_REPO) . '/zipball/v' . rawurlencode($version);
            self::githubDownload($url, $zipFile);
            $extractDir = $tmpDir . DIRECTORY_SEPARATOR . 'extract';
            BackAisleBackup::extractZip($zipFile, $extractDir);
            $sourceRoot = self::findExtractedRoot($extractDir);
            if ($sourceRoot === null) {
                throw new RuntimeException('Could not locate application root inside the release archive.');
            }
            $stats = self::applyTree($sourceRoot, BA_ROOT);
            @file_put_contents(BA_ROOT . '/VERSION', $version . "\n");
            self::applyPendingReplacements();
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
                . basename($backup['site_package']) . '. App files: ' . basename($backup['code_zip'])
                . " ({$stats['copied']} files).";
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
        $left = 0;
        $flag = BA_ROOT . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'tmp' . DIRECTORY_SEPARATOR . self::PENDING_FLAG;
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(BA_ROOT, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($it as $file) {
            $full = $file->getPathname();
            if (!str_ends_with($full, self::PENDING_SUFFIX)) continue;
            $dest = substr($full, 0, -strlen(self::PENDING_SUFFIX));
            if (@rename($full, $dest) || (@copy($full, $dest) && @unlink($full))) {
                continue;
            }
            $left++;
        }
        if ($left === 0 && is_file($flag)) {
            @unlink($flag);
        }
        return $left;
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
        return BA_ROOT . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'cacert.pem';
    }

    public static function installCaBundle(): string
    {
        $dest = self::caBundlePath();
        $dir = dirname($dest);
        if (!is_dir($dir)) @mkdir($dir, 0775, true);
        $url = 'https://curl.se/ca/cacert.pem';
        $body = self::httpRequest($url, false, false);
        if ($body === null || strlen($body) < 1000) {
            throw new RuntimeException('Could not download CA bundle.');
        }
        if (@file_put_contents($dest, $body) === false) {
            throw new RuntimeException('Could not write ' . $dest);
        }
        return $dest;
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
            'public/assets/tpl/',
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
            $self = str_replace('\\', '/', (string)($_SERVER['SCRIPT_FILENAME'] ?? ''));
            $tgtN = str_replace('\\', '/', $target);
            if ($self !== '' && strcasecmp($self, $tgtN) === 0) {
                if (self::stagePending($full, $target)) {
                    $deferred++;
                    $copied++;
                }
                continue;
            }
            if (@copy($full, $target)) {
                $copied++;
            } elseif (self::stagePending($full, $target)) {
                $copied++;
                $deferred++;
            } else {
                throw new RuntimeException('Failed to copy: ' . $relNorm . '. ' . self::aclHelpMessage());
            }
        }
        return ['copied' => $copied, 'skipped' => $skipped, 'deferred' => $deferred];
    }

    private static function stagePending(string $src, string $dest): bool
    {
        $pending = $dest . self::PENDING_SUFFIX;
        if (!@copy($src, $pending)) {
            return false;
        }
        $flagDir = BA_ROOT . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'tmp';
        if (!is_dir($flagDir)) @mkdir($flagDir, 0775, true);
        @file_put_contents($flagDir . DIRECTORY_SEPARATOR . self::PENDING_FLAG, '1');
        return true;
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
                } elseif ($file->isFile() && $zip->addFile($full, $relNorm)) {
                    $added++;
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

    private static function githubGetJson(string $url, bool $allowNotFound): mixed
    {
        $body = self::httpRequest($url, false, $allowNotFound);
        if ($body === null) return null;
        return json_decode($body, true);
    }

    private static function githubDownload(string $url, string $dest): void
    {
        $body = self::httpRequest($url, true, false);
        if ($body === null || $body === '') {
            throw new RuntimeException('Empty download from GitHub.');
        }
        if (@file_put_contents($dest, $body) === false) {
            throw new RuntimeException('Could not write release zip.');
        }
    }

    private static function httpRequest(string $url, bool $binary, bool $allowNotFound): ?string
    {
        if (!function_exists('curl_init')) {
            throw new RuntimeException('PHP cURL extension is required for updates.');
        }
        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('curl_init failed.');
        }
        $headers = [
            'Accept: application/vnd.github+json',
            'User-Agent: BackAisle-Updater',
            'X-GitHub-Api-Version: 2022-11-28',
        ];
        $sslVerify = !empty(self::config()['ssl_verify']);
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => $binary ? 300 : 45,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_SSL_VERIFYPEER => $sslVerify,
            CURLOPT_SSL_VERIFYHOST => $sslVerify ? 2 : 0,
        ];
        $ca = self::caBundlePath();
        if ($sslVerify && is_file($ca)) {
            $opts[CURLOPT_CAINFO] = $ca;
        }
        curl_setopt_array($ch, $opts);
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($body === false) {
            throw new RuntimeException('HTTP request failed: ' . $err);
        }
        if ($allowNotFound && $code === 404) {
            return null;
        }
        if ($code < 200 || $code >= 300) {
            throw new RuntimeException('GitHub HTTP ' . $code);
        }
        return (string)$body;
    }
}
