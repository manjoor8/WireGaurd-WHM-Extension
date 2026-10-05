<?php
/**
 * @var int $currentStep
 * @var array $wgInfo
 * @var array $systemHealth
 * @var array $settings
 * @var string $detectedIp
 * @var string $csrfToken
 * @var ?string $error
 * @var ?string $success
 */
$flashSuccess = $_SESSION['flash_success'] ?? $success;
unset($_SESSION['flash_success']);

$flashError = $_SESSION['flash_error'] ?? $error;
unset($_SESSION['flash_error']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>First-Time Setup - WireGuard VPN Manager</title>
    <link rel="stylesheet" href="/assets/css/app.css">
    <style>
        .wizard-container {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 2rem 1rem;
            background: radial-gradient(circle at top, #1e293b 0%, #0f172a 100%);
        }
        .wizard-card {
            width: 100%;
            max-width: 640px;
            background-color: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: var(--radius);
            box-shadow: 0 15px 35px -5px rgba(0, 0, 0, 0.6), 0 8px 15px -6px rgba(0, 0, 0, 0.4);
            overflow: hidden;
        }
        .wizard-header {
            padding: 2rem 2rem 1.5rem;
            text-align: center;
            border-bottom: 1px solid var(--border-color);
            background: rgba(15, 23, 42, 0.5);
        }
        .wizard-brand-icon {
            color: var(--primary);
            margin-bottom: 0.5rem;
        }
        .wizard-title {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--text-primary);
            margin-bottom: 0.25rem;
        }
        .wizard-subtitle {
            font-size: 0.9rem;
            color: var(--text-secondary);
        }
        .wizard-steps {
            display: flex;
            border-bottom: 1px solid var(--border-color);
            background-color: rgba(30, 41, 59, 0.3);
        }
        .step-item {
            flex: 1;
            padding: 0.75rem 0.5rem;
            text-align: center;
            font-size: 0.8rem;
            font-weight: 600;
            color: var(--text-secondary);
            border-bottom: 2px solid transparent;
            transition: all 0.2s ease;
        }
        .step-item.active {
            color: var(--primary);
            border-bottom-color: var(--primary);
            background-color: rgba(59, 130, 246, 0.05);
        }
        .step-item.completed {
            color: var(--color-success, #10b981);
        }
        .wizard-body {
            padding: 2rem;
        }
        .health-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.75rem 1rem;
            background: rgba(30, 41, 59, 0.5);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-sm);
            margin-bottom: 0.5rem;
        }
        .health-label {
            font-weight: 600;
            color: var(--text-primary);
        }
        .health-meta {
            font-size: 0.85rem;
            color: var(--text-secondary);
        }
        .progress-box {
            background: #0f172a;
            border: 1px solid var(--border-color);
            border-radius: var(--radius-sm);
            padding: 1rem;
            font-family: monospace;
            font-size: 0.85rem;
            margin-bottom: 1.5rem;
            line-height: 1.6;
        }
        .progress-line {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin-bottom: 0.25rem;
        }
        .progress-line.pending {
            color: var(--text-secondary);
        }
        .progress-line.running {
            color: var(--primary);
            font-weight: bold;
        }
        .progress-line.done {
            color: #10b981;
        }
        .progress-line.failed {
            color: #ef4444;
        }
    </style>
