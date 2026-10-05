<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/bootstrap.php';

use WireGuardManager\App;
use WireGuardManager\AuthService;
use WireGuardManager\SetupService;
use WireGuardManager\HealthService;
use WireGuardManager\Security;
use WireGuardManager\Database;

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

$db = Database::getConnection();
$setup = new SetupService($db);
$health = new HealthService($db);

$action = (string)($_POST['action'] ?? $_GET['action'] ?? 'check');

try {
    switch ($action) {
        case 'check':
            $stage = $setup->getCurrentStage();
            $healthData = $health->getSystemHealth();
            echo json_encode([
                'success' => true,
                'stage' => $stage,
                'is_complete' => $setup->isSetupComplete(),
                'health' => $healthData,
            ]);
            break;

        case 'password':
            // Step 1: Create initial administrator password
            if (AuthService::hasPassword()) {
                http_response_code(400);
                echo json_encode(App::formatError('ALREADY_CONFIGURED', 'Administrator credentials are already configured.', null, false));
                exit;
            }

            $password = (string)($_POST['password'] ?? '');
            $confirm = (string)($_POST['confirm_password'] ?? '');

            $err = $setup->createAdminPassword($password, $confirm);
            if ($err !== null) {
                http_response_code(400);
                echo json_encode(App::formatError('INVALID_PASSWORD', $err, null, true));
                exit;
            }

            echo json_encode(App::formatSuccess([
                'stage' => $setup->getCurrentStage(),
            ], 'Administrator password configured successfully.'));
            break;

        case 'install_wireguard':
            // Step 2: Install WireGuard tools (requires authentication)
            if (!AuthService::isAuthenticated()) {
                http_response_code(401);
                echo json_encode(App::formatError('UNAUTHORIZED', 'Authentication required.', null, false));
                exit;
            }

            $res = $setup->installWireGuard();
            if (!$res['success']) {
                http_response_code(500);
            }
            echo json_encode($res);
            break;

        case 'configure_vpn':
            // Step 3: Configure VPN interface and start service (requires authentication)
            if (!AuthService::isAuthenticated()) {
                http_response_code(401);
                echo json_encode(App::formatError('UNAUTHORIZED', 'Authentication required.', null, false));
                exit;
            }

            $res = $setup->configureVpn($_POST);
            if (!$res['success']) {
                http_response_code(500);
            }
            echo json_encode($res);
            break;

        case 'health':
            if (!AuthService::isAuthenticated()) {
                http_response_code(401);
                echo json_encode(App::formatError('UNAUTHORIZED', 'Authentication required.', null, false));
                exit;
            }

            echo json_encode(App::formatSuccess([
                'health' => $health->getSystemHealth(),
            ]));
            break;

        default:
            http_response_code(400);
            echo json_encode(App::formatError('INVALID_ACTION', "Unsupported setup action '$action'.", null, false));
            break;
    }
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(App::formatError('SYSTEM_ERROR', 'An unexpected system error occurred.', $e->getMessage(), true));
}
