<div class="page-header">
    <div>
        <nav aria-label="breadcrumb"><ol class="breadcrumb"><li class="breadcrumb-item"><a href="<?= e(route('inventory.issues.index')) ?>">Stock issues</a></li><li class="breadcrumb-item active" aria-current="page">Issue stock</li></ol></nav>
        <h1>Issue stock</h1>
        <div class="subtitle">Batches are picked automatically by First-Expiry-First-Out. Expired, recalled and reserved stock is never issued. The receiving department confirms what arrives.</div>
    </div>
</div>
<form method="post" action="<?= e(route('inventory.issues.store')) ?>" id="issue-form" novalidate>
    <?= csrf_field() ?>
    <div class="card mb-3"><div class="card-body">
        <div class="row g-3">
            <div class="col-md-4">
                <label class="form-label required" for="source_location_id">Issue from</label>
                <select class="form-select<?= invalid('source_location_id') ?>" id="source_location_id" name="source_location_id" required>
                    <?php $dept = null; foreach ($sources as $l): if ($dept !== $l['department_name']): if ($dept !== null): ?></optgroup><?php endif; $dept = $l['department_name']; ?><optgroup label="<?= e($dept) ?>"><?php endif; ?>
                        <option value="<?= (int) $l['id'] ?>"<?= (string) old('source_location_id') === (string) $l['id'] ? ' selected' : '' ?>><?= e($l['name']) ?></option>
                    <?php endforeach; if ($dept !== null): ?></optgroup><?php endif; ?>
                </select>
                <?php if ($m = error('source_location_id')): ?><div class="invalid-feedback"><?= e($m) ?></div><?php endif; ?>
            </div>
            <div class="col-md-4">
                <label class="form-label required" for="destination_location_id">Deliver to</label>
                <select class="form-select<?= invalid('destination_location_id') ?>" id="destination_location_id" name="destination_location_id" required>
                    <option value="">Select department location…</option>
                    <?php $dept = null; foreach ($destinations as $l): if ($dept !== $l['department_name']): if ($dept !== null): ?></optgroup><?php endif; $dept = $l['department_name']; ?><optgroup label="<?= e($dept) ?>"><?php endif; ?>
                        <option value="<?= (int) $l['id'] ?>" data-cold="<?= (int) $l['is_cold_chain'] ?>"<?= (string) old('destination_location_id') === (string) $l['id'] ? ' selected' : '' ?>><?= e($l['name']) ?><?= (int) $l['is_cold_chain'] ? ' ❄' : '' ?></option>
                    <?php endforeach; if ($dept !== null): ?></optgroup><?php endif; ?>
                </select>
                <?php if ($m = error('destination_location_id')): ?><div class="invalid-feedback"><?= e($m) ?></div><?php endif; ?>
            </div>
            <div class="col-md-4">
                <label class="form-label" for="reason">Reason / reference</label>
                <input class="form-control" id="reason" name="reason" value="<?= e(old('reason')) ?>" maxlength="255" placeholder="e.g. weekly ward top-up">
            </div>
        </div>
    </div></div>

    <div class="card">
        <div class="card-header"><h2 class="card-title">Items to issue</h2><span class="small text-muted">Availability shown for the selected source location</span></div>
        <div class="table-responsive" style="overflow:visible">
            <table class="table line-items mb-0" data-line-items="issue">
                <thead><tr>
                    <th scope="col" class="col-product">Product</th>
                    <th scope="col" class="col-qty">Quantity</th>
                    <th scope="col" class="col-unit">Unit</th>
                    <th scope="col">Batch</th>
                    <th scope="col" class="col-remove"><span class="visually-hidden">Remove</span></th>
                </tr></thead>
                <tbody></tbody>
            </table>
        </div>
        <template id="issue-row">
            <tr data-row>
                <td class="col-product">
                    <div class="picker" data-picker>
                        <input type="search" class="form-control form-control-sm" placeholder="Search product name or code…" aria-label="Product" autocomplete="off">
                        <input type="hidden" name="lines[__i__][product_id]">
                    </div>
                    <div class="line-hint" data-hint></div>
                </td>
                <td data-label="Quantity" class="col-qty"><input type="number" min="0" step="any" class="form-control form-control-sm" name="lines[__i__][quantity]" aria-label="Quantity"></td>
                <td data-label="Unit" class="col-unit"><select class="form-select form-select-sm" name="lines[__i__][unit_id]" aria-label="Unit"><option value="">—</option></select></td>
                <td data-label="Batch">
                    <select class="form-select form-select-sm" name="lines[__i__][batch_id]" aria-label="Batch"><option value="">Automatic (FEFO)</option></select>
                    <ul class="alloc-list" data-alloc></ul>
                </td>
                <td class="col-remove text-end"><button type="button" class="btn btn-sm btn-link text-danger" data-remove-line aria-label="Remove line"><i class="bi bi-x-lg" aria-hidden="true"></i></button></td>
            </tr>
        </template>
        <div class="card-body pt-2 pb-2">
            <button type="button" class="btn btn-sm btn-outline-secondary" data-add-line="issue"><i class="bi bi-plus" aria-hidden="true"></i> Add line</button> <button type="button" class="btn btn-sm btn-outline-primary" data-scan-add><i class="bi bi-upc-scan" aria-hidden="true"></i> Scan</button>
        </div>
    </div>
    <div class="form-actions">
        <a href="<?= e(route('inventory.issues.index')) ?>" class="btn btn-light">Cancel</a>
        <button type="submit" class="btn btn-primary"><i class="bi bi-truck me-1" aria-hidden="true"></i>Dispatch</button>
    </div>
</form>
