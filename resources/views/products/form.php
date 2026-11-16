<?php
$isEdit = $product !== null;
$val = fn (string $k, $d = '') => old($k, $product[$k] ?? $d);
$flag = fn (string $k, int $d = 0) => (int) old($k, $product[$k] ?? $d) === 1 ? 'checked' : '';
$packs = old('packs', array_map(fn ($p) => [
    'unit_id' => $p['unit_id'], 'factor_to_base' => qty($p['factor_to_base']), 'gtin_barcode' => $p['gtin_barcode'],
    'is_purchase_unit' => $p['is_purchase_unit'], 'is_issue_unit' => $p['is_issue_unit'],
], $packUnits));
if (!$packs) {
    $packs = [['unit_id' => '', 'factor_to_base' => '', 'gtin_barcode' => '']];
}
?>
<div class="page-header">
    <div>
        <nav aria-label="breadcrumb"><ol class="breadcrumb"><li class="breadcrumb-item"><a href="<?= e(route('products.index')) ?>">Products</a></li><li class="breadcrumb-item active" aria-current="page"><?= $isEdit ? e($product['product_code']) : 'Register' ?></li></ol></nav>
        <h1><?= $isEdit ? e($product['name']) : 'Register product' ?></h1>
        <?php if (!$isEdit): ?><div class="subtitle">A product code is generated from the category when you save.</div><?php endif; ?>
    </div>
</div>

