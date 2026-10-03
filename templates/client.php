<?php
/**
 * @var array $client
 * @var string $configText
 * @var string $qrDataUri
 * @var string $csrfToken
 */
?>

<div class="page-header">
    <div>
        <a href="/clients.php" class="back-link">&larr; Back to Clients</a>
        <h1 class="page-title"><?= h($client['name']) ?></h1>
        <p class="page-subtitle"><?= h($client['description'] ?: 'WireGuard VPN Client') ?></p>
    </div>
    <div class="page-actions">
        <?php if ($client['state'] !== 'revoked'): ?>
            <a href="/client.php?id=<?= (int)$client['id'] ?>&download=1" class="btn btn-outline">&darr; Download .conf</a>
            
            <?php if ($client['state'] === 'active'): ?>
                <form method="POST" action="/client.php?id=<?= (int)$client['id'] ?>" class="inline-form">
                    <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                    <input type="hidden" name="action" value="disable">
                    <button type="submit" class="btn btn-warning">Disable Client</button>
                </form>
            <?php elseif ($client['state'] === 'disabled'): ?>
                <form method="POST" action="/client.php?id=<?= (int)$client['id'] ?>" class="inline-form">
                    <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                    <input type="hidden" name="action" value="enable">
                    <button type="submit" class="btn btn-success">Enable Client</button>
                </form>
            <?php endif; ?>

            <button type="button" class="btn btn-danger" onclick="openRevokeModal(<?= (int)$client['id'] ?>, '<?= h($client['name']) ?>')">Revoke</button>
        <?php else: ?>
            <span class="badge badge-danger badge-lg">Revoked Client</span>
        <?php endif; ?>
    </div>
</div>

