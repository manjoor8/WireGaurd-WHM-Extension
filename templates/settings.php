<?php
/**
 * @var array $settings
 * @var array $settingsErrors
 * @var array $passwordErrors
 */
$settingsErrors = $settingsErrors ?? [];
$passwordErrors = $passwordErrors ?? [];
?>

<div class="page-header">
    <div>
        <h1 class="page-title">Settings</h1>
        <p class="page-subtitle">Configure WireGuard client profile defaults and administrator credentials</p>
    </div>
</div>

<!-- Change Administrator Password Card -->
<div class="card max-w-2xl mb-4">
    <div class="card-header">
        <h2 class="card-title">Change Administrator Password</h2>
    </div>
    <div class="card-body">
        <?php if (!empty($passwordErrors)): ?>
            <div class="alert alert-danger mb-4">
                <ul class="error-list">
                    <?php foreach ($passwordErrors as $error): ?>
                        <li><?= h($error) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <form method="POST" action="/settings.php">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="change_password">

            <div class="form-group">
                <label for="current_password" class="form-label">Current Password <span class="text-danger">*</span></label>
                <input type="password" id="current_password" name="current_password" class="form-control" autocomplete="current-password" required maxlength="72">
            </div>

            <div class="grid-2-col">
                <div class="form-group">
                    <label for="new_password" class="form-label">New Password <span class="text-danger">*</span></label>
                    <input type="password" id="new_password" name="new_password" class="form-control" autocomplete="new-password" required minlength="12" maxlength="72">
                    <p class="form-help">Minimum 12 characters.</p>
                </div>
                <div class="form-group">
                    <label for="confirm_password" class="form-label">Confirm New Password <span class="text-danger">*</span></label>
                    <input type="password" id="confirm_password" name="confirm_password" class="form-control" autocomplete="new-password" required minlength="12" maxlength="72">
                </div>
            </div>

            <div class="form-actions">
                <button type="submit" class="btn btn-primary">Update Password</button>
            </div>
        </form>
    </div>
</div>

<!-- WireGuard Client Defaults Card -->
<div class="card max-w-2xl">
    <div class="card-header">
        <h2 class="card-title">WireGuard &amp; Client Configuration Defaults</h2>
    </div>
    <div class="card-body">
        <?php if (!empty($settingsErrors)): ?>
            <div class="alert alert-danger mb-4">
                <ul class="error-list">
                    <?php foreach ($settingsErrors as $error): ?>
                        <li><?= h($error) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <form method="POST" action="/settings.php">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="update_settings">

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

<!-- Export & Import Full Configuration Card -->
<div class="card max-w-2xl mt-4">
    <div class="card-header">
        <h2 class="card-title">Backup &amp; Restore Configuration</h2>
    </div>
    <div class="card-body">
        <p class="text-secondary mb-4">
            Export or import the entire WireGuard VPN Manager configuration, including all client profiles (with public/private keys and allocated VPN IPs), network profile defaults, and the encrypted administrator password.
        </p>

        <!-- Export Section -->
        <div class="settings-backup-section mb-4 pb-4" style="border-bottom: 1px solid var(--border-color);">
            <h3 class="section-title mb-2">Export Configuration Backup</h3>
            <p class="form-help mb-3">Download a portable JSON backup file. Can be used for disaster recovery or migrating to another server.</p>
            <form method="POST" action="/settings.php">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="export">
                <button type="submit" class="btn btn-outline">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="margin-right: 6px; vertical-align: -2px;">
                        <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path>
                        <polyline points="7 10 12 15 17 10"></polyline>
                        <line x1="12" y1="15" x2="12" y2="3"></line>
                    </svg>
                    Export Configuration (.json)
                </button>
            </form>
        </div>

        <!-- Import Section -->
        <div class="settings-backup-section">
            <h3 class="section-title mb-2">Import Configuration Backup</h3>
            <p class="form-help mb-3">
                Upload a JSON backup file. Clients, settings, and administrator credentials will be restored, and active peers will be automatically synchronized with the WireGuard interface (<code>wg0</code>).
            </p>

            <form method="POST" action="/settings.php" enctype="multipart/form-data">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="import">

                <div class="form-group mb-3">
                    <label for="backup_file" class="form-label">Select Backup File (.json) <span class="text-danger">*</span></label>
                    <input type="file" id="backup_file" name="backup_file" accept=".json,application/json" class="form-control" required>
                </div>

                <div class="alert alert-warning mb-3">
                    <strong>Warning:</strong> Importing will replace all existing client records and synchronize active peers with WireGuard.
                </div>

                <button type="submit" class="btn btn-danger" data-confirm="Are you sure you want to import this configuration? This will replace existing clients and restore settings and credentials from the backup file.">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="margin-right: 6px; vertical-align: -2px;">
                        <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path>
                        <polyline points="17 8 12 3 7 8"></polyline>
                        <line x1="12" y1="3" x2="12" y2="15"></line>
                    </svg>
                    Import Configuration
                </button>
            </form>
        </div>
    </div>
