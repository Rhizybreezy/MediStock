<?php
$isEdit = $department !== null;
$val = fn (string $k, $d = '') => old($k, $department[$k] ?? $d);
?>
<div class="page-header">
    <div>
        <nav aria-label="breadcrumb"><ol class="breadcrumb"><li class="breadcrumb-item"><a href="<?= e(route('admin.departments.index')) ?>">Departments</a></li><li class="breadcrumb-item active" aria-current="page"><?= $isEdit ? e($department['name']) : 'New' ?></li></ol></nav>
        <h1><?= $isEdit ? 'Edit department' : 'New department' ?></h1>
        <?php if (!$isEdit): ?><div class="subtitle">A default store location is created automatically.</div><?php endif; ?>
    </div>
</div>
<div class="card content-narrow"><div class="card-body">
<form method="post" action="<?= e($isEdit ? route('admin.departments.update', ['id' => $department['id']]) : route('admin.departments.store')) ?>" novalidate>
    <?= csrf_field() ?>
    <div class="row g-3">
        <div class="col-md-3">
            <label class="form-label required" for="code">Code</label>
            <input class="form-control text-uppercase<?= invalid('code') ?>" id="code" name="code" value="<?= e($val('code')) ?>" required maxlength="20">
            <?php if ($m = error('code')): ?><div class="invalid-feedback"><?= e($m) ?></div><?php endif; ?>
        </div>
        <div class="col-md-5">
            <label class="form-label required" for="name">Name</label>
            <input class="form-control<?= invalid('name') ?>" id="name" name="name" value="<?= e($val('name')) ?>" required maxlength="100">
            <?php if ($m = error('name')): ?><div class="invalid-feedback"><?= e($m) ?></div><?php endif; ?>
        </div>
        <div class="col-md-4">
            <label class="form-label required" for="type">Type</label>
            <select class="form-select<?= invalid('type') ?>" id="type" name="type">
                <?php foreach ($types as $t): ?><option value="<?= e($t) ?>"<?= $val('type', 'clinical') === $t ? ' selected' : '' ?>><?= e(label($t)) ?></option><?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-6">
            <label class="form-label" for="head_user_id">Head of department</label>
            <select class="form-select<?= invalid('head_user_id') ?>" id="head_user_id" name="head_user_id">
                <option value="">— None —</option>
                <?php foreach ($users as $u): ?><option value="<?= (int) $u['id'] ?>"<?= (string) $val('head_user_id') === (string) $u['id'] ? ' selected' : '' ?>><?= e($u['full_name']) ?></option><?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-6">
            <label class="form-label" for="phone_extension">Phone extension</label>
            <input class="form-control<?= invalid('phone_extension') ?>" id="phone_extension" name="phone_extension" value="<?= e($val('phone_extension')) ?>" maxlength="20">
        </div>
        <div class="col-12">
            <label class="form-label" for="description">Description</label>
            <input class="form-control<?= invalid('description') ?>" id="description" name="description" value="<?= e($val('description')) ?>" maxlength="255">
        </div>
        <div class="col-12">
            <div class="form-check form-switch">
                <input class="form-check-input" type="checkbox" role="switch" id="is_central_store" name="is_central_store" value="1" <?= (int) $val('is_central_store', 0) === 1 ? 'checked' : '' ?> <?= $isEdit && (int) $department['is_central_store'] === 1 ? 'disabled' : '' ?>>
                <label class="form-check-label" for="is_central_store">This is the hospital's central store</label>
                <?php if ($isEdit && (int) $department['is_central_store'] === 1): ?>
                    <input type="hidden" name="is_central_store" value="1">
                    <div class="form-text">The central store designation is permanent.</div>
                <?php else: ?>
                    <div class="form-text">Only one department can be the central store.</div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="form-actions">
        <a href="<?= e(route('admin.departments.index')) ?>" class="btn btn-light">Cancel</a>
        <button type="submit" class="btn btn-primary"><?= $isEdit ? 'Save changes' : 'Create department' ?></button>
    </div>
</form>
</div></div>
