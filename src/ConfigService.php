<?php
declare(strict_types=1);

namespace WireGuardManager;

use PDO;
use InvalidArgumentException;

class ConfigService
{
    private PDO $db;

    private const LOCKED_SETTINGS = [
        'management_host' => '10.50.0.1',
        'management_port' => '5050',
    ];

    private const DEFAULTS = [
        'interface' => 'wg0',
        'vpn_network' => '10.50.0.0/24',
        'server_vpn_ip' => '10.50.0.1',
        'listen_port' => '51820',
        'management_host' => '10.50.0.1',
        'management_port' => '5050',
        'dns' => '1.1.1.1',
        'allowed_ips' => '0.0.0.0/0',
        'persistent_keepalive' => '25',
        'vpn_endpoint' => '',
    ];

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::getConnection();
    }

    public function getAll(): array
    {
        $stmt = $this->db->query("SELECT key, value FROM settings");
        $results = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

        $merged = array_merge(self::DEFAULTS, $results);
        // Ensure locked settings cannot be overridden
        foreach (self::LOCKED_SETTINGS as $k => $v) {
            $merged[$k] = $v;
        }

        return $merged;
    }

    public function get(string $key, ?string $default = null): ?string
    {
        if (isset(self::LOCKED_SETTINGS[$key])) {
            return self::LOCKED_SETTINGS[$key];
        }

        $stmt = $this->db->prepare("SELECT value FROM settings WHERE key = :key LIMIT 1");
        $stmt->execute([':key' => $key]);
        $val = $stmt->fetchColumn();

        if ($val !== false) {
            return (string)$val;
        }

        return self::DEFAULTS[$key] ?? $default;
    }

    public function set(string $key, string $value): void
    {
        if (isset(self::LOCKED_SETTINGS[$key])) {
            throw new InvalidArgumentException("Setting '$key' is immutable in V1 network isolation architecture.");
        }

        $stmt = $this->db->prepare(
            "INSERT INTO settings (key, value) VALUES (:key, :value)
             ON CONFLICT(key) DO UPDATE SET value = :value_update"
        );
        $stmt->execute([
            ':key' => $key,
            ':value' => trim($value),
            ':value_update' => trim($value),
        ]);
    }

    public function updateSettings(array $input): array
    {
        $errors = [];

        // Validate DNS
        if (isset($input['dns'])) {
            $dnsList = array_map('trim', explode(',', $input['dns']));
            foreach ($dnsList as $dns) {
                if (!filter_var($dns, FILTER_VALIDATE_IP)) {
                    $errors[] = "Invalid DNS server IP: " . htmlspecialchars($dns);
                }
            }
        }

        // Validate PersistentKeepalive
        if (isset($input['persistent_keepalive'])) {
            $keepalive = filter_var($input['persistent_keepalive'], FILTER_VALIDATE_INT, [
                'options' => ['min_range' => 0, 'max_range' => 3600]
            ]);
            if ($keepalive === false) {
                $errors[] = "Persistent keepalive must be an integer between 0 and 3600 seconds.";
            }
        }

        // Validate VPN Endpoint
        if (!empty($input['vpn_endpoint'])) {
            $endpoint = trim($input['vpn_endpoint']);
            // Allow domain name or IP, optionally with :port
            if (!preg_match('/^([a-zA-Z0-9.-]+)(:\d{1,5})?$/', $endpoint)) {
                $errors[] = "VPN Endpoint must be a valid domain or IP (e.g. vpn.example.com or 203.0.113.5).";
            }
        }

        // Validate AllowedIPs
        if (isset($input['allowed_ips'])) {
            $allowedList = array_map('trim', explode(',', $input['allowed_ips']));
            foreach ($allowedList as $cidr) {
                if (!preg_match('/^([0-9]{1,3}\.){3}[0-9]{1,3}\/([0-9]|[1-2][0-9]|3[0-2])$/', $cidr)) {
                    $errors[] = "Invalid AllowedIPs CIDR format: " . htmlspecialchars($cidr);
                }
            }
        }

        if (!empty($errors)) {
            return ['success' => false, 'errors' => $errors];
        }

        // Apply permitted changes
        $allowedKeys = ['dns', 'persistent_keepalive', 'vpn_endpoint', 'allowed_ips'];
        foreach ($allowedKeys as $key) {
            if (isset($input[$key])) {
                $this->set($key, (string)$input[$key]);
            }
        }

        return ['success' => true, 'errors' => []];
    }
}
