<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/autoload.php';

use WireGuardManager\App;
use WireGuardManager\AuthService;
use WireGuardManager\SetupService;
use WireGuardManager\HealthService;
use WireGuardManager\WireGuardService;
use WireGuardManager\ConfigService;
use WireGuardManager\ClientService;
use WireGuardManager\Database;

$passed = 0;
$failed = 0;

function it(string $desc, bool $condition): void {
    global $passed, $failed;
    if ($condition) {
        echo "[PASS] $desc\n";
        $passed++;
    } else {
        echo "[FAIL] $desc\n";
        $failed++;
    }
}

echo "=== Running In-App Lifecycle & Setup Wizard Tests ===\n\n";

// Use isolated temporary SQLite database
$testDb = sys_get_temp_dir() . '/wgm_lifecycle_test_' . uniqid() . '.db';
Database::setPath($testDb);
$db = Database::getConnection();

// Mock WireGuardService for simulation
class MockWireGuardService extends WireGuardService
{
    public bool $mockInstalled = false;
    public bool $mockInterfaceConfigured = false;
    public bool $mockInterfaceUp = false;
    public array $createdInterfaces = [];

    public function __construct()
    {
        parent::__construct('/bin/true');
    }

    public function isInstalled(): bool
    {
        return $this->mockInstalled;
    }

    public function getInstallationInfo(): array
    {
        return [
            'success' => true,
            'installed' => $this->mockInstalled,
            'version' => $this->mockInstalled ? 'wireguard-tools v1.0.20210914' : '',
            'path' => $this->mockInstalled ? '/usr/bin/wg' : '',
        ];
    }

    public function installWireGuard(): array
    {
        $this->mockInstalled = true;
        return [
            'success' => true,
            'message' => 'WireGuard installed successfully',
            'version' => 'wireguard-tools v1.0.20210914',
        ];
    }

    public function isInterfaceConfigured(string $iface = 'wg0'): bool
    {
        return $this->mockInterfaceConfigured;
    }

    public function getInterfaceDetails(string $iface = 'wg0'): array
    {
        return [
            'success' => true,
            'interface' => $iface,
            'exists' => $this->mockInterfaceConfigured,
            'is_up' => $this->mockInterfaceUp,
            'conf_exists' => $this->mockInterfaceConfigured,
        ];
    }

    public function createInterface(
        string $iface = 'wg0',
        string $vpnIp = '10.50.0.1',
        int $listenPort = 51820,
        ?string $serverPrivateKey = null,
        ?string $egressIface = null
    ): array {
        $this->mockInterfaceConfigured = true;
        $keypair = $this->generateKeyPair();
        $this->createdInterfaces[$iface] = [
            'vpn_ip' => $vpnIp,
            'listen_port' => $listenPort,
            'public_key' => $keypair['public_key'],
        ];
        return [
            'success' => true,
            'interface' => $iface,
            'public_key' => $keypair['public_key'],
            'listen_port' => (string)$listenPort,
        ];
    }

    public function startInterface(string $iface = 'wg0'): array
    {
        if (!$this->mockInterfaceConfigured) {
            throw new \RuntimeException("Cannot start unconfigured interface $iface");
        }
        $this->mockInterfaceUp = true;
        return ['success' => true, 'interface' => $iface];
    }

    public function getStatus(): array
    {
        if ($this->mockInterfaceUp) {
            return [
                'success' => true,
                'interface_up' => true,
                'interface' => 'wg0',
                'public_key' => 'MockServerPublicKey123456789012345678901234=',
                'listen_port' => '51820',
            ];
        }
        return [
            'success' => false,
            'interface_up' => false,
            'interface' => 'wg0',
            'error' => 'Interface wg0 is not active',
        ];
    }

    public function addPeer(string $publicKey, string $vpnIp): bool
    {
        return true;
    }

    public function removePeer(string $publicKey): bool
    {
        return true;
    }
}

$mockWg = new MockWireGuardService();
$config = new ConfigService($db);
$setup = new SetupService($db, $mockWg, $config);
$health = new HealthService($db, $mockWg, $config);

// ---------------------------------------------------------
// Scenario 1: Fresh Installation Experience
// ---------------------------------------------------------

// Stage 1: Password required
it('Fresh install starts at STAGE_PASSWORD', $setup->getCurrentStage() === SetupService::STAGE_PASSWORD);
it('Setup reports not complete initially', $setup->isSetupComplete() === false);

