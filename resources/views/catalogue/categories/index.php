<div class="page-header">
    <div>
        <nav aria-label="breadcrumb"><ol class="breadcrumb"><li class="breadcrumb-item">Catalogue</li><li class="breadcrumb-item active" aria-current="page">Categories</li></ol></nav>
        <h1>Inventory categories</h1>
        <div class="subtitle">Group medicines and supplies for reporting. "Requires expiry" pre-selects expiry tracking for new products.</div>
    </div>
    <a href="<?= e(route('catalogue.categories.create')) ?>" class="btn btn-primary"><i class="bi bi-plus-lg me-1" aria-hidden="true"></i>New category</a>
</div>
<div class="card">
    <div class="table-responsive">
        <table class="table table-hover">
            <thead><tr><th scope="col">Code</th><th scope="col">Name</th><th scope="col">Parent</th><th scope="col">Expiry</th><th scope="col" class="num">Products</th><th scope="col">Status</th><th scope="col" class="table-actions"><span class="visually-hidden">Actions</span></th></tr></thead>
            <tbody>
            <?php foreach ($categories as $c): ?>
                <tr>
                    <td><span class="code-chip"><?= e($c['code']) ?></span></td>
                    <td class="fw-semibold"><?= e($c['name']) ?><span class="cell-sub fw-normal"><?= e($c['description'] ?? '') ?></span></td>
                    <td class="small"><?= e($c['parent_name'] ?? '—') ?></td>
                    <td class="small"><?= (int) $c['requires_expiry'] === 1 ? '<i class="bi bi-calendar-check text-success" aria-hidden="true"></i> Required' : '<span class="text-muted">Optional</span>' ?></td>
                    <td class="num"><a href="<?= e(route('products.index', [], ['category_id' => $c['id']])) ?>"><?= (int) $c['product_count'] ?></a></td>
                    <td><?= status_badge((int) $c['is_active'] === 1 ? 'active' : 'inactive') ?></td>
                    <td class="table-actions"><a href="<?= e(route('catalogue.categories.edit', ['id' => $c['id']])) ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-pencil" aria-hidden="true"></i> Edit</a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
