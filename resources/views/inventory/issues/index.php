<?php /** @var App\Core\Paginator $page */
$incoming = $mode === 'incoming';
$statuses = ['open' => 'Awaiting receipt', 'dispatched' => 'Dispatched', 'partially_received' => 'Partially received', 'received' => 'Received'];
?>
<div class="page-header">
    <div>
        <nav aria-label="breadcrumb"><ol class="breadcrumb"><li class="breadcrumb-item">Inventory</li><li class="breadcrumb-item active" aria-current="page"><?= e($title) ?></li></ol></nav>
        <h1><?= e($title) ?></h1>
        <div class="subtitle"><?= $incoming ? 'Stock sent to your departments. Confirm what physically arrives.' : 'Stock dispatched from your stores. It stays “in transit” until the receiving department confirms.' ?></div>
    </div>
    <?php if (!$incoming && can('stock.issue')): ?><a href="<?= e(route('inventory.issues.create')) ?>" class="btn btn-primary"><i class="bi bi-box-arrow-up-right me-1" aria-hidden="true"></i>Issue stock</a><?php endif; ?>
</div>
<div class="card">
    <form class="filter-bar" method="get" role="search">
        <input type="search" name="q" class="form-control search-input" placeholder="Issue number" value="<?= e($filters['q']) ?>" aria-label="Search">
        <select name="status" class="form-select" data-auto-submit aria-label="Status">
            <?php if (!$incoming): ?><option value="">All statuses</option><?php else: ?><option value="all"<?= $filters['status'] === 'all' ? ' selected' : '' ?>>All statuses</option><?php endif; ?>
            <?php foreach ($statuses as $k => $label): ?><option value="<?= e($k) ?>"<?= $filters['status'] === $k ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
        </select>
        <button type="submit" class="btn btn-outline-secondary"><i class="bi bi-search" aria-hidden="true"></i><span class="visually-hidden">Search</span></button>
    </form>
    <?php if ($page->items): ?>
    <div class="table-responsive"><table class="table table-hover">
        <thead><tr><th scope="col"><?= sort_link('number', 'Number') ?></th><th scope="col"><?= sort_link('date', 'Dispatched') ?></th><th scope="col">From</th><th scope="col">To</th><th scope="col" class="num">Products</th><th scope="col">Status</th><th scope="col" class="table-actions"><span class="visually-hidden">Open</span></th></tr></thead>
        <tbody>
        <?php foreach ($page->items as $t): $open = in_array($t['status'], ['dispatched', 'partially_received'], true); ?>
            <tr>
                <td><a class="fw-semibold" href="<?= e(route('inventory.issues.show', ['id' => $t['id']])) ?>"><?= e($t['transfer_number']) ?></a><span class="cell-sub"><?= e($t['reason'] ?? '') ?></span></td>
                <td class="small text-nowrap"><?= e(format_date($t['dispatched_at'], true)) ?><span class="cell-sub"><?= e($t['dispatched_by_name'] ?? '') ?></span></td>
                <td class="small"><?= e($t['source_location']) ?><span class="cell-sub"><?= e($t['source_department']) ?></span></td>
                <td class="small"><?= e($t['destination_location']) ?><span class="cell-sub"><?= e($t['destination_department']) ?></span></td>
                <td class="num"><?= (int) $t['product_count'] ?></td>
                <td><?= status_badge($t['status'] === 'dispatched' ? 'submitted' : ($t['status'] === 'partially_received' ? 'pending' : $t['status'])) ?><?php if ($open): ?><span class="cell-sub"><?= e(qty($t['outstanding_qty'])) ?> unit(s) in transit</span><?php endif; ?></td>
                <td class="table-actions"><a href="<?= e(route('inventory.issues.show', ['id' => $t['id']])) ?>" class="btn btn-sm <?= $incoming && $open ? 'btn-primary' : 'btn-outline-secondary' ?>"><?= $incoming && $open ? 'Confirm receipt' : 'View' ?></a></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
    <?php else: ?>
        <?= partial('partials/empty', ['icon' => 'bi-truck', 'title' => $incoming ? 'Nothing awaiting receipt' : 'No issues yet', 'text' => $incoming ? 'Deliveries to your departments will appear here.' : 'Issued stock will be listed here.']) ?>
    <?php endif; ?>
    <?= partial('partials/pagination', ['page' => $page]) ?>
</div>
