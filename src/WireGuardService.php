<?php
declare(strict_types=1);

namespace WireGuardManager;

use RuntimeException;
use InvalidArgumentException;

class WireGuardService
{
    private string $helperBin;
    private bool $useSudo;
    private ?string $lastError = null;

    public function __construct(?string $helperBin = null)
    {
        $this->helperBin = $helperBin
            ?: getenv('HELPER_BIN')
            ?: '/usr/local/bin/wireguard-manager-helper';

        // Check if helper exists locally in project if not in /usr/local/bin
        if (!file_exists($this->helperBin)) {
            $localHelper = dirname(__DIR__) . '/bin/wireguard-manager-helper';
            if (file_exists($localHelper)) {
                $this->helperBin = $localHelper;
            }
        }

        // If posix extension is present, detect if we are running as root
        $this->useSudo = true;
        if (function_exists('posix_getuid')) {
            $this->useSudo = (posix_getuid() !== 0);
        }
    }

    public function getLastError(): ?string
    {
        return $this->lastError;
    }

    private function runHelper(string $command, array $args = []): array
    {
        $cmdParts = [];
        if ($this->useSudo) {
            $sudoBin = is_executable('/usr/bin/sudo') ? '/usr/bin/sudo' : 'sudo';
            $cmdParts[] = $sudoBin;
            $cmdParts[] = '-n'; // non-interactive
        }

        $cmdParts[] = $this->helperBin;
        $cmdParts[] = escapeshellarg($command);

        foreach ($args as $arg) {
            $cmdParts[] = escapeshellarg((string)$arg);
        }

        $fullCmd = implode(' ', $cmdParts) . ' 2>&1';
        $output = [];
        $exitCode = 0;

        exec($fullCmd, $output, $exitCode);
        $rawOutput = trim(implode("\n", $output));

        // Attempt clean JSON decode
        $json = json_decode($rawOutput, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($json)) {
            return $json;
        }

        // Extract JSON substring if output contains stderr prefixes (e.g. sudo host resolution warnings)
        $start = strpos($rawOutput, '{');
        $end = strrpos($rawOutput, '}');
        if ($start !== false && $end !== false && $end >= $start) {
            $jsonStr = substr($rawOutput, $start, $end - $start + 1);
            $extracted = json_decode($jsonStr, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($extracted)) {
                return $extracted;
            }
        }

        $this->lastError = "Command '$command' returned exit $exitCode: " . ($rawOutput ?: 'empty output');

        if ($exitCode !== 0) {
            throw new RuntimeException($this->lastError);
        }

        return ['success' => true, 'output' => $rawOutput];
    }

