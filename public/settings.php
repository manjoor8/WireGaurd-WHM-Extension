<?php
declare(strict_types=1);

session_start();

require_once dirname(__DIR__) . '/src/bootstrap.php';

use WireGuardManager\ConfigService;
use WireGuardManager\AuditService;
use WireGuardManager\Database;

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];

$db = Database::getConnection();
$configService = new ConfigService($db);
$audit = new AuditService($db);

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
        $errors[] = 'Invalid or expired CSRF token.';
    }

    if (empty($errors)) {
        $result = $configService->updateSettings($_POST);
        if ($result['success']) {
            $audit->log('UPDATE_SETTINGS', 'Settings updated');
            $_SESSION['flash_success'] = 'Settings saved successfully.';
            header('Location: /settings.php');
            exit;
        } else {
            $errors = $result['errors'];
        }
    }
}

$settings = $configService->getAll();
$pageTitle = 'Settings';
$activeNav = 'settings';

require dirname(__DIR__) . '/templates/header.php';
require dirname(__DIR__) . '/templates/settings.php';
require dirname(__DIR__) . '/templates/footer.php';
