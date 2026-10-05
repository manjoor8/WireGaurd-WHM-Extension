<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    @session_start();
}

require_once dirname(__DIR__) . '/src/bootstrap.php';

use WireGuardManager\AuthService;
use WireGuardManager\WireGuardService;
use WireGuardManager\ConfigService;
use WireGuardManager\ClientService;
use WireGuardManager\Database;

AuthService::requireAuth();

$csrfToken = $_SESSION['csrf_token'] ?? '';

$db = Database::getConnection();
$wg = new WireGuardService();
$configService = new ConfigService($db);
$clientService = new ClientService($db, $wg, $configService);

$errors = [];
$formData = [];

try {
    $suggestedIp = $clientService->getNextAvailableIp();
} catch (\Throwable $e) {
    $suggestedIp = '10.50.0.2';
    $errors[] = $e->getMessage();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $vpnIp = trim($_POST['vpn_ip'] ?? '');

    $formData['name'] = $name;
    $formData['description'] = $description;
    $formData['vpn_ip'] = $vpnIp;

    if ($name === '') {
        $errors[] = 'Client name is required.';
    } elseif (strlen($name) > 64) {
        $errors[] = 'Client name cannot exceed 64 characters.';
    }

    if ($vpnIp === '') {
        $vpnIp = $suggestedIp;
    }

    if (empty($errors)) {
        try {
            $created = $clientService->createClient($name, $description ?: null, $vpnIp);
            $_SESSION['flash_success'] = "WireGuard client '{$name}' created successfully with IP {$created['vpn_ip']}!";
            header("Location: /client.php?id=" . (int)$created['id'] . "&msg=" . urlencode("Client '{$name}' created successfully."));
            exit;
        } catch (\Throwable $e) {
            $errors[] = "Error creating client: " . $e->getMessage();
        }
    }
}

$pageTitle = 'Add WireGuard Client';
$activeNav = 'add-client';

require dirname(__DIR__) . '/templates/header.php';
require dirname(__DIR__) . '/templates/add-client.php';
require dirname(__DIR__) . '/templates/footer.php';
