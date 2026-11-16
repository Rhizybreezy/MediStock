<?php
$statusLabel = ['pending_approval' => 'Awaiting approval', 'approved' => 'Approved – ready to dispatch', 'dispatched' => 'In transit', 'partially_received' => 'Partially received', 'received' => 'Received', 'rejected' => 'Rejected', 'cancelled' => 'Cancelled'][$t['status']] ?? label($t['status']);
$canApprove = $canApprove ?? false;
$canDispatch = $canDispatch ?? false;
$canCancel = $canCancel ?? false;
$ownTransfer = $ownTransfer ?? false;
// Request lines (product-level, before dispatch) vs dispatched batch lines.
$requestLines = array_values(array_filter($items, fn ($i) => $i['parent_item_id'] === null && (float) $i['dispatched_qty'] == 0.0));
$items = array_values(array_filter($items, fn ($i) => (float) $i['dispatched_qty'] > 0));
$listUrl = $t['type'] === 'issue' ? (can('stock.issue') ? route('inventory.issues.index') : route('inventory.incoming')) : route('transfers.index');
$listName = $t['type'] === 'issue' ? (can('stock.issue') ? 'Stock issues' : 'Incoming deliveries') : 'Transfers & returns';
$typeLabel = ['issue' => 'Stock issue', 'transfer' => 'Department transfer', 'return_to_store' => 'Return to store', 'redistribution' => 'Redistribution'][$t['type']] ?? 'Delivery';
?>
<div class="page-header">
    <div>
        <nav aria-label="breadcrumb"><ol class="breadcrumb"><li class="breadcrumb-item"><a href="<?= e($listUrl) ?>"><?= e($listName) ?></a></li><li class="breadcrumb-item active" aria-current="page"><?= e($t['transfer_number']) ?></li></ol></nav>
        <h1><?= e($t['transfer_number']) ?></h1>
        <div class="subtitle"><?= e($typeLabel) ?> · <span class="badge <?= $t['status'] === 'received' ? 'text-bg-success' : (in_array($t['status'], ['rejected', 'cancelled'], true) ? 'text-bg-secondary' : 'text-bg-warning') ?> badge-status"><?= e($statusLabel) ?></span></div>
    </div>
    <div class="d-flex gap-2 flex-wrap">
        <?php if ($canDispatch): ?>
            <form method="post" action="<?= e(route('transfers.dispatch', ['id' => $t['id']])) ?>" data-confirm="Dispatch the approved quantities now? Batches are picked first-expiry-first-out." data-confirm-button="Dispatch"><?= csrf_field() ?><button type="submit" class="btn btn-primary"><i class="bi bi-truck me-1" aria-hidden="true"></i>Dispatch</button></form>
        <?php endif; ?>
        <?php if ($canCancel): ?>
            <form method="post" action="<?= e(route('transfers.cancel', ['id' => $t['id']])) ?>" data-confirm="Cancel this transfer?" data-confirm-button="Cancel transfer" data-confirm-variant="danger"><?= csrf_field() ?><button type="submit" class="btn btn-outline-danger">Cancel</button></form>
        <?php endif; ?>
    </div>
</div>

<div class="card mb-3"><div class="card-body">
    <div class="row g-3 align-items-center text-center text-md-start">
        <div class="col-md-5"><div class="small text-muted">From</div><div class="fw-semibold"><?= e($t['source_location']) ?></div><div class="small"><?= e($t['source_department']) ?></div></div>
        <div class="col-md-2 text-center"><i class="bi bi-arrow-right fs-3 text-muted" aria-hidden="true"></i><div class="small text-muted"><?= e(format_date($t['dispatched_at'], true)) ?></div></div>
        <div class="col-md-5"><div class="small text-muted">To</div><div class="fw-semibold"><?= e($t['destination_location']) ?></div><div class="small"><?= e($t['destination_department']) ?></div></div>
    </div>
    <hr>
    <dl class="dl-grid">
        <dt>Requested by</dt><dd><?= e($t['requested_by_name']) ?> <span class="small text-muted"><?= e(format_date($t['created_at'], true)) ?></span></dd>
        <?php if ($t['approved_at'] && $t['type'] !== 'issue'): ?><dt><?= $t['status'] === 'rejected' ? 'Rejected' : 'Approved' ?></dt><dd><?= e(format_date($t['approved_at'], true)) ?></dd><?php endif; ?>
        <?php if ($t['rejection_reason']): ?><dt>Rejection reason</dt><dd><?= e($t['rejection_reason']) ?></dd><?php endif; ?>
        <dt>Dispatched by</dt><dd><?= e($t['dispatched_by_name'] ?? '—') ?></dd>
        <dt>Received</dt><dd><?= $t['received_at'] ? e(format_date($t['received_at'], true)) . ' by ' . e($t['received_by_name'] ?? '—') : '<span class="text-muted">Not yet confirmed</span>' ?></dd>
        <?php if ($t['reason']): ?><dt>Reason</dt><dd><?= e($t['reason']) ?></dd><?php endif; ?>
        <?php if ($t['discrepancy_notes']): ?><dt>Notes</dt><dd class="text-warning-emphasis"><i class="bi bi-exclamation-triangle me-1" aria-hidden="true"></i><?= e($t['discrepancy_notes']) ?></dd><?php endif; ?>
    </dl>
