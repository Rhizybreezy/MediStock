<?php /** @var App\Core\Paginator $page */
$statuses = ['pending' => 'Awaiting approval / dispatch', 'open' => 'In transit', 'received' => 'Received', 'rejected' => 'Rejected', 'cancelled' => 'Cancelled'];
$typeLabel = ['transfer' => 'Transfer', 'return_to_store' => 'Return', 'redistribution' => 'Redistribution'];
$statusText = ['pending_approval' => ['Awaiting approval', 'warning'], 'approved' => ['Approved – to dispatch', 'primary'], 'dispatched' => ['In transit', 'info'], 'partially_received' => ['Partially received', 'warning'], 'received' => ['Received', 'success'], 'rejected' => ['Rejected', 'danger'], 'cancelled' => ['Cancelled', 'secondary']];
?>
<div class="page-header">
    <div>
        <nav aria-label="breadcrumb"><ol class="breadcrumb"><li class="breadcrumb-item">Departments</li><li class="breadcrumb-item active" aria-current="page">Transfers &amp; returns</li></ol></nav>
        <h1>Transfers &amp; returns</h1>
        <div class="subtitle">Moves between departments and returns to the central store. Every move needs approval by a different person.</div>
    </div>
    <?php if (can('transfers.create')): ?>
    <div class="d-flex gap-2">
        <a href="<?= e(route('transfers.create', [], ['type' => 'return_to_store'])) ?>" class="btn btn-outline-secondary"><i class="bi bi-arrow-return-left me-1" aria-hidden="true"></i>Return to store</a>
        <a href="<?= e(route('transfers.create')) ?>" class="btn btn-primary"><i class="bi bi-arrow-left-right me-1" aria-hidden="true"></i>New transfer</a>
    </div>
    <?php endif; ?>
</div>
<div class="card">
    <form class="filter-bar" method="get" role="search">
        <input type="search" name="q" class="form-control search-input" placeholder="Transfer number" value="<?= e($filters['q']) ?>" aria-label="Search">
        <select name="type" class="form-select" data-auto-submit aria-label="Type">
            <option value="">Transfers and returns</option>
            <option value="transfer"<?= $filters['type'] === 'transfer' ? ' selected' : '' ?>>Transfers only</option>
            <option value="return_to_store"<?= $filters['type'] === 'return_to_store' ? ' selected' : '' ?>>Returns only</option>
        </select>
        <select name="status" class="form-select" data-auto-submit aria-label="Status">
            <option value="">Any status</option>
            <?php foreach ($statuses as $k => $v): ?><option value="<?= $k ?>"<?= $filters['status'] === $k ? ' selected' : '' ?>><?= e($v) ?></option><?php endforeach; ?>
        </select>
    </form>
    <?php if ($page->items): ?>
    <div class="table-responsive"><table class="table table-hover">
        <thead><tr><th scope="col"><?= sort_link('number', 'Number') ?></th><th scope="col">Type</th><th scope="col">From</th><th scope="col">To</th><th scope="col" class="num">Products</th><th scope="col">Status</th><th scope="col"><?= sort_link('date', 'Requested') ?></th></tr></thead>
        <tbody>
        <?php foreach ($page->items as $t): [$st, $tone] = $statusText[$t['status']] ?? [label($t['status']), 'secondary']; ?>
            <tr>
                <td><a class="fw-semibold" href="<?= e(route('transfers.show', ['id' => $t['id']])) ?>"><?= e($t['transfer_number']) ?></a><span class="cell-sub"><?= e($t['reason'] ?? '') ?></span></td>
                <td class="small"><?= e($typeLabel[$t['type']] ?? label($t['type'])) ?></td>
                <td class="small"><?= e($t['source_location']) ?><span class="cell-sub"><?= e($t['source_department']) ?></span></td>
                <td class="small"><?= e($t['destination_location']) ?><span class="cell-sub"><?= e($t['destination_department']) ?></span></td>
                <td class="num"><?= (int) $t['product_count'] ?></td>
                <td><span class="badge text-bg-<?= $tone ?> badge-status"><?= e($st) ?></span></td>
                <td class="small text-nowrap"><?= e(time_ago($t['created_at'])) ?><span class="cell-sub"><?= e($t['requested_by_name']) ?></span></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
    <?php else: ?>
        <?= partial('partials/empty', ['icon' => 'bi-arrow-left-right', 'title' => 'No transfers or returns']) ?>
    <?php endif; ?>
    <?= partial('partials/pagination', ['page' => $page]) ?>
</div>
