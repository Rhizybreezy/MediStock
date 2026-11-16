<?php
/**
 * Variables passed by AuditLogController::index() (unpacked by View::render()).
 * @var App\Core\Paginator $page
 * @var array $filters
 * @var array $actions
 * @var array $entityTypes
 * @var array $users
 */
?>
<div class="page-header">
    <div>
        <nav aria-label="breadcrumb"><ol class="breadcrumb"><li class="breadcrumb-item">Administration</li><li class="breadcrumb-item active" aria-current="page">Audit log</li></ol></nav>
        <h1>Audit log</h1>
        <div class="subtitle">Append-only record of sign-ins, administrative changes, approvals and denied access attempts.</div>
    </div>
</div>
<div class="card">
    <form class="filter-bar" method="get" role="search">
        <input type="search" name="q" class="form-control search-input" placeholder="Search description" value="<?= e($filters['q']) ?>" aria-label="Search audit log">
        <select name="user_id" class="form-select" data-auto-submit aria-label="Filter by user">
            <option value="">All users</option>
            <?php foreach ($users as $u): ?><option value="<?= (int) $u['id'] ?>"<?= (string) $filters['user_id'] === (string) $u['id'] ? ' selected' : '' ?>><?= e($u['full_name']) ?></option><?php endforeach; ?>
        </select>
        <select name="action" class="form-select" data-auto-submit aria-label="Filter by action">
            <option value="">All actions</option>
            <?php foreach ($actions as $a): ?><option value="<?= e($a) ?>"<?= $filters['action'] === $a ? ' selected' : '' ?>><?= e(label($a)) ?></option><?php endforeach; ?>
        </select>
        <select name="entity_type" class="form-select" data-auto-submit aria-label="Filter by record type">
            <option value="">All records</option>
            <?php foreach ($entityTypes as $t): ?><option value="<?= e($t) ?>"<?= $filters['entity_type'] === $t ? ' selected' : '' ?>><?= e(label($t)) ?></option><?php endforeach; ?>
        </select>
        <input type="date" name="from" class="form-control" value="<?= e($filters['from']) ?>" aria-label="From date">
        <input type="date" name="to" class="form-control" value="<?= e($filters['to']) ?>" aria-label="To date">
        <button type="submit" class="btn btn-outline-secondary"><i class="bi bi-funnel" aria-hidden="true"></i> Filter</button>
        <?php if (array_filter([$filters['q'], $filters['user_id'], $filters['action'], $filters['entity_type'], $filters['from'], $filters['to']])): ?>
            <a href="<?= e(route('admin.audit.index')) ?>" class="btn btn-link">Clear</a>
        <?php endif; ?>
    </form>
    <?php if ($page->items): ?>
    <div class="table-responsive">
        <table class="table table-hover">
            <thead><tr>
                <th scope="col"><?= sort_link('date', 'Date & time') ?></th>
                <th scope="col"><?= sort_link('user', 'User') ?></th>
                <th scope="col"><?= sort_link('action', 'Action') ?></th>
                <th scope="col">Record</th>
                <th scope="col">Description</th>
                <th scope="col">IP address</th>
                <th scope="col" class="table-actions"><span class="visually-hidden">Details</span></th>
            </tr></thead>
            <tbody>
            <?php foreach ($page->items as $a):
                $tone = match (true) {
                    in_array($a['action'], ['access_denied', 'account_locked', 'delete', 'deactivate'], true) => 'danger',
                    in_array($a['action'], ['login', 'logout'], true) => 'secondary',
                    in_array($a['action'], ['create', 'approve', 'activate'], true) => 'success',
                    default => 'info',
                }; ?>
                <tr>
                    <td class="text-nowrap small"><?= e(format_date($a['created_at'], true)) ?></td>
                    <td class="small"><?= e($a['user_name'] ?? 'System') ?></td>
                    <td><span class="badge text-bg-<?= $tone ?> badge-status"><?= e(label($a['action'])) ?></span></td>
                    <td class="small"><?= $a['entity_type'] ? e(label($a['entity_type'])) . ($a['entity_id'] ? ' #' . (int) $a['entity_id'] : '') : '—' ?></td>
                    <td class="small"><?= e($a['description'] ?? '') ?></td>
                    <td class="small text-muted"><?= e($a['ip'] ?? '—') ?></td>
                    <td class="table-actions"><a href="<?= e(route('admin.audit.show', ['id' => $a['id']])) ?>" class="btn btn-sm btn-outline-secondary">View</a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php else: ?>
        <?= partial('partials/empty', ['icon' => 'bi-journal-text', 'title' => 'No audit entries match these filters']) ?>
    <?php endif; ?>
    <?= partial('partials/pagination', ['page' => $page]) ?>
</div>
