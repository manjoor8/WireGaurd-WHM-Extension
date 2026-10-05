<?php
/**
 * @var array $status
 * @var array $config
 * @var array $clients
 * @var array $metrics
 */
?>

<div class="page-header">
    <div>
        <h1 class="page-title">Dashboard</h1>
        <p class="page-subtitle">Overview of WireGuard VPN server and connected clients</p>
    </div>
    <div class="page-actions">
        <a href="/add-client.php" class="btn btn-primary">+ Add New Client</a>
    </div>
</div>

<?php if (!empty($systemHealth) && empty($systemHealth['overall']['is_ready'])): ?>
    <div class="alert alert-warning mb-4" style="display: flex; justify-content: space-between; align-items: center;">
        <div>
            <strong>⚠ Action Required:</strong> WireGuard VPN is not fully configured or running.
            Pending: <code><?= h(implode(', ', $systemHealth['overall']['pending_steps'])) ?></code>.
        </div>
        <a href="/setup.php" class="btn btn-sm btn-primary">Complete Setup &rarr;</a>
    </div>
<?php endif; ?>

<?php if (!empty($updateInfo['available'])): ?>
    <div class="alert alert-info mb-4" style="display: flex; justify-content: space-between; align-items: center;">
        <div>
            <strong>Software Update Available:</strong> Version <strong><?= h($updateInfo['latest_version']) ?></strong> is available (Current: <?= h($updateInfo['current_version']) ?>).
        </div>
        <a href="/settings.php#updates" class="btn btn-sm btn-primary">View Update &rarr;</a>
    </div>
<?php endif; ?>

<?php if (!empty($status['error'])): ?>
    <div class="alert alert-danger mb-4">
        <strong>WireGuard Runtime Notice:</strong> <?= h($status['error']) ?>
    </div>
<?php endif; ?>

<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-header">
            <span class="stat-label">WireGuard Status</span>
            <?php if (!empty($status['interface_up'])): ?>
                <span class="badge badge-success">Running</span>
            <?php else: ?>
                <span class="badge badge-danger">Stopped</span>
            <?php endif; ?>
        </div>
        <div class="stat-value"><?= h($config['interface']) ?></div>
        <div class="stat-meta">Port: <?= h($status['listen_port'] ?? $config['listen_port']) ?></div>
    </div>

    <div class="stat-card">
        <div class="stat-header">
            <span class="stat-label">VPN Network</span>
            <span class="badge badge-info">Subnet</span>
        </div>
        <div class="stat-value"><?= h($config['server_vpn_ip']) ?>/24</div>
        <div class="stat-meta">Network: <?= h($config['vpn_network']) ?></div>
    </div>

    <div class="stat-card">
        <div class="stat-header">
            <span class="stat-label">Total Clients</span>
            <span class="badge badge-neutral"><?= count($clients) ?></span>
        </div>
        <div class="stat-value"><?= $metrics['active_clients'] ?> <span class="stat-sub">Active</span></div>
        <div class="stat-meta"><?= $metrics['online_clients'] ?> connected now</div>
    </div>

    <div class="stat-card">
        <div class="stat-header">
            <span class="stat-label">Total Traffic</span>
            <span class="badge badge-neutral">Transfer</span>
        </div>
        <div class="stat-value"><?= h($metrics['total_rx_formatted']) ?> <span class="stat-sub">&darr; RX</span></div>
        <div class="stat-meta"><?= h($metrics['total_tx_formatted']) ?> &uarr; TX</div>
    </div>
</div>

<div class="card mb-4">
    <div class="card-header">
        <h2 class="card-title">Server Runtime Details</h2>
    </div>
    <div class="card-body">
        <div class="details-grid">
            <div class="detail-item">
                <span class="detail-label">Interface</span>
                <span class="detail-value"><code><?= h($config['interface']) ?></code></span>
            </div>
            <div class="detail-item">
                <span class="detail-label">Server VPN IP</span>
                <span class="detail-value"><code><?= h($config['server_vpn_ip']) ?>/24</code></span>
            </div>
            <div class="detail-item">
                <span class="detail-label">Listen Port</span>
                <span class="detail-value"><code><?= h($status['listen_port'] ?? $config['listen_port']) ?> UDP</code></span>
            </div>
            <div class="detail-item">
                <span class="detail-label">Management URL</span>
                <span class="detail-value"><code>http://10.50.0.1:5050</code> <span class="text-muted">(Isolated to VPN)</span></span>
            </div>
            <div class="detail-item detail-full">
                <span class="detail-label">Server Public Key</span>
                <span class="detail-value font-mono"><code><?= h($status['public_key'] ?? 'N/A') ?></code></span>
            </div>
        </div>
    </div>
</div>

