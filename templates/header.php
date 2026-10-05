<?php
/**
 * @var string $pageTitle
 * @var string $activeNav
 */
$pageTitle = $pageTitle ?? 'WireGuard VPN Manager';
$activeNav = $activeNav ?? 'dashboard';

$flashSuccess = $_SESSION['flash_success'] ?? $_GET['msg'] ?? null;
unset($_SESSION['flash_success']);

$flashError = $_SESSION['flash_error'] ?? $_GET['error'] ?? null;
unset($_SESSION['flash_error']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= h($pageTitle) ?> - WireGuard Manager</title>
    <link rel="stylesheet" href="/assets/css/app.css">
</head>
<body>
    <header class="app-header">
        <div class="header-container">
            <div class="header-top">
                <div class="header-brand">
                    <a href="/index.php" class="brand-link">
                        <svg class="brand-icon" viewBox="0 0 24 24" width="24" height="24" stroke="currentColor" stroke-width="2" fill="none" stroke-linecap="round" stroke-linejoin="round">
                            <rect x="2" y="2" width="20" height="8" rx="2" ry="2"></rect>
                            <rect x="2" y="14" width="20" height="8" rx="2" ry="2"></rect>
                            <line x1="6" y1="6" x2="6.01" y2="6"></line>
                            <line x1="6" y1="18" x2="6.01" y2="18"></line>
                        </svg>
                        <span class="brand-title">WireGuard Manager</span>
                    </a>
                    <span class="badge badge-server">10.50.0.1:5050 &bull; wg0</span>
                </div>
                <button type="button" class="nav-toggle" id="navToggle" aria-label="Toggle navigation menu" aria-expanded="false">
                    <span class="nav-toggle-bar"></span>
                    <span class="nav-toggle-bar"></span>
                    <span class="nav-toggle-bar"></span>
                </button>
            </div>
            <nav class="app-nav" id="appNav">
                <a href="/index.php" class="nav-item <?= $activeNav === 'dashboard' ? 'active' : '' ?>">Dashboard</a>
                <a href="/clients.php" class="nav-item <?= $activeNav === 'clients' ? 'active' : '' ?>">Clients</a>
                <a href="/add-client.php" class="nav-item <?= $activeNav === 'add-client' ? 'active' : '' ?>">Add Client</a>
                <a href="/settings.php" class="nav-item <?= $activeNav === 'settings' ? 'active' : '' ?>">Settings</a>
                <a href="/logs.php" class="nav-item <?= $activeNav === 'logs' ? 'active' : '' ?>">Audit Logs</a>
                <a href="/logout.php" class="nav-item nav-item-logout" title="Sign out of WireGuard Manager">Logout</a>
            </nav>
        </div>
    </header>

    <main class="app-main">
        <div class="main-container">
            <?php if (!empty($flashSuccess)): ?>
                <div class="alert alert-success">
                    <span class="alert-icon">&#x2714;</span>
                    <span class="alert-text"><?= h($flashSuccess) ?></span>
                </div>
            <?php endif; ?>

            <?php if (!empty($flashError)): ?>
                <div class="alert alert-danger">
                    <span class="alert-icon">&#x26A0;</span>
                    <span class="alert-text"><?= h($flashError) ?></span>
                </div>
            <?php endif; ?>
