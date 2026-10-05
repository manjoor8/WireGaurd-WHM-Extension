<?php
/**
 * @var array $clients
 * @var string $csrfToken
 */
?>

<!-- Clients Header Bar -->
<div class="clients-header-bar">
    <h1 class="clients-heading">Clients</h1>
    
    <div class="clients-header-controls">
        <!-- Search Input -->
        <div class="search-box-wrapper">
            <svg class="search-icon" viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <circle cx="11" cy="11" r="8"></circle>
                <line x1="21" y1="21" x2="16.65" y2="16.65"></line>
            </svg>
            <input type="search" id="clientSearch" class="search-input" placeholder="Search clients..." autocomplete="off">
        </div>

        <!-- Red Sort Button & Dropdown -->
        <div class="sort-dropdown-container">
            <button type="button" class="btn-sort" id="btnSort" aria-expanded="false" aria-haspopup="true">
                <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                    <line x1="12" y1="5" x2="12" y2="19"></line>
                    <polyline points="19 12 12 19 5 12"></polyline>
                </svg>
                <span>Sort</span>
            </button>
            <div class="sort-menu" id="sortMenu" style="display: none;">
                <button type="button" class="sort-option active" data-sort="name-asc">Name (A &rarr; Z)</button>
                <button type="button" class="sort-option" data-sort="name-desc">Name (Z &rarr; A)</button>
                <button type="button" class="sort-option" data-sort="ip-asc">IP Address (Low &rarr; High)</button>
                <button type="button" class="sort-option" data-sort="ip-desc">IP Address (High &rarr; Low)</button>
                <button type="button" class="sort-option" data-sort="traffic-desc">Traffic (Highest)</button>
                <button type="button" class="sort-option" data-sort="date-desc">Date Created (Newest)</button>
                <button type="button" class="sort-option" data-sort="date-asc">Date Created (Oldest)</button>
                <button type="button" class="sort-option" data-sort="status">Status (Active First)</button>
            </div>
        </div>

        <!-- + New Client Button -->
        <a href="/add-client.php" class="btn-new-client">
            <span class="plus-symbol">+</span> New
        </a>
    </div>
</div>