<form method="post" enctype="multipart/form-data" action="<?= e($isEdit ? route('products.update', ['id' => $product['id']]) : route('products.store')) ?>" novalidate id="product-form">
    <?= csrf_field() ?>
    <div class="row g-3">
        <div class="col-xl-8">
            <div class="card mb-3"><div class="card-body">
                <div class="form-section-title">Identification</div>
                <div class="form-section-desc">How staff will find and recognise this item.</div>
                <div class="row g-3">
                    <div class="col-md-7">
                        <label class="form-label required" for="name">Product name</label>
                        <input class="form-control<?= invalid('name') ?>" id="name" name="name" value="<?= e($val('name')) ?>" required maxlength="150" placeholder="e.g. Paracetamol 500 mg tablets">
                        <?php if ($m = error('name')): ?><div class="invalid-feedback"><?= e($m) ?></div><?php endif; ?>
                    </div>
                    <div class="col-md-5">
                        <label class="form-label" for="generic_name">Generic name</label>
                        <input class="form-control<?= invalid('generic_name') ?>" id="generic_name" name="generic_name" value="<?= e($val('generic_name')) ?>" maxlength="150">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label required" for="category_id">Category</label>
                        <select class="form-select<?= invalid('category_id') ?>" id="category_id" name="category_id" required>
                            <option value="">Select…</option>
                            <?php foreach ($categories as $c): ?><option value="<?= (int) $c['id'] ?>" data-requires-expiry="<?= (int) $c['requires_expiry'] ?>"<?= (string) $val('category_id') === (string) $c['id'] ? ' selected' : '' ?>><?= e($c['name']) ?></option><?php endforeach; ?>
                        </select>
                        <?php if ($m = error('category_id')): ?><div class="invalid-feedback"><?= e($m) ?></div><?php endif; ?>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label" for="dosage_form">Form</label>
                        <input class="form-control" id="dosage_form" name="dosage_form" value="<?= e($val('dosage_form')) ?>" maxlength="60" placeholder="Tablet, syrup…" list="dosage-forms">
                        <datalist id="dosage-forms"><?php foreach (['Tablet', 'Capsule', 'Syrup', 'Suspension', 'Injection', 'Infusion', 'Cream', 'Ointment', 'Drops', 'Inhaler', 'Suppository', 'Powder', 'Device', 'Consumable'] as $f): ?><option value="<?= e($f) ?>"><?php endforeach; ?></datalist>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label" for="strength">Strength</label>
                        <input class="form-control" id="strength" name="strength" value="<?= e($val('strength')) ?>" maxlength="60" placeholder="500 mg">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="manufacturer">Manufacturer</label>
                        <input class="form-control" id="manufacturer" name="manufacturer" value="<?= e($val('manufacturer')) ?>" maxlength="150">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="image">Product image</label>
                        <input type="file" class="form-control" id="image" name="image" accept="image/jpeg,image/png,image/webp">
                        <div class="form-text">JPG, PNG or WebP, up to 2 MB.</div>
                        <?php if ($isEdit && $product['image_path']): ?>
                            <div class="form-check mt-1"><input class="form-check-input" type="checkbox" id="remove_image" name="remove_image" value="1"><label class="form-check-label small" for="remove_image">Remove current image</label></div>
                        <?php endif; ?>
                    </div>
                    <div class="col-12">
                        <label class="form-label" for="description">Description</label>
                        <textarea class="form-control" id="description" name="description" rows="2" maxlength="2000"><?= e($val('description')) ?></textarea>
                    </div>
                </div>

                <div class="form-section">
                    <div class="form-section-title">Units &amp; packs</div>
                    <div class="form-section-desc">All stock is counted in the <strong>base unit</strong> (the smallest unit you issue). Packs let staff receive or issue in boxes, cartons, etc.</div>
                    <div class="row g-3 mb-2">
                        <div class="col-md-5">
                            <label class="form-label required" for="base_unit_id">Base unit</label>
                            <select class="form-select<?= invalid('base_unit_id') ?>" id="base_unit_id" name="base_unit_id" required>
                                <option value="">Select…</option>
                                <?php foreach ($units as $u): ?><option value="<?= (int) $u['id'] ?>"<?= (string) $val('base_unit_id') === (string) $u['id'] ? ' selected' : '' ?>><?= e($u['name']) ?> (<?= e($u['code']) ?>)</option><?php endforeach; ?>
                            </select>
                            <?php if ($m = error('base_unit_id')): ?><div class="invalid-feedback"><?= e($m) ?></div><?php endif; ?>
                        </div>
                    </div>
                    <div class="table-responsive border rounded">
                        <table class="table table-sm mb-0" id="pack-table">
                            <thead><tr><th scope="col">Pack unit</th><th scope="col" style="width:9rem">Contains (base)</th><th scope="col">Manufacturer barcode (GTIN)</th><th scope="col" class="text-center">Purchase</th><th scope="col" class="text-center">Issue</th><th scope="col"><span class="visually-hidden">Remove</span></th></tr></thead>
                            <tbody>
                            <?php foreach ($packs as $i => $p): ?>
                                <tr data-row>
                                    <td>
                                        <select class="form-select form-select-sm" name="packs[<?= (int) $i ?>][unit_id]" aria-label="Pack unit">
                                            <option value="">—</option>
                                            <?php foreach ($units as $u): ?><option value="<?= (int) $u['id'] ?>"<?= (string) ($p['unit_id'] ?? '') === (string) $u['id'] ? ' selected' : '' ?>><?= e($u['name']) ?></option><?php endforeach; ?>
                                        </select>
                                    </td>
                                    <td><input type="number" min="1" step="any" class="form-control form-control-sm" name="packs[<?= (int) $i ?>][factor_to_base]" value="<?= e($p['factor_to_base'] ?? '') ?>" aria-label="Base units per pack"></td>
                                    <td><input class="form-control form-control-sm" name="packs[<?= (int) $i ?>][gtin_barcode]" value="<?= e($p['gtin_barcode'] ?? '') ?>" maxlength="50" aria-label="Barcode"></td>
                                    <td class="text-center"><input class="form-check-input" type="checkbox" name="packs[<?= (int) $i ?>][is_purchase_unit]" value="1" <?= !empty($p['is_purchase_unit']) ? 'checked' : '' ?> aria-label="Purchase unit"></td>
                                    <td class="text-center"><input class="form-check-input" type="checkbox" name="packs[<?= (int) $i ?>][is_issue_unit]" value="1" <?= !empty($p['is_issue_unit']) ? 'checked' : '' ?> aria-label="Issue unit"></td>
                                    <td class="text-end"><button type="button" class="btn btn-sm btn-link text-danger" data-remove-row aria-label="Remove pack"><i class="bi bi-x-lg" aria-hidden="true"></i></button></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <button type="button" class="btn btn-sm btn-outline-secondary mt-2" id="add-pack"><i class="bi bi-plus" aria-hidden="true"></i> Add pack size</button>
                </div>
            </div></div>
        </div>

        <div class="col-xl-4">
            <div class="card mb-3"><div class="card-body">
                <div class="form-section-title">Stock control</div>
                <div class="form-section-desc">Hospital-wide levels in base units. Leave max at 0 for no maximum.</div>
                <div class="row g-2">
                    <div class="col-4"><label class="form-label required" for="min_level">Minimum</label><input type="number" min="0" step="any" class="form-control<?= invalid('min_level') ?>" id="min_level" name="min_level" value="<?= e($val('min_level', '0')) ?>"></div>
                    <div class="col-4"><label class="form-label required" for="reorder_level">Reorder</label><input type="number" min="0" step="any" class="form-control<?= invalid('reorder_level') ?>" id="reorder_level" name="reorder_level" value="<?= e($val('reorder_level', '0')) ?>"></div>
                    <div class="col-4"><label class="form-label required" for="max_level">Maximum</label><input type="number" min="0" step="any" class="form-control<?= invalid('max_level') ?>" id="max_level" name="max_level" value="<?= e($val('max_level', '0')) ?>"></div>
                    <div class="col-12"><label class="form-label" for="default_lead_time_days">Supplier lead time (days)</label><input type="number" min="0" max="365" class="form-control<?= invalid('default_lead_time_days') ?>" id="default_lead_time_days" name="default_lead_time_days" value="<?= e($val('default_lead_time_days')) ?>" placeholder="Uses system default if empty"></div>
                </div>
            </div></div>
            <div class="card mb-3"><div class="card-body">
                <div class="form-section-title">Storage &amp; handling</div>
                <div class="mt-2 mb-2">
                    <label class="form-label required" for="storage_condition">Storage condition</label>
                    <select class="form-select" id="storage_condition" name="storage_condition">
                        <?php foreach ($storage as $k => $label): ?><option value="<?= e($k) ?>"<?= $val('storage_condition', 'room_temp') === $k ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
                    </select>
                    <div class="form-text">Refrigerated/frozen items can only be stored in cold-chain locations.</div>
                </div>
                <div class="mb-3"><label class="form-label" for="storage_notes">Storage notes</label><input class="form-control" id="storage_notes" name="storage_notes" value="<?= e($val('storage_notes')) ?>" maxlength="255" placeholder="e.g. protect from light"></div>
                <div class="form-check form-switch mb-2"><input class="form-check-input" type="checkbox" role="switch" id="is_batch_tracked" name="is_batch_tracked" value="1" <?= $flag('is_batch_tracked', 1) ?>><label class="form-check-label" for="is_batch_tracked">Require lot/batch number at receipt</label></div>
                <div class="form-check form-switch mb-2"><input class="form-check-input" type="checkbox" role="switch" id="is_expiry_tracked" name="is_expiry_tracked" value="1" <?= $flag('is_expiry_tracked', 1) ?>><label class="form-check-label" for="is_expiry_tracked">Track expiry dates</label></div>
                <div class="form-check form-switch mb-2"><input class="form-check-input" type="checkbox" role="switch" id="is_critical" name="is_critical" value="1" <?= $flag('is_critical') ?>><label class="form-check-label" for="is_critical">Critical / life-saving item</label></div>
                <div class="form-check form-switch mb-2"><input class="form-check-input" type="checkbox" role="switch" id="is_controlled" name="is_controlled" value="1" <?= $flag('is_controlled') ?>><label class="form-check-label" for="is_controlled">Controlled drug</label></div>
                <div class="form-check form-switch"><input class="form-check-input" type="checkbox" role="switch" id="is_active" name="is_active" value="1" <?= $flag('is_active', 1) ?>><label class="form-check-label" for="is_active">Active</label></div>
            </div></div>
        </div>
    </div>
    <div class="form-actions">
        <a href="<?= e($isEdit ? route('products.show', ['id' => $product['id']]) : route('products.index')) ?>" class="btn btn-light">Cancel</a>
        <button type="submit" class="btn btn-primary"><?= $isEdit ? 'Save product' : 'Register product' ?></button>
    </div>
</form>
