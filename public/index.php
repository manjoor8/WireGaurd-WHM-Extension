<?php
declare(strict_types=1);

session_start();

require_once dirname(__DIR__) . '/src/bootstrap.php';

use WireGuardManager\AuthService;
use WireGuardManager\WireGuardService;
use WireGuardManager\ConfigService;
use WireGuardManager\ClientService;
use WireGuardManager\Database;

AuthService::requireAuth();

try {
    $db = Database::getConnection();
    $wg = new WireGuardService();
    $configService = new ConfigService($db);
    $clientService = new ClientService($db, $wg, $configService);

    $status = $wg->getStatus();
    $config = $configService->getAll();
    $clients = $clientService->listClients();

    $activeClients = 0;
    $onlineClients = 0;
    $totalRx = 0;
    $totalTx = 0;

    foreach ($clients as $c) {
        if ($c['state'] === 'active') {
            $activeClients++;
            if (!empty($c['is_online'])) {
                $onlineClients++;
            }
            $totalRx += (int)($c['transfer_rx'] ?? 0);
            $totalTx += (int)($c['transfer_tx'] ?? 0);
        }
    }

    $metrics = [
        'active_clients' => $activeClients,
        'online_clients' => $onlineClients,
        'total_rx_formatted' => WireGuardService::formatBytes($totalRx),
        'total_tx_formatted' => WireGuardService::formatBytes($totalTx),
    ];

    $pageTitle = 'Dashboard';
    $activeNav = 'dashboard';

    require dirname(__DIR__) . '/templates/header.php';
    require dirname(__DIR__) . '/templates/dashboard.php';
    require dirname(__DIR__) . '/templates/footer.php';

} catch (\Throwable $e) {
    http_response_code(500);
    echo "<h1>Internal Application Error</h1>";
    echo "<p>" . htmlspecialchars($e->getMessage()) . "</p>";
}
