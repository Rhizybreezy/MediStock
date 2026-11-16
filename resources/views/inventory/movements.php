<?php /** @var App\Core\Paginator $page */
$refLink = function (array $m): ?string {
    return match ($m['reference_type']) {
        'goods_receipt'  => route('inventory.receipts.show', ['id' => $m['reference_id']]),
        'stock_transfer' => route('inventory.issues.show', ['id' => $m['reference_id']]),
        default          => null,
    };
};
?>
<div class="page-header">
    <div>
        <nav aria-label="breadcrumb"><ol class="breadcrumb"><li class="breadcrumb-item">Inventory</li><li class="breadcrumb-item active" aria-current="page">Movement ledger</li></ol></nav>
        <h1>Stock movement ledger</h1>
        <div class="subtitle">Append-only record of every stock change. Corrections are made with new entries, never by editing history.</div>
    </div>
</div>
<div class="card">
    <form class="filter-bar" method="get" role="search">
        <input type="search" name="q" class="form-control search-input" placeholder="Product, batch or transaction no." value="<?= e($filters['q']) ?>" aria-label="Search ledger">
        <select name="type" class="form-select" data-auto-submit aria-label="Movement type">
            <option value="">All types</option>
            <?php foreach ($types as $t): ?><option value="<?= e($t) ?>"<?= $filters['type'] === $t ? ' selected' : '' ?>><?= e(label(strtolower($t))) ?></option><?php endforeach; ?>
        </select>
        <select name="department_id" class="form-select" data-auto-submit aria-label="Department">
            <option value="">All departments</option>
            <?php foreach ($departments as $d): ?><option value="<?= (int) $d['id'] ?>"<?= (string) $filters['department_id'] === (string) $d['id'] ? ' selected' : '' ?>><?= e($d['name']) ?></option><?php endforeach; ?>
        </select>
        <input type="date" name="from" class="form-control" value="<?= e($filters['from']) ?>" aria-label="From date">
        <input type="date" name="to" class="form-control" value="<?= e($filters['to']) ?>" aria-label="To date">
        <?php if ($filters['product_id']): ?><input type="hidden" name="product_id" value="<?= (int) $filters['product_id'] ?>"><?php endif; ?>
        <button type="submit" class="btn btn-outline-secondary"><i class="bi bi-funnel" aria-hidden="true"></i> Filter</button>
        <?php if (array_filter([$filters['q'], $filters['type'], $filters['department_id'], $filters['from'], $filters['to'], $filters['product_id']])): ?><a href="<?= e(route('inventory.movements')) ?>" class="btn btn-link">Clear</a><?php endif; ?>
    </form>
    <?php if ($page->items): ?>
    <div class="table-responsive"><table class="table table-sm table-hover">
        <thead><tr>
            <th scope="col"><?= sort_link('date', 'Date & time') ?></th><th scope="col"><?= sort_link('type', 'Type') ?></th>
            <th scope="col"><?= sort_link('product', 'Product') ?></th><th scope="col">Batch</th>
            <th scope="col" class="num"><?= sort_link('qty', 'Qty') ?></th><?php if ($showValues): ?><th scope="col" class="num">Value</th><?php endif; ?>
            <th scope="col">From</th><th scope="col">To</th><th scope="col">Reference</th><th scope="col">By</th>
        </tr></thead>
        <tbody>
        <?php foreach ($page->items as $m): $link = $refLink($m); ?>
            <tr>
                <td class="small text-nowrap"><?= e(format_date($m['created_at'], true)) ?></td>
                <td><?= movement_badge($m['movement_type']) ?></td>
                <td class="small"><a href="<?= e(route('products.show', ['id' => $m['product_id']])) ?>"><?= e($m['product_name']) ?></a></td>
                <td><a href="<?= e(route('inventory.batches.show', ['id' => $m['batch_id']])) ?>" class="code-chip"><?= e($m['batch_code']) ?></a></td>
                <td class="num"><?= e(qty($m['quantity'])) ?> <span class="small text-muted"><?= e($m['unit_code']) ?></span></td>
                <?php if ($showValues): ?><td class="num small"><?= e(money((float) $m['quantity'] * (float) $m['unit_cost'], false)) ?></td><?php endif; ?>
                <td class="small"><?= $m['from_location'] ? e($m['from_location']) : '<span class="text-muted">Outside</span>' ?></td>
                <td class="small"><?= $m['to_location'] ? e($m['to_location']) : '<span class="text-muted">Outside</span>' ?></td>
                <td class="small"><?php if ($link): ?><a href="<?= e($link) ?>"><?= e($m['txn_number']) ?></a><?php else: ?><?= e($m['txn_number']) ?><?php endif; ?><?php if ($m['reason']): ?><span class="cell-sub"><?= e($m['reason']) ?></span><?php endif; ?></td>
                <td class="small"><?= e($m['user_name']) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
    <?php else: ?>
        <?= partial('partials/empty', ['icon' => 'bi-arrow-left-right', 'title' => 'No movements found']) ?>
    <?php endif; ?>
    <?= partial('partials/pagination', ['page' => $page]) ?>
</div>
