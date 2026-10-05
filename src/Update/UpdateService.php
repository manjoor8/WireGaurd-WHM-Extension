<?php
declare(strict_types=1);

namespace WireGuardManager\Update;

use WireGuardManager\App;
use WireGuardManager\ConfigService;
use WireGuardManager\AuditService;
use WireGuardManager\Database;
use PDO;
use RuntimeException;
use InvalidArgumentException;
use ZipArchive;

class UpdateService
{
    private PDO $db;
    private ConfigService $config;
    private ?AuditService $audit;
    private ?IUpdateProvider $provider;
    private string $storageDir;
    private string $appDir;
    private string $helperBin;

    public const DEFAULT_UPDATE_ENDPOINT = 'https://raw.githubusercontent.com/manjoor8/WireGaurdManager/main/release.json';

    public function __construct(
        ?PDO $db = null,
        ?IUpdateProvider $provider = null,
        ?string $storageDir = null,
        ?string $appDir = null,
        ?string $helperBin = null
    ) {
        $this->db = $db ?? Database::getConnection();
        $this->config = new ConfigService($this->db);
        $this->audit = new AuditService($this->db);
        $this->appDir = $appDir ?: dirname(__DIR__, 2);
        $this->storageDir = $storageDir
            ?: getenv('DATA_DIR')
            ?: '/var/lib/wireguard-manager';
        $this->helperBin = $helperBin
            ?: getenv('HELPER_BIN')
            ?: '/usr/local/bin/wireguard-manager-helper';

        if ($provider !== null) {
            $this->provider = $provider;
        } else {
            $endpoint = $this->config->get('update_endpoint', self::DEFAULT_UPDATE_ENDPOINT);
            $this->provider = new JsonUpdateProvider($endpoint ?: self::DEFAULT_UPDATE_ENDPOINT);
        }
    }

    public function setProvider(IUpdateProvider $provider): void
    {
        $this->provider = $provider;
    }

    public function getProvider(): ?IUpdateProvider
    {
        return $this->provider;
    }

    public function getCurrentVersion(): string
    {
        return App::getVersion();
    }

    /**
     * Check if a newer version is available.
     *
     * @return array{available: bool, current_version: string, latest_version: ?string, update: ?UpdateInfo}
     */
    public function checkForUpdates(): array
    {
        $current = $this->getCurrentVersion();
        if ($this->provider === null) {
            return [
                'available' => false,
                'current_version' => $current,
                'latest_version' => $current,
                'update' => null,
            ];
        }

        try {
            $update = $this->provider->checkForUpdate($current);
            if ($update !== null && SemVer::isNewer($update->version, $current)) {
                $this->audit?->log('UPDATE_CHECK', "Update check: new version {$update->version} is available");
                return [
                    'available' => true,
                    'current_version' => $current,
                    'latest_version' => $update->version,
                    'update' => $update,
                ];
            }
        } catch (\Throwable $e) {
            $this->audit?->log('UPDATE_CHECK_FAILED', "Update check failed: " . $e->getMessage());
        }

        return [
            'available' => false,
            'current_version' => $current,
            'latest_version' => $current,
            'update' => null,
        ];
    }

    /**
     * Download the update package to storage.
     */
    public function downloadUpdate(UpdateInfo $update): string
    {
        $updateDir = $this->storageDir . '/updates';
        if (!is_dir($updateDir)) {
            @mkdir($updateDir, 0750, true);
        }

        $filename = 'wireguard-manager-' . preg_replace('/[^0-9A-Za-z.-]/', '', $update->version) . '.zip';
        $destPath = $updateDir . '/' . $filename;

        $downloaded = $this->provider->download($update, $destPath);

        // Verify checksum if provided
        if (!empty($update->checksum)) {
            $actualHash = hash_file('sha256', $downloaded);
            if (!hash_equals(strtolower($update->checksum), strtolower((string)$actualHash))) {
                @unlink($downloaded);
                throw new RuntimeException("Package checksum verification failed. Expected: {$update->checksum}, got: {$actualHash}");
            }
        }

        $this->audit?->log('UPDATE_DOWNLOADED', "Downloaded update version {$update->version} to {$destPath}");
        return $downloaded;
    }

