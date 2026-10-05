<?php
/**
 * @var array $clients
 * @var string $csrfToken
 */
?>

<div class="page-header">
    <div>
        <h1 class="page-title">Client Management</h1>
        <p class="page-subtitle">Manage WireGuard VPN clients, keys, and access controls</p>
    </div>
    <div class="page-actions">
        <a href="/add-client.php" class="btn btn-primary">+ Add Client</a>
    </div>
</div>

<div class="card">
    <div class="card-body p-0">
        <?php if (empty($clients)): ?>
            <div class="empty-state">
                <p>No clients found.</p>
                <a href="/add-client.php" class="btn btn-primary btn-sm mt-2">Create New Client</a>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Name & Description</th>
                            <th>VPN IP</th>
                            <th>Status</th>
                            <th>Last Handshake</th>
                            <th>RX / TX</th>
                            <th class="text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($clients as $c): ?>
                            <tr class="<?= $c['state'] === 'revoked' ? 'row-revoked' : '' ?>">
                                <td>
                                    <strong><a href="/client.php?id=<?= (int)$c['id'] ?>"><?= h($c['name']) ?></a></strong>
                                    <?php if (!empty($c['description'])): ?>
                                        <div class="text-muted text-xs"><?= h($c['description']) ?></div>
                                    <?php endif; ?>
                                    <div class="font-mono text-xs text-muted mt-1"><?= h(substr($c['public_key'], 0, 16)) ?>...</div>
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
                                    <div><span class="text-muted text-xs">&darr;</span> <?= h($c['rx_formatted']) ?></div>
                                    <div><span class="text-muted text-xs">&uarr;</span> <?= h($c['tx_formatted']) ?></div>
                                </td>
                                <td class="text-right table-actions">
                                    <a href="/client.php?id=<?= (int)$c['id'] ?>" class="btn btn-xs btn-outline" title="View details">View</a>
                                    
                                    <?php if ($c['state'] !== 'revoked'): ?>
                                        <a href="/client.php?id=<?= (int)$c['id'] ?>&download=1" class="btn btn-xs btn-outline" title="Download .conf file">Config</a>
                                        <button type="button" class="btn btn-xs btn-outline btn-qr" data-client-id="<?= (int)$c['id'] ?>" data-client-name="<?= h($c['name']) ?>" title="Show QR Code">QR</button>
                                        
                                        <?php if ($c['state'] === 'active'): ?>
                                            <?php if ($c['is_online']): ?>
                                                <form method="POST" action="/clients.php" class="inline-form" data-confirm="Forcefully reset active tunnel session for <?= h($c['name']) ?>?">
                                                    <?= csrf_field() ?>
                                                    <input type="hidden" name="action" value="reset_session">
                                                    <input type="hidden" name="client_id" value="<?= (int)$c['id'] ?>">
                                                    <button type="submit" class="btn btn-xs btn-outline" style="color: #f59e0b; border-color: rgba(245, 158, 11, 0.5);" title="Kick / Reset Active Session">Kick</button>
                                                </form>
                                            <?php endif; ?>

                                            <form method="POST" action="/clients.php" class="inline-form" data-confirm="Forcefully disconnect <?= h($c['name']) ?> from WireGuard?">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="action" value="disconnect">
                                                <input type="hidden" name="client_id" value="<?= (int)$c['id'] ?>">
                                                <button type="submit" class="btn btn-xs btn-warning" title="Forcefully disconnect peer from WireGuard">Disconnect</button>
                                            </form>
                                        <?php elseif ($c['state'] === 'disabled'): ?>
                                            <form method="POST" action="/clients.php" class="inline-form">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="action" value="enable">
                                                <input type="hidden" name="client_id" value="<?= (int)$c['id'] ?>">
                                                <button type="submit" class="btn btn-xs btn-success" title="Allow client to connect">Connect</button>
                                            </form>
                                        <?php endif; ?>

                                        <button type="button" class="btn btn-xs btn-danger btn-revoke" data-client-id="<?= (int)$c['id'] ?>" data-client-name="<?= h($c['name']) ?>">Revoke</button>
                                    <?php else: ?>
                                        <span class="text-muted text-xs">Revoked</span>
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

<!-- Revoke Confirmation Modal -->
<div id="revokeModal" class="modal" style="display: none;">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">Confirm Client Revocation</h3>
                <button type="button" class="modal-close" data-close-modal="revokeModal">&times;</button>
            </div>
            <div class="modal-body">
                <p>Are you sure you want to revoke client <strong id="revokeClientName"></strong>?</p>
                <div class="alert alert-danger mt-2">
                    <strong>Warning:</strong> Revocation is permanent. The peer will be disconnected immediately, and its private keys will be permanently deleted.
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-close-modal="revokeModal">Cancel</button>
                <form method="POST" action="/clients.php" class="inline-form">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="revoke">
                    <input type="hidden" name="client_id" id="revokeClientId" value="">
                    <button type="submit" class="btn btn-danger">Confirm Revoke</button>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- QR Code Modal -->
<div id="qrModal" class="modal" style="display: none;">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">WireGuard QR Code &bull; <span id="qrClientName"></span></h3>
                <button type="button" class="modal-close" data-close-modal="qrModal">&times;</button>
            </div>
            <div class="modal-body text-center">
                <div id="qrImageContainer" class="qr-container">
                    <div class="spinner">Loading QR Code...</div>
                </div>
                <p class="text-muted text-xs mt-3">Scan this code using the official WireGuard mobile application (iOS/Android).</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-close-modal="qrModal">Close</button>
            </div>
        </div>
    </div>
</div>
