<div class="page-header">
    <div>
        <nav aria-label="breadcrumb"><ol class="breadcrumb"><li class="breadcrumb-item"><a href="<?= e(route('purchase_orders.index')) ?>">Purchase orders</a></li><li class="breadcrumb-item active" aria-current="page">From approved requests</li></ol></nav>
        <h1>Order approved purchase requests</h1>
        <div class="subtitle">Choose a supplier and price per item. One draft purchase order is created per supplier. Quantities and prices are per base unit (you can switch to packs when editing the draft).</div>
    </div>
</div>
<?php if (!$items): ?>
    <div class="card"><?= partial('partials/empty', ['icon' => 'bi-check2-all', 'title' => 'Nothing waiting to be ordered', 'text' => 'Approved purchase request items appear here.']) ?></div>
<?php else: ?>
<form method="post" action="<?= e(route('purchase_orders.from_requests.store')) ?>" novalidate>
    <?= csrf_field() ?>
    <div class="card mb-3"><div class="card-body row g-3">
        <div class="col-md-4"><label class="form-label" for="delivery_location_id">Deliver to</label><select class="form-select" id="delivery_location_id" name="delivery_location_id"><?php foreach ($locations as $l): ?><option value="<?= (int) $l['id'] ?>"><?= e($l['name']) ?><?= (int) $l['is_cold_chain'] ? ' ❄' : '' ?></option><?php endforeach; ?></select></div>
    </div></div>
    <div class="card">
        <div class="table-responsive"><table class="table table-hover">
            <thead><tr><th scope="col"><span class="visually-hidden">Include</span></th><th scope="col">Product</th><th scope="col">Request</th><th scope="col" class="num">To order</th><th scope="col" style="width:8rem">Quantity</th><th scope="col">Supplier</th><th scope="col" style="width:8rem">Unit price</th></tr></thead>
            <tbody>
            <?php foreach ($items as $it):
                $pid = (int) $it['product_id'];
                $q = $quotes[$pid] ?? [];
                $defaultSupplier = $it['preferred_supplier_id'] ?: ($q[0]['supplier_id'] ?? null);
                $price = $it['estimated_unit_cost'];
                foreach ($q as $qq) { if ((int) $qq['supplier_id'] === (int) $defaultSupplier && $qq['quoted_unit_price'] !== null) { $price = $qq['quoted_unit_price']; } }
                $id = (int) $it['id']; ?>
                <tr>
                    <td><input class="form-check-input" type="checkbox" name="sel[<?= $id ?>][include]" value="1" checked aria-label="Include <?= e($it['product_name']) ?>"></td>
                    <td><?= e($it['product_name']) ?><span class="cell-sub"><?= e($it['product_code']) ?> · <?= e($it['unit_code']) ?></span></td>
                    <td class="small"><a href="<?= e(route('purchase_requests.show', ['id' => $it['pr_id']])) ?>"><?= e($it['pr_number']) ?></a><span class="cell-sub"><?= e($it['department_name']) ?> · <?= e(label($it['priority'])) ?></span></td>
                    <td class="num"><?= e(qty($it['remaining'])) ?></td>
                    <td><input type="number" min="0" step="any" class="form-control form-control-sm" name="sel[<?= $id ?>][quantity]" value="<?= e(rtrim(rtrim(number_format((float) $it['remaining'], 3, '.', ''), '0'), '.')) ?>" aria-label="Quantity"></td>
                    <td><select class="form-select form-select-sm" name="sel[<?= $id ?>][supplier_id]" aria-label="Supplier"><option value="">Choose…</option><?php foreach ($suppliers as $s): $quote = null; foreach ($q as $qq) { if ((int) $qq['supplier_id'] === (int) $s['id']) { $quote = $qq['quoted_unit_price']; } } ?><option value="<?= (int) $s['id'] ?>"<?= (int) $defaultSupplier === (int) $s['id'] ? ' selected' : '' ?>><?= e($s['name']) ?><?= $quote !== null ? ' (' . e(number_format((float) $quote, 4)) . ')' : '' ?></option><?php endforeach; ?></select></td>
                    <td><input type="number" min="0" step="any" class="form-control form-control-sm" name="sel[<?= $id ?>][unit_price]" value="<?= $price !== null ? e(rtrim(rtrim(number_format((float) $price, 4, '.', ''), '0'), '.')) : '' ?>" aria-label="Unit price"></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
    </div>
    <div class="form-actions">
        <a href="<?= e(route('purchase_orders.index')) ?>" class="btn btn-light">Cancel</a>
        <button type="submit" class="btn btn-primary"><i class="bi bi-file-earmark-plus me-1" aria-hidden="true"></i>Create draft purchase orders</button>
    </div>
</form>
<?php endif; ?>
