<?php
/**
 * System panel shown at the top of the administrator dashboard.
 * @var array $a  RoleDashboardService::adminPanel()
 */
$reconAt = $a['last_reconcile']['created_at'] ?? null;
$reconOk = $reconAt && str_contains((string) $a['last_reconcile']['description'], ' 0 mismatch');
$alertsAge = $a['alerts_last_run'] ? time() - strtotime($a['alerts_last_run']) : null;
?>
<div class="row g-3 mb-3">
    <?= partial('dashboard/partials/stat', ['label' => 'Active users', 'value' => number_format($a['users_active']), 'meta' => $a['users_inactive'] . ' inactive · ' . $a['users_must_change'] . ' must change password', 'icon' => 'bi-people', 'tone' => 'primary', 'url' => route('admin.users.index')]) ?>
    <?= partial('dashboard/partials/stat', ['label' => 'Locked accounts', 'value' => number_format($a['users_locked']), 'meta' => $a['users_locked'] ? 'Unlock them from Users' : 'No locked accounts', 'icon' => 'bi-lock', 'tone' => $a['users_locked'] ? 'danger' : 'success', 'url' => route('admin.users.index', [], ['status' => 'locked'])]) ?>
    <?= partial('dashboard/partials/stat', ['label' => 'Failed sign-ins (24 h)', 'value' => number_format($a['failed_logins_24h']), 'meta' => 'Wrong username or password', 'icon' => 'bi-shield-exclamation', 'tone' => $a['failed_logins_24h'] > 10 ? 'warning' : 'neutral', 'url' => null]) ?>
    <?= partial('dashboard/partials/stat', ['label' => 'Access denied (7 days)', 'value' => number_format($a['denied_7d']), 'meta' => 'Attempts to open pages without permission', 'icon' => 'bi-slash-circle', 'tone' => 'neutral', 'url' => route('admin.audit.index', [], ['action' => 'access_denied'])]) ?>
</div>
<div class="row g-3 mb-3">
    <div class="col-xl-4">
        <div class="card h-100">
            <div class="card-header"><h3 class="card-title"><i class="bi bi-heart-pulse me-1" aria-hidden="true"></i>System health</h3></div>
            <ul class="list-group list-group-flush small">
                <li class="list-group-item d-flex justify-content-between gap-2">
                    <span>Stock reconciliation</span>
                    <?php if ($reconAt): ?>
                        <span class="<?= $reconOk ? 'text-success' : 'text-danger' ?> text-end"><?= $reconOk ? 'Balances match' : 'Check results' ?> · <?= e(time_ago($reconAt)) ?></span>
                    <?php else: ?><span class="text-warning">Never run</span><?php endif; ?>
                </li>
                <li class="list-group-item d-flex justify-content-between gap-2">
                    <span>Alert scan</span>
                    <?php if ($alertsAge !== null): ?>
                        <span class="<?= $alertsAge <= 3600 ? 'text-success' : 'text-warning' ?>"><?= e(time_ago($a['alerts_last_run'])) ?></span>
                    <?php else: ?><span class="text-warning">Never run</span><?php endif; ?>
                </li>
                <li class="list-group-item d-flex justify-content-between gap-2">
                    <span>AI assistant</span>
                    <span class="<?= $a['ai_configured'] ? 'text-success' : 'text-muted' ?>"><?= $a['ai_configured'] ? 'Enabled' : 'Disabled (no API key or switched off)' ?></span>
                </li>
            </ul>
            <div class="card-body py-2 small text-muted border-top">For a full check run <code>php bin/console system:check</code> on the server.</div>
        </div>
    </div>
    <div class="col-xl-8">
        <div class="card h-100">
            <div class="card-header">
                <h3 class="card-title"><i class="bi bi-journal-text me-1" aria-hidden="true"></i>Latest audit entries</h3>
                <a class="small" href="<?= e(route('admin.audit.index')) ?>">Audit log <i class="bi bi-arrow-right" aria-hidden="true"></i></a>
            </div>
            <div class="table-responsive">
                <table class="table table-sm table-hover mb-0">
                    <thead><tr><th scope="col">When</th><th scope="col">User</th><th scope="col">Action</th><th scope="col">Details</th></tr></thead>
                    <tbody>
                    <?php foreach ($a['recent_audit'] as $r): ?>
                        <tr>
                            <td class="text-nowrap small"><a href="<?= e(route('admin.audit.show', ['id' => (int) $r['id']])) ?>"><?= e(time_ago($r['created_at'])) ?></a></td>
                            <td class="small"><?= e($r['full_name'] ?? 'System') ?></td>
                            <td><span class="badge text-bg-light border"><?= e(label($r['action'])) ?></span></td>
                            <td class="small text-truncate" style="max-width: 22rem"><?= e($r['description'] ?? $r['entity_type'] ?? '') ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
<h2 class="h6 text-muted text-uppercase mb-2">Hospital inventory</h2>