<!-- Clients List Card Container -->
<div class="clients-list-card">
    <div id="clientsList" class="clients-list">
        <?php if (empty($clients)): ?>
            <div class="empty-state" id="emptyClientsNotice">
                <p>No clients configured yet.</p>
                <a href="/add-client.php" class="btn-new-client mt-3" style="display: inline-flex;">+ Add Your First Client</a>
            </div>
        <?php else: ?>
            <div class="no-search-results" id="noSearchResults" style="display: none; padding: 2.5rem; text-align: center; color: var(--text-muted);">
                <p>No clients matching your search query.</p>
            </div>

            <?php foreach ($clients as $c): 
                $totalTraffic = (int)($c['transfer_rx'] ?? 0) + (int)($c['transfer_tx'] ?? 0);
                $isRevoked = $c['state'] === 'revoked';
                $isDisabled = $c['state'] === 'disabled';
                $isActive = $c['state'] === 'active';
            ?>
                <div class="client-card-row <?= $isDisabled ? 'client-inactive' : ($isRevoked ? 'client-revoked' : '') ?>"
                     id="client-row-<?= (int)$c['id'] ?>"
                     data-id="<?= (int)$c['id'] ?>"
                     data-name="<?= h(strtolower($c['name'])) ?>"
                     data-display-name="<?= h($c['name']) ?>"
                     data-ip="<?= h($c['vpn_ip']) ?>"
                     data-desc="<?= h(strtolower($c['description'] ?? '')) ?>"
                     data-traffic="<?= $totalTraffic ?>"
                     data-date="<?= h($c['created_at']) ?>"
                     data-state="<?= h($c['state']) ?>">
                    
                    <!-- Left: Avatar & Identity -->
                    <div class="client-main-col">
                        <div class="client-avatar-icon">
                            <svg viewBox="0 0 24 24" width="22" height="22" fill="currentColor">
                                <path d="M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm0 2c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z"/>
                            </svg>
                        </div>
                        <div class="client-identity">
                            <div class="client-name-status">
                                <a href="/client.php?id=<?= (int)$c['id'] ?>" class="client-title-link" id="client-title-<?= (int)$c['id'] ?>"><?= h($c['name']) ?></a>
                                <span class="client-badge-wrapper" id="client-badge-<?= (int)$c['id'] ?>">
                                    <?php if ($isRevoked): ?>
                                        <span class="badge badge-danger">Revoked</span>
                                    <?php elseif ($isDisabled): ?>
                                        <span class="badge badge-warning">Disconnected</span>
                                    <?php elseif (!empty($c['is_online'])): ?>
                                        <span class="badge badge-success"><span class="dot dot-online"></span> Online</span>
                                    <?php else: ?>
                                        <span class="badge badge-neutral"><span class="dot dot-offline"></span> Offline</span>
                                    <?php endif; ?>
                                </span>
                            </div>
                            <div class="client-ip-text">
                                <?= h($c['vpn_ip']) ?>
                            </div>
                            <div class="client-subtitle-text" id="client-subtitle-<?= (int)$c['id'] ?>">
                                <?php if (!empty($c['description'])): ?>
                                    <span><?= h($c['description']) ?></span>
                                <?php elseif (!empty($c['created_at'])): ?>
                                    <span><?= date('F j, Y', strtotime($c['created_at'])) ?></span>
                                <?php else: ?>
                                    <span>Permanent</span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Center: Transfer Statistics (↓ and ↑) -->
                    <div class="client-transfer-col" id="transfer-col-<?= (int)$c['id'] ?>">
                        <?php if ($isActive && ($c['transfer_rx'] > 0 || $c['transfer_tx'] > 0)): ?>
                            <div class="transfer-stat-item">
                                <div class="transfer-rate"><span class="arrow-down">&darr;</span> <?= h($c['rx_formatted']) ?></div>
                                <div class="transfer-total"><?= h($c['rx_formatted']) ?></div>
                            </div>
                            <div class="transfer-stat-item">
                                <div class="transfer-rate"><span class="arrow-up">&uarr;</span> <?= h($c['tx_formatted']) ?></div>
                                <div class="transfer-total"><?= h($c['tx_formatted']) ?></div>
                            </div>
                        <?php elseif ($isActive): ?>
                            <div class="transfer-stat-item stat-idle">
                                <div class="transfer-rate"><span class="arrow-down">&darr;</span> 0 B</div>
                                <div class="transfer-total">0 B</div>
                            </div>
                            <div class="transfer-stat-item stat-idle">
                                <div class="transfer-rate"><span class="arrow-up">&uarr;</span> 0 B</div>
                                <div class="transfer-total">0 B</div>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Right: Dropdown Action Menu for each record -->
                    <div class="client-actions-col">
                        <div class="action-dropdown-wrapper">
                            <button type="button" class="btn-action-dropdown" id="btnAction-<?= (int)$c['id'] ?>" aria-expanded="false" aria-haspopup="true">
                                <span>Action</span>
                                <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                                    <polyline points="6 9 12 15 18 9"></polyline>
                                </svg>
                            </button>
                            <div class="action-dropdown-menu" id="actionMenu-<?= (int)$c['id'] ?>" style="display: none;">
                                <?php if (!$isRevoked): ?>
                                    <!-- Toggle Connect / Disconnect -->
                                    <button type="button" class="dropdown-item btn-menu-toggle" data-client-id="<?= (int)$c['id'] ?>">
                                        <?php if ($isActive): ?>
                                            <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                                <path d="M18.36 6.64a9 9 0 1 1-12.73 0"></path>
                                                <line x1="12" y1="2" x2="12" y2="12"></line>
                                            </svg>
                                            <span class="toggle-text">Disconnect Client</span>
                                        <?php else: ?>
                                            <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                                <polyline points="20 6 9 17 4 12"></polyline>
                                            </svg>
                                            <span class="toggle-text">Connect Client</span>
                                        <?php endif; ?>
                                    </button>

                                    <!-- Edit Client Details -->
                                    <button type="button" class="dropdown-item btn-edit-trigger" 
                                            data-client-id="<?= (int)$c['id'] ?>" 
                                            data-client-name="<?= h($c['name']) ?>" 
                                            data-client-desc="<?= h($c['description'] ?? '') ?>">
                                        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                            <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path>
                                            <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path>
                                        </svg>
                                        <span>Edit Details</span>
                                    </button>

                                    <!-- QR Code -->
                                    <button type="button" class="dropdown-item btn-qr-trigger" 
                                            data-client-id="<?= (int)$c['id'] ?>" 
                                            data-client-name="<?= h($c['name']) ?>">
                                        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                            <rect x="3" y="3" width="7" height="7"></rect>
                                            <rect x="14" y="3" width="7" height="7"></rect>
                                            <rect x="14" y="14" width="7" height="7"></rect>
                                            <rect x="3" y="14" width="7" height="7"></rect>
                                        </svg>
                                        <span>Show QR Code</span>
                                    </button>

                                    <!-- Download Configuration (.conf) -->
                                    <a href="/client.php?id=<?= (int)$c['id'] ?>&download=1" class="dropdown-item">
                                        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                            <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path>
                                            <polyline points="7 10 12 15 17 10"></polyline>
                                            <line x1="12" y1="15" x2="12" y2="3"></line>
                                        </svg>
                                        <span>Download .conf</span>
                                    </a>
                                <?php endif; ?>

                                <!-- View Details -->
                                <a href="/client.php?id=<?= (int)$c['id'] ?>" class="dropdown-item">
                                    <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                        <path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"></path>
                                        <path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"></path>
                                    </svg>
                                    <span>View Details</span>
                                </a>

                                <div class="dropdown-divider"></div>

                                <!-- Delete Client -->
                                <button type="button" class="dropdown-item dropdown-item-danger btn-delete-trigger" 
                                        data-client-id="<?= (int)$c['id'] ?>" 
                                        data-client-name="<?= h($c['name']) ?>" 
                                        data-client-ip="<?= h($c['vpn_ip']) ?>">
                                    <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                        <polyline points="3 6 5 6 21 6"></polyline>
                                        <path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path>
                                        <line x1="10" y1="11" x2="10" y2="17"></line>
                                        <line x1="14" y1="11" x2="14" y2="17"></line>
                                    </svg>
                                    <span>Delete Client</span>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<!-- Delete Confirmation Modal (Permanently removes from DB and WireGuard) -->
