<?php /** @var App\Core\Paginator $page */ ?>
<div class="page-header">
    <div>
        <nav aria-label="breadcrumb"><ol class="breadcrumb"><li class="breadcrumb-item">Inventory</li><li class="breadcrumb-item active" aria-current="page">Products</li></ol></nav>
        <h1>Products &amp; medicines</h1>
        <div class="subtitle"><?= number_format($page->total) ?> product(s)<?= $scoped ? ' · stock shown for your departments' : ' · hospital-wide stock' ?></div>
    </div>
    <?php if (can('products.manage')): ?>
        <a href="<?= e(route('products.create')) ?>" class="btn btn-primary"><i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Register product</a>
    <?php endif; ?>
</div>
<div class="card">
    <form class="filter-bar" method="get" role="search">
        <input type="search" name="q" class="form-control search-input" placeholder="Name, generic name or code" value="<?= e($filters['q']) ?>" aria-label="Search products">
        <select name="category_id" class="form-select" data-auto-submit aria-label="Category">
            <option value="">All categories</option>
            <?php foreach ($categories as $c): ?><option value="<?= (int) $c['id'] ?>"<?= (string) $filters['category_id'] === (string) $c['id'] ? ' selected' : '' ?>><?= e($c['name']) ?></option><?php endforeach; ?>
        </select>
        <select name="stock" class="form-select" data-auto-submit aria-label="Stock status">
            <option value="">Any stock level</option>
            <option value="in"<?= $filters['stock'] === 'in' ? ' selected' : '' ?>>In stock</option>
            <option value="low"<?= $filters['stock'] === 'low' ? ' selected' : '' ?>>Low stock</option>
            <option value="out"<?= $filters['stock'] === 'out' ? ' selected' : '' ?>>Out of stock</option>
        </select>
        <select name="flag" class="form-select" data-auto-submit aria-label="Flags">
            <option value="">All products</option>
            <option value="critical"<?= $filters['flag'] === 'critical' ? ' selected' : '' ?>>Critical items</option>
            <option value="controlled"<?= $filters['flag'] === 'controlled' ? ' selected' : '' ?>>Controlled drugs</option>
        </select>
        <select name="active" class="form-select" data-auto-submit aria-label="Status">
            <option value="1"<?= $filters['active'] === '1' ? ' selected' : '' ?>>Active</option>
            <option value="0"<?= $filters['active'] === '0' ? ' selected' : '' ?>>Inactive</option>
        </select>
        <button type="submit" class="btn btn-outline-secondary"><i class="bi bi-search" aria-hidden="true"></i><span class="visually-hidden">Search</span></button>
    </form>
    <?php if ($page->items): ?>
    <div class="table-responsive">
        <table class="table table-hover">
            <thead><tr>
                <th scope="col"><?= sort_link('code', 'Code') ?></th>
                <th scope="col"><?= sort_link('name', 'Product') ?></th>
                <th scope="col"><?= sort_link('category', 'Category') ?></th>
                <th scope="col" class="num"><?= sort_link('stock', 'Usable stock') ?></th>
                <th scope="col" class="num">Reorder at</th>
                <th scope="col">Level</th>
                <?php if ($showValues): ?><th scope="col" class="num"><?= sort_link('value', 'Value') ?></th><?php endif; ?>
            </tr></thead>
            <tbody>
            <?php foreach ($page->items as $p): ?>
                <tr>
                    <td><span class="code-chip"><?= e($p['product_code']) ?></span></td>
                    <td>
                        <a href="<?= e(route('products.show', ['id' => $p['id']])) ?>" class="fw-semibold"><?= e($p['name']) ?></a>
                        <?php if ((int) $p['is_critical'] === 1): ?><span class="badge text-bg-danger ms-1" title="Critical item">Critical</span><?php endif; ?>
                        <?php if ((int) $p['is_controlled'] === 1): ?><span class="badge text-bg-secondary ms-1" title="Controlled drug"><i class="bi bi-lock" aria-hidden="true"></i></span><?php endif; ?>
                        <?php if (in_array($p['storage_condition'], ['refrigerated', 'frozen'], true)): ?><i class="bi bi-snow text-info ms-1" title="Cold chain" aria-label="Cold chain"></i><?php endif; ?>
                        <span class="cell-sub"><?= e(implode(' · ', array_filter([$p['generic_name'], $p['strength'], $p['dosage_form']]))) ?></span>
                    </td>
                    <td class="small"><?= e($p['category_name']) ?></td>
                    <td class="num"><?= e(qty($p['usable_qty'])) ?> <span class="text-muted small"><?= e($p['unit_code']) ?></span>
                        <?php if ((float) $p['on_hand'] > (float) $p['usable_qty']): ?><span class="cell-sub" title="Includes expired, recalled or in-transit stock"><?= e(qty($p['on_hand'])) ?> total</span><?php endif; ?></td>
                    <td class="num small"><?= (float) $p['reorder_level'] > 0 ? e(qty($p['reorder_level'])) : '—' ?></td>
                    <td><?= (int) $p['is_active'] === 1 ? stock_level_badge((float) $p['usable_qty'], (float) $p['reorder_level']) : status_badge('inactive') ?></td>
                    <?php if ($showValues): ?><td class="num"><?= e(money($p['stock_value'], false)) ?></td><?php endif; ?>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php else: ?>
        <?= partial('partials/empty', ['icon' => 'bi-capsule', 'title' => 'No products found', 'text' => can('products.manage') ? 'Register your first medicine or supply to start tracking stock.' : 'Try changing the filters.']) ?>
    <?php endif; ?>
    <?= partial('partials/pagination', ['page' => $page]) ?>
</div>
