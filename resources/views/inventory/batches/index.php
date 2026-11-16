<?php /** @var App\Core\Paginator $page */ ?>
<div class="page-header">
    <div>
        <nav aria-label="breadcrumb"><ol class="breadcrumb"><li class="breadcrumb-item">Inventory</li><li class="breadcrumb-item active" aria-current="page">Batches &amp; expiry</li></ol></nav>
        <h1>Batches &amp; expiry</h1>
        <div class="subtitle">Sorted by expiry date (soonest first). Warning window <?= (int) $warningDays ?> days, critical <?= (int) $criticalDays ?> days.</div>
    </div>
</div>
<div class="card">
    <form class="filter-bar" method="get" role="search">
        <input type="search" name="q" class="form-control search-input" placeholder="Batch code, lot or product" value="<?= e($filters['q']) ?>" aria-label="Search batches">
        <select name="expiry" class="form-select" data-auto-submit aria-label="Expiry">
            <option value="">Any expiry</option>
            <option value="expired"<?= $filters['expiry'] === 'expired' ? ' selected' : '' ?>>Expired</option>
            <option value="30"<?= $filters['expiry'] === '30' ? ' selected' : '' ?>>Within 30 days</option>
            <option value="90"<?= $filters['expiry'] === '90' ? ' selected' : '' ?>>Within 90 days</option>
            <option value="180"<?= $filters['expiry'] === '180' ? ' selected' : '' ?>>Within 180 days</option>
            <option value="none"<?= $filters['expiry'] === 'none' ? ' selected' : '' ?>>No expiry date</option>
        </select>
        <select name="department_id" class="form-select" data-auto-submit aria-label="Department">
            <option value=""><?= $scoped ? 'All my departments' : 'All departments' ?></option>
            <?php foreach ($departments as $d): ?><option value="<?= (int) $d['id'] ?>"<?= (string) $filters['department_id'] === (string) $d['id'] ? ' selected' : '' ?>><?= e($d['name']) ?></option><?php endforeach; ?>
        </select>
        <select name="status" class="form-select" data-auto-submit aria-label="Status">
            <option value="">Any status</option>
            <?php foreach (['active', 'quarantined', 'recalled', 'expired', 'depleted'] as $s): ?><option value="<?= $s ?>"<?= $filters['status'] === $s ? ' selected' : '' ?>><?= e(label($s)) ?></option><?php endforeach; ?>
        </select>
        <?php if (!$scoped): ?>
        <select name="show" class="form-select" data-auto-submit aria-label="Show">
            <option value="in_stock">With stock</option>
            <option value="all"<?= $filters['show'] === 'all' ? ' selected' : '' ?>>Include empty batches</option>
        </select>
        <?php endif; ?>
        <button type="submit" class="btn btn-outline-secondary"><i class="bi bi-search" aria-hidden="true"></i><span class="visually-hidden">Search</span></button>
    </form>
    <?php if ($page->items): ?>
    <div class="table-responsive"><table class="table table-hover">
        <thead><tr>
            <th scope="col"><?= sort_link('batch', 'Batch') ?></th><th scope="col"><?= sort_link('product', 'Product') ?></th>
            <th scope="col"><?= sort_link('expiry', 'Expiry') ?></th><th scope="col" class="num"><?= sort_link('qty', 'On hand') ?></th>
            <th scope="col" class="num">Locations</th><?php if ($showValues): ?><th scope="col" class="num">Value</th><?php endif; ?>
            <th scope="col">Status</th><th scope="col"><?= sort_link('received', 'Received') ?></th>
        </tr></thead>
        <tbody>
        <?php foreach ($page->items as $b): ?>
            <tr>
                <td><a href="<?= e(route('inventory.batches.show', ['id' => $b['id']])) ?>" class="code-chip"><?= e($b['batch_code']) ?></a><span class="cell-sub">Lot <?= e($b['lot_number']) ?></span></td>
                <td><a href="<?= e(route('products.show', ['id' => $b['product_id']])) ?>"><?= e($b['product_name']) ?></a><span class="cell-sub"><?= e($b['product_code']) ?></span></td>
                <td class="small"><?= expiry_badge($b['expiry_date']) ?></td>
                <td class="num"><?= e(qty($b['on_hand'])) ?> <span class="small text-muted"><?= e($b['unit_code']) ?></span></td>
                <td class="num"><?= (int) $b['location_count'] ?></td>
                <?php if ($showValues): ?><td class="num"><?= e(money((float) $b['on_hand'] * (float) $b['unit_cost'], false)) ?></td><?php endif; ?>
                <td><?= status_badge($b['status']) ?></td>
                <td class="small text-nowrap"><?= e(format_date($b['received_at'])) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
    <?php else: ?>
        <?= partial('partials/empty', ['icon' => 'bi-upc', 'title' => 'No batches match', 'text' => 'Batches are created when stock is received.']) ?>
    <?php endif; ?>
    <?= partial('partials/pagination', ['page' => $page]) ?>
</div>
