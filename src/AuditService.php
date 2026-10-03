<?php
declare(strict_types=1);

namespace WireGuardManager;

use PDO;

class AuditService
{
    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::getConnection();
    }

    public function log(string $action, ?string $details = null, string $actor = 'local'): void
    {
        // Strip any accidental private key patterns before logging
        if ($details !== null) {
            $details = preg_replace('/PrivateKey\s*=\s*[A-Za-z0-9+\/=]+/i', 'PrivateKey = [REDACTED]', $details);
        }

        $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';

        $stmt = $this->db->prepare(
            "INSERT INTO audit_log (action, actor, details, ip_address, created_at)
             VALUES (:action, :actor, :details, :ip, datetime('now'))"
        );

        $stmt->execute([
            ':action' => $action,
            ':actor' => $actor,
            ':details' => $details,
            ':ip' => $ip,
        ]);
    }

    public function getRecentLogs(int $limit = 100): array
    {
        $stmt = $this->db->prepare(
            "SELECT id, action, actor, details, ip_address, created_at
             FROM audit_log
             ORDER BY id DESC
             LIMIT :limit"
        );
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }
}