</div></div>

<?php if ($requestLines): ?>
<?php if ($canApprove): ?><form method="post" action="<?= e(route('transfers.approve', ['id' => $t['id']])) ?>"><?= csrf_field() ?><?php endif; ?>
<div class="card mb-3">
    <div class="card-header"><h2 class="card-title">Requested items</h2><?php if ($t['status'] === 'pending_approval' && $ownTransfer): ?><span class="small text-muted">You requested this transfer; another person must approve it.</span><?php endif; ?></div>
    <div class="table-responsive"><table class="table table-hover">
        <thead><tr><th scope="col">Product</th><th scope="col">Batch</th><th scope="col" class="num">Requested</th><th scope="col" class="num">Approved</th><?php if ($canApprove): ?><th scope="col" style="width:9rem">Approve qty</th><?php endif; ?></tr></thead>
        <tbody>
        <?php foreach ($requestLines as $rl): ?>
            <tr>
                <td><?= e($rl['product_name']) ?><span class="cell-sub"><?= e($rl['product_code']) ?> · <?= e($rl['unit_code']) ?></span></td>
                <td class="small"><?= $rl['batch_code'] ? '<span class="code-chip">' . e($rl['batch_code']) . '</span>' : 'Automatic (FEFO)' ?></td>
                <td class="num"><?= e(qty($rl['requested_qty'])) ?></td>
                <td class="num"><?= $rl['approved_qty'] !== null ? e(qty($rl['approved_qty'])) : '—' ?></td>
                <?php if ($canApprove): ?><td><input type="number" class="form-control form-control-sm" name="approved[<?= (int) $rl['id'] ?>]" min="0" max="<?= e(qty($rl['requested_qty'])) ?>" step="any" value="<?= e(qty($rl['requested_qty'])) ?>" aria-label="Approve quantity: <?= e($rl['product_name']) ?>"></td><?php endif; ?>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
    <?php if ($canApprove): ?>
    <div class="card-body border-top">
        <label class="form-label" for="rejection_reason">Rejection reason</label>
        <input class="form-control mb-3" id="rejection_reason" name="rejection_reason" maxlength="255" placeholder="Only needed if you reject">
        <div class="d-flex justify-content-end gap-2 flex-wrap">
            <button type="submit" name="decision" value="reject" class="btn btn-outline-danger">Reject</button>
            <button type="submit" name="decision" value="approve" class="btn btn-primary"><i class="bi bi-check2-circle me-1" aria-hidden="true"></i>Approve</button>
        </div>
    </div>
    <?php endif; ?>
</div>
<?php if ($canApprove): ?></form><?php endif; ?>
<?php endif; ?>

<?php if ($canConfirm): ?>
<form method="post" action="<?= e(route('inventory.issues.confirm', ['id' => $t['id']])) ?>"
      data-confirm="Confirm that these quantities physically arrived? Stock will be added to <?= e($t['destination_location']) ?>." data-confirm-button="Confirm receipt">
    <?= csrf_field() ?>
