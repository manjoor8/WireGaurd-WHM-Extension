<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/bootstrap.php';

use WireGuardManager\App;
use WireGuardManager\AuthService;
use WireGuardManager\Update\UpdateService;
use WireGuardManager\Database;
use WireGuardManager\Security;

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

AuthService::requireAuth();

$db = Database::getConnection();
$updateService = new UpdateService($db);

$action = (string)($_POST['action'] ?? $_GET['action'] ?? 'check');

try {
    switch ($action) {
        case 'check':
            $result = $updateService->checkForUpdates();
            echo json_encode(App::formatSuccess([
                'update_available' => $result['available'],
                'current_version' => $result['current_version'],
                'latest_version' => $result['latest_version'],
                'update' => $result['update'] ? $result['update']->toArray() : null,
            ]));
            break;

        case 'update':
            // Verify CSRF for mutating POST
            if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
                http_response_code(405);
                echo json_encode(App::formatError('METHOD_NOT_ALLOWED', 'POST method required for update.', null, false));
                exit;
            }

            // Check if update is available
            $check = $updateService->checkForUpdates();
            if (!$check['available'] || $check['update'] === null) {
                echo json_encode(App::formatSuccess([
                    'updated' => false,
                    'message' => 'The application is already running the latest version (' . $updateService->getCurrentVersion() . ').',
                ]));
                exit;
            }

            // 1. Download update package
            $packageFile = $updateService->downloadUpdate($check['update']);

            // 2. Apply update safely with automatic backup & rollback
            $applyRes = $updateService->applyUpdate($packageFile);

            echo json_encode(App::formatSuccess([
                'updated' => true,
                'previous_version' => $applyRes['previous_version'],
                'new_version' => $applyRes['new_version'],
            ], 'Application updated successfully.'));
            break;

        case 'status':
            echo json_encode(App::formatSuccess([
                'current_version' => $updateService->getCurrentVersion(),
                'build' => App::getBuild(),
                'runtime' => App::getRuntime(),
                'os' => App::getOperatingSystem(),
            ]));
            break;

        default:
            http_response_code(400);
            echo json_encode(App::formatError('INVALID_ACTION', "Unsupported update action '$action'.", null, false));
            break;
    }
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(App::formatError(
        'UPDATE_ERROR',
        'Update operation failed: ' . $e->getMessage(),
        $e->getMessage(),
        true
    ));
}
