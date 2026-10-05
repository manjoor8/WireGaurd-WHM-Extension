<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/bootstrap.php';

use WireGuardManager\Database;
use WireGuardManager\ConfigService;
use WireGuardManager\AuditService;
use WireGuardManager\ClientService;
use WireGuardManager\WireGuardService;

function assertTest(bool $condition, string $testName): void {
    if (!$condition) {
        echo "[FAIL] $testName\n";
        exit(1);
    }
    echo "[PASS] $testName\n";
}

// Create a mock WireGuardService for testing database logic without root/helper
class MockWireGuardService extends WireGuardService {
    public array $activePeers = [];

    public function __construct() {}

    public function getStatus(): array {
        return [
            'success' => true,
            'interface_up' => true,
            'interface' => 'wg0',
            'public_key' => 'c2VydmVyUHVibGljS2V5Rm9yVGVzdGluZzEyMzQ1Njc=',
            'listen_port' => '51820',
        ];
    }

    public function listPeers(): array {
        return $this->activePeers;
    }

    public function addPeer(string $publicKey, string $vpnIp): bool {
        $this->validatePublicKey($publicKey);
        $this->validateVpnIp($vpnIp);
        $this->activePeers[$publicKey] = [
            'public_key' => $publicKey,
            'endpoint' => '192.168.1.100:54321',
            'allowed_ips' => "$vpnIp/32",
            'latest_handshake' => time() - 10,
            'transfer_rx' => 10240,
            'transfer_tx' => 20480,
            'persistent_keepalive' => '25',
        ];
        return true;
    }

    public function removePeer(string $publicKey): bool {
        $this->validatePublicKey($publicKey);
        unset($this->activePeers[$publicKey]);
        return true;
    }

    public function generateKeyPair(): array {
        $priv = base64_encode(random_bytes(32));
        $pub = base64_encode(random_bytes(32));
        return ['private_key' => $priv, 'public_key' => $pub];
    }
}

// Use a temporary SQLite database
$tempDbPath = sys_get_temp_dir() . '/wg_test_' . uniqid() . '.db';
Database::setPath($tempDbPath);
$pdo = Database::getConnection();

// Test 1: Verify tables exist
$tables = $pdo->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(PDO::FETCH_COLUMN);
assertTest(in_array('clients', $tables), 'Table clients exists');
assertTest(in_array('settings', $tables), 'Table settings exists');
assertTest(in_array('audit_log', $tables), 'Table audit_log exists');
assertTest(in_array('admin_auth', $tables), 'Table admin_auth exists');
assertTest(in_array('login_attempts', $tables), 'Table login_attempts exists');

// Test 2: Verify default settings
$configService = new ConfigService($pdo);
$settings = $configService->getAll();
assertTest($settings['interface'] === 'wg0', 'Default interface is wg0');
assertTest($settings['server_vpn_ip'] === '10.50.0.1', 'Default server IP is 10.50.0.1');
assertTest($settings['management_host'] === '10.50.0.1', 'Management host is locked to 10.50.0.1');
assertTest($settings['management_port'] === '5050', 'Management port is locked to 5050');

// Test 3: Updating settings
$updRes = $configService->updateSettings([
    'dns' => '8.8.8.8, 8.8.4.4',
    'persistent_keepalive' => '30',
    'vpn_endpoint' => 'vpn.mydomain.com',
    'allowed_ips' => '0.0.0.0/0',
]);
assertTest($updRes['success'] === true, 'Settings update valid input accepted');
assertTest($configService->get('dns') === '8.8.8.8, 8.8.4.4', 'DNS setting updated');
assertTest($configService->get('persistent_keepalive') === '30', 'Keepalive setting updated');

// Test 4: Setting lock immunity
$caughtLock = false;
try {
    $configService->set('management_host', '0.0.0.0');
} catch (\InvalidArgumentException $e) {
    $caughtLock = true;
}
assertTest($caughtLock, 'Attempt to change management_host to 0.0.0.0 threw exception');

// Test 5: Client creation and IP auto-allocation
$mockWg = new MockWireGuardService();
$audit = new AuditService($pdo);
$clientService = new ClientService($pdo, $mockWg, $configService, $audit);

$c1 = $clientService->createClient('alice-laptop', 'Alice work machine');
assertTest($c1['vpn_ip'] === '10.50.0.2', 'First client allocated 10.50.0.2');
assertTest($c1['state'] === 'active', 'First client state is active');
assertTest(!empty($c1['config']), 'Configuration generated for first client');

$c2 = $clientService->createClient('bob-phone', 'Bob mobile');
assertTest($c2['vpn_ip'] === '10.50.0.3', 'Second client allocated 10.50.0.3');

// Test 6: Disable, disconnect, and re-enable client
$clientService->disconnectClient((int)$c1['id']);
$c1Reload = $clientService->getClient((int)$c1['id']);
assertTest($c1Reload['state'] === 'disabled', 'Client state updated to disconnected (disabled)');
assertTest(!isset($mockWg->activePeers[$c1['public_key']]), 'Disconnected peer removed from WireGuard runtime');