<?php endif; ?>
<?php if ($items): ?>
<div class="card mb-3">
    <div class="card-header"><h2 class="card-title"><?= $requestLines ? 'Dispatched batches' : 'Items' ?></h2><?php if ($canConfirm): ?><span class="small text-muted">Enter what actually arrived. Shortfalls stay in transit until resolved.</span><?php endif; ?></div>
    <div class="table-responsive"><table class="table table-hover">
        <thead><tr><th scope="col">Product</th><th scope="col">Batch</th><th scope="col">Expiry</th><th scope="col" class="num">Dispatched</th><th scope="col" class="num">Received</th><th scope="col" class="num">Returned</th><th scope="col" class="num">In transit</th><?php if ($canConfirm): ?><th scope="col" style="width:9rem">Received now</th><?php endif; ?></tr></thead>
        <tbody>
        <?php foreach ($items as $i): $outstanding = (float) $i['dispatched_qty'] - (float) $i['received_qty'] - (float) $i['returned_qty']; ?>
            <tr>
                <td><?= e($i['product_name']) ?><span class="cell-sub"><?= e($i['product_code']) ?></span></td>
                <td><a href="<?= e(route('inventory.batches.show', ['id' => $i['batch_id']])) ?>" class="code-chip"><?= e($i['batch_code']) ?></a><span class="cell-sub">Lot <?= e($i['lot_number']) ?></span></td>
                <td class="small"><?= expiry_badge($i['expiry_date']) ?></td>
                <td class="num"><?= e(qty($i['dispatched_qty'])) ?> <span class="small text-muted"><?= e($i['unit_code']) ?></span></td>
                <td class="num"><?= e(qty($i['received_qty'])) ?></td>
                <td class="num"><?= e(qty($i['returned_qty'])) ?></td>
                <td class="num<?= $outstanding > 0 ? ' fw-semibold text-warning-emphasis' : '' ?>"><?= e(qty($outstanding)) ?></td>
                <?php if ($canConfirm): ?>
                <td><?php if ($outstanding > 0): ?><input type="number" class="form-control form-control-sm" name="received[<?= (int) $i['id'] ?>]" min="0" max="<?= e(qty($outstanding)) ?>" step="any" value="<?= e(qty($outstanding)) ?>" aria-label="Received now: <?= e($i['product_name']) ?>"><?php else: ?><span class="text-muted small">Done</span><?php endif; ?></td>
                <?php endif; ?>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
    <?php if ($canConfirm): ?>
    <div class="card-body border-top">
        <label class="form-label" for="notes">Discrepancy notes</label>
        <input class="form-control" id="notes" name="notes" maxlength="255" placeholder="Required if you received less than dispatched, e.g. 1 box damaged">
        <div class="d-flex justify-content-end mt-3"><button type="submit" class="btn btn-primary"><i class="bi bi-check2-circle me-1" aria-hidden="true"></i>Confirm receipt</button></div>
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>
<?php if ($canConfirm): ?></form><?php endif; ?>

<?php if ($canReturn): ?>
<div class="card mb-3 border-warning-subtle">
    <div class="card-body">
        <h2 class="card-title mb-1">Resolve stock still in transit</h2>
        <p class="small text-muted">If the remaining quantity will not be delivered, return it to <?= e($t['source_location']) ?>. This closes the delivery.</p>
        <form method="post" action="<?= e(route('inventory.issues.return', ['id' => $t['id']])) ?>" class="row g-2"
              data-confirm="Return all quantities still in transit to <?= e($t['source_location']) ?>? This closes the delivery." data-confirm-button="Return to source" data-confirm-variant="warning">
            <?= csrf_field() ?>
            <div class="col-md-8"><input class="form-control" name="reason" required minlength="3" maxlength="200" placeholder="Reason (e.g. not delivered, sent back)" aria-label="Reason"></div>
            <div class="col-md-4"><button type="submit" class="btn btn-outline-warning w-100"><i class="bi bi-arrow-counterclockwise me-1" aria-hidden="true"></i>Return to source</button></div>
        </form>
    </div>
</div>
<?php endif; ?>

<?php if ($movements): ?>
<div class="card">
    <div class="card-header"><h2 class="card-title">Ledger entries</h2></div>
    <div class="table-responsive"><table class="table table-sm table-hover">
        <thead><tr><th scope="col">When</th><th scope="col">Txn</th><th scope="col">Type</th><th scope="col">Product / batch</th><th scope="col" class="num">Qty</th><th scope="col">From → To</th><th scope="col">By</th></tr></thead>
        <tbody>
        <?php foreach ($movements as $m): ?>
            <tr>
                <td class="small text-nowrap"><?= e(format_date($m['created_at'], true)) ?></td>
                <td class="small"><span class="code-chip"><?= e($m['txn_number']) ?></span></td>
                <td><?= movement_badge($m['movement_type']) ?></td>
                <td class="small"><?= e($m['product_name']) ?> · <?= e($m['batch_code']) ?></td>
                <td class="num"><?= e(qty($m['quantity'])) ?></td>
                <td class="small"><?= e($m['from_location'] ?? 'Outside') ?> → <?= e($m['to_location'] ?? 'Outside') ?></td>
                <td class="small"><?= e($m['user_name']) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
</div>
<?php endif; ?>
