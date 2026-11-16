<?php
$isEdit = $location !== null;
$val = fn (string $k, $d = '') => old($k, $location[$k] ?? $d);
?>
<div class="page-header">
    <div>
        <nav aria-label="breadcrumb"><ol class="breadcrumb"><li class="breadcrumb-item"><a href="<?= e(route('admin.locations.index')) ?>">Storage locations</a></li><li class="breadcrumb-item active" aria-current="page"><?= $isEdit ? e($location['name']) : 'New' ?></li></ol></nav>
        <h1><?= $isEdit ? 'Edit location' : 'New location' ?></h1>
    </div>
</div>
<div class="card content-narrow"><div class="card-body">
<form method="post" action="<?= e($isEdit ? route('admin.locations.update', ['id' => $location['id']]) : route('admin.locations.store')) ?>" novalidate id="location-form">
    <?= csrf_field() ?>
    <div class="row g-3">
        <div class="col-md-6">
            <label class="form-label required" for="department_id">Department</label>
            <select class="form-select<?= invalid('department_id') ?>" id="department_id" name="department_id" required>
                <option value="">Select…</option>
                <?php foreach ($departments as $d): ?><option value="<?= (int) $d['id'] ?>"<?= (string) $val('department_id', request()->query('department_id')) === (string) $d['id'] ? ' selected' : '' ?>><?= e($d['name']) ?></option><?php endforeach; ?>
            </select>
            <?php if ($m = error('department_id')): ?><div class="invalid-feedback"><?= e($m) ?></div><?php endif; ?>
        </div>
        <div class="col-md-6">
            <label class="form-label required" for="type">Type</label>
            <select class="form-select<?= invalid('type') ?>" id="type" name="type">
                <?php foreach ($types as $t): ?><option value="<?= e($t) ?>"<?= $val('type', 'store') === $t ? ' selected' : '' ?>><?= e(label($t)) ?></option><?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-4">
            <label class="form-label required" for="code">Code</label>
            <input class="form-control text-uppercase<?= invalid('code') ?>" id="code" name="code" value="<?= e($val('code')) ?>" required maxlength="30" placeholder="e.g. PH-SHELF-A">
            <?php if ($m = error('code')): ?><div class="invalid-feedback"><?= e($m) ?></div><?php endif; ?>
        </div>
        <div class="col-md-8">
            <label class="form-label required" for="name">Name</label>
            <input class="form-control<?= invalid('name') ?>" id="name" name="name" value="<?= e($val('name')) ?>" required maxlength="100">
            <?php if ($m = error('name')): ?><div class="invalid-feedback"><?= e($m) ?></div><?php endif; ?>
        </div>
        <div class="col-12">
            <div class="form-check form-switch">
                <input class="form-check-input" type="checkbox" role="switch" id="is_cold_chain" name="is_cold_chain" value="1" <?= (int) $val('is_cold_chain', 0) === 1 ? 'checked' : '' ?>>
                <label class="form-check-label" for="is_cold_chain">Temperature-controlled (cold chain)</label>
            </div>
        </div>
        <div class="col-md-6" data-cold-field>
            <label class="form-label" for="min_temp_c">Minimum temperature (°C)</label>
            <input type="number" step="0.1" class="form-control<?= invalid('min_temp_c') ?>" id="min_temp_c" name="min_temp_c" value="<?= e($val('min_temp_c')) ?>">
            <?php if ($m = error('min_temp_c')): ?><div class="invalid-feedback"><?= e($m) ?></div><?php endif; ?>
        </div>
        <div class="col-md-6" data-cold-field>
            <label class="form-label" for="max_temp_c">Maximum temperature (°C)</label>
            <input type="number" step="0.1" class="form-control<?= invalid('max_temp_c') ?>" id="max_temp_c" name="max_temp_c" value="<?= e($val('max_temp_c')) ?>">
            <?php if ($m = error('max_temp_c')): ?><div class="invalid-feedback"><?= e($m) ?></div><?php endif; ?>
        </div>
        <div class="col-12">
            <label class="form-label" for="description">Description</label>
            <input class="form-control" id="description" name="description" value="<?= e($val('description')) ?>" maxlength="255">
        </div>
    </div>
    <div class="form-actions">
        <a href="<?= e(route('admin.locations.index')) ?>" class="btn btn-light">Cancel</a>
        <button type="submit" class="btn btn-primary"><?= $isEdit ? 'Save changes' : 'Create location' ?></button>
    </div>
</form>
</div></div>
