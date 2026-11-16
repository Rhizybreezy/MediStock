<?php /** @var App\Core\Paginator $page */ ?>
<div class="page-header">
    <div>
        <nav aria-label="breadcrumb"><ol class="breadcrumb"><li class="breadcrumb-item">Administration</li><li class="breadcrumb-item active" aria-current="page">Users</li></ol></nav>
        <h1>Users</h1>
        <div class="subtitle"><?= (int) $counts['active'] ?> active · <?= (int) $counts['inactive'] ?> inactive · <?= (int) $counts['locked'] ?> locked</div>
    </div>
    <a href="<?= e(route('admin.users.create')) ?>" class="btn btn-primary"><i class="bi bi-person-plus me-1" aria-hidden="true"></i>New user</a>
</div>

<div class="card">
    <form class="filter-bar" method="get" role="search">
        <input type="search" name="q" class="form-control search-input" placeholder="Search name, username or email" value="<?= e($filters['q']) ?>" aria-label="Search users">
        <select name="role_id" class="form-select" data-auto-submit aria-label="Filter by role">
            <option value="">All roles</option>
            <?php foreach ($roles as $r): ?>
                <option value="<?= (int) $r['id'] ?>"<?= (string) $filters['role_id'] === (string) $r['id'] ? ' selected' : '' ?>><?= e($r['name']) ?></option>
            <?php endforeach; ?>
        </select>
        <select name="department_id" class="form-select" data-auto-submit aria-label="Filter by department">
            <option value="">All departments</option>
            <?php foreach ($departments as $d): ?>
                <option value="<?= (int) $d['id'] ?>"<?= (string) $filters['department_id'] === (string) $d['id'] ? ' selected' : '' ?>><?= e($d['name']) ?></option>
            <?php endforeach; ?>
        </select>
        <select name="status" class="form-select" data-auto-submit aria-label="Filter by status">
            <option value="">Any status</option>
            <?php foreach (['active', 'inactive', 'locked'] as $s): ?>
                <option value="<?= $s ?>"<?= $filters['status'] === $s ? ' selected' : '' ?>><?= e(label($s)) ?></option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="btn btn-outline-secondary"><i class="bi bi-search" aria-hidden="true"></i><span class="visually-hidden">Search</span></button>
        <?php if (array_filter([$filters['q'], $filters['role_id'], $filters['department_id'], $filters['status']])): ?>
            <a href="<?= e(route('admin.users.index')) ?>" class="btn btn-link">Clear</a>
        <?php endif; ?>
    </form>

    <?php if ($page->items): ?>
    <div class="table-responsive">
        <table class="table table-hover">
            <thead><tr>
                <th scope="col"><?= sort_link('name', 'Name') ?></th>
                <th scope="col"><?= sort_link('username', 'Username') ?></th>
                <th scope="col">Roles</th>
                <th scope="col">Departments</th>
                <th scope="col"><?= sort_link('status', 'Status') ?></th>
                <th scope="col"><?= sort_link('last_login', 'Last sign-in') ?></th>
                <th scope="col" class="table-actions"><span class="visually-hidden">Actions</span></th>
            </tr></thead>
            <tbody>
            <?php foreach ($page->items as $u): ?>
                <tr>
                    <td><a href="<?= e(route('admin.users.edit', ['id' => $u['id']])) ?>" class="fw-semibold"><?= e($u['full_name']) ?></a><span class="cell-sub"><?= e($u['email']) ?></span></td>
                    <td><span class="code-chip"><?= e($u['username']) ?></span></td>
                    <td class="small"><?= e($u['roles'] ?? '—') ?></td>
                    <td class="small"><?= e($u['departments'] ?? '—') ?></td>
                    <td>
                        <?= status_badge($u['status']) ?>
                        <?php if ((int) $u['must_change_password'] === 1): ?><span class="badge text-bg-secondary badge-status" title="Must change password at next sign-in"><i class="bi bi-key" aria-hidden="true"></i><span class="visually-hidden">Password change pending</span></span><?php endif; ?>
                    </td>
                    <td class="small text-nowrap"><?= $u['last_login_at'] ? e(time_ago($u['last_login_at'])) : '<span class="text-muted">Never</span>' ?></td>
                    <td class="table-actions">
                        <a href="<?= e(route('admin.users.edit', ['id' => $u['id']])) ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-pencil" aria-hidden="true"></i> Edit</a>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php else: ?>
        <?= partial('partials/empty', ['icon' => 'bi-people', 'title' => 'No users found', 'text' => 'Try changing the filters.']) ?>
    <?php endif; ?>
    <?= partial('partials/pagination', ['page' => $page]) ?>
</div>
