<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/bootstrap.php';

use WireGuardManager\AuthService;
use WireGuardManager\HealthService;
use WireGuardManager\Update\UpdateService;
use WireGuardManager\Database;

AuthService::requireAuth();

$db = Database::getConnection();
$healthService = new HealthService($db);
$updateService = new UpdateService($db);

$health = $healthService->getSystemHealth();
$updateInfo = $updateService->checkForUpdates();

$pageTitle = 'System Status';
$activeNav = 'status';

require dirname(__DIR__) . '/templates/header.php';
require dirname(__DIR__) . '/templates/status.php';
require dirname(__DIR__) . '/templates/footer.php';
