<?php /** @var App\Core\Paginator $page */ ?>
<div class="page-header">
    <div>
        <nav aria-label="breadcrumb"><ol class="breadcrumb"><li class="breadcrumb-item">Administration</li><li class="breadcrumb-item active" aria-current="page">Storage locations</li></ol></nav>
        <h1>Storage locations</h1>
        <div class="subtitle">Stores, shelves, refrigerators and quarantine areas where stock is held.</div>
    </div>
    <a href="<?= e(route('admin.locations.create')) ?>" class="btn btn-primary"><i class="bi bi-plus-lg me-1" aria-hidden="true"></i>New location</a>
</div>
<div class="card">
    <form class="filter-bar" method="get" role="search">
        <input type="search" name="q" class="form-control search-input" placeholder="Search name or code" value="<?= e($filters['q']) ?>" aria-label="Search locations">
        <select name="department_id" class="form-select" data-auto-submit aria-label="Filter by department">
            <option value="">All departments</option>
            <?php foreach ($departments as $d): ?><option value="<?= (int) $d['id'] ?>"<?= (string) $filters['department_id'] === (string) $d['id'] ? ' selected' : '' ?>><?= e($d['name']) ?></option><?php endforeach; ?>
        </select>
        <select name="type" class="form-select" data-auto-submit aria-label="Filter by type">
            <option value="">All types</option>
            <?php foreach ($types as $t): ?><option value="<?= e($t) ?>"<?= $filters['type'] === $t ? ' selected' : '' ?>><?= e(label($t)) ?></option><?php endforeach; ?>
        </select>
        <button type="submit" class="btn btn-outline-secondary"><i class="bi bi-search" aria-hidden="true"></i><span class="visually-hidden">Search</span></button>
    </form>
    <?php if ($page->items): ?>
    <div class="table-responsive">
        <table class="table table-hover">
            <thead><tr>
                <th scope="col"><?= sort_link('code', 'Code') ?></th>
                <th scope="col"><?= sort_link('name', 'Location') ?></th>
                <th scope="col"><?= sort_link('department', 'Department') ?></th>
                <th scope="col"><?= sort_link('type', 'Type') ?></th>
                <th scope="col">Cold chain</th>
                <th scope="col" class="num">Units held</th>
                <th scope="col">Status</th>
                <th scope="col" class="table-actions"><span class="visually-hidden">Actions</span></th>
            </tr></thead>
            <tbody>
            <?php foreach ($page->items as $l): $system = $l['type'] === 'transit'; ?>
                <tr>
                    <td><span class="code-chip"><?= e($l['code']) ?></span></td>
                    <td class="fw-semibold"><?= e($l['name']) ?></td>
                    <td class="small"><?= e($l['department_name']) ?></td>
                    <td class="small"><?= e(label($l['type'])) ?><?= $system ? ' <span class="badge text-bg-secondary">System</span>' : '' ?></td>
                    <td class="small"><?= (int) $l['is_cold_chain'] === 1 ? '<i class="bi bi-snow text-info" aria-hidden="true"></i> ' . e(qty($l['min_temp_c'])) . '–' . e(qty($l['max_temp_c'])) . ' °C' : '—' ?></td>
                    <td class="num"><?= e(qty($l['stock_qty'])) ?></td>
                    <td><?= status_badge((int) $l['is_active'] === 1 ? 'active' : 'inactive') ?></td>
                    <td class="table-actions">
                        <?php if (!$system): ?>
                        <a href="<?= e(route('admin.locations.edit', ['id' => $l['id']])) ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-pencil" aria-hidden="true"></i> Edit</a>
                        <form method="post" action="<?= e(route('admin.locations.toggle', ['id' => $l['id']])) ?>" class="d-inline"
                              <?= (int) $l['is_active'] === 1 ? 'data-confirm="Deactivate ' . e($l['name']) . '?" data-confirm-button="Deactivate" data-confirm-variant="danger"' : '' ?>>
                            <?= csrf_field() ?>
                            <button type="submit" class="btn btn-sm <?= (int) $l['is_active'] === 1 ? 'btn-outline-danger' : 'btn-outline-primary' ?>"><?= (int) $l['is_active'] === 1 ? 'Deactivate' : 'Activate' ?></button>
                        </form>
                        <?php else: ?>
                            <span class="small text-muted">Managed by system</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php else: ?>
        <?= partial('partials/empty', ['icon' => 'bi-geo-alt', 'title' => 'No locations found']) ?>
    <?php endif; ?>
    <?= partial('partials/pagination', ['page' => $page]) ?>
</div>
