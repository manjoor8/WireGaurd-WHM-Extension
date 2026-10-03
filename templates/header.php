<?php
/**
 * @var string $pageTitle
 * @var string $activeNav
 */
$pageTitle = $pageTitle ?? 'WireGuard VPN Manager';
$activeNav = $activeNav ?? 'dashboard';
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
            <nav class="app-nav">
                <a href="/index.php" class="nav-item <?= $activeNav === 'dashboard' ? 'active' : '' ?>">Dashboard</a>
                <a href="/clients.php" class="nav-item <?= $activeNav === 'clients' ? 'active' : '' ?>">Clients</a>
                <a href="/add-client.php" class="nav-item <?= $activeNav === 'add-client' ? 'active' : '' ?>">Add Client</a>
                <a href="/settings.php" class="nav-item <?= $activeNav === 'settings' ? 'active' : '' ?>">Settings</a>
                <a href="/logs.php" class="nav-item <?= $activeNav === 'logs' ? 'active' : '' ?>">Audit Logs</a>
            </nav>
        </div>
    </header>

    <main class="app-main">
        <div class="main-container">
            <?php if (!empty($_SESSION['flash_success'])): ?>
                <div class="alert alert-success">
                    <span class="alert-icon">&#x2714;</span>
                    <span class="alert-text"><?= h($_SESSION['flash_success']) ?></span>
                </div>
                <?php unset($_SESSION['flash_success']); ?>
            <?php endif; ?>

            <?php if (!empty($_SESSION['flash_error'])): ?>
                <div class="alert alert-danger">
                    <span class="alert-icon">&#x26A0;</span>
                    <span class="alert-text"><?= h($_SESSION['flash_error']) ?></span>
                </div>
                <?php unset($_SESSION['flash_error']); ?>
            <?php endif; ?>