<div class="grid-2-col mb-4">
    <div class="card">
        <div class="card-header flex-between">
            <h2 class="card-title">System Health</h2>
            <a href="/status.php" class="btn btn-xs btn-outline">Full Status &rarr;</a>
        </div>
        <div class="card-body">
            <div style="display: flex; flex-direction: column; gap: 0.5rem; font-size: 0.9rem;">
                <div style="display: flex; justify-content: space-between;">
                    <span>Application (v<?= h($appVersion) ?>)</span>
                    <span class="badge badge-success">✓ Ready</span>
                </div>
                <div style="display: flex; justify-content: space-between;">
                    <span>WireGuard</span>
                    <?php if (!empty($systemHealth['components']['wireguard']['installed'])): ?>
                        <span class="badge badge-success">✓ Installed</span>
                    <?php else: ?>
                        <span class="badge badge-danger">⚠ Not Installed</span>
                    <?php endif; ?>
                </div>
                <div style="display: flex; justify-content: space-between;">
                    <span>VPN Interface (<?= h($config['interface']) ?>)</span>
                    <?php if (!empty($systemHealth['components']['interface']['configured'])): ?>
                        <span class="badge badge-success">✓ Configured</span>
                    <?php else: ?>
                        <span class="badge badge-warning">⚠ Not Configured</span>
                    <?php endif; ?>
                </div>
                <div style="display: flex; justify-content: space-between;">
                    <span>Firewall &amp; Forwarding</span>
                    <?php if (!empty($systemHealth['components']['firewall']['configured'])): ?>
                        <span class="badge badge-success">✓ Configured</span>
                    <?php else: ?>
                        <span class="badge badge-warning">⚠ Action Required</span>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-header flex-between">
            <h2 class="card-title">Application Updates</h2>
            <a href="/settings.php#updates" class="btn btn-xs btn-outline">Settings &rarr;</a>
        </div>
        <div class="card-body">
            <?php if (!empty($updateInfo['available'])): ?>
                <div class="alert alert-warning mb-2 p-2 text-sm">
                    <strong>Update Available:</strong> Version <?= h($updateInfo['latest_version']) ?> is ready.
                </div>
                <a href="/settings.php#updates" class="btn btn-sm btn-primary">View Update</a>
            <?php else: ?>
                <div style="display: flex; align-items: center; gap: 0.5rem; color: #10b981; font-weight: 600; margin-bottom: 0.5rem;">
                    <span>✓ Application is up to date (v<?= h($appVersion) ?>)</span>
                </div>
                <p class="text-muted text-xs mb-0">Running latest stable release.</p>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header flex-between">
        <h2 class="card-title">Connected / Active Clients</h2>
        <a href="/clients.php" class="btn btn-secondary btn-sm">View All Clients &rarr;</a>
    </div>
    <div class="card-body p-0">
        <?php if (empty($clients)): ?>
            <div class="empty-state">
                <p>No WireGuard clients configured yet.</p>
                <a href="/add-client.php" class="btn btn-primary btn-sm mt-2">Add Your First Client</a>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>VPN IP</th>
                            <th>Status</th>
                            <th>Last Handshake</th>
                            <th>Transfer</th>
                            <th class="text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach (array_slice($clients, 0, 10) as $c): ?>
                            <tr>
                                <td>
                                    <strong><a href="/client.php?id=<?= (int)$c['id'] ?>"><?= h($c['name']) ?></a></strong>
                                    <?php if (!empty($c['description'])): ?>
                                        <div class="text-muted text-xs"><?= h($c['description']) ?></div>
                                    <?php endif; ?>
                                </td>
                                <td><code><?= h($c['vpn_ip']) ?></code></td>
                                <td>
                                    <?php if ($c['state'] === 'revoked'): ?>
                                        <span class="badge badge-danger">Revoked</span>
                                    <?php elseif ($c['state'] === 'disabled'): ?>
                                        <span class="badge badge-warning">Disconnected</span>
                                    <?php elseif ($c['is_online']): ?>
                                        <span class="badge badge-success"><span class="dot dot-online"></span> Online</span>
                                    <?php else: ?>
                                        <span class="badge badge-neutral"><span class="dot dot-offline"></span> Offline</span>
                                    <?php endif; ?>
                                </td>
                                <td><?= h($c['handshake_text']) ?></td>
                                <td>
                                    <span class="text-xs">&darr; <?= h($c['rx_formatted']) ?></span> / 
                                    <span class="text-xs">&uarr; <?= h($c['tx_formatted']) ?></span>
                                </td>
                                <td class="text-right table-actions">
                                    <a href="/client.php?id=<?= (int)$c['id'] ?>" class="btn btn-xs btn-outline">View</a>
                                    <a href="/client.php?id=<?= (int)$c['id'] ?>&download=1" class="btn btn-xs btn-outline">Config</a>
                                    <?php if ($c['state'] === 'active'): ?>
                                        <form method="POST" action="/clients.php" class="inline-form" data-confirm="Forcefully disconnect <?= h($c['name']) ?> from WireGuard?">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="disconnect">
                                            <input type="hidden" name="client_id" value="<?= (int)$c['id'] ?>">
                                            <button type="submit" class="btn btn-xs btn-warning" title="Forcefully disconnect">Disconnect</button>
                                        </form>
                                    <?php elseif ($c['state'] === 'disabled'): ?>
                                        <form method="POST" action="/clients.php" class="inline-form">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="enable">
                                            <input type="hidden" name="client_id" value="<?= (int)$c['id'] ?>">
                                            <button type="submit" class="btn btn-xs btn-success" title="Connect client">Connect</button>
                                        </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>
