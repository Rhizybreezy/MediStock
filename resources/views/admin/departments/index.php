<?php /** @var App\Core\Paginator $page */ ?>
<div class="page-header">
    <div>
        <nav aria-label="breadcrumb"><ol class="breadcrumb"><li class="breadcrumb-item">Administration</li><li class="breadcrumb-item active" aria-current="page">Departments</li></ol></nav>
        <h1>Departments</h1>
        <div class="subtitle">Each department keeps its own stock balance in its storage locations.</div>
    </div>
    <a href="<?= e(route('admin.departments.create')) ?>" class="btn btn-primary"><i class="bi bi-plus-lg me-1" aria-hidden="true"></i>New department</a>
</div>
<div class="card">
    <form class="filter-bar" method="get" role="search">
        <input type="search" name="q" class="form-control search-input" placeholder="Search name or code" value="<?= e($filters['q']) ?>" aria-label="Search departments">
        <select name="active" class="form-select" data-auto-submit aria-label="Filter by status">
            <option value="">All</option>
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
                <th scope="col"><?= sort_link('name', 'Department') ?></th>
                <th scope="col"><?= sort_link('type', 'Type') ?></th>
                <th scope="col">Head</th>
                <th scope="col" class="num"><?= sort_link('users', 'Users') ?></th>
                <th scope="col" class="num">Locations</th>
                <th scope="col">Status</th>
                <th scope="col" class="table-actions"><span class="visually-hidden">Actions</span></th>
            </tr></thead>
            <tbody>
            <?php foreach ($page->items as $d): ?>
                <tr>
                    <td><span class="code-chip"><?= e($d['code']) ?></span></td>
                    <td class="fw-semibold"><?= e($d['name']) ?><?php if ((int) $d['is_central_store'] === 1): ?> <span class="badge text-bg-primary">Central store</span><?php endif; ?><span class="cell-sub fw-normal"><?= e($d['description'] ?? '') ?></span></td>
                    <td class="small"><?= e(label($d['type'])) ?></td>
                    <td class="small"><?= e($d['head_name'] ?? '—') ?></td>
                    <td class="num"><?= (int) $d['user_count'] ?></td>
                    <td class="num"><a href="<?= e(route('admin.locations.index', [], ['department_id' => $d['id']])) ?>"><?= (int) $d['location_count'] ?></a></td>
                    <td><?= status_badge((int) $d['is_active'] === 1 ? 'active' : 'inactive') ?></td>
                    <td class="table-actions">
                        <a href="<?= e(route('admin.departments.edit', ['id' => $d['id']])) ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-pencil" aria-hidden="true"></i> Edit</a>
                        <?php if ((int) $d['is_central_store'] === 0): ?>
                        <form method="post" action="<?= e(route('admin.departments.toggle', ['id' => $d['id']])) ?>" class="d-inline"
                              <?= (int) $d['is_active'] === 1 ? 'data-confirm="Deactivate ' . e($d['name']) . '? Its users keep their accounts but lose access to this department." data-confirm-button="Deactivate" data-confirm-variant="danger"' : '' ?>>
                            <?= csrf_field() ?>
                            <button type="submit" class="btn btn-sm <?= (int) $d['is_active'] === 1 ? 'btn-outline-danger' : 'btn-outline-primary' ?>"><?= (int) $d['is_active'] === 1 ? 'Deactivate' : 'Activate' ?></button>
                        </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php else: ?>
        <?= partial('partials/empty', ['icon' => 'bi-building', 'title' => 'No departments found']) ?>
    <?php endif; ?>
    <?= partial('partials/pagination', ['page' => $page]) ?>
</div>
