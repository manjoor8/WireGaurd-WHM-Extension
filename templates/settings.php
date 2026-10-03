<?php
/**
 * @var array $settings
 * @var string $csrfToken
 * @var array $errors
 */
$errors = $errors ?? [];
?>

<div class="page-header">
    <div>
        <h1 class="page-title">Settings</h1>
        <p class="page-subtitle">Configure WireGuard client profile defaults and network properties</p>
    </div>
</div>

<div class="card max-w-2xl">
    <div class="card-header">
        <h2 class="card-title">WireGuard &amp; Client Configuration Defaults</h2>
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

        <form method="POST" action="/settings.php">
            <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">

            <h3 class="section-title">Network Architecture (V1 Locked)</h3>
            <div class="grid-2-col mb-4">
                <div class="form-group">
                    <label class="form-label">WireGuard Interface</label>
                    <input type="text" class="form-control" value="<?= h($settings['interface']) ?>" readonly disabled>
                </div>
                <div class="form-group">
                    <label class="form-label">VPN Subnet</label>
                    <input type="text" class="form-control" value="<?= h($settings['vpn_network']) ?>" readonly disabled>
                </div>
                <div class="form-group">
                    <label class="form-label">Server VPN Address</label>
                    <input type="text" class="form-control" value="<?= h($settings['server_vpn_ip']) ?>" readonly disabled>
                </div>
                <div class="form-group">
                    <label class="form-label">WireGuard Listen Port</label>
                    <input type="text" class="form-control" value="<?= h($settings['listen_port']) ?> UDP" readonly disabled>
                </div>
            </div>

            <div class="alert alert-info mb-4">
                <strong>Management Isolation:</strong> Bound strictly to <code><?= h($settings['management_host']) ?>:<?= h($settings['management_port']) ?></code>. External public binding (0.0.0.0) is locked to guarantee security boundary.
            </div>

            <h3 class="section-title">Client Profile Defaults</h3>
            
            <div class="form-group">
                <label for="vpn_endpoint" class="form-label">VPN Public Endpoint</label>
                <input type="text" id="vpn_endpoint" name="vpn_endpoint" class="form-control" value="<?= h($settings['vpn_endpoint']) ?>" placeholder="e.g. vpn.example.com or 203.0.113.10">
                <p class="form-help">The public hostname or IP that remote clients use to connect to WireGuard port <?= h($settings['listen_port']) ?>. If omitted, a placeholder will be placed in client configs.</p>
            </div>

            <div class="form-group">
                <label for="dns" class="form-label">Client DNS Server</label>
                <input type="text" id="dns" name="dns" class="form-control" value="<?= h($settings['dns']) ?>" placeholder="1.1.1.1, 8.8.8.8" required>
                <p class="form-help">DNS resolver assigned to clients in their configuration profiles.</p>
            </div>

            <div class="form-group">
                <label for="allowed_ips" class="form-label">Default Client AllowedIPs</label>
                <input type="text" id="allowed_ips" name="allowed_ips" class="form-control" value="<?= h($settings['allowed_ips']) ?>" placeholder="0.0.0.0/0" required>
                <p class="form-help">Client-side routing directive. Use <code>0.0.0.0/0</code> to route all client traffic through VPN, or <code>10.50.0.0/24</code> for split tunneling.</p>
            </div>

            <div class="form-group">
                <label for="persistent_keepalive" class="form-label">Persistent Keepalive (Seconds)</label>
                <input type="number" id="persistent_keepalive" name="persistent_keepalive" class="form-control" value="<?= h($settings['persistent_keepalive']) ?>" min="0" max="3600" required>
                <p class="form-help">Keeps NAT mappings alive behind firewalls (recommended: 25 seconds).</p>
            </div>

            <div class="form-actions">
                <button type="submit" class="btn btn-primary">Save Settings</button>
            </div>
        </form>
    </div>
</div>
