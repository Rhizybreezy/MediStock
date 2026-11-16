<?php
$statusText = ['draft' => 'Draft', 'pending_approval' => 'Awaiting approval', 'approved' => 'Approved', 'sent' => 'Sent to supplier', 'partially_received' => 'Partially received', 'received' => 'Fully received', 'closed' => 'Closed', 'cancelled' => 'Cancelled'];
$s = $po['status'];
$printable = !in_array($s, ['draft', 'pending_approval', 'cancelled'], true);
?>
<div class="page-header">
    <div>
        <nav aria-label="breadcrumb"><ol class="breadcrumb"><li class="breadcrumb-item"><a href="<?= e(route('purchase_orders.index')) ?>">Purchase orders</a></li><li class="breadcrumb-item active" aria-current="page"><?= e($po['po_number']) ?></li></ol></nav>
        <h1><?= e($po['po_number']) ?></h1>
        <div class="subtitle"><strong><?= e($statusText[$s]) ?></strong> · <a href="<?= e(route('suppliers.show', ['id' => $po['supplier_id']])) ?>"><?= e($po['supplier_name']) ?></a><?= (int) $po['is_late'] ? ' · <span class="text-danger fw-semibold"><i class="bi bi-exclamation-triangle" aria-hidden="true"></i> Delivery overdue</span>' : '' ?></div>
    </div>
    <div class="d-flex gap-2 flex-wrap">
        <?php if ($printable): ?><a href="<?= e(route('purchase_orders.pdf', ['id' => $po['id']])) ?>" class="btn btn-outline-secondary" target="_blank" rel="noopener"><i class="bi bi-filetype-pdf me-1" aria-hidden="true"></i>PDF</a><?php endif; ?>
        <?php if ($canReceive): ?><a href="<?= e(route('inventory.receipts.po.create', ['id' => $po['id']])) ?>" class="btn btn-primary"><i class="bi bi-box-arrow-in-down me-1" aria-hidden="true"></i>Receive goods</a><?php endif; ?>
        <?php if ($canManage && $s === 'draft'): ?>
            <a href="<?= e(route('purchase_orders.edit', ['id' => $po['id']])) ?>" class="btn btn-outline-secondary"><i class="bi bi-pencil me-1" aria-hidden="true"></i>Edit</a>
            <form method="post" action="<?= e(route('purchase_orders.action', ['id' => $po['id']])) ?>"><?= csrf_field() ?><input type="hidden" name="action" value="submit"><button type="submit" class="btn btn-primary"><i class="bi bi-send me-1" aria-hidden="true"></i>Submit for approval</button></form>
        <?php endif; ?>
        <?php if ($canManage && $s === 'approved'): ?>
            <form method="post" action="<?= e(route('purchase_orders.action', ['id' => $po['id']])) ?>" data-confirm="Mark this purchase order as sent to <?= e($po['supplier_name']) ?>?" data-confirm-button="Mark as sent"><?= csrf_field() ?><input type="hidden" name="action" value="send"><button type="submit" class="btn btn-primary"><i class="bi bi-envelope-check me-1" aria-hidden="true"></i>Mark as sent</button></form>
        <?php endif; ?>
    </div>
