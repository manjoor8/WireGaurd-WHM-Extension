<?php
/**
 * @var array $logs
 */
?>

<div class="page-header">
    <div>
        <h1 class="page-title">Audit Logs</h1>
        <p class="page-subtitle">Security and administrative activity trail</p>
    </div>
</div>

<div class="card">
    <div class="card-body p-0">
        <?php if (empty($logs)): ?>
            <div class="empty-state">
                <p>No audit log events recorded yet.</p>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Timestamp</th>
                            <th>Action</th>
                            <th>Actor</th>
                            <th>Client IP</th>
                            <th>Details</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($logs as $log): ?>
                            <tr>
                                <td class="text-sm"><?= h($log['created_at']) ?></td>
                                <td>
                                    <?php
                                    $actionClass = 'badge-neutral';
                                    if (str_starts_with($log['action'], 'CREATE')) $actionClass = 'badge-success';
                                    elseif (str_starts_with($log['action'], 'REVOKE')) $actionClass = 'badge-danger';
                                    elseif (str_starts_with($log['action'], 'DISABLE')) $actionClass = 'badge-warning';
                                    elseif (str_starts_with($log['action'], 'ENABLE')) $actionClass = 'badge-info';
                                    ?>
                                    <span class="badge <?= $actionClass ?>"><?= h($log['action']) ?></span>
                                </td>
                                <td><code><?= h($log['actor']) ?></code></td>
                                <td><code><?= h($log['ip_address']) ?></code></td>
                                <td class="text-sm"><?= h($log['details']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>
