<?php /** @var App\Core\Paginator $page */
$icons = ['critical' => 'bi-exclamation-octagon-fill text-danger', 'warning' => 'bi-exclamation-triangle-fill text-warning', 'info' => 'bi-info-circle-fill text-info'];
?>
<div class="page-header">
    <div>
        <h1>Notifications</h1>
        <div class="subtitle">Alerts about stock levels, expiry, approvals and deliveries.</div>
    </div>
    <form method="post" action="<?= e(route('notifications.read_all')) ?>">
        <?= csrf_field() ?>
        <button type="submit" class="btn btn-outline-secondary"><i class="bi bi-check2-all me-1" aria-hidden="true"></i>Mark all as read</button>
    </form>
</div>
<div class="card">
    <form class="filter-bar" method="get">
        <select name="status" class="form-select" data-auto-submit aria-label="Filter by status">
            <option value="">All</option>
            <option value="unread"<?= $filters['status'] === 'unread' ? ' selected' : '' ?>>Unread</option>
            <option value="read"<?= $filters['status'] === 'read' ? ' selected' : '' ?>>Read</option>
        </select>
        <select name="severity" class="form-select" data-auto-submit aria-label="Filter by severity">
            <option value="">Any severity</option>
            <?php foreach (['critical', 'warning', 'info'] as $s): ?><option value="<?= $s ?>"<?= $filters['severity'] === $s ? ' selected' : '' ?>><?= e(label($s)) ?></option><?php endforeach; ?>
        </select>
        <?php if ($types): ?>
        <select name="type" class="form-select" data-auto-submit aria-label="Filter by type">
            <option value="">All types</option>
            <?php foreach ($types as $t): ?><option value="<?= e($t) ?>"<?= $filters['type'] === $t ? ' selected' : '' ?>><?= e(label($t)) ?></option><?php endforeach; ?>
        </select>
        <?php endif; ?>
        <noscript><button type="submit" class="btn btn-outline-secondary">Filter</button></noscript>
    </form>
    <?php if ($page->items): ?>
    <div class="list-group list-group-flush list-compact">
        <?php foreach ($page->items as $n): ?>
        <div class="list-group-item d-flex gap-3 align-items-start<?= (int) $n['is_read'] === 0 ? ' bg-primary-subtle bg-opacity-10' : '' ?>">
            <i class="bi <?= e($icons[$n['severity']] ?? $icons['info']) ?> fs-5 mt-1" aria-hidden="true"></i>
            <div class="flex-grow-1 min-w-0">
                <div class="d-flex justify-content-between gap-2 flex-wrap">
                    <span class="fw-semibold"><?= e($n['title']) ?><?php if ((int) $n['is_read'] === 0): ?> <span class="badge text-bg-primary">New</span><?php endif; ?></span>
                    <span class="small text-muted text-nowrap"><?= e(format_date($n['created_at'], true)) ?></span>
                </div>
                <div class="small"><?= e($n['message']) ?></div>
                <?php if ($n['link_url']): ?><a class="small" href="<?= e(url($n['link_url'])) ?>">Open <i class="bi bi-arrow-right" aria-hidden="true"></i></a><?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php else: ?>
        <?= partial('partials/empty', ['icon' => 'bi-bell-slash', 'title' => 'No notifications', 'text' => 'Stock, expiry and approval alerts will appear here.']) ?>
    <?php endif; ?>
    <?= partial('partials/pagination', ['page' => $page]) ?>
</div>
