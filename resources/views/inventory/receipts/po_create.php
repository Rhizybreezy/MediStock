<div class="page-header">
    <div>
        <nav aria-label="breadcrumb"><ol class="breadcrumb"><li class="breadcrumb-item"><a href="<?= e(route('inventory.receipts.po')) ?>">Receive against PO</a></li><li class="breadcrumb-item active" aria-current="page"><?= e($po['po_number']) ?></li></ol></nav>
        <h1>Receive <?= e($po['po_number']) ?></h1>
        <div class="subtitle"><?= e($po['supplier_name']) ?> · quantities in order units. Rejected items are recorded but not added to stock and remain outstanding.<?= $tolerance > 0 ? ' Over-receipt tolerance: ' . e(qty($tolerance)) . '%.' : '' ?></div>
    </div>
</div>
<form method="post" action="<?= e(route('inventory.receipts.po.store', ['id' => $po['id']])) ?>" id="po-receipt-form" novalidate data-confirm="Post this goods receipt? Accepted quantities are added to stock as new batches." data-confirm-button="Post receipt">
    <?= csrf_field() ?>
    <input type="hidden" name="submission_token" value="<?= e($token) ?>">
    <div class="card mb-3"><div class="card-body row g-3">
        <div class="col-md-4"><label class="form-label required" for="receiving_location_id">Receive into</label><select class="form-select" id="receiving_location_id" name="receiving_location_id"><?php $dept = null; foreach ($locations as $l): if ($dept !== $l['department_name']): if ($dept !== null): ?></optgroup><?php endif; $dept = $l['department_name']; ?><optgroup label="<?= e($dept) ?>"><?php endif; ?><option value="<?= (int) $l['id'] ?>"<?= (int) $l['id'] === (int) $po['delivery_location_id'] ? ' selected' : '' ?>><?= e($l['name']) ?><?= (int) $l['is_cold_chain'] ? ' ❄' : '' ?></option><?php endforeach; if ($dept !== null): ?></optgroup><?php endif; ?></select></div>
        <div class="col-md-3"><label class="form-label required" for="delivery_note_number">Delivery note no.</label><input class="form-control<?= invalid('delivery_note_number') ?>" id="delivery_note_number" name="delivery_note_number" required maxlength="60" value="<?= e(old('delivery_note_number')) ?>"></div>
        <div class="col-md-2"><label class="form-label" for="invoice_number">Invoice no.</label><input class="form-control" id="invoice_number" name="invoice_number" maxlength="60" value="<?= e(old('invoice_number')) ?>"></div>
        <div class="col-md-3"><label class="form-label" for="notes">Notes</label><input class="form-control" id="notes" name="notes" maxlength="1000" value="<?= e(old('notes')) ?>"></div>
    </div></div>
    <div class="card">
        <div class="card-header"><h2 class="card-title">Delivered items</h2><span class="small text-muted">Use “+ lot” when one item arrives in several lots</span></div>
        <div class="table-responsive"><table class="table mb-0" id="po-receipt-lines">
            <thead><tr><th scope="col">Product</th><th scope="col" class="num">Outstanding</th><th scope="col" style="width:9rem">Lot no.</th><th scope="col" style="width:9.5rem">Expiry</th><th scope="col" style="width:9.5rem">Mfg date</th><th scope="col" style="width:7rem">Accepted</th><th scope="col" style="width:6.5rem">Rejected</th><th scope="col">Rejection reason</th><th scope="col"><span class="visually-hidden">Lots</span></th></tr></thead>
            <tbody>
            <?php foreach ($items as $n => $it): ?>
                <tr data-row data-item="<?= (int) $it['id'] ?>">
                    <td><?= e($it['product_name']) ?><?= in_array($it['storage_condition'], ['refrigerated', 'frozen'], true) ? ' <i class="bi bi-snow text-info" aria-label="Cold chain"></i>' : '' ?><span class="cell-sub"><?= e($it['product_code']) ?> · per <?= e($it['order_unit']) ?><?= (int) $it['is_expiry_tracked'] ? ' · expiry required' : '' ?></span><input type="hidden" name="lines[<?= $n ?>][purchase_order_item_id]" value="<?= (int) $it['id'] ?>"></td>
                    <td class="num"><?= e(qty($it['outstanding_qty'])) ?> <?= e($it['order_unit']) ?></td>
                    <td><input class="form-control form-control-sm" name="lines[<?= $n ?>][lot_number]" maxlength="60" aria-label="Lot number"></td>
                    <td><input type="date" class="form-control form-control-sm" name="lines[<?= $n ?>][expiry_date]" min="<?= date('Y-m-d', strtotime('+1 day')) ?>" aria-label="Expiry"></td>
                    <td><input type="date" class="form-control form-control-sm" name="lines[<?= $n ?>][manufacture_date]" max="<?= date('Y-m-d') ?>" aria-label="Manufacture date"></td>
                    <td><input type="number" min="0" step="any" class="form-control form-control-sm" name="lines[<?= $n ?>][quantity]" value="<?= e(rtrim(rtrim(number_format((float) $it['outstanding_qty'], 3, '.', ''), '0'), '.')) ?>" aria-label="Accepted quantity"></td>
                    <td><input type="number" min="0" step="any" class="form-control form-control-sm" name="lines[<?= $n ?>][qty_rejected]" aria-label="Rejected quantity"></td>
                    <td><input class="form-control form-control-sm" name="lines[<?= $n ?>][rejection_reason]" maxlength="255" aria-label="Rejection reason" placeholder="e.g. damaged, short expiry"></td>
                    <td><button type="button" class="btn btn-sm btn-link" data-add-lot aria-label="Add another lot">+ lot</button></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
    </div>
    <div class="form-actions">
        <a href="<?= e(route('purchase_orders.show', ['id' => $po['id']])) ?>" class="btn btn-light">Cancel</a>
        <button type="submit" class="btn btn-primary"><i class="bi bi-box-arrow-in-down me-1" aria-hidden="true"></i>Post goods receipt</button>
    </div>
</form>
