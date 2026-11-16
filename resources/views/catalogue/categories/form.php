<?php $isEdit = $category !== null; $val = fn ($k, $d = '') => old($k, $category[$k] ?? $d); ?>
<div class="page-header">
    <div>
        <nav aria-label="breadcrumb"><ol class="breadcrumb"><li class="breadcrumb-item"><a href="<?= e(route('catalogue.categories.index')) ?>">Categories</a></li><li class="breadcrumb-item active" aria-current="page"><?= $isEdit ? e($category['name']) : 'New' ?></li></ol></nav>
        <h1><?= e($title) ?></h1>
    </div>
</div>
<div class="card content-narrow" style="max-width:720px"><div class="card-body">
<form method="post" action="<?= e($isEdit ? route('catalogue.categories.update', ['id' => $category['id']]) : route('catalogue.categories.store')) ?>" novalidate>
    <?= csrf_field() ?>
    <div class="row g-3">
        <div class="col-md-4">
            <label class="form-label required" for="code">Code</label>
            <input class="form-control text-uppercase<?= invalid('code') ?>" id="code" name="code" value="<?= e($val('code')) ?>" maxlength="20" required>
            <div class="form-text">Used as the product code prefix (e.g. MED-000001).</div>
            <?php if ($m = error('code')): ?><div class="invalid-feedback"><?= e($m) ?></div><?php endif; ?>
        </div>
        <div class="col-md-8">
            <label class="form-label required" for="name">Name</label>
            <input class="form-control<?= invalid('name') ?>" id="name" name="name" value="<?= e($val('name')) ?>" maxlength="100" required>
            <?php if ($m = error('name')): ?><div class="invalid-feedback"><?= e($m) ?></div><?php endif; ?>
        </div>
        <div class="col-md-6">
            <label class="form-label" for="parent_id">Parent category</label>
            <select class="form-select" id="parent_id" name="parent_id">
                <option value="">— None —</option>
                <?php foreach ($parents as $p): ?><option value="<?= (int) $p['id'] ?>"<?= (string) $val('parent_id') === (string) $p['id'] ? ' selected' : '' ?>><?= e($p['name']) ?></option><?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-6">
            <label class="form-label" for="description">Description</label>
            <input class="form-control" id="description" name="description" value="<?= e($val('description')) ?>" maxlength="255">
        </div>
        <div class="col-md-6">
            <div class="form-check form-switch">
                <input class="form-check-input" type="checkbox" role="switch" id="requires_expiry" name="requires_expiry" value="1" <?= (int) $val('requires_expiry', 1) === 1 ? 'checked' : '' ?>>
                <label class="form-check-label" for="requires_expiry">Products usually have expiry dates</label>
            </div>
        </div>
        <div class="col-md-6">
            <div class="form-check form-switch">
                <input class="form-check-input" type="checkbox" role="switch" id="is_active" name="is_active" value="1" <?= (int) $val('is_active', 1) === 1 ? 'checked' : '' ?>>
                <label class="form-check-label" for="is_active">Active</label>
            </div>
        </div>
    </div>
    <div class="form-actions">
        <a href="<?= e(route('catalogue.categories.index')) ?>" class="btn btn-light">Cancel</a>
        <button type="submit" class="btn btn-primary">Save</button>
    </div>
</form>
</div></div>
