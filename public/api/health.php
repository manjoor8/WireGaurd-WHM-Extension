<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/bootstrap.php';

use WireGuardManager\App;
use WireGuardManager\AuthService;
use WireGuardManager\HealthService;
use WireGuardManager\Database;

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

AuthService::requireAuth();

$db = Database::getConnection();
$healthService = new HealthService($db);

echo json_encode(App::formatSuccess([
    'health' => $healthService->getSystemHealth(),
]));
