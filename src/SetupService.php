<?php
declare(strict_types=1);

namespace WireGuardManager;

use PDO;
use RuntimeException;
use InvalidArgumentException;

/**
 * Manages first-run lifecycle detection, initialization sequence, and setup wizard workflows.
 */
class SetupService
{
    public const STAGE_PASSWORD = 'password';
    public const STAGE_WIREGUARD = 'wireguard';
    public const STAGE_VPN_CONFIG = 'vpn_config';
    public const STAGE_READY = 'ready';

    private PDO $db;
    private WireGuardService $wg;
    private ConfigService $config;
    private SystemService $system;
    private AuditService $audit;

    public function __construct(
        ?PDO $db = null,
        ?WireGuardService $wg = null,
        ?ConfigService $config = null,
        ?SystemService $system = null
    ) {
        $this->db = $db ?? Database::getConnection();
        $this->wg = $wg ?? new WireGuardService();
        $this->config = $config ?? new ConfigService($this->db);
        $this->system = $system ?? new SystemService();
        $this->audit = new AuditService($this->db);
    }

    /**
     * Determine the current setup stage based on actual system and DB state.
     */
    public function getCurrentStage(): string
    {
        // 1. If administrator password does not exist -> Step 1
        if (!AuthService::hasPassword()) {
            return self::STAGE_PASSWORD;
        }

        // 2. If WireGuard utility is not installed -> Step 2
        if (!$this->wg->isInstalled()) {
            return self::STAGE_WIREGUARD;
        }

        // 3. If WireGuard is installed but VPN interface is not configured or running -> Step 3
        $iface = $this->config->get('interface', 'wg0');
        $status = $this->wg->getStatus();
        if (empty($status['interface_up']) && !$this->wg->isInterfaceConfigured($iface)) {
            return self::STAGE_VPN_CONFIG;
        }

        return self::STAGE_READY;
    }

    public function isSetupComplete(): bool
    {
        return $this->getCurrentStage() === self::STAGE_READY;
    }

    /**
     * Create the initial administrator password (Step 1).
     *
     * @return string|null Error string or null on success
     */
    public function createAdminPassword(string $password, string $confirm): ?string
    {
        if (AuthService::hasPassword()) {
            return 'Administrator password is already configured.';
        }

        if ($password !== $confirm) {
            return 'Passwords do not match.';
        }

        $valErr = AuthService::validateNewPassword($password);
        if ($valErr !== null) {
            return $valErr;
        }

        AuthService::setPassword($password);

        // Sign the administrator into the current session immediately
        Session::regenerate();
        $_SESSION['authenticated'] = true;
        $_SESSION['auth_time'] = time();
        $_SESSION['last_activity'] = time();
        $_SESSION['credential_fp'] = hash('sha256', (string)AuthService::getHash());
        Security::rotateCsrfToken();

        $this->audit->log('SETUP_ADMIN_PASSWORD', 'Initial administrator password created via Setup Wizard');
        return null;
    }

    /**
     * Install WireGuard on the host system (Step 2).
     */
    public function installWireGuard(): array
    {
        $this->audit->log('SETUP_WG_INSTALL', 'WireGuard installation requested via Setup Wizard');

        try {
            $result = $this->wg->installWireGuard();
            $this->audit->log('SETUP_WG_INSTALLED', 'WireGuard installed successfully');
            return [
                'success' => true,
                'message' => $result['message'] ?? 'WireGuard installed successfully.',
                'version' => $result['version'] ?? 'unknown',
            ];
        } catch (\Throwable $e) {
            $this->audit->log('SETUP_WG_INSTALL_FAILED', 'WireGuard installation failed: ' . $e->getMessage());
            return App::formatError(
                'WIREGUARD_INSTALL_FAILED',
                'WireGuard could not be installed automatically.',
                $e->getMessage(),
                true
            );
        }
    }

    /**
     * Configure VPN interface and start WireGuard (Step 3).
     */
    public function configureVpn(array $params = []): array
    {
        $iface = trim((string)($params['interface'] ?? $this->config->get('interface', 'wg0')));
        $vpnIp = trim((string)($params['server_vpn_ip'] ?? $this->config->get('server_vpn_ip', '10.50.0.1')));
        $listenPort = (int)($params['listen_port'] ?? $this->config->get('listen_port', '51820'));
        $dns = trim((string)($params['dns'] ?? $this->config->get('dns', '1.1.1.1')));
        $endpoint = trim((string)($params['vpn_endpoint'] ?? ''));

        // Validate interface name
        if (!preg_match('/^[a-zA-Z0-9_-]{1,15}$/', $iface)) {
            return App::formatError('INVALID_INTERFACE', 'Invalid interface name.', null, false);
        }

        // Validate VPN IP
        if ($vpnIp !== '10.50.0.1') {
            return App::formatError('INVALID_IP', 'Server VPN IP must be 10.50.0.1 in network architecture.', null, false);
        }

        // Validate listen port
        if ($listenPort < 1024 || $listenPort > 65535) {
            return App::formatError('INVALID_PORT', 'WireGuard listen port must be between 1024 and 65535.', null, false);
        }

        $this->audit->log('SETUP_VPN_CONFIG', "Configuring VPN interface $iface on $vpnIp:$listenPort");

        try {
            // 1. Enable IPv4 packet forwarding
            $this->system->enableSysctl();

            // 2. Detect egress WAN interface
            $egressIface = $this->system->detectEgressInterface();

            // 3. Create interface configuration and generate server keys
            $createRes = $this->wg->createInterface($iface, $vpnIp, $listenPort, null, $egressIface);

            // 4. Configure firewall
            $this->system->configureFirewall($listenPort, $iface);

            // 5. Start and enable WireGuard interface service
            $this->wg->startInterface($iface);

            // 6. Save configuration to settings
            $this->config->set('interface', $iface);
            $this->config->set('server_vpn_ip', $vpnIp);
            $this->config->set('listen_port', (string)$listenPort);
            $this->config->set('dns', $dns);

            if (!empty($endpoint)) {
                $this->config->set('vpn_endpoint', $endpoint);
            } else {
                $detected = $this->config->detectServerPublicIp();
                if ($detected) {
                    $this->config->set('vpn_endpoint', $detected);
                }
            }

            $this->audit->log('SETUP_VPN_COMPLETE', "VPN interface $iface configured and started successfully");

            return App::formatSuccess([
                'interface' => $iface,
                'vpn_ip' => $vpnIp,
                'listen_port' => $listenPort,
                'public_key' => $createRes['public_key'] ?? '',
            ], 'WireGuard VPN interface configured and started successfully.');

        } catch (\Throwable $e) {
            $this->audit->log('SETUP_VPN_FAILED', 'VPN configuration failed: ' . $e->getMessage());
            return App::formatError(
                'VPN_CONFIG_FAILED',
                'Failed to configure or start WireGuard VPN.',
                $e->getMessage(),
                true
            );
        }
    }
}
