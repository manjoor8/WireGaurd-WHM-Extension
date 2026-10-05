<?php
declare(strict_types=1);

// bootstrap enforces Host allow-list, hardened session and CSRF on POST
require_once dirname(__DIR__) . '/src/bootstrap.php';

use WireGuardManager\AuthService;
use WireGuardManager\ConfigService;
use WireGuardManager\AuditService;
use WireGuardManager\Database;
use WireGuardManager\Session;

AuthService::requireAuth();

$db = Database::getConnection();
$configService = new ConfigService($db);
$audit = new AuditService($db);

$settingsErrors = [];
$passwordErrors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? 'update_settings');

    if ($action === 'change_password') {
        $current = (string)($_POST['current_password'] ?? '');
        $new = (string)($_POST['new_password'] ?? '');
        $confirm = (string)($_POST['confirm_password'] ?? '');

        $err = AuthService::changePassword($current, $new, $confirm);
        if ($err === null) {
            Session::flash('success', 'Administrator password changed successfully.');
            header('Location: /settings.php');
            exit;
        } else {
            $passwordErrors[] = $err;
        }
    } else {
        $result = $configService->updateSettings($_POST);
        if ($result['success']) {
            $audit->log('UPDATE_SETTINGS', 'Settings updated');
            Session::flash('success', 'Settings saved successfully.');
            header('Location: /settings.php');
            exit;
        } else {
            $settingsErrors = $result['errors'];
        }
    }
}

$settings = $configService->getAll();
$pageTitle = 'Settings';
$activeNav = 'settings';

require dirname(__DIR__) . '/templates/header.php';
require dirname(__DIR__) . '/templates/settings.php';
require dirname(__DIR__) . '/templates/footer.php';