</div>

<!-- Application Updates Card -->
<div class="card max-w-2xl mt-4" id="updates">
    <div class="card-header flex-between">
        <h2 class="card-title">Application Updates</h2>
        <?php if (!empty($updateInfo['available'])): ?>
            <span class="badge badge-warning">Update Available</span>
        <?php else: ?>
            <span class="badge badge-success">Up to Date</span>
        <?php endif; ?>
    </div>
    <div class="card-body">
        <div class="grid-2-col mb-4">
            <div>
                <span class="text-secondary text-sm">Current Version</span>
                <div style="font-size: 1.25rem; font-weight: 700; color: var(--text-primary);">
                    <?= h($updateInfo['current_version']) ?>
                </div>
            </div>
            <div>
                <span class="text-secondary text-sm">Latest Available</span>
                <div style="font-size: 1.25rem; font-weight: 700; color: var(--text-primary);">
                    <?= h($updateInfo['latest_version']) ?>
                </div>
            </div>
        </div>

        <?php if (!empty($updateInfo['available']) && !empty($updateInfo['update'])): ?>
            <div class="alert alert-info mb-4">
                <strong>New Version Available (<?= h($updateInfo['update']->version) ?>):</strong>
                <?php if (!empty($updateInfo['update']->releaseNotes)): ?>
                    <div class="mt-2 text-sm" style="white-space: pre-line; line-height: 1.5;">
                        <?= h($updateInfo['update']->releaseNotes) ?>
                    </div>
                <?php endif; ?>
            </div>

            <div class="alert alert-warning mb-4 text-sm">
                <strong>Safety Notice:</strong> Updating will safely restart the management application backend.
                Your active WireGuard tunnels, peers, and keys will <strong>not</strong> be removed or interrupted.
            </div>

            <form method="POST" action="/settings.php#updates" onsubmit="return confirm('Update WireGuard VPN Manager?\n\nCurrent version: <?= h($updateInfo['current_version']) ?>\nNew version: <?= h($updateInfo['latest_version']) ?>\n\nThe application may restart during the update.\nYour WireGuard VPN configuration and peers will not be removed.\n\nProceed with update?');">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="apply_update">
                <button type="submit" class="btn btn-primary">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="margin-right: 6px; vertical-align: -2px;">
                        <polyline points="17 8 12 3 7 8"></polyline>
                        <line x1="12" y1="3" x2="12" y2="15"></line>
                        <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path>
                    </svg>
                    Update Now to <?= h($updateInfo['latest_version']) ?>
                </button>
            </form>
        <?php else: ?>
            <div class="alert alert-success mb-4">
                <span class="alert-icon">&#x2714;</span>
                <span class="alert-text">You are running the latest version of WireGuard VPN Manager.</span>
            </div>

            <form method="GET" action="/settings.php">
                <button type="submit" class="btn btn-outline">Check for Updates</button>
            </form>
        <?php endif; ?>
    </div>
</div>

<!-- About Application Card -->
<div class="card max-w-2xl mt-4" id="about">
    <div class="card-header">
        <h2 class="card-title">About WireGuard VPN Manager</h2>
    </div>
    <div class="card-body">
        <div class="details-grid">
            <div class="detail-item">
                <span class="detail-label">Application</span>
                <span class="detail-value">WireGuard VPN Manager</span>
            </div>
            <div class="detail-item">
                <span class="detail-label">Version</span>
                <span class="detail-value"><code><?= h($aboutInfo['version']) ?></code></span>
            </div>
            <div class="detail-item">
                <span class="detail-label">Build</span>
                <span class="detail-value"><code><?= h($aboutInfo['build']) ?></code></span>
            </div>
            <div class="detail-item">
                <span class="detail-label">PHP Runtime</span>
                <span class="detail-value"><?= h($aboutInfo['runtime']) ?></span>
            </div>
            <div class="detail-item detail-full">
                <span class="detail-label">Host Operating System</span>
                <span class="detail-value"><?= h($aboutInfo['os']) ?></span>
            </div>
        </div>
    </div>
</div>