</head>
<body>
    <div class="wizard-container">
        <div class="wizard-card">
            <div class="wizard-header">
                <svg class="wizard-brand-icon" viewBox="0 0 24 24" width="44" height="44" stroke="currentColor" stroke-width="2" fill="none" stroke-linecap="round" stroke-linejoin="round">
                    <rect x="2" y="2" width="20" height="8" rx="2" ry="2"></rect>
                    <rect x="2" y="14" width="20" height="8" rx="2" ry="2"></rect>
                    <line x1="6" y1="6" x2="6.01" y2="6"></line>
                    <line x1="6" y1="18" x2="6.01" y2="18"></line>
                </svg>
                <h1 class="wizard-title">WireGuard VPN Manager</h1>
                <p class="wizard-subtitle">First-Time System Initialization &amp; Setup Wizard</p>
            </div>

            <div class="wizard-steps">
                <div class="step-item <?= $currentStep === 1 ? 'active' : ($currentStep > 1 ? 'completed' : '') ?>">
                    1. Security
                </div>
                <div class="step-item <?= $currentStep === 2 ? 'active' : ($currentStep > 2 ? 'completed' : '') ?>">
                    2. WireGuard
                </div>
                <div class="step-item <?= $currentStep === 3 ? 'active' : ($currentStep > 3 ? 'completed' : '') ?>">
                    3. Configuration
                </div>
                <div class="step-item <?= $currentStep === 4 ? 'active' : '' ?>">
                    4. System Status
                </div>
            </div>

            <div class="wizard-body">
                <?php if (!empty($flashSuccess)): ?>
                    <div class="alert alert-success mb-4">
                        <span class="alert-icon">&#x2714;</span>
                        <span class="alert-text"><?= h((string)$flashSuccess) ?></span>
                    </div>
                <?php endif; ?>

                <?php if (!empty($flashError)): ?>
                    <div class="alert alert-danger mb-4">
                        <span class="alert-icon">&#x26A0;</span>
                        <span class="alert-text"><?= h((string)$flashError) ?></span>
                    </div>
                <?php endif; ?>

                <!-- STEP 1: Application Security -->
                <?php if ($currentStep === 1): ?>
                    <h2 class="card-title mb-2">Create Administrator Password</h2>
                    <p class="text-secondary mb-4">
                        Set a strong administrator password to secure access to this WireGuard VPN Management portal.
                    </p>

                    <form method="POST" action="/setup.php">
                        <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                        <input type="hidden" name="action" value="set_password">

                        <div class="form-group mb-3">
                            <label for="password" class="form-label">Administrator Password <span class="text-danger">*</span></label>
                            <input
                                type="password"
                                id="password"
                                name="password"
                                class="form-control"
                                placeholder="Enter strong password (min. 12 characters)"
                                autocomplete="new-password"
                                required
                                minlength="12"
                                maxlength="72"
                                autofocus
                            >
                            <p class="form-help">Must be at least 12 characters and up to 72 bytes.</p>
                        </div>

                        <div class="form-group mb-4">
                            <label for="confirm_password" class="form-label">Confirm Password <span class="text-danger">*</span></label>
                            <input
                                type="password"
                                id="confirm_password"
                                name="confirm_password"
                                class="form-control"
                                placeholder="Confirm administrator password"
                                autocomplete="new-password"
                                required
                                minlength="12"
                                maxlength="72"
                            >
                        </div>

                        <button type="submit" class="btn btn-primary btn-block">Continue &rarr;</button>
                    </form>

                <!-- STEP 2: WireGuard Installation -->
                <?php elseif ($currentStep === 2): ?>
                    <h2 class="card-title mb-2">System Setup: WireGuard</h2>
                    <p class="text-secondary mb-4">
                        WireGuard is required on this server to create and manage secure VPN tunnels.
                    </p>

                    <?php if (!empty($wgInfo['installed'])): ?>
                        <div class="card mb-4" style="background: rgba(16, 185, 129, 0.08); border-color: rgba(16, 185, 129, 0.3);">
                            <div class="card-body">
                                <div style="display: flex; align-items: center; justify-content: space-between;">
                                    <div>
                                        <h3 style="color: #10b981; margin-bottom: 0.25rem;">✓ WireGuard is Installed</h3>
                                        <p class="text-muted text-sm" style="margin: 0;">
                                            Version: <strong><?= h($wgInfo['version'] ?: 'Detected') ?></strong>
                                        </p>
                                    </div>
                                    <span class="badge badge-success">Ready</span>
                                </div>
                            </div>
                        </div>

                        <div class="form-actions">
                            <a href="/setup.php?step=3" class="btn btn-primary btn-block">Continue to VPN Configuration &rarr;</a>
                        </div>
                    <?php else: ?>
                        <div class="card mb-4" style="background: rgba(239, 68, 68, 0.08); border-color: rgba(239, 68, 68, 0.3);">
                            <div class="card-body">
                                <div style="display: flex; align-items: center; justify-content: space-between;">
                                    <div>
                                        <h3 style="color: #ef4444; margin-bottom: 0.25rem;">⚠ WireGuard Not Installed</h3>
                                        <p class="text-muted text-sm" style="margin: 0;">
                                            The WireGuard utility (<code>wg</code>) was not found on this system.
                                        </p>
                                    </div>
                                    <span class="badge badge-danger">Not Installed</span>
                                </div>
                            </div>
                        </div>

                        <div id="installProgressBox" class="progress-box" style="display: none;">
                            <div class="progress-line done">✓ Checking operating system compatibility</div>
                            <div class="progress-line done">✓ Checking administrator privileges</div>
                            <div class="progress-line running" id="stepDownloading">→ Downloading WireGuard packages...</div>
                            <div class="progress-line pending" id="stepInstalling">○ Installing WireGuard kernel module and tools</div>
                            <div class="progress-line pending" id="stepVerifying">○ Verifying installation</div>
                        </div>

                        <form id="installWgForm" method="POST" action="/setup.php">
                            <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                            <input type="hidden" name="action" value="install_wireguard">
                            <button type="submit" id="btnInstallWg" class="btn btn-primary btn-block">
                                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="margin-right: 6px; vertical-align: -3px;">
                                    <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path>
                                    <polyline points="7 10 12 15 17 10"></polyline>
                                    <line x1="12" y1="15" x2="12" y2="3"></line>
                                </svg>
                                Install WireGuard Automatically
                            </button>
                        </form>

                        <script>
                            document.getElementById('installWgForm')?.addEventListener('submit', function() {
                                document.getElementById('installProgressBox').style.display = 'block';
                                var btn = document.getElementById('btnInstallWg');
                                btn.disabled = true;
                                btn.innerHTML = 'Installing WireGuard... Please wait';
                                setTimeout(function() {
                                    document.getElementById('stepDownloading').className = 'progress-line done';
                                    document.getElementById('stepDownloading').innerHTML = '✓ Downloaded WireGuard packages';
                                    document.getElementById('stepInstalling').className = 'progress-line running';
                                    document.getElementById('stepInstalling').innerHTML = '→ Installing WireGuard tools & iptables...';
                                }, 2500);
                            });
                        </script>
                    <?php endif; ?>

                <!-- STEP 3: VPN Configuration -->
                <?php elseif ($currentStep === 3): ?>
                    <h2 class="card-title mb-2">WireGuard VPN Configuration</h2>
                    <p class="text-secondary mb-4">
                        Configure the server VPN interface and network parameters. Private and public keys will be generated automatically.
                    </p>

                    <form method="POST" action="/setup.php">
                        <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                        <input type="hidden" name="action" value="configure_vpn">

                        <div class="grid-2-col mb-3">
                            <div class="form-group">
                                <label for="interface" class="form-label">VPN Interface</label>
                                <input type="text" id="interface" name="interface" class="form-control" value="<?= h($settings['interface']) ?>" readonly>
                            </div>
                            <div class="form-group">
                                <label for="server_vpn_ip" class="form-label">Server VPN IP</label>
                                <input type="text" id="server_vpn_ip" name="server_vpn_ip" class="form-control" value="<?= h($settings['server_vpn_ip']) ?>" readonly>
                            </div>
                        </div>

                        <div class="grid-2-col mb-3">
                            <div class="form-group">
                                <label for="listen_port" class="form-label">Listen Port (UDP) <span class="text-danger">*</span></label>
                                <input type="number" id="listen_port" name="listen_port" class="form-control" value="<?= h($settings['listen_port']) ?>" min="1024" max="65535" required>
                            </div>
                            <div class="form-group">
                                <label for="dns" class="form-label">Client DNS <span class="text-danger">*</span></label>
                                <input type="text" id="dns" name="dns" class="form-control" value="<?= h($settings['dns']) ?>" required>
                            </div>
                        </div>

                        <div class="form-group mb-4">
                            <label for="vpn_endpoint" class="form-label">Public VPN Server Endpoint</label>
                            <input type="text" id="vpn_endpoint" name="vpn_endpoint" class="form-control" value="<?= h($settings['vpn_endpoint'] ?: $detectedIp) ?>" placeholder="e.g. 203.0.113.10 or vpn.example.com">
                            <p class="form-help">IP or domain clients use to reach this VPN server from the internet.</p>
                        </div>

                        <div class="alert alert-info mb-4 text-sm">
                            <strong>Automated Tasks:</strong>
                            <ul style="margin: 0.25rem 0 0 1rem; padding: 0;">
                                <li>Generates Curve25519 server keypair</li>
                                <li>Enables IPv4 packet forwarding in sysctl</li>
                                <li>Configures NAT masquerading and firewall rules</li>
                                <li>Starts and enables the WireGuard service</li>
                            </ul>
                        </div>

                        <button type="submit" class="btn btn-primary btn-block">Configure &amp; Start WireGuard VPN &rarr;</button>
                    </form>

                <!-- STEP 4: Setup Status & System Health -->
                <?php elseif ($currentStep === 4): ?>
                    <h2 class="card-title mb-2">System Status &amp; Verification</h2>
                    <p class="text-secondary mb-4">
                        Setup is complete! Overview of your WireGuard VPN Management appliance:
                    </p>

                    <div class="health-item">
                        <div>
                            <div class="health-label">Application</div>
                            <div class="health-meta">Version <?= h($systemHealth['components']['application']['version']) ?> &bull; <?= h($systemHealth['components']['application']['os']) ?></div>
                        </div>
                        <span class="badge badge-success">✓ Ready</span>
                    </div>

                    <div class="health-item">
                        <div>
                            <div class="health-label">Administrator Authentication</div>
                            <div class="health-meta">Configured with bcrypt credential hash</div>
                        </div>
                        <span class="badge badge-success">✓ Ready</span>
                    </div>

                    <div class="health-item">
                        <div>
                            <div class="health-label">WireGuard</div>
                            <div class="health-meta"><?= h($systemHealth['components']['wireguard']['version'] ?: 'Installed') ?></div>
                        </div>
                        <span class="badge badge-success">✓ Installed</span>
                    </div>

                    <div class="health-item">
                        <div>
                            <div class="health-label">VPN Interface</div>
                            <div class="health-meta"><?= h($systemHealth['components']['interface']['name']) ?> (<?= h($systemHealth['components']['interface']['address']) ?>)</div>
                        </div>
                        <span class="badge badge-success">✓ Configured</span>
                    </div>

                    <div class="health-item">
                        <div>
                            <div class="health-label">VPN Service</div>
                            <div class="health-meta">UDP port <?= h((string)$systemHealth['components']['interface']['listen_port']) ?></div>
                        </div>
                        <?php if (!empty($systemHealth['components']['service']['running'])): ?>
                            <span class="badge badge-success"><span class="dot dot-online"></span> Running</span>
                        <?php else: ?>
                            <span class="badge badge-warning">Stopped</span>
                        <?php endif; ?>
                    </div>

                    <div class="health-item">
                        <div>
                            <div class="health-label">Firewall &amp; NAT</div>
                            <div class="health-meta">Traffic forwarding &amp; UDP port open</div>
                        </div>
                        <span class="badge badge-success">✓ Configured</span>
                    </div>

                    <div class="form-actions mt-4">
                        <a href="/index.php" class="btn btn-primary btn-block">Go to Dashboard &rarr;</a>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</body>
</html>
