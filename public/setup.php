<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/bootstrap.php';

use WireGuardManager\App;
use WireGuardManager\AuthService;
use WireGuardManager\SetupService;
use WireGuardManager\HealthService;
use WireGuardManager\WireGuardService;
use WireGuardManager\ConfigService;
use WireGuardManager\Database;
use WireGuardManager\Session;
use WireGuardManager\Security;

$db = Database::getConnection();
$setup = new SetupService($db);
$health = new HealthService($db);
$wg = new WireGuardService();
$configService = new ConfigService($db);

$stage = $setup->getCurrentStage();

// If setup is already complete and not explicitly inspecting status, go to dashboard
if ($setup->isSetupComplete() && empty($_GET['force'])) {
    if (AuthService::isAuthenticated()) {
        header('Location: /index.php');
        exit;
    }
}

// Authentication gate: Step 1 does not require auth (creates initial credentials).
// All subsequent steps require authentication.
if (AuthService::hasPassword() && !AuthService::isAuthenticated()) {
    header('Location: /login.php?return=' . urlencode('/setup.php'));
    exit;
}

$error = null;
$success = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'set_password') {
        $p1 = (string)($_POST['password'] ?? '');
        $p2 = (string)($_POST['confirm_password'] ?? '');

        $err = $setup->createAdminPassword($p1, $p2);
        if ($err !== null) {
            $error = $err;
        } else {
            Session::flash('success', 'Administrator password configured successfully.');
            header('Location: /setup.php?step=2');
            exit;
        }
    } elseif ($action === 'install_wireguard') {
        AuthService::requireAuth();
        $res = $setup->installWireGuard();
        if ($res['success']) {
            Session::flash('success', 'WireGuard installed successfully!');
            header('Location: /setup.php?step=3');
            exit;
        } else {
            $error = $res['message'] . (!empty($res['details']) ? ' (' . $res['details'] . ')' : '');
        }
    } elseif ($action === 'configure_vpn') {
        AuthService::requireAuth();
        $res = $setup->configureVpn($_POST);
        if ($res['success']) {
            Session::flash('success', 'WireGuard VPN successfully configured and running!');
            header('Location: /setup.php?step=4');
            exit;
        } else {
            $error = $res['message'] . (!empty($res['details']) ? ' (' . $res['details'] . ')' : '');
        }
    }
}

// Determine active step from GET or stage
$requestedStep = isset($_GET['step']) ? (int)$_GET['step'] : null;
$currentStep = 1;

if (!AuthService::hasPassword()) {
    $currentStep = 1;
} elseif (!$wg->isInstalled()) {
    $currentStep = 2;
} elseif (!$setup->isSetupComplete()) {
    $currentStep = 3;
} else {
    $currentStep = 4;
}

if ($requestedStep !== null && $requestedStep >= 1 && $requestedStep <= 4) {
    // Cannot skip ahead of prerequisite requirements
    if ($requestedStep > 1 && !AuthService::hasPassword()) {
        $currentStep = 1;
    } else {
        $currentStep = $requestedStep;
    }
}

$wgInfo = $wg->getInstallationInfo();
$systemHealth = $health->getSystemHealth();
$settings = $configService->getAll();
$detectedIp = $configService->detectServerPublicIp() ?: '10.50.0.1';

$csrfToken = Security::csrfToken();
$pageTitle = 'Setup Wizard';

require dirname(__DIR__) . '/templates/setup.php';
