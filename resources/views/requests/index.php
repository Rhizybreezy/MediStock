<?php /** @var App\Core\Paginator $page */
$statuses = ['to_review' => 'Awaiting review', 'to_issue' => 'Ready to issue', 'open' => 'All open', 'draft' => 'Draft', 'received' => 'Received', 'rejected' => 'Rejected', 'cancelled' => 'Cancelled', 'all' => 'All'];
$prioClass = ['emergency' => 'danger', 'urgent' => 'warning', 'routine' => 'secondary'];
?>
<div class="page-header">
    <div>
        <nav aria-label="breadcrumb"><ol class="breadcrumb"><li class="breadcrumb-item">Departments</li><li class="breadcrumb-item active" aria-current="page">Stock requests</li></ol></nav>
        <h1>Stock requests</h1>
        <div class="subtitle">Department requests → review (full, partial or reject) → issue → department confirms receipt.</div>
    </div>
    <?php if (can('requests.create')): ?><a href="<?= e(route('requests.create')) ?>" class="btn btn-primary"><i class="bi bi-clipboard-plus me-1" aria-hidden="true"></i>New request</a><?php endif; ?>
</div>
<div class="card">
    <form class="filter-bar" method="get" role="search">
        <input type="search" name="q" class="form-control search-input" placeholder="Request number" value="<?= e($filters['q']) ?>" aria-label="Search">
        <select name="status" class="form-select" data-auto-submit aria-label="Status">
            <?php foreach ($statuses as $k => $label): ?><option value="<?= e($k) ?>"<?= ($filters['status'] === $k || ($k === 'all' && $filters['status'] === '')) ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
        </select>
        <select name="priority" class="form-select" data-auto-submit aria-label="Priority">
            <option value="">Any priority</option>
            <?php foreach (['emergency', 'urgent', 'routine'] as $p): ?><option value="<?= $p ?>"<?= $filters['priority'] === $p ? ' selected' : '' ?>><?= e(label($p)) ?></option><?php endforeach; ?>
        </select>
        <select name="department_id" class="form-select" data-auto-submit aria-label="Department">
            <option value="">All departments</option>
            <?php foreach ($departments as $d): ?><option value="<?= (int) $d['id'] ?>"<?= (string) $filters['department_id'] === (string) $d['id'] ? ' selected' : '' ?>><?= e($d['name']) ?></option><?php endforeach; ?>
        </select>
    </form>
    <?php if ($page->items): ?>
    <div class="table-responsive"><table class="table table-hover">
        <thead><tr><th scope="col"><?= sort_link('number', 'Request') ?></th><th scope="col"><?= sort_link('priority', 'Priority') ?></th><th scope="col">Department</th><th scope="col">Supplied by</th><th scope="col" class="num">Items</th><th scope="col"><?= sort_link('needed', 'Needed by') ?></th><th scope="col">Status</th><th scope="col"><?= sort_link('date', 'Requested') ?></th></tr></thead>
        <tbody>
        <?php foreach ($page->items as $r): ?>
            <tr>
                <td><a class="fw-semibold" href="<?= e(route('requests.show', ['id' => $r['id']])) ?>"><?= e($r['request_number']) ?></a></td>
                <td><span class="badge text-bg-<?= $prioClass[$r['priority']] ?> badge-status"><?= e(label($r['priority'])) ?></span></td>
                <td class="small"><?= e($r['requesting_department']) ?></td>
                <td class="small"><?= e($r['supplying_department']) ?></td>
                <td class="num"><?= (int) $r['item_count'] ?></td>
                <td class="small"><?= e(format_date($r['needed_by'])) ?></td>
                <td><?= status_badge($r['status']) ?></td>
                <td class="small text-nowrap"><?= e(time_ago($r['created_at'])) ?><span class="cell-sub"><?= e($r['requested_by_name']) ?></span></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
    <?php else: ?>
        <?= partial('partials/empty', ['icon' => 'bi-clipboard-check', 'title' => 'No requests here', 'text' => 'Try a different status filter.']) ?>
    <?php endif; ?>
    <?= partial('partials/pagination', ['page' => $page]) ?>
</div>