<div id="deleteModal" class="modal" style="display: none;">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">Delete Client</h3>
                <button type="button" class="modal-close" data-close-modal="deleteModal">&times;</button>
            </div>
            <div class="modal-body">
                <p>Are you sure you want to delete client <strong id="deleteClientName"></strong> (<code id="deleteClientIp"></code>)?</p>
                <div class="alert alert-danger mt-3">
                    <strong>Warning:</strong> This will remove the peer from WireGuard and permanently delete the record from the database. This action cannot be undone.
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-close-modal="deleteModal">Cancel</button>
                <form id="deleteClientForm" method="POST" action="/clients.php" class="inline-form">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="client_id" id="deleteClientId" value="">
                    <button type="submit" class="btn btn-danger" id="confirmDeleteBtn">Delete Client</button>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- Edit Client Modal -->
<div id="editModal" class="modal" style="display: none;">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">Edit Client</h3>
                <button type="button" class="modal-close" data-close-modal="editModal">&times;</button>
            </div>
            <form id="editClientForm" method="POST" action="/clients.php">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="update">
                <input type="hidden" name="client_id" id="editClientId" value="">
                <div class="modal-body">
                    <div class="form-group mb-3">
                        <label for="editClientName" class="form-label">Client Name</label>
                        <input type="text" id="editClientName" name="name" class="form-control" required maxlength="64">
                    </div>
                    <div class="form-group">
                        <label for="editClientDesc" class="form-label">Description / Notes</label>
                        <input type="text" id="editClientDesc" name="description" class="form-control" maxlength="255" placeholder="e.g. Work Laptop, Mobile, etc.">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-close-modal="editModal">Cancel</button>
                    <button type="submit" class="btn btn-primary" id="saveEditBtn">Save Changes</button>
                </div>
            </form>
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
                <p class="text-muted text-xs mt-3">Scan this barcode using the official WireGuard app on iOS or Android.</p>
            </div>
            <div class="modal-footer">
                <a href="#" id="qrDownloadBtn" class="btn btn-primary btn-sm" download>&darr; Download .conf</a>
                <button type="button" class="btn btn-secondary btn-sm" data-close-modal="qrModal">Close</button>
            </div>
        </div>
    </div>
</div>