    /**
     * Create a backup archive of the current application before applying an update.
     */
    public function backupCurrentVersion(): string
    {
        $backupDir = $this->storageDir . '/backups';
        if (!is_dir($backupDir)) {
            @mkdir($backupDir, 0750, true);
        }

        $currentVersion = $this->getCurrentVersion();
        $backupFile = $backupDir . '/backup-' . $currentVersion . '-' . date('Ymd-His') . '.zip';

        if (class_exists('ZipArchive')) {
            $zip = new ZipArchive();
            if ($zip->open($backupFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true) {
                $this->addFolderToZip($this->appDir, $zip, strlen($this->appDir) + 1);
                $zip->close();
            } else {
                throw new RuntimeException("Failed to create backup zip file at $backupFile");
            }
        } else {
            // Fallback to tar if zip extension is not available
            $cmd = sprintf('tar -czf %s -C %s . 2>&1', escapeshellarg($backupFile), escapeshellarg($this->appDir));
            exec($cmd, $out, $ret);
            if ($ret !== 0) {
                throw new RuntimeException("Failed to create backup tar archive: " . implode("\n", $out));
            }
        }

        // Save rollback manifest
        $manifest = [
            'version' => $currentVersion,
            'timestamp' => time(),
            'backup_file' => $backupFile,
        ];
        file_put_contents($backupDir . '/latest_backup.json', json_encode($manifest, JSON_PRETTY_PRINT));

        $this->audit?->log('UPDATE_BACKUP', "Created backup of version {$currentVersion} at {$backupFile}");
        return $backupFile;
    }

    private function addFolderToZip(string $dir, ZipArchive $zip, int $stripLen): void
    {
        $handle = opendir($dir);
        if (!$handle) return;

        while (($file = readdir($handle)) !== false) {
            if ($file === '.' || $file === '..' || $file === '.git') {
                continue;
            }
            $filePath = "$dir/$file";
            $localPath = substr($filePath, $stripLen);

            if (is_dir($filePath)) {
                // Skip storage, sessions, and logs from application code backup
                if (in_array($file, ['storage', 'sessions', 'logs', 'backups', 'updates'], true)) {
                    continue;
                }
                $zip->addEmptyDir($localPath);
                $this->addFolderToZip($filePath, $zip, $stripLen);
            } elseif (is_file($filePath)) {
                $zip->addFile($filePath, $localPath);
            }
        }
        closedir($handle);
    }

    /**
     * Safely apply an update package.
     * Preserves DB, WireGuard configuration, peers, keys, settings, and credentials.
     * Restores backup if verification fails.
     *
     * @param string $packagePath Path to the downloaded update package (.zip or .tar.gz)
     * @return array{success: bool, previous_version: string, new_version: string}
     */
    public function applyUpdate(string $packagePath): array
    {
        if (!file_exists($packagePath)) {
            throw new InvalidArgumentException("Update package not found: $packagePath");
        }

        $previousVersion = $this->getCurrentVersion();

        // 1. Create a full pre-update backup for rollback safety
        $backupPath = $this->backupCurrentVersion();

        // 2. Validate update package contents before touching live app
        $this->validatePackage($packagePath);

        $this->audit?->log('UPDATE_START', "Starting application update: {$previousVersion} -> package {$packagePath}");

        // 3. Delegate to privileged helper or perform atomic extraction
        $success = false;
        $errorMsg = null;

        try {
            if (file_exists($this->helperBin) && is_executable($this->helperBin)) {
                $cmdParts = [];
                if (function_exists('posix_getuid') && posix_getuid() !== 0) {
                    $cmdParts[] = 'sudo -n';
                }
                $cmdParts[] = escapeshellcmd($this->helperBin);
                $cmdParts[] = 'apply-update';
                $cmdParts[] = escapeshellarg($packagePath);

                $fullCmd = implode(' ', $cmdParts) . ' 2>&1';
                exec($fullCmd, $output, $exitCode);
                $raw = trim(implode("\n", $output));

                if ($exitCode === 0) {
                    $success = true;
                } else {
                    throw new RuntimeException("Helper apply-update failed with code $exitCode: $raw");
                }
            } else {
                // In non-systemd or test environment: perform direct extraction
                $this->extractPackage($packagePath, $this->appDir);
                $success = true;
            }
        } catch (\Throwable $e) {
            $errorMsg = $e->getMessage();
            $this->audit?->log('UPDATE_FAILED', "Update application failed: {$errorMsg}. Initiating rollback.");

            // Rollback to previous version
            try {
                $this->rollback($backupPath);
            } catch (\Throwable $rbErr) {
                $this->audit?->log('ROLLBACK_FAILED', "Critical: Rollback also encountered an error: " . $rbErr->getMessage());
            }

            throw new RuntimeException("Update failed and previous version ({$previousVersion}) was restored: {$errorMsg}");
        }

        // 4. Verify Application Health after update
        $healthCheck = $this->verifyHealth();
        if (!$healthCheck['healthy']) {
            $reason = $healthCheck['error'] ?? 'Health check failed after update';
            $this->audit?->log('UPDATE_FAILED', "Post-update health check failed: {$reason}. Initiating rollback.");

            try {
                $this->rollback($backupPath);
            } catch (\Throwable $rbErr) {
                $this->audit?->log('ROLLBACK_FAILED', "Critical: Rollback error: " . $rbErr->getMessage());
            }

            throw new RuntimeException("Updated application failed health verification ({$reason}). Restored {$previousVersion}.");
        }

        $newVersion = $this->getCurrentVersion();
        $this->audit?->log('UPDATE_SUCCESS', "Application updated successfully: {$previousVersion} -> {$newVersion}");

        return [
            'success' => true,
            'previous_version' => $previousVersion,
            'new_version' => $newVersion,
        ];
    }

    /**
     * Rollback to a previous backup archive.
     */
    public function rollback(string $backupPath): void
    {
        if (!file_exists($backupPath)) {
            throw new RuntimeException("Cannot rollback: backup file not found at $backupPath");
        }

        if (file_exists($this->helperBin) && is_executable($this->helperBin)) {
            $cmdParts = [];
            if (function_exists('posix_getuid') && posix_getuid() !== 0) {
                $cmdParts[] = 'sudo -n';
            }
            $cmdParts[] = escapeshellcmd($this->helperBin);
            $cmdParts[] = 'rollback-update';
            $cmdParts[] = escapeshellarg($backupPath);

            $fullCmd = implode(' ', $cmdParts) . ' 2>&1';
            exec($fullCmd, $output, $exitCode);
            if ($exitCode !== 0) {
                throw new RuntimeException("Helper rollback-update failed with code $exitCode: " . implode("\n", $output));
            }
        } else {
            $this->extractPackage($backupPath, $this->appDir);
        }

        $this->audit?->log('UPDATE_ROLLBACK', "Successfully restored application from backup: $backupPath");
    }

    /**
     * Validate that an update package contains required application files.
     */
    public function validatePackage(string $packagePath): void
    {
        if (class_exists('ZipArchive') && str_ends_with($packagePath, '.zip')) {
            $zip = new ZipArchive();
            if ($zip->open($packagePath) !== true) {
                throw new RuntimeException("Cannot open update zip package.");
            }

            $hasBootstrap = false;
            $hasPublic = false;

            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                $name = $stat['name'] ?? '';

                // Reject path traversal attempts inside the zip
                if (str_contains($name, '../') || str_contains($name, '..\\')) {
                    $zip->close();
                    throw new RuntimeException("Security violation: path traversal detected in update package ('$name').");
                }

                if (str_contains($name, 'bootstrap.php')) {
                    $hasBootstrap = true;
                }
                if (str_contains($name, 'public/') || str_contains($name, 'public\\')) {
                    $hasPublic = true;
                }
            }
            $zip->close();

            if (!$hasBootstrap && !$hasPublic) {
                throw new RuntimeException("Invalid update package: missing essential application files.");
            }
        }
    }

