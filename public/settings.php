<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    @session_start();
}

require_once dirname(__DIR__) . '/src/bootstrap.php';

use WireGuardManager\AuthService;
use WireGuardManager\ConfigService;
use WireGuardManager\AuditService;
use WireGuardManager\Database;

AuthService::requireAuth();

$csrfToken = $_SESSION['csrf_token'] ?? '';

$db = Database::getConnection();
$configService = new ConfigService($db);
$audit = new AuditService($db);

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $result = $configService->updateSettings($_POST);
    if ($result['success']) {
        $audit->log('UPDATE_SETTINGS', 'Settings updated');
        $_SESSION['flash_success'] = 'Settings saved successfully.';
        header('Location: /settings.php?msg=' . urlencode('Settings saved successfully.'));
        exit;
    } else {
        $errors = $result['errors'];
    }
}

$settings = $configService->getAll();
$pageTitle = 'Settings';
$activeNav = 'settings';

require dirname(__DIR__) . '/templates/header.php';
require dirname(__DIR__) . '/templates/settings.php';
require dirname(__DIR__) . '/templates/footer.php';
