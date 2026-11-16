<?php /** @var App\Core\Paginator $page */ ?>
<div class="page-header">
    <div>
        <nav aria-label="breadcrumb"><ol class="breadcrumb"><li class="breadcrumb-item">Inventory</li><li class="breadcrumb-item active" aria-current="page">Stock on hand</li></ol></nav>
        <h1>Stock on hand</h1>
        <div class="subtitle">Usable stock excludes expired, recalled, quarantined and in-transit quantities.</div>
    </div>
    <div class="d-flex gap-2 flex-wrap">
        <?php if (can('goods_receipts.direct') || can('stock.opening_balance')): ?><a href="<?= e(route('inventory.receipts.create')) ?>" class="btn btn-outline-secondary"><i class="bi bi-box-arrow-in-down me-1" aria-hidden="true"></i>Receive</a><?php endif; ?>
        <?php if (can('stock.issue')): ?><a href="<?= e(route('inventory.issues.create')) ?>" class="btn btn-primary"><i class="bi bi-box-arrow-up-right me-1" aria-hidden="true"></i>Issue stock</a><?php endif; ?>
    </div>
</div>
<div class="card">
    <form class="filter-bar" method="get" role="search">
        <input type="search" name="q" class="form-control search-input" placeholder="Product name or code" value="<?= e($filters['q']) ?>" aria-label="Search">
        <select name="department_id" class="form-select" data-auto-submit aria-label="Department">
            <option value=""><?= count($departments) > 1 ? 'All my departments' : 'My department' ?></option>
            <?php foreach ($departments as $d): ?><option value="<?= (int) $d['id'] ?>"<?= (string) $filters['department_id'] === (string) $d['id'] ? ' selected' : '' ?>><?= e($d['name']) ?></option><?php endforeach; ?>
        </select>
        <select name="category_id" class="form-select" data-auto-submit aria-label="Category">
            <option value="">All categories</option>
            <?php foreach ($categories as $c): ?><option value="<?= (int) $c['id'] ?>"<?= (string) $filters['category_id'] === (string) $c['id'] ? ' selected' : '' ?>><?= e($c['name']) ?></option><?php endforeach; ?>
        </select>
        <select name="stock" class="form-select" data-auto-submit aria-label="Stock level">
            <option value="">Any level</option>
            <option value="in"<?= $filters['stock'] === 'in' ? ' selected' : '' ?>>In stock</option>
            <option value="low"<?= $filters['stock'] === 'low' ? ' selected' : '' ?>>Low stock</option>
            <option value="out"<?= $filters['stock'] === 'out' ? ' selected' : '' ?>>Out of stock</option>
        </select>
        <button type="submit" class="btn btn-outline-secondary"><i class="bi bi-search" aria-hidden="true"></i><span class="visually-hidden">Search</span></button>
    </form>
    <?php if ($page->items): ?>
    <div class="table-responsive"><table class="table table-hover">
        <thead><tr>
            <th scope="col"><?= sort_link('name', 'Product') ?></th><th scope="col"><?= sort_link('category', 'Category') ?></th>
            <th scope="col" class="num"><?= sort_link('stock', 'Usable') ?></th><th scope="col" class="num">On hand</th>
            <th scope="col" class="num">Reorder at</th><th scope="col">Level</th>
            <?php if ($showValues): ?><th scope="col" class="num"><?= sort_link('value', 'Value') ?></th><?php endif; ?>
        </tr></thead>
        <tbody>
        <?php foreach ($page->items as $p): ?>
            <tr>
                <td><a href="<?= e(route('products.show', ['id' => $p['id']])) ?>" class="fw-semibold"><?= e($p['name']) ?></a><span class="cell-sub"><?= e($p['product_code']) ?><?= $p['strength'] ? ' · ' . e($p['strength']) : '' ?></span></td>
                <td class="small"><?= e($p['category_name']) ?></td>
                <td class="num fw-semibold"><?= e(qty($p['usable_qty'])) ?> <span class="small text-muted fw-normal"><?= e($p['unit_code']) ?></span></td>
                <td class="num"><?= e(qty($p['on_hand'])) ?></td>
                <td class="num small"><?= (float) $p['reorder_level'] > 0 ? e(qty($p['reorder_level'])) : '—' ?></td>
                <td><?= stock_level_badge((float) $p['usable_qty'], (float) $p['reorder_level']) ?></td>
                <?php if ($showValues): ?><td class="num"><?= e(money($p['stock_value'], false)) ?></td><?php endif; ?>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
    <?php else: ?>
        <?= partial('partials/empty', ['icon' => 'bi-boxes', 'title' => 'No products match', 'text' => 'Register products and receive stock to see balances here.']) ?>
    <?php endif; ?>
    <?= partial('partials/pagination', ['page' => $page]) ?>
</div>
