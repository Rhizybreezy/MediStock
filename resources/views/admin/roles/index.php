<div class="page-header">
    <div>
        <nav aria-label="breadcrumb"><ol class="breadcrumb"><li class="breadcrumb-item">Administration</li><li class="breadcrumb-item active" aria-current="page">Roles &amp; permissions</li></ol></nav>
        <h1>Roles &amp; permissions</h1>
        <div class="subtitle">Permissions are enforced on the server for every page and action. Changes apply immediately.</div>
    </div>
    <a href="<?= e(route('admin.roles.create')) ?>" class="btn btn-primary"><i class="bi bi-plus-lg me-1" aria-hidden="true"></i>New role</a>
</div>

<div class="card">
    <div class="table-responsive">
        <table class="table table-hover">
            <thead><tr>
                <th scope="col">Role</th><th scope="col">Scope</th><th scope="col" class="num">Permissions</th>
                <th scope="col" class="num">Users</th><th scope="col">Type</th><th scope="col" class="table-actions"><span class="visually-hidden">Actions</span></th>
            </tr></thead>
            <tbody>
            <?php foreach ($roles as $r): ?>
                <tr>
                    <td><a href="<?= e(route('admin.roles.edit', ['id' => $r['id']])) ?>" class="fw-semibold"><?= e($r['name']) ?></a><span class="cell-sub"><?= e($r['description'] ?? '') ?></span></td>
                    <td><?= $r['scope'] === 'global'
                        ? '<span class="badge text-bg-primary badge-status"><i class="bi bi-globe2 me-1" aria-hidden="true"></i>Hospital-wide</span>'
                        : '<span class="badge text-bg-info badge-status"><i class="bi bi-building me-1" aria-hidden="true"></i>Department</span>' ?></td>
                    <td class="num"><?= (int) $r['permission_count'] ?></td>
                    <td class="num"><?= (int) $r['user_count'] ?></td>
                    <td><?= (int) $r['is_system'] === 1 ? '<span class="text-muted small"><i class="bi bi-lock me-1" aria-hidden="true"></i>System</span>' : '<span class="small">Custom</span>' ?></td>
                    <td class="table-actions">
                        <a href="<?= e(route('admin.roles.edit', ['id' => $r['id']])) ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-pencil" aria-hidden="true"></i> Edit</a>
                        <?php if ((int) $r['is_system'] === 0 && (int) $r['user_count'] === 0): ?>
                        <form method="post" action="<?= e(route('admin.roles.delete', ['id' => $r['id']])) ?>" class="d-inline"
                              data-confirm="Delete the role “<?= e($r['name']) ?>”? This cannot be undone." data-confirm-button="Delete" data-confirm-variant="danger">
                            <?= csrf_field() ?>
                            <button type="submit" class="btn btn-sm btn-outline-danger" aria-label="Delete <?= e($r['name']) ?>"><i class="bi bi-trash" aria-hidden="true"></i></button>
                        </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
