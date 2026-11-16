<div class="page-header">
    <div>
        <nav aria-label="breadcrumb"><ol class="breadcrumb"><li class="breadcrumb-item">Catalogue</li><li class="breadcrumb-item active" aria-current="page">Units of measure</li></ol></nav>
        <h1>Units of measure</h1>
        <div class="subtitle">Stock is always stored in a product's base unit; packs (box, carton …) are converted automatically.</div>
    </div>
    <a href="<?= e(route('catalogue.units.create')) ?>" class="btn btn-primary"><i class="bi bi-plus-lg me-1" aria-hidden="true"></i>New unit</a>
</div>
<div class="card">
    <div class="table-responsive">
        <table class="table table-hover">
            <thead><tr><th scope="col">Code</th><th scope="col">Name</th><th scope="col">Decimals</th><th scope="col" class="num">Base unit of</th><th scope="col" class="num">Pack unit of</th><th scope="col">Status</th><th scope="col" class="table-actions"><span class="visually-hidden">Actions</span></th></tr></thead>
            <tbody>
            <?php foreach ($units as $u): ?>
                <tr>
                    <td><span class="code-chip"><?= e($u['code']) ?></span></td>
                    <td class="fw-semibold"><?= e($u['name']) ?></td>
                    <td class="small"><?= (int) $u['allows_decimal'] === 1 ? 'Allowed (e.g. 2.5)' : 'Whole numbers only' ?></td>
                    <td class="num"><?= (int) $u['base_count'] ?> product(s)</td>
                    <td class="num"><?= (int) $u['pack_count'] ?> product(s)</td>
                    <td><?= status_badge((int) $u['is_active'] === 1 ? 'active' : 'inactive') ?></td>
                    <td class="table-actions"><a href="<?= e(route('catalogue.units.edit', ['id' => $u['id']])) ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-pencil" aria-hidden="true"></i> Edit</a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
