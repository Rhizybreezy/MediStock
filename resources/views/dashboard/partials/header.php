<?php
/**
 * Dashboard header: greeting, role, scope and quick actions.
 * @var string $role        e.g. "Inventory Manager"
 * @var ?array $scopeNames  null = hospital-wide
 * @var array  $actions     list of [label, icon, url]
 */
$firstName = explode(' ', (string) (user()['full_name'] ?? ''))[0];
$hour = (int) date('G');
$greeting = $hour < 12 ? 'Good morning' : ($hour < 17 ? 'Good afternoon' : 'Good evening');
?>
<div class="page-header">
    <div>
        <h1><?= e($greeting) ?>, <?= e($firstName) ?></h1>
        <div class="subtitle">
            <span class="badge text-bg-light border me-1"><?= e($role) ?></span>
            <?= $scopeNames === null ? 'Hospital-wide view' : 'Your departments: ' . e(implode(', ', $scopeNames) ?: 'none assigned') ?>
            · <?= e(date('l, d F Y')) ?>
        </div>
    </div>
</div>
<?php if ($scopeNames !== null && !$scopeNames): ?>
    <div class="alert alert-warning scope-banner"><i class="bi bi-exclamation-triangle me-1" aria-hidden="true"></i>You are not assigned to any active department, so no stock figures can be shown. Ask an administrator to assign your department.</div>
<?php endif; ?>
<?php if (!empty($actions)): ?>
<nav class="dash-actions mb-3" aria-label="Quick actions">
    <?php foreach ($actions as $a): ?>
        <a class="btn btn-sm btn-outline-primary" href="<?= e($a['url']) ?>"><i class="bi <?= e($a['icon']) ?> me-1" aria-hidden="true"></i><?= e($a['label']) ?></a>
    <?php endforeach; ?>
</nav>
<?php endif; ?>
