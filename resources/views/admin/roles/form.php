<?php
$isEdit = $role !== null;
$selectedIds = array_map('intval', (array) old('permissions', $selected));
$isSystem = $isEdit && (int) $role['is_system'] === 1;
?>
<div class="page-header">
    <div>
        <nav aria-label="breadcrumb"><ol class="breadcrumb"><li class="breadcrumb-item"><a href="<?= e(route('admin.roles.index')) ?>">Roles</a></li><li class="breadcrumb-item active" aria-current="page"><?= $isEdit ? e($role['name']) : 'New' ?></li></ol></nav>
        <h1><?= $isEdit ? e($role['name']) : 'New role' ?></h1>
        <div class="subtitle"><span id="perm-count"><?= count($selectedIds) ?></span> permission(s) selected</div>
    </div>
</div>

<form method="post" action="<?= e($isEdit ? route('admin.roles.update', ['id' => $role['id']]) : route('admin.roles.store')) ?>" id="role-form" novalidate>
    <?= csrf_field() ?>
    <div class="card mb-3"><div class="card-body">
        <div class="row g-3">
            <div class="col-md-5">
                <label class="form-label required" for="name">Role name</label>
                <input class="form-control<?= invalid('name') ?>" id="name" name="name" value="<?= e(old('name', $role['name'] ?? '')) ?>" required maxlength="100">
                <?php if ($m = error('name')): ?><div class="invalid-feedback"><?= e($m) ?></div><?php endif; ?>
                <?php if ($isSystem): ?><div class="form-text"><i class="bi bi-lock" aria-hidden="true"></i> System role (code <code><?= e($role['code']) ?></code>) — it cannot be deleted.</div><?php endif; ?>
            </div>
            <div class="col-md-3">
                <label class="form-label required" for="scope">Data scope</label>
                <select class="form-select<?= invalid('scope') ?>" id="scope" name="scope">
                    <?php $scope = old('scope', $role['scope'] ?? 'department'); ?>
                    <option value="department"<?= $scope === 'department' ? ' selected' : '' ?>>Assigned departments only</option>
                    <option value="global"<?= $scope === 'global' ? ' selected' : '' ?>>Hospital-wide</option>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label" for="description">Description</label>
                <input class="form-control<?= invalid('description') ?>" id="description" name="description" value="<?= e(old('description', $role['description'] ?? '')) ?>" maxlength="255">
            </div>
        </div>
    </div></div>

    <div class="d-flex justify-content-between align-items-center mb-2 flex-wrap gap-2">
        <h2 class="h6 fw-semibold mb-0">Permissions</h2>
        <input type="search" class="form-control form-control-sm" style="max-width:260px" placeholder="Filter permissions" id="perm-filter" aria-label="Filter permissions">
    </div>

    <?php foreach ($modules as $module => $perms): ?>
    <section class="perm-module" data-module>
        <div class="perm-module-header">
            <h3><?= e($module) ?></h3>
            <div class="form-check form-switch mb-0">
                <input class="form-check-input module-toggle" type="checkbox" id="mod-<?= e(md5($module)) ?>">
                <label class="form-check-label small" for="mod-<?= e(md5($module)) ?>">Select all</label>
            </div>
        </div>
        <div class="perm-module-body">
            <div class="row g-2">
                <?php foreach ($perms as $p): ?>
                <div class="col-md-6 col-xl-4" data-perm-text="<?= e(mb_strtolower($p['code'] . ' ' . $p['description'])) ?>">
                    <label class="check-card">
                        <input class="form-check-input perm-check" type="checkbox" name="permissions[]" value="<?= (int) $p['id'] ?>" <?= in_array((int) $p['id'], $selectedIds, true) ? 'checked' : '' ?>>
                        <span><span class="check-title d-block"><?= e($p['description']) ?></span><code><?= e($p['code']) ?></code></span>
                    </label>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </section>
    <?php endforeach; ?>

    <div class="form-actions">
        <a href="<?= e(route('admin.roles.index')) ?>" class="btn btn-light">Cancel</a>
        <button type="submit" class="btn btn-primary"><?= $isEdit ? 'Save role' : 'Create role' ?></button>
    </div>
</form>
