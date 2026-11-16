<?php $total = 0; foreach ($items as $i) { $total += (float) $i['qty_received'] * (float) $i['unit_cost']; } ?>
<div class="page-header">
    <div>
        <nav aria-label="breadcrumb"><ol class="breadcrumb"><li class="breadcrumb-item"><a href="<?= e(route('inventory.receipts.index')) ?>">Goods receipts</a></li><li class="breadcrumb-item active" aria-current="page"><?= e($grn['grn_number']) ?></li></ol></nav>
        <h1><?= e($grn['grn_number']) ?></h1>
        <div class="subtitle"><?= e($types[$grn['receipt_type']] ?? label($grn['receipt_type'])) ?> · <?= status_badge($grn['status']) ?></div>
    </div>
    <div class="d-flex gap-2">
        <?php if (can('codes.print')): ?><a href="<?= e(route('codes.labels', [], ['receipt' => $grn['id']])) ?>" class="btn btn-primary" target="_blank" rel="noopener"><i class="bi bi-printer me-1" aria-hidden="true"></i>Print batch labels</a><?php endif; ?>
        <?php if (can('goods_receipts.direct')): ?><a href="<?= e(route('inventory.receipts.create')) ?>" class="btn btn-outline-secondary"><i class="bi bi-plus-lg me-1" aria-hidden="true"></i>New receipt</a><?php endif; ?>
    </div>
</div>
<div class="row g-3">
    <div class="col-lg-4">
        <div class="card"><div class="card-body">
            <dl class="dl-grid">
                <dt>Received into</dt><dd><?= e($grn['location_name']) ?><br><span class="small text-muted"><?= e($grn['department_name']) ?></span></dd>
                <dt>Received</dt><dd><?= e(format_date($grn['received_at'], true)) ?></dd>
                <dt>Received by</dt><dd><?= e($grn['received_by_name']) ?></dd>
                <dt>Supplier</dt><dd><?= e($grn['supplier_name'] ?? '—') ?></dd>
                <?php if ($grn['purchase_order_id']): ?><dt>Purchase order</dt><dd><a href="<?= e(route('purchase_orders.show', ['id' => $grn['purchase_order_id']])) ?>">View PO</a></dd><?php endif; ?>
                <dt>Delivery note</dt><dd><?= e($grn['delivery_note_number'] ?? '—') ?></dd>
                <dt>Invoice</dt><dd><?= e($grn['invoice_number'] ?? '—') ?></dd>
                <?php if ($showValues): ?><dt>Total value</dt><dd class="fw-semibold"><?= e(money($total)) ?></dd><?php endif; ?>
                <?php if ($grn['notes']): ?><dt>Notes</dt><dd><?= e($grn['notes']) ?></dd><?php endif; ?>
            </dl>
        </div></div>
    </div>
    <div class="col-lg-8">
        <div class="card">
            <div class="card-header"><h2 class="card-title">Items</h2></div>
            <div class="table-responsive"><table class="table table-hover">
                <thead><tr><th scope="col">Product</th><th scope="col">Batch</th><th scope="col">Expiry</th><th scope="col" class="num">Received</th><?php if ($showValues): ?><th scope="col" class="num">Unit cost</th><th scope="col" class="num">Line total</th><?php endif; ?></tr></thead>
                <tbody>
                <?php foreach ($items as $i): ?>
                    <tr>
                        <td><a href="<?= e(route('products.show', ['id' => $i['product_id']])) ?>"><?= e($i['product_name']) ?></a><span class="cell-sub"><?= e($i['product_code']) ?></span></td>
                        <td><?php if ($i['batch_id']): ?><a href="<?= e(route('inventory.batches.show', ['id' => $i['batch_id']])) ?>" class="code-chip"><?= e($i['batch_code']) ?></a><?php endif; ?><span class="cell-sub">Lot <?= e($i['lot_number']) ?></span></td>
                        <td class="small"><?= $i['expiry_date'] ? e(format_date($i['expiry_date'])) : '—' ?></td>
                        <td class="num"><?= e(qty($i['qty_received'])) ?> <span class="small text-muted"><?= e($i['unit_code']) ?></span><?php if ((float) $i['qty_rejected'] > 0): ?><span class="cell-sub text-danger"><?= e(qty($i['qty_rejected'])) ?> rejected<?= $i['rejection_reason'] ? ': ' . e($i['rejection_reason']) : '' ?></span><?php endif; ?></td>
                        <?php if ($showValues): ?><td class="num"><?= e(number_format((float) $i['unit_cost'], 4)) ?></td><td class="num"><?= e(money((float) $i['qty_received'] * (float) $i['unit_cost'], false)) ?></td><?php endif; ?>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
        </div>
    </div>
</div>