    private function extractPackage(string $packagePath, string $destDir): void
    {
        if (class_exists('ZipArchive') && str_ends_with($packagePath, '.zip')) {
            $zip = new ZipArchive();
            if ($zip->open($packagePath) === true) {
                $zip->extractTo($destDir);
                $zip->close();
                return;
            }
        }

        // Fallback for tar.gz or CLI unzip
        if (str_ends_with($packagePath, '.tar.gz') || str_ends_with($packagePath, '.tgz')) {
            $cmd = sprintf('tar -xzf %s -C %s 2>&1', escapeshellarg($packagePath), escapeshellarg($destDir));
            exec($cmd, $out, $ret);
            if ($ret !== 0) {
                throw new RuntimeException("Failed to extract tarball update: " . implode("\n", $out));
            }
            return;
        }

        throw new RuntimeException("Unable to extract update package: unsupported format or missing ZipArchive.");
    }

    /**
     * Verify that the application is functional and healthy after an update.
     */
    public function verifyHealth(): array
    {
        // 1. Check bootstrap and core autoloading
        if (!file_exists($this->appDir . '/src/bootstrap.php')) {
            return ['healthy' => false, 'error' => 'Core bootstrap.php is missing.'];
        }

        // 2. Check Database connectivity
        try {
            $stmt = $this->db->query("SELECT count(*) FROM settings");
            $stmt->fetchColumn();
        } catch (\Throwable $e) {
            return ['healthy' => false, 'error' => 'Database query check failed: ' . $e->getMessage()];
        }

        // 3. Check App class
        try {
            $v = App::getVersion();
            if (empty($v)) {
                return ['healthy' => false, 'error' => 'Version check returned empty string.'];
            }
        } catch (\Throwable $e) {
            return ['healthy' => false, 'error' => 'Application version check threw: ' . $e->getMessage()];
        }

        return ['healthy' => true, 'version' => App::getVersion(), 'error' => null];
    }
}
