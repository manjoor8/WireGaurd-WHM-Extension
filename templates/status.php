<?php
/**
 * @var array $health
 * @var array $updateInfo
 */
$overall = $health['overall'];
$comp = $health['components'];
?>

<div class="page-header">
    <div>
        <h1 class="page-title">System Status</h1>
        <p class="page-subtitle">Central health verification and operational status of all VPN components</p>
    </div>
    <div class="page-actions">
        <?php if (!$overall['is_ready']): ?>
            <a href="/setup.php" class="btn btn-primary">Complete Setup &rarr;</a>
        <?php else: ?>
            <a href="/settings.php#updates" class="btn btn-secondary">Check for Updates</a>
        <?php endif; ?>
    </div>
</div>

<?php if (!$overall['is_ready']): ?>
    <div class="alert alert-warning mb-4">
        <strong>Action Required:</strong> System setup has not been fully completed.
        Pending component(s): <code><?= h(implode(', ', $overall['pending_steps'])) ?></code>.
        <a href="/setup.php" style="color: inherit; text-decoration: underline; font-weight: bold; margin-left: 0.5rem;">Launch Setup Wizard</a>
    </div>
<?php endif; ?>

<!-- Overall System Status Card -->
<div class="card mb-4">
    <div class="card-header flex-between">
        <h2 class="card-title">Appliance Health Overview</h2>
        <?php if ($overall['is_ready']): ?>
            <span class="badge badge-success" style="font-size: 0.9rem; padding: 0.35rem 0.75rem;">✓ System Ready</span>
        <?php else: ?>
            <span class="badge badge-warning" style="font-size: 0.9rem; padding: 0.35rem 0.75rem;">⚠ Action Required</span>
        <?php endif; ?>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Subsystem Component</th>
                        <th>Status</th>
                        <th>Details</th>
                        <th class="text-right">Operational State</th>
                    </tr>
                </thead>
                <tbody>
                    <!-- Application -->
                    <tr>
                        <td><strong>Application</strong></td>
                        <td>
                            <span class="badge badge-success">✓ Ready</span>
                        </td>
                        <td>Version <?= h($comp['application']['version']) ?> (Build <?= h($comp['application']['build']) ?>)</td>
                        <td class="text-right text-muted text-sm"><?= h($comp['application']['runtime']) ?></td>
                    </tr>

                    <!-- Authentication -->
                    <tr>
                        <td><strong>Administrator Authentication</strong></td>
                        <td>
                            <?php if (!empty($comp['authentication']['configured'])): ?>
                                <span class="badge badge-success">✓ Configured</span>
                            <?php else: ?>
                                <span class="badge badge-danger">⚠ Not Configured</span>
                            <?php endif; ?>
                        </td>
                        <td><?= h($comp['authentication']['description']) ?></td>
                        <td class="text-right text-muted text-sm">Bcrypt Password Hash</td>
                    </tr>

                    <!-- WireGuard -->
                    <tr>
                        <td><strong>WireGuard Utility</strong></td>
                        <td>
                            <?php if (!empty($comp['wireguard']['installed'])): ?>
                                <span class="badge badge-success">✓ Installed</span>
                            <?php else: ?>
                                <span class="badge badge-danger">⚠ Not Installed</span>
                            <?php endif; ?>
                        </td>
                        <td><?= h($comp['wireguard']['version']) ?></td>
                        <td class="text-right text-muted text-sm"><?= h($comp['wireguard']['path'] ?: 'N/A') ?></td>
                    </tr>

                    <!-- VPN Interface -->
                    <tr>
                        <td><strong>VPN Interface</strong></td>
                        <td>
                            <?php if (!empty($comp['interface']['configured'])): ?>
                                <span class="badge badge-success">✓ Configured</span>
                            <?php else: ?>
                                <span class="badge badge-warning">⚠ Not Configured</span>
                            <?php endif; ?>
                        </td>
                        <td>Interface <code><?= h($comp['interface']['name']) ?></code> (IP: <code><?= h($comp['interface']['address']) ?></code>)</td>
                        <td class="text-right text-muted text-sm">UDP <?= h((string)$comp['interface']['listen_port']) ?></td>
                    </tr>

                    <!-- VPN Service -->
                    <tr>
                        <td><strong>VPN Service</strong></td>
                        <td>
                            <?php if (!empty($comp['service']['running'])): ?>
                                <span class="badge badge-success"><span class="dot dot-online"></span> Running</span>
                            <?php else: ?>
                                <span class="badge badge-danger"><span class="dot dot-offline"></span> Stopped</span>
                            <?php endif; ?>
                        </td>
                        <td><?= h($comp['service']['description']) ?></td>
                        <td class="text-right text-muted text-sm">wg-quick@<?= h($comp['interface']['name']) ?></td>
                    </tr>

                    <!-- Firewall -->
                    <tr>
                        <td><strong>Firewall &amp; NAT</strong></td>
                        <td>
                            <?php if (!empty($comp['firewall']['configured'])): ?>
                                <span class="badge badge-success">✓ Configured</span>
                            <?php else: ?>
                                <span class="badge badge-warning">⚠ Action Required</span>
                            <?php endif; ?>
                        </td>
                        <td>UDP <?= h((string)$comp['firewall']['port']) ?> &bull; Forwarding: <?= !empty($comp['firewall']['forwarding']) ? 'Enabled' : 'Disabled' ?></td>
                        <td class="text-right text-muted text-sm"><?= h($comp['firewall']['description']) ?></td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Technical Details Expandable Card -->
<div class="card">
    <div class="card-header">
        <h2 class="card-title">Technical Diagnostics</h2>
    </div>
    <div class="card-body">
        <details>
            <summary style="cursor: pointer; color: var(--primary); font-weight: 600; padding: 0.5rem 0;">
                Click to view technical environment &amp; raw diagnostics
            </summary>
            <div class="mt-3 p-3" style="background: rgba(15, 23, 42, 0.6); border-radius: var(--radius-sm); font-family: monospace; font-size: 0.85rem;">
                <div><strong>Operating System:</strong> <?= h($comp['application']['os']) ?></div>
                <div><strong>Runtime:</strong> <?= h($comp['application']['runtime']) ?></div>
                <div><strong>Application Version:</strong> <?= h($comp['application']['version']) ?> (Build <?= h($comp['application']['build']) ?>)</div>
                <div><strong>WireGuard Path:</strong> <?= h($comp['wireguard']['path'] ?: 'None') ?></div>
                <div><strong>Pending Steps:</strong> <?= h(json_encode($overall['pending_steps'])) ?></div>
                <div><strong>Update Available:</strong> <?= !empty($updateInfo['available']) ? 'Yes (' . h($updateInfo['latest_version']) . ')' : 'No (Up to date)' ?></div>
            </div>
        </details>
    </div>
</div>
