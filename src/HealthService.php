<?php
declare(strict_types=1);

namespace WireGuardManager;

use PDO;

/**
 * Evaluates holistic system and application health for Dashboard and Setup.
 */
class HealthService
{
    private PDO $db;
    private WireGuardService $wg;
    private ConfigService $config;
    private SystemService $system;

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
    }

    /**
     * Get complete structured system health report.
     *
     * @return array
     */
    public function getSystemHealth(): array
    {
        $appVersion = App::getVersion();
        $hasAuth = AuthService::hasPassword();

        // WireGuard installation & module check
        $wgInfo = $this->wg->getInstallationInfo();
        $wgInstalled = !empty($wgInfo['installed']);
        $wgVersion = !empty($wgInfo['version']) ? $wgInfo['version'] : null;

        // Interface and Service check
        $iface = $this->config->get('interface', 'wg0');
        $port = (int)$this->config->get('listen_port', '51820');
        $vpnIp = $this->config->get('server_vpn_ip', '10.50.0.1');

        $ifaceDetails = $this->wg->getInterfaceDetails($iface);
        $ifaceConfigured = !empty($ifaceDetails['conf_exists']) || !empty($ifaceDetails['exists']);
        
        $runtimeStatus = $this->wg->getStatus();
        $vpnRunning = !empty($runtimeStatus['interface_up']);

        // Firewall status check
        $fw = $this->system->checkFirewall($port);
        $firewallConfigured = !empty($fw['port_open']) || empty($fw['firewall_active']);

        // Sysctl forwarding
        $sysctl = $this->system->checkSysctl();
        $forwardingEnabled = ((string)($sysctl['ip_forward'] ?? '0')) === '1';

        $pendingSteps = [];
        if (!$hasAuth) {
            $pendingSteps[] = 'auth';
        }
        if (!$wgInstalled) {
            $pendingSteps[] = 'wireguard';
        }
        if (!$ifaceConfigured) {
            $pendingSteps[] = 'interface';
        }
        if ($ifaceConfigured && !$vpnRunning) {
            $pendingSteps[] = 'vpn_service';
        }
        if (!$firewallConfigured) {
            $pendingSteps[] = 'firewall';
        }

        $isReady = empty($pendingSteps);

        return [
            'overall' => [
                'is_ready' => $isReady,
                'status' => $isReady ? 'ready' : 'action_required',
                'pending_steps' => $pendingSteps,
            ],
            'components' => [
                'application' => [
                    'title' => 'Application',
                    'status' => 'ready',
                    'version' => $appVersion,
                    'build' => App::getBuild(),
                    'runtime' => App::getRuntime(),
                    'os' => App::getOperatingSystem(),
                ],
                'authentication' => [
                    'title' => 'Administrator Authentication',
                    'status' => $hasAuth ? 'ready' : 'action_required',
                    'configured' => $hasAuth,
                    'description' => $hasAuth ? 'Configured' : 'Not configured',
                ],
                'wireguard' => [
                    'title' => 'WireGuard',
                    'status' => $wgInstalled ? 'ready' : 'action_required',
                    'installed' => $wgInstalled,
                    'version' => $wgVersion ?: 'Not installed',
                    'path' => $wgInfo['path'] ?? '',
                ],
                'interface' => [
                    'title' => 'VPN Interface',
                    'status' => $ifaceConfigured ? 'ready' : 'action_required',
                    'configured' => $ifaceConfigured,
                    'name' => $iface,
                    'address' => $vpnIp,
                    'listen_port' => $port,
                    'description' => $ifaceConfigured ? "$iface ($vpnIp)" : 'Not configured',
                ],
                'service' => [
                    'title' => 'VPN Service',
                    'status' => $vpnRunning ? 'running' : 'stopped',
                    'running' => $vpnRunning,
                    'description' => $vpnRunning ? 'Running' : ($ifaceConfigured ? 'Stopped' : 'Not configured'),
                ],
                'firewall' => [
                    'title' => 'Firewall',
                    'status' => $firewallConfigured ? 'ready' : 'action_required',
                    'configured' => $firewallConfigured,
                    'port' => $port,
                    'forwarding' => $forwardingEnabled,
                    'description' => $firewallConfigured ? "UDP $port Allowed" : 'Action required',
                ],
            ],
        ];
    }
}