$clientService->enableClient((int)$c1['id']);
$c1Reload2 = $clientService->getClient((int)$c1['id']);
assertTest($c1Reload2['state'] === 'active', 'Client state re-enabled to active');
assertTest(isset($mockWg->activePeers[$c1['public_key']]), 'Enabled peer restored in WireGuard runtime');

// Test 6b: Reset session (Kick) on active client
$clientService->resetSession((int)$c1['id']);
assertTest(isset($mockWg->activePeers[$c1['public_key']]), 'Peer active in WireGuard after session reset');

// Test 7: Revoke client
$clientService->revokeClient((int)$c1['id']);
$c1Revoked = $clientService->getClient((int)$c1['id']);
assertTest($c1Revoked['state'] === 'revoked', 'Client state updated to revoked');
assertTest(!isset($mockWg->activePeers[$c1['public_key']]), 'Revoked peer removed from WireGuard runtime');
assertTest($c1Revoked['private_key'] === null, 'Revoked client private key wiped');

// Test 8: Prevent re-enabling revoked client
$caughtRevokedReenable = false;
try {
    $clientService->enableClient((int)$c1['id']);
} catch (\RuntimeException $e) {
    $caughtRevokedReenable = true;
}
assertTest($caughtRevokedReenable, 'Re-enabling revoked client throws exception');

// Test 9: Audit log recorded actions
$logs = $audit->getRecentLogs(10);
assertTest(count($logs) >= 4, 'Audit logs recorded');
$actions = array_column($logs, 'action');
assertTest(in_array('CREATE_CLIENT', $actions), 'Audit log contains CREATE_CLIENT');
assertTest(in_array('DISABLE_CLIENT', $actions), 'Audit log contains DISABLE_CLIENT');
assertTest(in_array('ENABLE_CLIENT', $actions), 'Audit log contains ENABLE_CLIENT');
assertTest(in_array('REVOKE_CLIENT', $actions), 'Audit log contains REVOKE_CLIENT');

// Test 10: AuthService password verification & lifecycle
use WireGuardManager\AuthService;
assertTest(!AuthService::hasPassword(), 'AuthService initially has no password configured');
assertTest(!AuthService::verifyPassword('TestPassword123!'), 'verifyPassword returns false when no password configured');

// Validate password rules
assertTest(AuthService::validateNewPassword('short') !== null, 'Password under 12 characters is rejected');
assertTest(AuthService::validateNewPassword('ValidPasswordWithMoreThan12Chars!') === null, 'Valid password is accepted');

// Set password
AuthService::setPassword('ValidPasswordWithMoreThan12Chars!');
assertTest(AuthService::hasPassword(), 'AuthService reports password is now configured');
assertTest(AuthService::verifyPassword('ValidPasswordWithMoreThan12Chars!'), 'verifyPassword accepts correct password');
assertTest(!AuthService::verifyPassword('WrongPassword123!'), 'verifyPassword rejects wrong password');

// Test lockout reset
AuthService::resetLockouts();
assertTest(AuthService::lockoutRemaining('127.0.0.1') === 0, 'No lockout remaining after reset');

// Test 11: Key generation ordering & validation
$realWg = new WireGuardService();
if (function_exists('sodium_crypto_scalarmult_base')) {
    $keys = $realWg->generateKeyPair();
    assertTest(preg_match('/^[A-Za-z0-9+\/]{43}=$/', $keys['private_key']) === 1, 'Built-in crypto generated valid private key');
    assertTest(preg_match('/^[A-Za-z0-9+\/]{43}=$/', $keys['public_key']) === 1, 'Built-in crypto generated valid public key');
}

// Test 12: BackupService export and import
use WireGuardManager\BackupService;
$backup = new BackupService($pdo);
$exported = $backup->export();
assertTest($exported['format'] === 'wireguard-manager-backup', 'Backup format header is correct');
assertTest(isset($exported['settings']['dns']), 'Exported settings contain DNS');
assertTest(count($exported['clients']) >= 1, 'Exported clients contain at least one client');
assertTest(!empty($exported['admin_auth']['password_hash']), 'Exported admin_auth contains password hash');

$json = $backup->exportJson();
$decoded = json_decode($json, true);
assertTest(is_array($decoded) && $decoded['format'] === 'wireguard-manager-backup', 'Exported JSON is valid and decodable');

// Test import
$importResult = $backup->import($decoded, $mockWg);
assertTest($importResult['clients_count'] >= 1, 'Import restored client count');
assertTest(AuthService::verifyPassword('ValidPasswordWithMoreThan12Chars!'), 'Password hash remained valid after import');

// Clean up temp DB
@unlink($tempDbPath);

echo "\nAll database, lifecycle & authentication tests passed successfully!\n";
