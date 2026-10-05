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
                                        <form method="POST" action="/clients.php" class="inline-form" onsubmit="return confirm('Forcefully disconnect <?= h($c['name']) ?> from WireGuard?');">
                                            <input type="hidden" name="action" value="disconnect">
                                            <input type="hidden" name="client_id" value="<?= (int)$c['id'] ?>">
                                            <button type="submit" class="btn btn-xs btn-warning" title="Forcefully disconnect">Disconnect</button>
                                        </form>
                                    <?php elseif ($c['state'] === 'disabled'): ?>
                                        <form method="POST" action="/clients.php" class="inline-form">
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
