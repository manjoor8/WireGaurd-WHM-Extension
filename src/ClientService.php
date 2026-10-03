<?php
declare(strict_types=1);

namespace WireGuardManager;

use PDO;
use RuntimeException;
use InvalidArgumentException;

class ClientService
{
    private PDO $db;
    private WireGuardService $wg;
    private ConfigService $config;
    private AuditService $audit;

    public function __construct(
        ?PDO $db = null,
        ?WireGuardService $wg = null,
        ?ConfigService $config = null,
        ?AuditService $audit = null
    ) {
        $this->db = $db ?? Database::getConnection();
        $this->wg = $wg ?? new WireGuardService();
        $this->config = $config ?? new ConfigService($this->db);
        $this->audit = $audit ?? new AuditService($this->db);
    }

    public function syncExistingPeers(): int
    {
        $livePeers = $this->wg->listPeers();
        if (empty($livePeers)) {
            return 0;
        }

        $stmt = $this->db->query("SELECT public_key, vpn_ip FROM clients");
        $existingKeys = [];
        $existingIps = [];
        while ($row = $stmt->fetch()) {
            $existingKeys[$row['public_key']] = true;
            $existingIps[$row['vpn_ip']] = true;
        }

        $imported = 0;
        foreach ($livePeers as $pubKey => $peer) {
            if (isset($existingKeys[$pubKey])) {
                continue;
            }

            // Extract VPN IP from allowed_ips (e.g. 10.50.0.2/32 or 10.50.0.2)
            $allowedIps = $peer['allowed_ips'] ?? '';
            $vpnIp = null;
            if (preg_match('/(10\.50\.0\.(?:[2-9]|[1-9][0-9]|1[0-9]{2}|2[0-4][0-9]|25[0-4]))/', $allowedIps, $matches)) {
                $candidateIp = $matches[1];
                if (!isset($existingIps[$candidateIp])) {
                    $vpnIp = $candidateIp;
                }
            }

            if (!$vpnIp) {
                try {
                    $vpnIp = $this->getNextAvailableIp();
                } catch (\Throwable $e) {
                    continue;
                }
            }

            $shortKey = substr($pubKey, 0, 8);
            $clientNum = str_replace('10.50.0.', '', $vpnIp);
            $clientName = "client-{$clientNum}";
            $description = "Auto-detected on wg0 (key: {$shortKey}...)";

            $insertStmt = $this->db->prepare(
                "INSERT INTO clients (name, description, vpn_ip, public_key, state, created_at, updated_at)
                 VALUES (:name, :desc, :ip, :key, 'active', datetime('now'), datetime('now'))"
            );
            $insertStmt->execute([
                ':name' => $clientName,
                ':desc' => $description,
                ':ip' => $vpnIp,
                ':key' => $pubKey,
            ]);

            $existingKeys[$pubKey] = true;
            $existingIps[$vpnIp] = true;
            $imported++;

            $this->audit->log('SYNC_PEER', "Imported existing WireGuard peer {$pubKey} as {$clientName} ({$vpnIp})");
        }

        return $imported;
    }

    public function listClients(): array
    {
        // Automatically sync any existing peers found in WireGuard runtime
        try {
            $this->syncExistingPeers();
        } catch (\Throwable $e) {
            // Non-blocking sync error
        }

        $stmt = $this->db->query(
            "SELECT id, name, description, vpn_ip, public_key, state, created_at, updated_at, revoked_at
             FROM clients
             ORDER BY id ASC"
        );
        $clients = $stmt->fetchAll();

        // Get runtime peers from WireGuard
        $livePeers = $this->wg->listPeers();

        foreach ($clients as &$client) {
            $pubKey = $client['public_key'];
            if ($client['state'] === 'active' && isset($livePeers[$pubKey])) {
                $peer = $livePeers[$pubKey];
                $handshake = (int)($peer['latest_handshake'] ?? 0);
                $hsInfo = WireGuardService::formatHandshake($handshake);

                $client['is_online'] = $hsInfo['is_online'];
                $client['handshake_text'] = $hsInfo['text'];
                $client['latest_handshake'] = $handshake;
                $client['transfer_rx'] = (int)($peer['transfer_rx'] ?? 0);
                $client['transfer_tx'] = (int)($peer['transfer_tx'] ?? 0);
                $client['rx_formatted'] = WireGuardService::formatBytes($client['transfer_rx']);
                $client['tx_formatted'] = WireGuardService::formatBytes($client['transfer_tx']);
                $client['endpoint'] = $peer['endpoint'] ?? '(none)';
            } else {
                $client['is_online'] = false;
                $client['handshake_text'] = 'Never';
                $client['latest_handshake'] = 0;
                $client['transfer_rx'] = 0;
                $client['transfer_tx'] = 0;
                $client['rx_formatted'] = '0 B';
                $client['tx_formatted'] = '0 B';
                $client['endpoint'] = '(none)';
            }
        }
        unset($client);

        return $clients;
    }

