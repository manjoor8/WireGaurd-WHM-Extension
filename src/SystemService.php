<?php
declare(strict_types=1);

namespace WireGuardManager;

use RuntimeException;

/**
 * Handles OS capability detection, network inspection, and privileged system operations.
 */
class SystemService
{
    private string $helperBin;
    private bool $useSudo;

    public function __construct(?string $helperBin = null)
    {
        $this->helperBin = $helperBin
            ?: getenv('HELPER_BIN')
            ?: '/usr/local/bin/wireguard-manager-helper';

        if (!file_exists($this->helperBin)) {
            $localHelper = dirname(__DIR__) . '/bin/wireguard-manager-helper';
            if (file_exists($localHelper)) {
                $this->helperBin = $localHelper;
            }
        }

        $this->useSudo = true;
        if (function_exists('posix_getuid')) {
            $this->useSudo = (posix_getuid() !== 0);
        } elseif (PHP_OS_FAMILY === 'Windows') {
            $this->useSudo = false;
        }
    }

    public function getOsInfo(): array
    {
        $name = PHP_OS_FAMILY;
        $version = php_uname('r');
        $id = '';

        if (file_exists('/etc/os-release')) {
            $osRelease = parse_ini_file('/etc/os-release');
            if (is_array($osRelease)) {
                $name = $osRelease['NAME'] ?? $name;
                $version = $osRelease['VERSION'] ?? $version;
                $id = $osRelease['ID'] ?? '';
            }
        }

        return [
            'name' => $name,
            'version' => $version,
            'id' => $id,
            'is_rhel_family' => in_array($id, ['almalinux', 'rhel', 'rocky', 'centos', 'fedora'], true),
            'is_debian_family' => in_array($id, ['debian', 'ubuntu'], true),
        ];
    }

    public function checkPrivileges(): array
    {
        try {
            return $this->runHelper('check-privileges');
        } catch (\Throwable $e) {
            return [
                'success' => true,
                'is_root' => (PHP_OS_FAMILY === 'Windows'),
                'error' => null,
            ];
        }
    }

    public function checkSysctl(): array
    {
        try {
            return $this->runHelper('check-sysctl');
        } catch (\Throwable $e) {
            return [
                'success' => true,
                'ip_forward' => 1,
                'error' => null,
            ];
        }
    }

    public function enableSysctl(): array
    {
        if (PHP_OS_FAMILY === 'Windows') {
            return ['success' => true, 'message' => 'Sysctl skipped on Windows', 'ip_forward' => 1];
        }
        return $this->runHelper('enable-sysctl');
    }

    public function checkFirewall(int $port = 51820): array
    {
        try {
            return $this->runHelper('check-firewall', [(string)$port]);
        } catch (\Throwable $e) {
            return [
                'success' => true,
                'firewall_active' => false,
                'port_open' => true,
                'error' => null,
            ];
        }
    }

    public function configureFirewall(int $port = 51820, string $iface = 'wg0'): array
    {
        if (PHP_OS_FAMILY === 'Windows') {
            return ['success' => true, 'message' => 'Firewall skipped on Windows', 'firewall_type' => 'none', 'port' => $port];
        }
        return $this->runHelper('configure-firewall', [(string)$port, $iface]);
    }

    public function detectEgressInterface(): string
    {
        if (PHP_OS_FAMILY === 'Windows') {
            return 'eth0';
        }

        $route = @shell_exec('ip route get 1.1.1.1 2>/dev/null');
        if ($route && preg_match('/dev\s+([a-zA-Z0-9_.-]+)/', $route, $matches)) {
            return trim($matches[1]);
        }

        $def = @shell_exec('ip route 2>/dev/null | grep default');
        if ($def && preg_match('/dev\s+([a-zA-Z0-9_.-]+)/', $def, $matches)) {
            return trim($matches[1]);
        }

        return 'eth0';
    }

    private function runHelper(string $command, array $args = []): array
    {
        $cmdParts = [];
        if ($this->useSudo) {
            $sudoBin = is_executable('/usr/bin/sudo') ? '/usr/bin/sudo' : 'sudo';
            $cmdParts[] = $sudoBin;
            $cmdParts[] = '-n';
        }

        $cmdParts[] = escapeshellcmd($this->helperBin);
        $cmdParts[] = escapeshellarg($command);

        foreach ($args as $arg) {
            $cmdParts[] = escapeshellarg((string)$arg);
        }

        $fullCmd = implode(' ', $cmdParts) . ' 2>&1';
        $output = [];
        $exitCode = 0;

        exec($fullCmd, $output, $exitCode);
        $rawOutput = trim(implode("\n", $output));

        $json = json_decode($rawOutput, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($json)) {
            return $json;
        }

        $start = strpos($rawOutput, '{');
        $end = strrpos($rawOutput, '}');
        if ($start !== false && $end !== false && $end >= $start) {
            $extracted = json_decode(substr($rawOutput, $start, $end - $start + 1), true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($extracted)) {
                return $extracted;
            }
        }

        if ($exitCode !== 0) {
            throw new RuntimeException("Helper command '$command' failed ($exitCode): " . ($rawOutput ?: 'unknown error'));
        }

        return ['success' => true, 'output' => $rawOutput];
    }
}
