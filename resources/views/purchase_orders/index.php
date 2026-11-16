<?php /** @var App\Core\Paginator $page */
$statusText = ['draft' => 'Draft', 'pending_approval' => 'Awaiting approval', 'approved' => 'Approved', 'sent' => 'Sent', 'partially_received' => 'Partially received', 'received' => 'Received', 'closed' => 'Closed', 'cancelled' => 'Cancelled'];
$tone = ['draft' => 'secondary', 'pending_approval' => 'warning', 'approved' => 'primary', 'sent' => 'info', 'partially_received' => 'warning', 'received' => 'success', 'closed' => 'secondary', 'cancelled' => 'secondary']; ?>
<div class="page-header">
    <div>
        <nav aria-label="breadcrumb"><ol class="breadcrumb"><li class="breadcrumb-item">Procurement</li><li class="breadcrumb-item active" aria-current="page">Purchase orders</li></ol></nav>
        <h1>Purchase orders</h1>
        <div class="subtitle">Draft → approval by a different person → sent → goods received.</div>
    </div>
    <?php if (can('purchase_orders.manage')): ?>
    <div class="d-flex gap-2">
        <a href="<?= e(route('purchase_orders.from_requests')) ?>" class="btn btn-outline-secondary position-relative"><i class="bi bi-file-earmark-arrow-down me-1" aria-hidden="true"></i>From approved requests<?php if ($toOrder): ?> <span class="badge text-bg-warning ms-1"><?= (int) $toOrder ?></span><?php endif; ?></a>
        <a href="<?= e(route('purchase_orders.create')) ?>" class="btn btn-primary"><i class="bi bi-plus-lg me-1" aria-hidden="true"></i>New PO</a>
    </div>
    <?php endif; ?>
</div>
<div class="card">
    <form class="filter-bar" method="get">
        <input type="search" name="q" class="form-control search-input" placeholder="PO number" value="<?= e($filters['q']) ?>" aria-label="Search">
        <select name="status" class="form-select" data-auto-submit aria-label="Status">
            <?php foreach (['open' => 'All open', 'pending_approval' => 'Awaiting approval', 'to_receive' => 'Awaiting delivery', 'late' => 'Overdue deliveries', 'draft' => 'Draft', 'received' => 'Received', 'closed' => 'Closed', 'cancelled' => 'Cancelled', 'all' => 'All'] as $k => $v): ?><option value="<?= $k ?>"<?= ($filters['status'] === $k || ($k === 'all' && $filters['status'] === '')) ? ' selected' : '' ?>><?= e($v) ?></option><?php endforeach; ?>
        </select>
        <select name="supplier_id" class="form-select" data-auto-submit aria-label="Supplier">
            <option value="">All suppliers</option>
            <?php foreach ($suppliers as $s): ?><option value="<?= (int) $s['id'] ?>"<?= (string) $filters['supplier_id'] === (string) $s['id'] ? ' selected' : '' ?>><?= e($s['name']) ?></option><?php endforeach; ?>
        </select>
    </form>
    <?php if ($page->items): ?>
    <div class="table-responsive"><table class="table table-hover">
        <thead><tr><th scope="col"><?= sort_link('date', 'PO') ?></th><th scope="col">Supplier</th><th scope="col" class="num">Lines</th><th scope="col" class="num"><?= sort_link('total', 'Total') ?></th><th scope="col"><?= sort_link('expected', 'Expected') ?></th><th scope="col">Status</th><th scope="col">Created</th></tr></thead>
        <tbody>
        <?php foreach ($page->items as $po): ?>
            <tr>
                <td><a class="fw-semibold" href="<?= e(route('purchase_orders.show', ['id' => $po['id']])) ?>"><?= e($po['po_number']) ?></a></td>
                <td class="small"><?= e($po['supplier_name']) ?></td>
                <td class="num"><?= (int) $po['line_count'] ?></td>
                <td class="num"><?= e(money($po['total_amount'], false)) ?></td>
                <td class="small <?= (int) $po['is_late'] ? 'text-danger fw-semibold' : '' ?>"><?= e(format_date($po['expected_delivery_date'])) ?><?= (int) $po['is_late'] ? ' <i class="bi bi-exclamation-triangle" title="Overdue" aria-label="Overdue"></i>' : '' ?></td>
                <td><span class="badge text-bg-<?= $tone[$po['status']] ?> badge-status"><?= e($statusText[$po['status']]) ?></span></td>
                <td class="small text-nowrap"><?= e(time_ago($po['created_at'])) ?><span class="cell-sub"><?= e($po['created_by_name']) ?></span></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
    <?php else: ?><?= partial('partials/empty', ['icon' => 'bi-file-earmark-text', 'title' => 'No purchase orders here']) ?><?php endif; ?>
    <?= partial('partials/pagination', ['page' => $page]) ?>
</div>
