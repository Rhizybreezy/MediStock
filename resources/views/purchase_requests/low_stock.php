<div class="page-header">
    <div>
        <nav aria-label="breadcrumb"><ol class="breadcrumb"><li class="breadcrumb-item"><a href="<?= e(route('purchase_requests.index')) ?>">Purchase requests</a></li><li class="breadcrumb-item active" aria-current="page">Low-stock reorder</li></ol></nav>
        <h1>Reorder low-stock items</h1>
        <div class="subtitle">Products at or below their hospital reorder level. Suggested quantity = target (maximum level, or 2× reorder level) − usable stock − already on order − already requested. Review before submitting.</div>
    </div>
</div>
<?php if (!$rows): ?>
    <div class="card"><?= partial('partials/empty', ['icon' => 'bi-check2-circle', 'title' => 'Nothing below reorder level', 'text' => 'All products with reorder levels have enough usable stock.']) ?></div>
<?php else: ?>
<form method="post" action="<?= e(route('purchase_requests.store')) ?>" novalidate>
    <?= csrf_field() ?>
    <input type="hidden" name="source" value="reorder_suggestion">
    <div class="card mb-3"><div class="card-body"><?= partial('purchase_requests/_header_fields', ['departments' => $departments, 'defaultJustification' => 'Replenish items at or below reorder level']) ?></div></div>
    <div class="card">
        <div class="table-responsive"><table class="table table-hover">
            <thead><tr><th scope="col"><span class="visually-hidden">Include</span></th><th scope="col">Product</th><th scope="col" class="num">Usable</th><th scope="col" class="num">Reorder at</th><th scope="col" class="num">On order</th><th scope="col" class="num">Requested</th><th scope="col" style="width:8rem">Quantity</th><th scope="col" style="width:8rem">Est. cost / unit</th><th scope="col">Supplier</th></tr></thead>
            <tbody>
            <?php foreach ($rows as $i => $r): $include = (float) $r['suggested_qty'] > 0; ?>
                <tr>
                    <td><input class="form-check-input" type="checkbox" name="lines[<?= $i ?>][include]" value="1" <?= $include ? 'checked' : '' ?> aria-label="Include <?= e($r['name']) ?>"><input type="hidden" name="lines[<?= $i ?>][product_id]" value="<?= (int) $r['product_id'] ?>"></td>
                    <td><?= e($r['name']) ?><span class="cell-sub"><?= e($r['product_code']) ?> · <?= e($r['unit_code']) ?></span></td>
                    <td class="num <?= (float) $r['usable_qty'] <= 0 ? 'text-danger fw-semibold' : '' ?>"><?= e(qty($r['usable_qty'])) ?></td>
                    <td class="num small"><?= e(qty($r['reorder_level'])) ?></td>
                    <td class="num small"><?= e(qty($r['on_order'])) ?></td>
                    <td class="num small"><?= e(qty($r['requested'])) ?></td>
                    <td><input type="number" min="0" step="any" class="form-control form-control-sm" name="lines[<?= $i ?>][quantity]" value="<?= e((string) $r['suggested_qty']) ?>" aria-label="Quantity for <?= e($r['name']) ?>"></td>
                    <td><input type="number" min="0" step="any" class="form-control form-control-sm" name="lines[<?= $i ?>][estimated_unit_cost]" value="<?= $r['estimated_cost'] !== null ? e(rtrim(rtrim(number_format((float) $r['estimated_cost'], 4, '.', ''), '0'), '.')) : '' ?>" aria-label="Estimated cost"></td>
                    <td><select class="form-select form-select-sm" name="lines[<?= $i ?>][preferred_supplier_id]" aria-label="Supplier"><option value="">—</option><?php foreach ($suppliers as $s): ?><option value="<?= (int) $s['id'] ?>"<?= (int) $r['preferred_supplier_id'] === (int) $s['id'] ? ' selected' : '' ?>><?= e($s['name']) ?></option><?php endforeach; ?></select></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
    </div>
    <div class="form-actions">
        <a href="<?= e(route('purchase_requests.index')) ?>" class="btn btn-light">Cancel</a>
        <button type="submit" name="action" value="draft" class="btn btn-outline-secondary">Save draft</button>
        <button type="submit" name="action" value="submit" class="btn btn-primary"><i class="bi bi-send me-1" aria-hidden="true"></i>Submit selected for approval</button>
    </div>
</form>
<?php endif; ?>
