<div class="page-header">
    <div>
        <nav aria-label="breadcrumb"><ol class="breadcrumb"><li class="breadcrumb-item"><a href="<?= e(route('purchase_requests.index')) ?>">Purchase requests</a></li><li class="breadcrumb-item active" aria-current="page">New</li></ol></nav>
        <h1>New purchase request</h1>
    </div>
    <a href="<?= e(route('purchase_requests.low_stock')) ?>" class="btn btn-outline-secondary"><i class="bi bi-arrow-down-circle me-1" aria-hidden="true"></i>Start from low-stock items</a>
</div>
<form method="post" action="<?= e(route('purchase_requests.store')) ?>" id="pr-form" novalidate>
    <?= csrf_field() ?>
    <input type="hidden" name="source" value="manual">
    <div class="card mb-3"><div class="card-body"><?= partial('purchase_requests/_header_fields', ['departments' => $departments]) ?></div></div>
    <div class="card">
        <div class="card-header"><h2 class="card-title">Items</h2><span class="small text-muted">Estimated cost is per selected unit</span></div>
        <div class="table-responsive" style="overflow:visible">
            <table class="table line-items mb-0" data-line-items="pr">
                <thead><tr><th scope="col" class="col-product">Product</th><th scope="col" class="col-qty">Quantity</th><th scope="col" class="col-unit">Unit</th><th scope="col" class="col-money">Est. cost</th><th scope="col">Preferred supplier</th><th scope="col" class="col-remove"><span class="visually-hidden">Remove</span></th></tr></thead>
                <tbody></tbody>
            </table>
        </div>
        <template id="pr-row">
            <tr data-row>
                <td class="col-product"><div class="picker" data-picker><input type="search" class="form-control form-control-sm" placeholder="Search product…" aria-label="Product" autocomplete="off"><input type="hidden" name="lines[__i__][product_id]"></div><div class="line-hint" data-hint></div></td>
                <td data-label="Quantity" class="col-qty"><input type="number" min="0" step="any" class="form-control form-control-sm" name="lines[__i__][quantity]" aria-label="Quantity"></td>
                <td data-label="Unit" class="col-unit"><select class="form-select form-select-sm" name="lines[__i__][unit_id]" aria-label="Unit"><option value="">—</option></select></td>
                <td data-label="Est. cost" class="col-money"><input type="number" min="0" step="any" class="form-control form-control-sm" name="lines[__i__][estimated_unit_cost]" aria-label="Estimated cost per unit"></td>
                <td data-label="Supplier"><select class="form-select form-select-sm" name="lines[__i__][preferred_supplier_id]" aria-label="Preferred supplier"><option value="">—</option><?php foreach ($suppliers as $s): ?><option value="<?= (int) $s['id'] ?>"><?= e($s['name']) ?></option><?php endforeach; ?></select></td>
                <td class="col-remove text-end"><button type="button" class="btn btn-sm btn-link text-danger" data-remove-line aria-label="Remove line"><i class="bi bi-x-lg" aria-hidden="true"></i></button></td>
            </tr>
        </template>
        <div class="card-body pt-2 pb-2 d-flex justify-content-between flex-wrap gap-2"><button type="button" class="btn btn-sm btn-outline-secondary" data-add-line="pr"><i class="bi bi-plus" aria-hidden="true"></i> Add item</button><span class="small">Estimated total: <strong id="pr-total">0.00</strong> <?= e(setting('currency_code', 'USD')) ?></span></div>
    </div>
    <div class="form-actions">
        <a href="<?= e(route('purchase_requests.index')) ?>" class="btn btn-light">Cancel</a>
        <button type="submit" name="action" value="draft" class="btn btn-outline-secondary">Save draft</button>
        <button type="submit" name="action" value="submit" class="btn btn-primary"><i class="bi bi-send me-1" aria-hidden="true"></i>Submit for approval</button>
    </div>
</form>