    public function getClient(int $id): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT id, name, description, vpn_ip, public_key, private_key, state, created_at, updated_at, revoked_at
             FROM clients
             WHERE id = :id LIMIT 1"
        );
        $stmt->execute([':id' => $id]);
        $client = $stmt->fetch();

        if (!$client) {
            return null;
        }

        $livePeers = $this->wg->listPeers();
        $pubKey = $client['public_key'];

        if ($client['state'] === 'active' && isset($livePeers[$pubKey])) {
            $peer = $livePeers[$pubKey];
            $handshake = (int)($peer['latest_handshake'] ?? 0);
            $hsInfo = WireGuardService::formatHandshake($handshake);

            $client['is_online'] = $hsInfo['is_online'];
            $client['handshake_text'] = $hsInfo['text'];
            $client['latest_handshake'] = $handshake;
            $client['transfer_rx'] = (int)($peer['transfer_rx'] ?? 0);
            $client['transfer_tx'] = (int)($peer['transfer_tx'] ?? 0);
            $client['rx_formatted'] = WireGuardService::formatBytes($client['transfer_rx']);
            $client['tx_formatted'] = WireGuardService::formatBytes($client['transfer_tx']);
            $client['endpoint'] = $peer['endpoint'] ?? '(none)';
        } else {
            $client['is_online'] = false;
            $client['handshake_text'] = 'Never';
            $client['latest_handshake'] = 0;
            $client['transfer_rx'] = 0;
            $client['transfer_tx'] = 0;
            $client['rx_formatted'] = '0 B';
            $client['tx_formatted'] = '0 B';
            $client['endpoint'] = '(none)';
        }

        return $client;
    }

    public function createClient(string $name, ?string $description = null, ?string $requestedIp = null): array
    {
        $name = trim($name);
        if ($name === '' || strlen($name) > 64) {
            throw new InvalidArgumentException("Client name must be between 1 and 64 characters.");
        }

        // Sanitize name for filenames/comments
        if (preg_match('/[^\w\s.-]/u', $name)) {
            throw new InvalidArgumentException("Client name contains invalid characters. Use letters, numbers, hyphens, and spaces.");
        }

        // Determine VPN IP
        if ($requestedIp !== null && trim($requestedIp) !== '') {
            $vpnIp = trim($requestedIp);
            $this->wg->validateVpnIp($vpnIp);
            if ($this->isIpAllocated($vpnIp)) {
                throw new InvalidArgumentException("VPN IP {$vpnIp} is already allocated.");
            }
        } else {
            $vpnIp = $this->getNextAvailableIp();
        }

        // Generate WireGuard key pair
        $keyPair = $this->wg->generateKeyPair();
        $privateKey = $keyPair['private_key'];
        $publicKey = $keyPair['public_key'];

        // Add peer to WireGuard runtime
        $added = $this->wg->addPeer($publicKey, $vpnIp);
        if (!$added) {
            throw new RuntimeException("Failed to add peer to WireGuard interface.");
        }

        // Insert client record into database
        $stmt = $this->db->prepare(
            "INSERT INTO clients (name, description, vpn_ip, public_key, private_key, state, created_at, updated_at)
             VALUES (:name, :description, :vpn_ip, :public_key, :private_key, 'active', datetime('now'), datetime('now'))"
        );

        $stmt->execute([
            ':name' => $name,
            ':description' => $description ? trim($description) : null,
            ':vpn_ip' => $vpnIp,
            ':public_key' => $publicKey,
            ':private_key' => $privateKey,
        ]);

        $clientId = (int)$this->db->lastInsertId();

        // Audit log
        $this->audit->log('CREATE_CLIENT', "Client '{$name}' created with IP {$vpnIp} (id: {$clientId})");

        $clientRecord = $this->getClient($clientId);
        $clientRecord['private_key'] = $privateKey;
        $clientRecord['config'] = $this->generateClientConfig($clientRecord);

        return $clientRecord;
    }

    public function disableClient(int $id): bool
    {
        $client = $this->getClient($id);
        if (!$client) {
            throw new InvalidArgumentException("Client not found.");
        }

        if ($client['state'] === 'revoked') {
            throw new RuntimeException("Cannot disable a revoked client.");
        }

        if ($client['state'] === 'disabled') {
            return true;
        }

        // Remove peer from active WireGuard interface
        $this->wg->removePeer($client['public_key']);

        $stmt = $this->db->prepare(
            "UPDATE clients SET state = 'disabled', updated_at = datetime('now') WHERE id = :id"
        );
        $stmt->execute([':id' => $id]);

        $this->audit->log('DISABLE_CLIENT', "Client '{$client['name']}' disabled (id: {$id})");
        return true;
    }

    public function enableClient(int $id): bool
    {
        $client = $this->getClient($id);
        if (!$client) {
            throw new InvalidArgumentException("Client not found.");
        }

        if ($client['state'] === 'revoked') {
            throw new RuntimeException("Cannot re-enable a revoked client.");
        }

        if ($client['state'] === 'active') {
            return true;
        }

        // Add peer back to active WireGuard interface
        $this->wg->addPeer($client['public_key'], $client['vpn_ip']);

        $stmt = $this->db->prepare(
            "UPDATE clients SET state = 'active', updated_at = datetime('now') WHERE id = :id"
        );
        $stmt->execute([':id' => $id]);

        $this->audit->log('ENABLE_CLIENT', "Client '{$client['name']}' enabled (id: {$id})");
        return true;
    }

    public function revokeClient(int $id): bool
    {
        $client = $this->getClient($id);
        if (!$client) {
            throw new InvalidArgumentException("Client not found.");
        }

        if ($client['state'] === 'revoked') {
            return true;
        }

        // Remove peer from active WireGuard interface
        try {
            $this->wg->removePeer($client['public_key']);
        } catch (\Throwable $e) {
            // Peer may already be removed if disabled
        }

        // Clear sensitive private key permanently and mark as revoked
        $stmt = $this->db->prepare(
            "UPDATE clients SET state = 'revoked', private_key = NULL, revoked_at = datetime('now'), updated_at = datetime('now') WHERE id = :id"
        );
        $stmt->execute([':id' => $id]);

        $this->audit->log('REVOKE_CLIENT', "Client '{$client['name']}' revoked (id: {$id})");
        return true;
    }

    public function generateClientConfig(array $client): string
    {
        $serverStatus = $this->wg->getStatus();
        $serverPubKey = $serverStatus['public_key'] ?? '';
        $listenPort = $this->config->get('listen_port', '51820');
        $endpoint = $this->config->get('vpn_endpoint', '');

        if (empty($endpoint)) {
            // Fall back to server management IP or prompt placeholder
            $endpoint = "SERVER_PUBLIC_IP:{$listenPort}";
        } elseif (!str_contains($endpoint, ':')) {
            $endpoint .= ":{$listenPort}";
        }

        $dns = $this->config->get('dns', '1.1.1.1');
        $allowedIps = $this->config->get('allowed_ips', '0.0.0.0/0');
        $keepalive = $this->config->get('persistent_keepalive', '25');

        $privateKey = !empty($client['private_key']) ? $client['private_key'] : '<CLIENT_PRIVATE_KEY>';
        $clientIp = $client['vpn_ip'];

        $config = <<<CONF
[Interface]
PrivateKey = {$privateKey}
Address = {$clientIp}/32
DNS = {$dns}

[Peer]
PublicKey = {$serverPubKey}
Endpoint = {$endpoint}
AllowedIPs = {$allowedIps}
PersistentKeepalive = {$keepalive}

CONF;

        return $config;
    }

    public function getNextAvailableIp(): string
    {
        // Network: 10.50.0.0/24. Valid range: 10.50.0.2 to 10.50.0.254.
        $stmt = $this->db->query("SELECT vpn_ip, state FROM clients");
        $rows = $stmt->fetchAll();

        $activeOrDisabledIps = [];
        $revokedIps = [];

        foreach ($rows as $row) {
            $ip = $row['vpn_ip'];
            if ($row['state'] === 'revoked') {
                $revokedIps[$ip] = true;
            } else {
                $activeOrDisabledIps[$ip] = true;
            }
        }

        // First pass: find lowest IP never used at all (not even revoked)
        for ($i = 2; $i <= 254; $i++) {
            $candidate = "10.50.0.{$i}";
            if (!isset($activeOrDisabledIps[$candidate]) && !isset($revokedIps[$candidate])) {
                return $candidate;
            }
        }

        // Second pass: if subnet is dense, reuse lowest revoked IP not active
        for ($i = 2; $i <= 254; $i++) {
            $candidate = "10.50.0.{$i}";
            if (!isset($activeOrDisabledIps[$candidate])) {
                return $candidate;
            }
        }

        throw new RuntimeException("No available VPN IP addresses left in 10.50.0.0/24 subnet.");
    }

    private function isIpAllocated(string $ip): bool
    {
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) FROM clients WHERE vpn_ip = :ip AND state IN ('active', 'disabled')"
        );
        $stmt->execute([':ip' => $ip]);
        return ((int)$stmt->fetchColumn()) > 0;
    }
}
