<div class="page-header">
    <div>
        <nav aria-label="breadcrumb"><ol class="breadcrumb"><li class="breadcrumb-item"><a href="<?= e(route('inventory.receipts.index')) ?>">Goods receipts</a></li><li class="breadcrumb-item active" aria-current="page">Receive stock</li></ol></nav>
        <h1>Receive stock</h1>
        <div class="subtitle">Record a direct purchase, donation or opening balance. Each line becomes a traceable batch. Purchase-order receipts arrive with the procurement module.</div>
    </div>
</div>

<form method="post" action="<?= e(route('inventory.receipts.store')) ?>" id="receipt-form" novalidate>
    <?= csrf_field() ?>
    <input type="hidden" name="submission_token" value="<?= e($token) ?>">
    <div class="card mb-3"><div class="card-body">
        <div class="row g-3">
            <div class="col-md-3">
                <label class="form-label required" for="receipt_type">Receipt type</label>
                <select class="form-select" id="receipt_type" name="receipt_type">
                    <?php foreach ($types as $k => $label): ?><option value="<?= e($k) ?>"<?= old('receipt_type') === $k ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label required" for="receiving_location_id">Receive into</label>
                <select class="form-select<?= invalid('receiving_location_id') ?>" id="receiving_location_id" name="receiving_location_id" required>
                    <?php $dept = null; foreach ($locations as $l): if ($dept !== $l['department_name']): if ($dept !== null): ?></optgroup><?php endif; $dept = $l['department_name']; ?><optgroup label="<?= e($dept) ?>"><?php endif; ?>
                        <option value="<?= (int) $l['id'] ?>" data-cold="<?= (int) $l['is_cold_chain'] ?>"<?= (string) old('receiving_location_id') === (string) $l['id'] ? ' selected' : '' ?>><?= e($l['name']) ?><?= (int) $l['is_cold_chain'] ? ' ❄' : '' ?></option>
                    <?php endforeach; if ($dept !== null): ?></optgroup><?php endif; ?>
                </select>
                <?php if ($m = error('receiving_location_id')): ?><div class="invalid-feedback"><?= e($m) ?></div><?php endif; ?>
            </div>
            <div class="col-md-5">
                <label class="form-label" for="supplier_id">Supplier / donor</label>
                <select class="form-select" id="supplier_id" name="supplier_id">
                    <option value="">— Not specified —</option>
                    <?php foreach ($suppliers as $s): ?><option value="<?= (int) $s['id'] ?>"<?= (string) old('supplier_id') === (string) $s['id'] ? ' selected' : '' ?>><?= e($s['name']) ?></option><?php endforeach; ?>
                </select>
                <?php if (!$suppliers): ?><div class="form-text">Suppliers are registered in the procurement module (Phase 4).</div><?php endif; ?>
            </div>
            <div class="col-md-3"><label class="form-label" for="delivery_note_number">Delivery note no.</label><input class="form-control" id="delivery_note_number" name="delivery_note_number" value="<?= e(old('delivery_note_number')) ?>" maxlength="60"></div>
            <div class="col-md-3"><label class="form-label" for="invoice_number">Invoice no.</label><input class="form-control" id="invoice_number" name="invoice_number" value="<?= e(old('invoice_number')) ?>" maxlength="60"></div>
            <div class="col-md-6"><label class="form-label" for="notes">Notes</label><input class="form-control" id="notes" name="notes" value="<?= e(old('notes')) ?>" maxlength="1000"></div>
        </div>
    </div></div>

    <div class="card">
        <div class="card-header">
            <h2 class="card-title">Items received</h2>
            <span class="small text-muted">Expired stock cannot be received. Quantities may be entered in packs.</span>
        </div>
        <div class="table-responsive" style="overflow:visible">
            <table class="table line-items mb-0" data-line-items="receipt">
                <thead><tr>
                    <th scope="col" class="col-product">Product</th>
                    <th scope="col">Lot / batch no.</th>
                    <th scope="col" class="col-date">Expiry date</th>
                    <th scope="col" class="col-date">Mfg date</th>
                    <th scope="col" class="col-qty">Quantity</th>
                    <th scope="col" class="col-unit">Unit</th>
                    <th scope="col" class="col-money">Unit cost</th>
                    <th scope="col" class="col-remove"><span class="visually-hidden">Remove</span></th>
                </tr></thead>
                <tbody></tbody>
            </table>
        </div>
        <template id="receipt-row">
            <tr data-row>
                <td class="col-product">
                    <div class="picker" data-picker>
                        <input type="search" class="form-control form-control-sm" placeholder="Search product name or code…" aria-label="Product" autocomplete="off">
                        <input type="hidden" name="lines[__i__][product_id]">
                    </div>
                    <div class="line-hint" data-hint></div>
                </td>
                <td data-label="Lot / batch no."><input class="form-control form-control-sm" name="lines[__i__][lot_number]" maxlength="60" aria-label="Lot number"></td>
                <td data-label="Expiry date" class="col-date"><input type="date" class="form-control form-control-sm" name="lines[__i__][expiry_date]" aria-label="Expiry date"></td>
                <td data-label="Mfg date" class="col-date"><input type="date" class="form-control form-control-sm" name="lines[__i__][manufacture_date]" aria-label="Manufacture date"></td>
                <td data-label="Quantity" class="col-qty"><input type="number" min="0" step="any" class="form-control form-control-sm" name="lines[__i__][quantity]" aria-label="Quantity"></td>
                <td data-label="Unit" class="col-unit"><select class="form-select form-select-sm" name="lines[__i__][unit_id]" aria-label="Unit"><option value="">—</option></select></td>
                <td data-label="Unit cost" class="col-money"><input type="number" min="0" step="any" class="form-control form-control-sm" name="lines[__i__][unit_cost]" aria-label="Cost per selected unit" placeholder="0.00"></td>
                <td class="col-remove text-end"><button type="button" class="btn btn-sm btn-link text-danger" data-remove-line aria-label="Remove line"><i class="bi bi-x-lg" aria-hidden="true"></i></button></td>
            </tr>
        </template>
        <div class="card-body pt-2 pb-2 d-flex justify-content-between align-items-center flex-wrap gap-2">
            <span><button type="button" class="btn btn-sm btn-outline-secondary" data-add-line="receipt"><i class="bi bi-plus" aria-hidden="true"></i> Add line</button> <button type="button" class="btn btn-sm btn-outline-primary" data-scan-add><i class="bi bi-upc-scan" aria-hidden="true"></i> Scan</button></span>
            <div class="small">Lines: <strong id="line-count">0</strong> · Total value: <strong id="receipt-total">0.00</strong> <?= e(setting('currency_code', 'USD')) ?></div>
        </div>
    </div>

    <div class="form-actions">
        <a href="<?= e(route('inventory.receipts.index')) ?>" class="btn btn-light">Cancel</a>
        <button type="submit" class="btn btn-primary" id="receipt-submit"><i class="bi bi-box-arrow-in-down me-1" aria-hidden="true"></i>Post receipt</button>
    </div>
</form>
