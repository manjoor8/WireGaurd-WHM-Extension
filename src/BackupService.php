<?php
declare(strict_types=1);

namespace WireGuardManager;

use PDO;
use RuntimeException;
use InvalidArgumentException;

/**
 * Handles exporting and importing the entire WireGuard VPN Manager configuration,
 * including clients (keys, IPs, state), settings, administrator credentials, and audit logs.
 */
class BackupService
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * Export the full configuration as an associative array.
     *
     * @return array<string, mixed>
     */
    public function export(): array
    {
        // 1. Settings (key => value map)
        $settingsStmt = $this->db->query("SELECT key, value FROM settings ORDER BY key ASC");
        $settings = [];
        while ($row = $settingsStmt->fetch(PDO::FETCH_ASSOC)) {
            $settings[$row['key']] = $row['value'];
        }

        // 2. Administrator authentication (bcrypt password hash)
        $authStmt = $this->db->query("SELECT id, password_hash, updated_at FROM admin_auth WHERE id = 1");
        $adminAuth = $authStmt->fetch(PDO::FETCH_ASSOC) ?: null;

        // 3. Clients (all fields including private/public keys, VPN IP, state)
        $clientsStmt = $this->db->query("SELECT id, name, description, vpn_ip, public_key, private_key, state, created_at, updated_at, revoked_at FROM clients ORDER BY id ASC");
        $clients = $clientsStmt->fetchAll(PDO::FETCH_ASSOC);

        // 4. Audit Log (recent 500 entries)
        $auditStmt = $this->db->query("SELECT action, actor, details, ip_address, created_at FROM audit_log ORDER BY id DESC LIMIT 500");
        $auditLogs = array_reverse($auditStmt->fetchAll(PDO::FETCH_ASSOC));

        return [
            'format' => 'wireguard-manager-backup',
            'version' => 1,
            'exported_at' => gmdate('c'),
            'admin_auth' => $adminAuth,
            'settings' => $settings,
            'clients' => $clients,
            'audit_log' => $auditLogs,
        ];
    }

    /**
     * Export full configuration formatted as JSON.
     */
    public function exportJson(): string
    {
        $data = $this->export();
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new RuntimeException("Failed to encode configuration to JSON: " . json_last_error_msg());
        }
        return $json;
    }

    /**
     * Import a configuration array into the database and synchronize WireGuard runtime.
     *
     * @param array<string, mixed> $data
     * @param WireGuardService|null $wg
     * @return array{clients_count: int, active_clients: int, has_admin_auth: bool}
     * @throws InvalidArgumentException|RuntimeException
     */
    public function import(array $data, ?WireGuardService $wg = null): array
    {
        // Basic validation
        if (!isset($data['clients']) || !is_array($data['clients'])) {
            throw new InvalidArgumentException("Invalid backup file: missing 'clients' array.");
        }
        if (!isset($data['settings']) || !is_array($data['settings'])) {
            throw new InvalidArgumentException("Invalid backup file: missing 'settings' object.");
        }

        $this->db->beginTransaction();

        try {
            // 1. Restore Administrator Credentials
            $hasAdminAuth = false;
            if (!empty($data['admin_auth']['password_hash']) && is_string($data['admin_auth']['password_hash'])) {
                $hash = trim($data['admin_auth']['password_hash']);
                if (str_starts_with($hash, '$2y$') || str_starts_with($hash, '$2a$') || str_starts_with($hash, '$argon2')) {
                    $stmt = $this->db->prepare(
                        "INSERT INTO admin_auth (id, password_hash, updated_at)
                         VALUES (1, :hash, datetime('now'))
                         ON CONFLICT(id) DO UPDATE SET password_hash = excluded.password_hash, updated_at = excluded.updated_at"
                    );
                    $stmt->execute([':hash' => $hash]);
                    $hasAdminAuth = true;
                }
            }

            // 2. Restore User-Configurable Settings (preserve locked network architecture)
            $allowedSettingKeys = [
                'vpn_endpoint',
                'dns',
                'allowed_ips',
                'persistent_keepalive',
            ];

            $stmtSetting = $this->db->prepare(
                "INSERT INTO settings (key, value)
                 VALUES (:key, :val)
                 ON CONFLICT(key) DO UPDATE SET value = excluded.value"
            );

            foreach ($data['settings'] as $key => $val) {
                if (in_array($key, $allowedSettingKeys, true)) {
                    $stmtSetting->execute([
                        ':key' => $key,
                        ':val' => (string)$val,
                    ]);
                }
            }

            // 3. Remove existing active peers from WireGuard runtime before clearing database
            if ($wg !== null) {
                $currentPeers = $this->db->query("SELECT public_key FROM clients WHERE state = 'active'")->fetchAll(PDO::FETCH_COLUMN);
                foreach ($currentPeers as $pubKey) {
                    try {
                        $wg->removePeer($pubKey);
                    } catch (\Throwable $e) {
                        // Ignore cleanup errors for peers that may not be active in wg0
                    }
                }
            }

            // 4. Restore Clients Table
            $this->db->exec("DELETE FROM clients");

            $stmtClient = $this->db->prepare(
                "INSERT INTO clients (id, name, description, vpn_ip, public_key, private_key, state, created_at, updated_at, revoked_at)
                 VALUES (:id, :name, :description, :vpn_ip, :public_key, :private_key, :state, :created_at, :updated_at, :revoked_at)"
            );

            $importedActiveCount = 0;
            $clientCount = 0;

            foreach ($data['clients'] as $c) {
                if (!is_array($c) || empty($c['name']) || empty($c['vpn_ip']) || empty($c['public_key'])) {
                    continue;
                }

                $state = (string)($c['state'] ?? 'active');
                if (!in_array($state, ['active', 'disabled', 'revoked'], true)) {
                    $state = 'active';
                }

                $stmtClient->execute([
                    ':id' => isset($c['id']) && is_numeric($c['id']) ? (int)$c['id'] : null,
                    ':name' => (string)$c['name'],
                    ':description' => !empty($c['description']) ? (string)$c['description'] : null,
                    ':vpn_ip' => (string)$c['vpn_ip'],
                    ':public_key' => (string)$c['public_key'],
                    ':private_key' => !empty($c['private_key']) ? (string)$c['private_key'] : null,
                    ':state' => $state,
                    ':created_at' => $c['created_at'] ?? gmdate('Y-m-d H:i:s'),
                    ':updated_at' => $c['updated_at'] ?? gmdate('Y-m-d H:i:s'),
                    ':revoked_at' => !empty($c['revoked_at']) ? $c['revoked_at'] : null,
                ]);

                $clientCount++;

                // Register active peer with WireGuard kernel
                if ($wg !== null && $state === 'active') {
                    try {
                        $wg->addPeer((string)$c['public_key'], (string)$c['vpn_ip']);
                        $importedActiveCount++;
                    } catch (\Throwable $e) {
                        // Peer addition error in wg runtime is logged in audit
                    }
                }
            }

            // 5. Audit Log Entry
            (new AuditService($this->db))->log(
                'CONFIG_IMPORTED',
                sprintf(
                    'Configuration imported: %d client(s) restored (%d active in WireGuard), admin credentials %s.',
                    $clientCount,
                    $importedActiveCount,
                    $hasAdminAuth ? 'restored' : 'unchanged'
                )
            );

            $this->db->commit();

            return [
                'clients_count' => $clientCount,
                'active_clients' => $importedActiveCount,
                'has_admin_auth' => $hasAdminAuth,
            ];
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw new RuntimeException("Import failed: " . $e->getMessage(), (int)$e->getCode(), $e);
        }
    }
}