<div class="grid-2-col">
    <!-- Left Column: Details & Stats -->
    <div>
        <div class="card mb-4">
            <div class="card-header">
                <h2 class="card-title">Client Information</h2>
            </div>
            <div class="card-body">
                <div class="details-grid">
                    <div class="detail-item">
                        <span class="detail-label">State</span>
                        <span class="detail-value">
                            <?php if ($client['state'] === 'revoked'): ?>
                                <span class="badge badge-danger">Revoked</span>
                            <?php elseif ($client['state'] === 'disabled'): ?>
                                <span class="badge badge-warning">Disabled</span>
                            <?php else: ?>
                                <span class="badge badge-success">Active</span>
                            <?php endif; ?>
                        </span>
                    </div>

                    <div class="detail-item">
                        <span class="detail-label">VPN IP Address</span>
                        <span class="detail-value"><code><?= h($client['vpn_ip']) ?>/32</code></span>
                    </div>

                    <div class="detail-item">
                        <span class="detail-label">Connection Status</span>
                        <span class="detail-value">
                            <?php if ($client['is_online']): ?>
                                <span class="badge badge-success"><span class="dot dot-online"></span> Online</span>
                            <?php else: ?>
                                <span class="badge badge-neutral"><span class="dot dot-offline"></span> Offline</span>
                            <?php endif; ?>
                        </span>
                    </div>

                    <div class="detail-item">
                        <span class="detail-label">Last Handshake</span>
                        <span class="detail-value"><?= h($client['handshake_text']) ?></span>
                    </div>

                    <div class="detail-item">
                        <span class="detail-label">Data Received (RX)</span>
                        <span class="detail-value"><?= h($client['rx_formatted']) ?></span>
                    </div>

                    <div class="detail-item">
                        <span class="detail-label">Data Sent (TX)</span>
                        <span class="detail-value"><?= h($client['tx_formatted']) ?></span>
                    </div>

                    <div class="detail-item">
                        <span class="detail-label">Client Endpoint</span>
                        <span class="detail-value"><code><?= h($client['endpoint']) ?></code></span>
                    </div>

                    <div class="detail-item">
                        <span class="detail-label">Created At</span>
                        <span class="detail-value text-sm"><?= h($client['created_at']) ?></span>
                    </div>

                    <div class="detail-item detail-full">
                        <span class="detail-label">Client Public Key</span>
                        <span class="detail-value font-mono"><code><?= h($client['public_key']) ?></code></span>
                    </div>
                </div>
            </div>
        </div>

        <?php if ($client['state'] !== 'revoked'): ?>
            <div class="card">
                <div class="card-header flex-between">
                    <h2 class="card-title">WireGuard Mobile QR Code</h2>
                    <?php if (empty($client['private_key'])): ?>
                        <span class="badge badge-warning">Imported Peer</span>
                    <?php endif; ?>
                </div>
                <div class="card-body text-center">
                    <?php if (empty($client['private_key'])): ?>
                        <div class="alert alert-info" style="text-align: left;">
                            <strong>Imported Client Notice:</strong><br>
                            This client was auto-imported from the WireGuard interface without a private key (private keys reside on client devices).<br><br>
                            To scan and connect a new mobile phone or device with this client's IP (<code><?= h($client['vpn_ip']) ?></code>), generate a fresh key pair:
                            <form method="POST" action="/client.php?id=<?= (int)$client['id'] ?>" class="mt-3">
                                <input type="hidden" name="action" value="rekey">
                                <button type="submit" class="btn btn-primary btn-sm">&#x21bb; Generate New Key Pair &amp; QR Code</button>
                            </form>
                        </div>
                    <?php elseif (!empty($qrDataUri)): ?>
                        <div class="qr-container">
                            <img src="<?= $qrDataUri ?>" alt="WireGuard QR Code" class="qr-img">
                        </div>
                        <p class="text-muted text-xs mt-3">Scan this code using the official WireGuard app on iOS or Android.</p>
                    <?php else: ?>
                        <div class="alert alert-warning" style="text-align: left;">
                            <strong>Barcode Generator Missing:</strong><br>
                            To display the QR barcode, install <code>qrencode</code> on your server:<br>
                            <code class="mt-1" style="display:inline-block; padding: 4px 8px; background: #000; border-radius: 4px;">dnf install -y qrencode</code>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <!-- Right Column: Configuration -->
    <div>
        <div class="card">
            <div class="card-header flex-between">
                <h2 class="card-title">Client Configuration (.conf)</h2>
                <?php if ($client['state'] !== 'revoked'): ?>
                    <button type="button" class="btn btn-secondary btn-sm" onclick="copyConfig()">Copy Config</button>
                <?php endif; ?>
            </div>
            <div class="card-body">
                <?php if ($client['state'] === 'revoked'): ?>
                    <div class="alert alert-danger">
                        This client is revoked. Its configuration and cryptographic keys are permanently invalidated.
                    </div>
                <?php else: ?>
                    <pre id="configBox" class="code-box"><?= h($configText) ?></pre>
                    <div class="form-actions mt-3">
                        <a href="/client.php?id=<?= (int)$client['id'] ?>&download=1" class="btn btn-primary">&darr; Download Configuration File</a>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Revoke Confirmation Modal -->
<div id="revokeModal" class="modal" style="display: none;">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">Confirm Revocation</h3>
                <button type="button" class="modal-close" onclick="closeModal('revokeModal')">&times;</button>
            </div>
            <div class="modal-body">
                <p>Are you sure you want to revoke <strong id="revokeClientName"></strong>?</p>
                <div class="alert alert-danger mt-2">
                    Revocation immediately terminates VPN access and clears private keys. This cannot be undone.
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('revokeModal')">Cancel</button>
                <form method="POST" action="/client.php?id=<?= (int)$client['id'] ?>" class="inline-form">
                    <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                    <input type="hidden" name="action" value="revoke">
                    <button type="submit" class="btn btn-danger">Confirm Revoke</button>
                </form>
            </div>
        </div>
    </div>
</div>