// Weak passwords rejected
$errShort = $setup->createAdminPassword('short', 'short');
it('Rejects password under 12 chars', $errShort !== null && str_contains($errShort, '12'));

$errMismatch = $setup->createAdminPassword('StrongPassword123!', 'MismatchPassword123!');
it('Rejects mismatched password confirmation', $errMismatch !== null && str_contains($errMismatch, 'match'));

// Valid password accepted
$errValid = $setup->createAdminPassword('AdminSecretPassword2026!', 'AdminSecretPassword2026!');
it('Accepts valid strong admin password', $errValid === null);
it('AuthService reports password is now set', AuthService::hasPassword() === true);
it('Cannot create second admin password once set', $setup->createAdminPassword('AnotherPass1234!', 'AnotherPass1234!') !== null);

// Stage 2: WireGuard Installation required
it('Transitions to STAGE_WIREGUARD after password creation', $setup->getCurrentStage() === SetupService::STAGE_WIREGUARD);

// Install WireGuard
$installRes = $setup->installWireGuard();
it('WireGuard installation succeeds', $installRes['success'] === true);
it('WireGuard is now reported installed', $mockWg->isInstalled() === true);

// Stage 3: VPN Configuration required
it('Transitions to STAGE_VPN_CONFIG after WireGuard installation', $setup->getCurrentStage() === SetupService::STAGE_VPN_CONFIG);

// Test parameter validation on configureVpn
$badPort = $setup->configureVpn(['listen_port' => 80]); // Port < 1024
it('Rejects privileged listen port < 1024', $badPort['success'] === false);

$badIp = $setup->configureVpn(['server_vpn_ip' => '192.168.1.1']);
it('Rejects non-10.50.0.1 server address', $badIp['success'] === false);

// Valid VPN configuration
$vpnRes = $setup->configureVpn([
    'interface' => 'wg0',
    'server_vpn_ip' => '10.50.0.1',
    'listen_port' => 51820,
    'dns' => '1.1.1.1, 8.8.8.8',
    'vpn_endpoint' => 'vpn.example.com',
]);
it('Valid VPN configuration succeeds', $vpnRes['success'] === true);
it('VPN interface is created', $mockWg->isInterfaceConfigured('wg0') === true);
it('VPN service is running', $mockWg->mockInterfaceUp === true);

// Stage 4: System Ready
it('Transitions to STAGE_READY after VPN configuration', $setup->getCurrentStage() === SetupService::STAGE_READY);
it('Setup reports complete', $setup->isSetupComplete() === true);

// Verify HealthService report
$healthData = $health->getSystemHealth();
it('HealthService overall is ready', $healthData['overall']['is_ready'] === true);
it('HealthService authentication is ready', $healthData['components']['authentication']['status'] === 'ready');
it('HealthService wireguard is ready', $healthData['components']['wireguard']['status'] === 'ready');
it('HealthService interface is ready', $healthData['components']['interface']['status'] === 'ready');
it('HealthService service is running', $healthData['components']['service']['status'] === 'running');

// ---------------------------------------------------------
// Scenario 2: Existing WireGuard Installation
// ---------------------------------------------------------
$mockWg2 = new MockWireGuardService();
$mockWg2->mockInstalled = true; // WireGuard is already present
$setup2 = new SetupService($db, $mockWg2, $config);

it('Does not prompt for WireGuard install if already present', $setup2->getCurrentStage() !== SetupService::STAGE_WIREGUARD);

// ---------------------------------------------------------
// Scenario 3: Existing VPN Configuration Preservation
// ---------------------------------------------------------
$clientService = new ClientService($db, $mockWg, $config);
$client = $clientService->createClient('TestClient', 'Test Device');
it('Can create client on configured system', !empty($client['id']));

// Verify configuration and clients are preserved
$clients = $clientService->listClients();
it('Clients preserved in database', count($clients) === 1);
it('Settings preserved in database', $config->get('dns') === '1.1.1.1, 8.8.8.8');
it('Endpoint preserved in database', $config->get('vpn_endpoint') === 'vpn.example.com');

@unlink($testDb);

echo "\nSummary: $passed passed, $failed failed.\n";
if ($failed > 0) {
    exit(1);
}