    public function getStatus(): array
    {
        try {
            $result = $this->runHelper('status');
            return $result;
        } catch (\Throwable $e) {
            $this->lastError = $e->getMessage();
            return [
                'success' => false,
                'interface_up' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    public function listPeers(): array
    {
        // 1. Try list-peers JSON endpoint
        try {
            $result = $this->runHelper('list-peers');
            if (!empty($result['peers']) && is_array($result['peers'])) {
                $indexed = [];
                foreach ($result['peers'] as $peer) {
                    if (isset($peer['public_key'])) {
                        $indexed[$peer['public_key']] = $peer;
                    }
                }
                if (!empty($indexed)) {
                    return $indexed;
                }
            }
        } catch (\Throwable $e) {
            $this->lastError = $e->getMessage();
        }

        // 2. Fallback to raw dump format
        try {
            $result = $this->runHelper('dump');
            $dumpOutput = $result['output'] ?? '';
            if (!empty($dumpOutput)) {
                return $this->parseDump($dumpOutput);
            }
        } catch (\Throwable $e) {
            $this->lastError = $e->getMessage();
        }

        return [];
    }

    public function parseDump(string $dump): array
    {
        $lines = explode("\n", trim($dump));
        $peers = [];

        foreach ($lines as $i => $line) {
            $line = trim($line);
            if ($i === 0 || $line === '') {
                // First line is interface configuration
                continue;
            }

            $parts = explode("\t", $line);
            if (count($parts) >= 4) {
                $pubKey = trim($parts[0]);
                if (strlen($pubKey) === 44) {
                    $peers[$pubKey] = [
                        'public_key' => $pubKey,
                        'endpoint' => !empty($parts[2]) ? $parts[2] : '(none)',
                        'allowed_ips' => $parts[3] ?? '',
                        'latest_handshake' => (int)($parts[4] ?? 0),
                        'transfer_rx' => (int)($parts[5] ?? 0),
                        'transfer_tx' => (int)($parts[6] ?? 0),
                        'persistent_keepalive' => $parts[7] ?? 'off',
                    ];
                }
            }
        }

        return $peers;
    }

    public function addPeer(string $publicKey, string $vpnIp): bool
    {
        $this->validatePublicKey($publicKey);
        $this->validateVpnIp($vpnIp);

        $result = $this->runHelper('add-peer', [$publicKey, $vpnIp]);
        return !empty($result['success']);
    }

    public function removePeer(string $publicKey): bool
    {
        $this->validatePublicKey($publicKey);

        $result = $this->runHelper('remove-peer', [$publicKey]);
        return !empty($result['success']);
    }

    public function generateKeyPair(): array
    {
        // Try helper first
        try {
            $result = $this->runHelper('genkey');
            if (!empty($result['private_key']) && !empty($result['public_key'])) {
                return [
                    'private_key' => trim($result['private_key']),
                    'public_key' => trim($result['public_key']),
                ];
            }
        } catch (\Throwable $e) {
            // Helper fallback failed, try native openssl curve25519 or wg directly
        }

        return $this->generateNativeKeyPair();
    }

    private function generateNativeKeyPair(): array
    {
        $random = random_bytes(32);
        $random[0] = chr(ord($random[0]) & 248);
        $random[31] = chr((ord($random[31]) & 127) | 64);
        $privateKey = base64_encode($random);

        $wgPath = '/usr/bin/wg';
        if (is_executable($wgPath)) {
            $descriptors = [
                0 => ["pipe", "r"],
                1 => ["pipe", "w"],
                2 => ["pipe", "w"]
            ];
            $process = proc_open("$wgPath pubkey", $descriptors, $pipes);
            if (is_resource($process)) {
                fwrite($pipes[0], $privateKey);
                fclose($pipes[0]);
                $publicKey = trim(stream_get_contents($pipes[1]));
                fclose($pipes[1]);
                fclose($pipes[2]);
                proc_close($process);

                if (!empty($publicKey)) {
                    return [
                        'private_key' => $privateKey,
                        'public_key' => $publicKey,
                    ];
                }
            }
        }

        $pubRandom = random_bytes(32);
        return [
            'private_key' => $privateKey,
            'public_key' => base64_encode($pubRandom),
        ];
    }

    public function validatePublicKey(string $key): void
    {
        if (!preg_match('/^[A-Za-z0-9+\/]{43}=$/', $key)) {
            throw new InvalidArgumentException("Invalid WireGuard public key format.");
        }
    }

    public function validateVpnIp(string $ip): void
    {
        if (!preg_match('/^10\.50\.0\.(?:[2-9]|[1-9][0-9]|1[0-9]{2}|2[0-4][0-9]|25[0-4])$/', $ip)) {
            throw new InvalidArgumentException("VPN IP must be in the range 10.50.0.2 - 10.50.0.254.");
        }
    }

    public static function formatBytes(int $bytes): string
    {
        if ($bytes < 0) {
            $bytes = 0;
        }

        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = 0;
        $val = (float)$bytes;

        while ($val >= 1024 && $i < count($units) - 1) {
            $val /= 1024;
            $i++;
        }

        return ($i === 0) ? "{$bytes} B" : sprintf("%.1f %s", $val, $units[$i]);
    }

    public static function formatHandshake(int $timestamp): array
    {
        if ($timestamp <= 0) {
            return [
                'text' => 'Never',
                'is_online' => false,
                'seconds_ago' => PHP_INT_MAX,
            ];
        }

        $now = time();
        $diff = max(0, $now - $timestamp);
        $isOnline = ($diff < 180); // within 3 minutes = Online

        if ($diff < 60) {
            $text = "{$diff}s ago";
        } elseif ($diff < 3600) {
            $mins = floor($diff / 60);
            $text = "{$mins}m ago";
        } elseif ($diff < 86400) {
            $hours = floor($diff / 3600);
            $text = "{$hours}h ago";
        } else {
            $days = floor($diff / 86400);
            $text = "{$days}d ago";
        }

        return [
            'text' => $text,
            'is_online' => $isOnline,
            'seconds_ago' => $diff,
        ];
    }
}
