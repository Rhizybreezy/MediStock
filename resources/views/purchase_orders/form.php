<?php $isEdit = $po !== null; $val = fn ($k, $d = '') => old($k, $po[$k] ?? $d); $supplierId = (string) $val('supplier_id', request()->query('supplier_id')); ?>
<div class="page-header">
    <div>
        <nav aria-label="breadcrumb"><ol class="breadcrumb"><li class="breadcrumb-item"><a href="<?= e(route('purchase_orders.index')) ?>">Purchase orders</a></li><li class="breadcrumb-item active" aria-current="page"><?= $isEdit ? e($po['po_number']) : 'New' ?></li></ol></nav>
        <h1><?= e($title) ?></h1>
        <div class="subtitle">Quantities and prices are per order unit (e.g. per box). Saved as a draft until submitted for approval.</div>
    </div>
</div>
<form method="post" action="<?= e($isEdit ? route('purchase_orders.update', ['id' => $po['id']]) : route('purchase_orders.store')) ?>" id="po-form" novalidate>
    <?= csrf_field() ?>
    <div class="card mb-3"><div class="card-body"><div class="row g-3">
        <div class="col-md-4"><label class="form-label required" for="supplier_id">Supplier</label>
            <select class="form-select" id="supplier_id" name="supplier_id" required><option value="">Select…</option><?php foreach ($suppliers as $s): ?><option value="<?= (int) $s['id'] ?>" data-lead="<?= (int) $s['default_lead_time_days'] ?>"<?= $supplierId === (string) $s['id'] ? ' selected' : '' ?>><?= e($s['name']) ?></option><?php endforeach; ?></select></div>
        <div class="col-md-3"><label class="form-label required" for="delivery_location_id">Deliver to</label>
            <select class="form-select" id="delivery_location_id" name="delivery_location_id"><?php foreach ($locations as $l): ?><option value="<?= (int) $l['id'] ?>"<?= (string) $val('delivery_location_id') === (string) $l['id'] ? ' selected' : '' ?>><?= e($l['name']) ?><?= (int) $l['is_cold_chain'] ? ' ❄' : '' ?></option><?php endforeach; ?></select></div>
        <div class="col-md-3"><label class="form-label" for="expected_delivery_date">Expected delivery</label><input type="date" class="form-control" id="expected_delivery_date" name="expected_delivery_date" value="<?= e($val('expected_delivery_date')) ?>"><div class="form-text">Defaults to today + supplier lead time.</div></div>
        <div class="col-md-2"><label class="form-label" for="tax_amount">Tax amount</label><input type="number" min="0" step="0.01" class="form-control" id="tax_amount" name="tax_amount" value="<?= e($val('tax_amount', '0')) ?>"></div>
        <div class="col-12"><label class="form-label" for="notes">Notes to supplier</label><textarea class="form-control" id="notes" name="notes" rows="2" maxlength="2000"><?= e($val('notes')) ?></textarea></div>
    </div></div></div>
    <div class="card">
        <div class="card-header"><h2 class="card-title">Order lines</h2></div>
        <div class="table-responsive" style="overflow:visible">
            <table class="table line-items mb-0" data-line-items="po">
                <thead><tr><th scope="col" class="col-product">Product</th><th scope="col" class="col-qty">Quantity</th><th scope="col" class="col-unit">Order unit</th><th scope="col" class="col-money">Unit price</th><th scope="col" class="col-money num">Line total</th><th scope="col" class="col-remove"><span class="visually-hidden">Remove</span></th></tr></thead>
                <tbody></tbody>
            </table>
        </div>
        <template id="po-row">
            <tr data-row>
                <td class="col-product"><div class="picker" data-picker><input type="search" class="form-control form-control-sm" placeholder="Search product…" aria-label="Product" autocomplete="off"><input type="hidden" name="lines[__i__][product_id]"></div><input type="hidden" name="lines[__i__][purchase_request_item_id]"><div class="line-hint" data-hint></div></td>
                <td data-label="Quantity" class="col-qty"><input type="number" min="0" step="any" class="form-control form-control-sm" name="lines[__i__][quantity]" aria-label="Quantity"></td>
                <td data-label="Order unit" class="col-unit"><select class="form-select form-select-sm" name="lines[__i__][unit_id]" aria-label="Order unit"><option value="">—</option></select></td>
                <td data-label="Unit price" class="col-money"><input type="number" min="0" step="any" class="form-control form-control-sm" name="lines[__i__][unit_price]" aria-label="Unit price"></td>
                <td data-label="Line total" class="col-money num small align-middle" data-line-total>0.00</td>
                <td class="col-remove text-end"><button type="button" class="btn btn-sm btn-link text-danger" data-remove-line aria-label="Remove line"><i class="bi bi-x-lg" aria-hidden="true"></i></button></td>
            </tr>
        </template>
        <div class="card-body pt-2 pb-2 d-flex justify-content-between flex-wrap gap-2"><button type="button" class="btn btn-sm btn-outline-secondary" data-add-line="po"><i class="bi bi-plus" aria-hidden="true"></i> Add line</button><span class="small">Subtotal <strong id="po-subtotal">0.00</strong> · Total <strong id="po-total">0.00</strong> <?= e(setting('currency_code', 'USD')) ?></span></div>
    </div>
    <script type="application/json" id="po-initial"><?= json_encode($initial, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
    <div class="form-actions">
        <a href="<?= e($isEdit ? route('purchase_orders.show', ['id' => $po['id']]) : route('purchase_orders.index')) ?>" class="btn btn-light">Cancel</a>
        <button type="submit" class="btn btn-primary"><i class="bi bi-save me-1" aria-hidden="true"></i>Save draft</button>
    </div>
</form>
