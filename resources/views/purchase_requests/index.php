<?php /** @var App\Core\Paginator $page */ $prio = ['emergency' => 'danger', 'urgent' => 'warning', 'routine' => 'secondary']; ?>
<div class="page-header">
    <div>
        <nav aria-label="breadcrumb"><ol class="breadcrumb"><li class="breadcrumb-item">Procurement</li><li class="breadcrumb-item active" aria-current="page">Purchase requests</li></ol></nav>
        <h1>Purchase requests</h1>
        <div class="subtitle">Request → approval by a different person → purchase orders.</div>
    </div>
    <?php if (can('purchase_requests.create')): ?>
    <div class="d-flex gap-2">
        <a href="<?= e(route('purchase_requests.low_stock')) ?>" class="btn btn-outline-secondary"><i class="bi bi-arrow-down-circle me-1" aria-hidden="true"></i>From low stock</a>
        <a href="<?= e(route('purchase_requests.create')) ?>" class="btn btn-primary"><i class="bi bi-plus-lg me-1" aria-hidden="true"></i>New request</a>
    </div>
    <?php endif; ?>
</div>
<div class="card">
    <form class="filter-bar" method="get">
        <input type="search" name="q" class="form-control search-input" placeholder="PR number" value="<?= e($filters['q']) ?>" aria-label="Search">
        <select name="status" class="form-select" data-auto-submit aria-label="Status">
            <option value="">Any status</option>
            <?php foreach (['submitted' => 'Awaiting approval', 'to_order' => 'Approved – to order', 'ordered' => 'Ordered', 'draft' => 'Draft', 'rejected' => 'Rejected', 'cancelled' => 'Cancelled'] as $k => $v): ?><option value="<?= $k ?>"<?= $filters['status'] === $k ? ' selected' : '' ?>><?= e($v) ?></option><?php endforeach; ?>
        </select>
    </form>
    <?php if ($page->items): ?>
    <div class="table-responsive"><table class="table table-hover">
        <thead><tr><th scope="col">PR</th><th scope="col">Priority</th><th scope="col">Department</th><th scope="col" class="num">Items</th><th scope="col" class="num">Estimated</th><th scope="col">Source</th><th scope="col">Status</th><th scope="col">Requested</th></tr></thead>
        <tbody>
        <?php foreach ($page->items as $p): ?>
            <tr>
                <td><a class="fw-semibold" href="<?= e(route('purchase_requests.show', ['id' => $p['id']])) ?>"><?= e($p['pr_number']) ?></a></td>
                <td><span class="badge text-bg-<?= $prio[$p['priority']] ?> badge-status"><?= e(label($p['priority'])) ?></span></td>
                <td class="small"><?= e($p['department_name']) ?></td>
                <td class="num"><?= (int) $p['item_count'] ?></td>
                <td class="num"><?= e(money($p['estimated_total'], false)) ?></td>
                <td class="small"><?= e(['manual' => 'Manual', 'reorder_suggestion' => 'Low-stock reorder', 'ai_proposal' => 'AI proposal'][$p['source']] ?? $p['source']) ?></td>
                <td><?= status_badge($p['status']) ?></td>
                <td class="small text-nowrap"><?= e(time_ago($p['created_at'])) ?><span class="cell-sub"><?= e($p['requested_by_name']) ?></span></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
    <?php else: ?><?= partial('partials/empty', ['icon' => 'bi-file-earmark-plus', 'title' => 'No purchase requests']) ?><?php endif; ?>
    <?= partial('partials/pagination', ['page' => $page]) ?>
</div>
