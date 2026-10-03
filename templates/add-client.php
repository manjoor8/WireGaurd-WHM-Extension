<?php
/**
 * @var string $suggestedIp
 * @var string $csrfToken
 * @var array $errors
 * @var array $formData
 */
$errors = $errors ?? [];
$formData = $formData ?? [];
?>

<div class="page-header">
    <div>
        <a href="/clients.php" class="back-link">&larr; Back to Clients</a>
        <h1 class="page-title">Add WireGuard Client</h1>
        <p class="page-subtitle">Generate a new client key pair and register peer on wg0 interface</p>
    </div>
</div>

<div class="card max-w-2xl">
    <div class="card-header">
        <h2 class="card-title">Client Details</h2>
    </div>
    <div class="card-body">
        <?php if (!empty($errors)): ?>
            <div class="alert alert-danger mb-4">
                <ul class="error-list">
                    <?php foreach ($errors as $error): ?>
                        <li><?= h($error) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <form method="POST" action="/add-client.php">
            <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">

            <div class="form-group">
                <label for="name" class="form-label">Client Name <span class="text-danger">*</span></label>
                <input type="text" id="name" name="name" class="form-control" value="<?= h($formData['name'] ?? '') ?>" placeholder="e.g. john-laptop, android-phone, office-desktop" required autofocus maxlength="64">
                <p class="form-help">A human-readable identifier for this client device.</p>
            </div>

            <div class="form-group">
                <label for="description" class="form-label">Description (Optional)</label>
                <input type="text" id="description" name="description" class="form-control" value="<?= h($formData['description'] ?? '') ?>" placeholder="e.g. MacBook Pro M3, Manjoor's personal phone" maxlength="255">
                <p class="form-help">Optional notes about the user or equipment.</p>
            </div>

            <div class="form-group">
                <label for="vpn_ip" class="form-label">VPN IP Address <span class="text-danger">*</span></label>
                <input type="text" id="vpn_ip" name="vpn_ip" class="form-control font-mono" value="<?= h($formData['vpn_ip'] ?? $suggestedIp) ?>" required pattern="^10\.50\.0\.(?:[2-9]|[1-9][0-9]|1[0-9]{2}|2[0-4][0-9]|25[0-4])$">
                <p class="form-help">
                    Automatically assigned next available IP in <code>10.50.0.0/24</code> subnet (range <code>10.50.0.2</code> - <code>10.50.0.254</code>).
                    <code>10.50.0.1</code> is reserved for the WireGuard server.
                </p>
            </div>

            <div class="form-group">
                <div class="alert alert-info">
                    <strong>Key Generation Note:</strong> A unique WireGuard Curve25519 key pair will be generated on creation. The peer will be immediately registered to <code>wg0</code> without restarting any services.
                </div>
            </div>

            <div class="form-actions">
                <a href="/clients.php" class="btn btn-secondary">Cancel</a>
                <button type="submit" class="btn btn-primary">Create Client &amp; Generate Configuration</button>
            </div>
        </form>
    </div>
</div>
