<?php $isEdit = $unit !== null; $val = fn ($k, $d = '') => old($k, $unit[$k] ?? $d); ?>
<div class="page-header">
    <div>
        <nav aria-label="breadcrumb"><ol class="breadcrumb"><li class="breadcrumb-item"><a href="<?= e(route('catalogue.units.index')) ?>">Units</a></li><li class="breadcrumb-item active" aria-current="page"><?= $isEdit ? e($unit['name']) : 'New' ?></li></ol></nav>
        <h1><?= e($title) ?></h1>
    </div>
</div>
<div class="card" style="max-width:620px"><div class="card-body">
<form method="post" action="<?= e($isEdit ? route('catalogue.units.update', ['id' => $unit['id']]) : route('catalogue.units.store')) ?>" novalidate>
    <?= csrf_field() ?>
    <div class="row g-3">
        <div class="col-md-4">
            <label class="form-label required" for="code">Code</label>
            <input class="form-control text-uppercase<?= invalid('code') ?>" id="code" name="code" value="<?= e($val('code')) ?>" maxlength="10" required>
            <?php if ($m = error('code')): ?><div class="invalid-feedback"><?= e($m) ?></div><?php endif; ?>
        </div>
        <div class="col-md-8">
            <label class="form-label required" for="name">Name</label>
            <input class="form-control<?= invalid('name') ?>" id="name" name="name" value="<?= e($val('name')) ?>" maxlength="50" required>
            <?php if ($m = error('name')): ?><div class="invalid-feedback"><?= e($m) ?></div><?php endif; ?>
        </div>
        <div class="col-md-6">
            <div class="form-check form-switch">
                <input class="form-check-input" type="checkbox" role="switch" id="allows_decimal" name="allows_decimal" value="1" <?= (int) $val('allows_decimal', 0) === 1 ? 'checked' : '' ?>>
                <label class="form-check-label" for="allows_decimal">Allow fractional quantities</label>
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
        <a href="<?= e(route('catalogue.units.index')) ?>" class="btn btn-light">Cancel</a>
        <button type="submit" class="btn btn-primary">Save</button>
    </div>
</form>
</div></div>
