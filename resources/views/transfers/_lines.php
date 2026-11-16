<?php /* Shared line editor (product + qty + unit + batch with FEFO preview), driven by js/pages/issue-form.js */ ?>
<div class="card">
    <div class="card-header"><h2 class="card-title">Items</h2><span class="small text-muted">Availability shown for the selected source location</span></div>
    <div class="table-responsive" style="overflow:visible">
        <table class="table line-items mb-0" data-line-items="issue">
            <thead><tr><th scope="col" class="col-product">Product</th><th scope="col" class="col-qty">Quantity</th><th scope="col" class="col-unit">Unit</th><th scope="col">Batch</th><th scope="col" class="col-remove"><span class="visually-hidden">Remove</span></th></tr></thead>
            <tbody></tbody>
        </table>
    </div>
    <template id="issue-row">
        <tr data-row>
            <td class="col-product"><div class="picker" data-picker><input type="search" class="form-control form-control-sm" placeholder="Search product…" aria-label="Product" autocomplete="off"><input type="hidden" name="lines[__i__][product_id]"></div><div class="line-hint" data-hint></div></td>
            <td data-label="Quantity" class="col-qty"><input type="number" min="0" step="any" class="form-control form-control-sm" name="lines[__i__][quantity]" aria-label="Quantity"></td>
            <td data-label="Unit" class="col-unit"><select class="form-select form-select-sm" name="lines[__i__][unit_id]" aria-label="Unit"><option value="">—</option></select></td>
            <td data-label="Batch"><select class="form-select form-select-sm" name="lines[__i__][batch_id]" aria-label="Batch"><option value="">Automatic (FEFO)</option></select><ul class="alloc-list" data-alloc></ul></td>
            <td class="col-remove text-end"><button type="button" class="btn btn-sm btn-link text-danger" data-remove-line aria-label="Remove line"><i class="bi bi-x-lg" aria-hidden="true"></i></button></td>
        </tr>
    </template>
    <div class="card-body pt-2 pb-2"><button type="button" class="btn btn-sm btn-outline-secondary" data-add-line="issue"><i class="bi bi-plus" aria-hidden="true"></i> Add line</button> <button type="button" class="btn btn-sm btn-outline-primary" data-scan-add><i class="bi bi-upc-scan" aria-hidden="true"></i> Scan</button></div>
</div>