</div>
<?php if ($s === 'pending_approval' && $own): ?><div class="alert alert-info small"><i class="bi bi-info-circle me-1" aria-hidden="true"></i>Awaiting approval by another authorised person.</div><?php endif; ?>
<div class="row g-3">
    <div class="col-xl-4">
        <div class="card mb-3"><div class="card-body">
            <dl class="dl-grid">
                <dt>Supplier</dt><dd><?= e($po['supplier_name']) ?><br><span class="small text-muted"><?= e(trim(($po['contact_person'] ?? '') . ' ' . ($po['supplier_phone'] ?? ''))) ?></span></dd>
                <dt>Deliver to</dt><dd><?= e($po['delivery_location']) ?></dd>
                <dt>Order date</dt><dd><?= e(format_date($po['order_date'])) ?></dd>
                <dt>Expected</dt><dd><?= e(format_date($po['expected_delivery_date'])) ?></dd>
                <dt>Created by</dt><dd><?= e($po['created_by_name']) ?> <span class="small text-muted"><?= e(format_date($po['created_at'])) ?></span></dd>
                <?php if ($po['approved_by']): ?><dt>Approved by</dt><dd><?= e($po['approved_by_name']) ?> <span class="small text-muted"><?= e(format_date($po['approved_at'])) ?></span></dd><?php endif; ?>
                <?php if ($po['sent_at']): ?><dt>Sent</dt><dd><?= e(format_date($po['sent_at'], true)) ?></dd><?php endif; ?>
                <dt>Subtotal</dt><dd><?= e(money($po['subtotal'])) ?></dd>
                <dt>Tax</dt><dd><?= e(money($po['tax_amount'])) ?></dd>
                <dt>Total</dt><dd class="fw-semibold"><?= e(money($po['total_amount'])) ?></dd>
                <?php if ($po['notes']): ?><dt>Notes</dt><dd class="small"><?= nl2br(e($po['notes'])) ?></dd><?php endif; ?>
            </dl>
        </div></div>
        <?php if ($canDecide): ?>
        <div class="card border-warning-subtle mb-3"><div class="card-body">
            <h2 class="card-title mb-2">Approval</h2>
            <form method="post" action="<?= e(route('purchase_orders.action', ['id' => $po['id']])) ?>" class="mb-2" data-confirm="Approve <?= e($po['po_number']) ?> for <?= e(money($po['total_amount'])) ?>?" data-confirm-button="Approve"><?= csrf_field() ?><input type="hidden" name="action" value="approve"><button type="submit" class="btn btn-primary w-100"><i class="bi bi-check2-circle me-1" aria-hidden="true"></i>Approve purchase order</button></form>
            <form method="post" action="<?= e(route('purchase_orders.action', ['id' => $po['id']])) ?>"><?= csrf_field() ?><input type="hidden" name="action" value="return"><input class="form-control mb-2" name="reason" required minlength="3" maxlength="255" placeholder="What needs to change?" aria-label="Reason"><button type="submit" class="btn btn-outline-danger w-100">Return for changes</button></form>
        </div></div>
        <?php endif; ?>
        <?php if ($canManage && in_array($s, ['draft', 'pending_approval', 'approved', 'sent'], true) && (float) $po['received_total'] == 0.0): ?>
        <div class="card mb-3"><div class="card-body">
            <form method="post" action="<?= e(route('purchase_orders.action', ['id' => $po['id']])) ?>" data-confirm="Cancel this purchase order? Linked purchase request quantities become available to order again." data-confirm-button="Cancel PO" data-confirm-variant="danger"><?= csrf_field() ?><input type="hidden" name="action" value="cancel"><input class="form-control form-control-sm mb-2" name="reason" required minlength="3" maxlength="255" placeholder="Reason for cancelling" aria-label="Reason"><button type="submit" class="btn btn-sm btn-outline-danger w-100">Cancel purchase order</button></form>
        </div></div>
        <?php elseif ($canManage && in_array($s, ['partially_received', 'approved', 'sent'], true)): ?>
        <div class="card mb-3"><div class="card-body">
            <form method="post" action="<?= e(route('purchase_orders.action', ['id' => $po['id']])) ?>" data-confirm="Close this purchase order? No further deliveries will be expected." data-confirm-button="Close PO" data-confirm-variant="warning"><?= csrf_field() ?><input type="hidden" name="action" value="close"><input class="form-control form-control-sm mb-2" name="reason" required minlength="3" maxlength="255" placeholder="Reason (e.g. supplier cannot deliver balance)" aria-label="Reason"><button type="submit" class="btn btn-sm btn-outline-warning w-100">Close with outstanding balance</button></form>
        </div></div>
        <?php endif; ?>
    </div>
    <div class="col-xl-8">
        <div class="card mb-3"><div class="card-header"><h2 class="card-title">Order lines</h2></div>
            <div class="table-responsive"><table class="table table-hover">
                <thead><tr><th scope="col">Product</th><th scope="col" class="num">Ordered</th><th scope="col" class="num">Unit price</th><th scope="col" class="num">Line total</th><th scope="col" class="num">Received</th><th scope="col" class="num">Outstanding</th></tr></thead>
                <tbody>
                <?php foreach ($items as $i): ?>
                    <tr>
                        <td><a href="<?= e(route('products.show', ['id' => $i['product_id']])) ?>"><?= e($i['product_name']) ?></a><span class="cell-sub"><?= e($i['product_code']) ?><?= $i['pr_number'] ? ' · ' . e($i['pr_number']) : '' ?><?= (float) $i['unit_factor'] != 1.0 ? ' · 1 ' . e($i['order_unit']) . ' = ' . e(qty($i['unit_factor'])) . ' ' . e($i['unit_code']) : '' ?></span></td>
                        <td class="num"><?= e(qty($i['ordered_qty'])) ?> <span class="small text-muted"><?= e($i['order_unit']) ?></span></td>
                        <td class="num"><?= e(number_format((float) $i['unit_price'], 2)) ?></td>
                        <td class="num"><?= e(money($i['line_total'], false)) ?></td>
                        <td class="num"><?= e(qty($i['received_qty'])) ?></td>
                        <td class="num <?= (float) $i['outstanding_qty'] > 0 && !in_array($s, ['draft', 'pending_approval', 'cancelled', 'closed'], true) ? 'fw-semibold text-warning-emphasis' : '' ?>"><?= e(qty($i['outstanding_qty'])) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
        </div>
        <?php if ($receipts): ?>
        <div class="card"><div class="card-header"><h2 class="card-title">Goods receipts</h2></div>
            <div class="table-responsive"><table class="table table-sm table-hover">
                <thead><tr><th scope="col">GRN</th><th scope="col">Delivery note</th><th scope="col">Received</th><th scope="col">By</th><th scope="col" class="num">Rejected (base)</th></tr></thead>
                <tbody><?php foreach ($receipts as $g): ?><tr><td><a href="<?= e(route('inventory.receipts.show', ['id' => $g['id']])) ?>"><?= e($g['grn_number']) ?></a></td><td class="small"><?= e($g['delivery_note_number'] ?? '—') ?></td><td class="small"><?= e(format_date($g['posted_at'], true)) ?></td><td class="small"><?= e($g['received_by_name']) ?></td><td class="num small <?= (float) $g['rejected'] > 0 ? 'text-danger' : '' ?>"><?= e(qty($g['rejected'] ?? 0)) ?></td></tr><?php endforeach; ?></tbody>
            </table></div>
        </div>
        <?php endif; ?>
    </div>
</div>
