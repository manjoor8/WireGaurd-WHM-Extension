<?php
declare(strict_types=1);

// bootstrap enforces Host allow-list, hardened session and CSRF on POST
require_once dirname(__DIR__) . '/src/bootstrap.php';

use WireGuardManager\AuthService;

$rawReturn = $_GET['return'] ?? $_POST['return'] ?? '/index.php';
$returnUrl = '/index.php';
if (is_string($rawReturn)
    && str_starts_with($rawReturn, '/')
    && !str_starts_with($rawReturn, '//')
    && !str_contains($rawReturn, '\\')
    && !preg_match('/[\r\n]/', $rawReturn)
) {
    $returnUrl = $rawReturn;
}

// If already logged in, redirect immediately
if (AuthService::isAuthenticated()) {
    header('Location: ' . $returnUrl);
    exit;
}

$passwordConfigured = AuthService::hasPassword();
if (!$passwordConfigured) {
    header('Location: /setup.php');
    exit;
}
$error = null;

// Session-only flash (never from the query string)
$msg = $_SESSION['flash_success'] ?? null;
unset($_SESSION['flash_success']);
if (!empty($_SESSION['flash_error'])) {
    $error = $_SESSION['flash_error'];
    unset($_SESSION['flash_error']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $passwordConfigured) {
    $password = (string)($_POST['password'] ?? '');
    $result = AuthService::login($password);
    if ($result['ok']) {
        header('Location: ' . $returnUrl);
        exit;
    }
    $error = $result['error'];
}

$csrfToken = \WireGuardManager\Security::csrfToken();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - WireGuard Manager</title>
    <link rel="stylesheet" href="/assets/css/app.css">
    <style>
        .login-wrapper {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1.5rem;
            background: radial-gradient(circle at top, #1e293b 0%, #0f172a 100%);
        }
        .login-card {
            width: 100%;
            max-width: 400px;
            background-color: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: var(--radius);
            box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.5), 0 8px 10px -6px rgba(0, 0, 0, 0.4);
            overflow: hidden;
        }
        .login-header {
            padding: 2rem 1.5rem 1.5rem;
            text-align: center;
            border-bottom: 1px solid var(--border-color);
        }
        .login-brand-icon {
            color: var(--primary);
            margin-bottom: 0.75rem;
        }
        .login-title {
            font-size: 1.35rem;
            font-weight: 700;
            color: var(--text-primary);
            margin-bottom: 0.35rem;
        }
        .login-subtitle {
            font-size: 0.85rem;
            color: var(--text-secondary);
        }
        .login-body {
            padding: 1.75rem 1.5rem;
        }
        .btn-block {
            width: 100%;
            padding: 0.7rem 1rem;
            font-size: 0.95rem;
        }
        @media (max-width: 480px) {
            .login-wrapper {
                padding: 1rem;
                align-items: center;
            }
            .login-header {
                padding: 1.5rem 1.25rem 1.25rem;
            }
            .login-body {
                padding: 1.25rem;
            }
            .login-title {
                font-size: 1.2rem;
            }
            .form-control {
                font-size: 16px; /* Prevents auto zoom on mobile */
            }
        }
    </style>
</head>
<body>
    <div class="login-wrapper">
        <div class="login-card">
            <div class="login-header">
                <svg class="login-brand-icon" viewBox="0 0 24 24" width="40" height="40" stroke="currentColor" stroke-width="2" fill="none" stroke-linecap="round" stroke-linejoin="round">
                    <rect x="2" y="2" width="20" height="8" rx="2" ry="2"></rect>
                    <rect x="2" y="14" width="20" height="8" rx="2" ry="2"></rect>
                    <line x1="6" y1="6" x2="6.01" y2="6"></line>
                    <line x1="6" y1="18" x2="6.01" y2="18"></line>
                </svg>
                <h1 class="login-title">WireGuard Manager</h1>
                <p class="login-subtitle">Enter administrator password to continue</p>
            </div>
            <div class="login-body">
                <?php if (!empty($msg)): ?>
                    <div class="alert alert-info">
                        <span class="alert-icon">&#x2139;</span>
                        <span class="alert-text"><?= h((string)$msg) ?></span>
                    </div>
                <?php endif; ?>

                <?php if (!empty($error)): ?>
                    <div class="alert alert-danger">
                        <span class="alert-icon">&#x26A0;</span>
                        <span class="alert-text"><?= h($error) ?></span>
                    </div>
                <?php endif; ?>

                <?php if (!$passwordConfigured): ?>
                    <div class="alert alert-danger">
                        <span class="alert-icon">&#x26A0;</span>
                        <span class="alert-text">
                            No administrator password is configured. Sign-in is disabled.<br>
                            On the server, run as root: <code>wireguard-manager-passwd</code>
                        </span>
                    </div>
                <?php else: ?>
                <form method="POST" action="/login.php" autocomplete="on">
                    <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                    <input type="hidden" name="return" value="<?= h($returnUrl) ?>">

                    <div class="form-group">
                        <label for="password" class="form-label">Password</label>
                        <input
                            type="password"
                            id="password"
                            name="password"
                            class="form-control"
                            placeholder="Enter management password"
                            autocomplete="current-password"
                            maxlength="72"
                            required
                            autofocus
                        >
                    </div>

                    <div class="form-group" style="margin-top: 1.5rem; margin-bottom: 0;">
                        <button type="submit" class="btn btn-primary btn-block">Sign In</button>
                    </div>
                </form>
                <?php endif; ?>
            </div>
        </div>
    </div>
</body>
</html>
