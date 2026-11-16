<?php $id = (int) $dept['id']; ?>
<div class="page-header">
    <div>
        <nav aria-label="breadcrumb"><ol class="breadcrumb"><li class="breadcrumb-item"><a href="<?= e(route('departments.index')) ?>">Department stock</a></li><li class="breadcrumb-item active" aria-current="page"><?= e($dept['name']) ?></li></ol></nav>
        <h1><?= e($dept['name']) ?></h1>
        <div class="subtitle">Department levels drive low-stock alerts and reorder suggestions for this department.</div>
    </div>
    <div class="d-flex gap-2 flex-wrap">
        <?php if (can('requests.create', $id)): ?><a href="<?= e(route('requests.create')) ?>" class="btn btn-primary"><i class="bi bi-clipboard-plus me-1" aria-hidden="true"></i>Request stock</a><?php endif; ?>
        <?php if (can('consumption.record', $id)): ?><a href="<?= e(route('consumption.create')) ?>" class="btn btn-outline-secondary"><i class="bi bi-clipboard-pulse me-1" aria-hidden="true"></i>Record consumption</a><?php endif; ?>
        <?php if (can('transfers.create', $id) && (int) $dept['is_central_store'] !== 1): ?><a href="<?= e(route('transfers.create', [], ['type' => 'return_to_store'])) ?>" class="btn btn-outline-secondary"><i class="bi bi-arrow-return-left me-1" aria-hidden="true"></i>Return to store</a><?php endif; ?>
    </div>
</div>
<?php if ($summary): ?>
<div class="row g-3 mb-3">
    <div class="col-6 col-lg-3"><div class="card stat-card"><div class="stat-icon tone-primary"><i class="bi bi-boxes" aria-hidden="true"></i></div><div><div class="stat-label">Products held</div><div class="stat-value"><?= (int) $summary['products_held'] ?></div></div></div></div>
    <div class="col-6 col-lg-3"><a class="stat-link" href="<?= e(route('departments.show', ['id' => $id], ['filter' => 'low'])) ?>"><div class="card stat-card"><div class="stat-icon tone-warning"><i class="bi bi-arrow-down-circle" aria-hidden="true"></i></div><div><div class="stat-label">Below reorder level</div><div class="stat-value"><?= (int) $summary['below_reorder'] ?></div></div></div></a></div>
    <div class="col-6 col-lg-3"><a class="stat-link" href="<?= e(route('requests.index', [], ['department_id' => $id, 'status' => 'open'])) ?>"><div class="card stat-card"><div class="stat-icon tone-info"><i class="bi bi-clipboard-check" aria-hidden="true"></i></div><div><div class="stat-label">Open requests</div><div class="stat-value"><?= (int) $summary['open_requests'] ?></div></div></div></a></div>
    <div class="col-6 col-lg-3"><a class="stat-link" href="<?= e(route('inventory.incoming')) ?>"><div class="card stat-card"><div class="stat-icon tone-neutral"><i class="bi bi-truck" aria-hidden="true"></i></div><div><div class="stat-label">Incoming deliveries</div><div class="stat-value"><?= (int) $summary['incoming'] ?></div></div></div></a></div>
</div>
<?php endif; ?>

<div class="card">
    <form class="filter-bar" method="get" role="search">
        <input type="search" name="q" class="form-control search-input" placeholder="Product name or code" value="<?= e($q) ?>" aria-label="Search">
        <select name="filter" class="form-select" data-auto-submit aria-label="Show">
            <option value="all"<?= $filter === 'all' ? ' selected' : '' ?>>Held or stocked here</option>
            <option value="low"<?= $filter === 'low' ? ' selected' : '' ?>>Below reorder level</option>
            <?php if ($canEdit): ?><option value="catalogue"<?= $filter === 'catalogue' ? ' selected' : '' ?>>Whole catalogue (to add items)</option><?php endif; ?>
        </select>
        <button type="submit" class="btn btn-outline-secondary"><i class="bi bi-search" aria-hidden="true"></i><span class="visually-hidden">Search</span></button>
    </form>
    <?php if ($rows): ?>
    <?php if ($canEdit): ?><form method="post" action="<?= e(route('departments.levels', ['id' => $id])) ?>"><?= csrf_field() ?><?php endif; ?>
    <div class="table-responsive"><table class="table table-hover">
        <thead><tr>
            <th scope="col">Product</th><th scope="col" class="num">Usable</th><th scope="col" class="num">Used 30d</th>
            <th scope="col" style="width:7rem">Min</th><th scope="col" style="width:7rem">Reorder</th><th scope="col" style="width:7rem">Max</th>
            <th scope="col" class="text-center">Stocked</th><th scope="col">Level</th>
        </tr></thead>
        <tbody>
        <?php foreach ($rows as $r): $pid = (int) $r['id']; ?>
            <tr>
                <td><a href="<?= e(route('products.show', ['id' => $pid])) ?>"><?= e($r['name']) ?></a><span class="cell-sub"><?= e($r['product_code']) ?> · <?= e($r['unit_code']) ?></span></td>
                <td class="num fw-semibold"><?= e(qty($r['usable_qty'])) ?></td>
                <td class="num small"><?= e(qty($r['consumed_30d'])) ?></td>
                <?php foreach (['min_level', 'reorder_level', 'max_level'] as $k): ?>
                    <td><?php if ($canEdit): ?><input type="number" min="0" step="any" class="form-control form-control-sm" name="levels[<?= $pid ?>][<?= $k ?>]" value="<?= $r[$k] !== null ? e(qty($r[$k])) : '' ?>" aria-label="<?= e(label($k)) ?> for <?= e($r['name']) ?>"><?php else: ?><span class="small"><?= $r[$k] !== null ? e(qty($r[$k])) : '—' ?></span><?php endif; ?></td>
                <?php endforeach; ?>
                <td class="text-center"><?php if ($canEdit): ?><input class="form-check-input" type="checkbox" name="levels[<?= $pid ?>][is_stocked]" value="1" <?= (int) $r['is_stocked'] === 1 ? 'checked' : '' ?> aria-label="Stocked here"><?php else: ?><?= (int) $r['is_stocked'] === 1 ? '<i class="bi bi-check2" aria-label="Yes"></i>' : '—' ?><?php endif; ?></td>
                <td><?= $r['setting_id'] ? stock_level_badge((float) $r['usable_qty'], (float) $r['reorder_level']) : '<span class="small text-muted">No level set</span>' ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
    <?php if ($canEdit): ?>
        <div class="card-body border-top d-flex justify-content-between align-items-center flex-wrap gap-2">
            <span class="small text-muted">Tick “Stocked” for items this department should always keep. Clear all values and untick to remove an item.</span>
            <button type="submit" class="btn btn-primary"><i class="bi bi-check2 me-1" aria-hidden="true"></i>Save levels</button>
        </div>
    </form>
    <?php endif; ?>
    <?php else: ?>
        <?= partial('partials/empty', ['icon' => 'bi-box', 'title' => 'Nothing to show', 'text' => $canEdit ? 'Choose “Whole catalogue” to set levels for items this department should stock.' : 'This department holds no stock yet.']) ?>
    <?php endif; ?>
</div>
