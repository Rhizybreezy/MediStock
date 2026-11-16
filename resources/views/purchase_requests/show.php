<?php $prio = ['emergency' => 'danger', 'urgent' => 'warning', 'routine' => 'secondary']; ?>
<div class="page-header">
    <div>
        <nav aria-label="breadcrumb"><ol class="breadcrumb"><li class="breadcrumb-item"><a href="<?= e(route('purchase_requests.index')) ?>">Purchase requests</a></li><li class="breadcrumb-item active" aria-current="page"><?= e($pr['pr_number']) ?></li></ol></nav>
        <h1><?= e($pr['pr_number']) ?></h1>
        <div class="subtitle"><?= status_badge($pr['status']) ?> <span class="badge text-bg-<?= $prio[$pr['priority']] ?> badge-status"><?= e(label($pr['priority'])) ?></span> · <?= e($pr['department_name']) ?></div>
    </div>
    <div class="d-flex gap-2 flex-wrap">
        <?php if ($canSubmit): ?><form method="post" action="<?= e(route('purchase_requests.submit', ['id' => $pr['id']])) ?>"><?= csrf_field() ?><button class="btn btn-primary" type="submit"><i class="bi bi-send me-1" aria-hidden="true"></i>Submit</button></form><?php endif; ?>
        <?php if ($canOrder): ?><a class="btn btn-primary" href="<?= e(route('purchase_orders.from_requests')) ?>"><i class="bi bi-file-earmark-plus me-1" aria-hidden="true"></i>Create purchase orders</a><?php endif; ?>
        <?php if ($canCancel): ?><form method="post" action="<?= e(route('purchase_requests.cancel', ['id' => $pr['id']])) ?>" data-confirm="Cancel this purchase request?" data-confirm-button="Cancel request" data-confirm-variant="danger"><?= csrf_field() ?><button class="btn btn-outline-danger" type="submit">Cancel</button></form><?php endif; ?>
    </div>
</div>
<div class="row g-3">
    <div class="col-xl-4">
        <div class="card mb-3"><div class="card-body">
            <dl class="dl-grid">
                <dt>Requested by</dt><dd><?= e($pr['requested_by_name']) ?><br><span class="small text-muted"><?= e(format_date($pr['submitted_at'] ?? $pr['created_at'], true)) ?></span></dd>
                <dt>Justification</dt><dd><?= e($pr['justification'] ?? '—') ?></dd>
                <dt>Estimated total</dt><dd class="fw-semibold"><?= e(money($pr['estimated_total'])) ?></dd>
                <?php if ($pr['approved_by']): ?><dt><?= $pr['status'] === 'rejected' ? 'Rejected by' : 'Approved by' ?></dt><dd><?= e($pr['approved_by_name']) ?><br><span class="small text-muted"><?= e(format_date($pr['approved_at'], true)) ?></span></dd><?php endif; ?>
                <?php if ($pr['rejection_reason']): ?><dt>Reason</dt><dd><?= e($pr['rejection_reason']) ?></dd><?php endif; ?>
            </dl>
        </div></div>
        <?php if ($canDecide): ?>
        <div class="card border-warning-subtle"><div class="card-body">
            <h2 class="card-title mb-2">Your decision</h2>
            <form method="post" action="<?= e(route('purchase_requests.decide', ['id' => $pr['id']])) ?>">
                <?= csrf_field() ?>
                <input class="form-control mb-2" name="rejection_reason" maxlength="255" placeholder="Reason (required to reject)" aria-label="Rejection reason">
                <div class="d-flex gap-2"><button name="decision" value="reject" class="btn btn-outline-danger flex-fill">Reject</button><button name="decision" value="approve" class="btn btn-primary flex-fill"><i class="bi bi-check2-circle me-1" aria-hidden="true"></i>Approve</button></div>
            </form>
        </div></div>
        <?php elseif ($pr['status'] === 'submitted' && $own): ?>
            <div class="alert alert-info small"><i class="bi bi-info-circle me-1" aria-hidden="true"></i>Awaiting approval by another authorised person.</div>
        <?php endif; ?>
        <?php if ($orders): ?>
        <div class="card mt-3"><div class="card-header"><h2 class="card-title">Purchase orders</h2></div><ul class="list-group list-group-flush list-compact">
            <?php foreach ($orders as $o): ?><li class="list-group-item d-flex justify-content-between"><span><a href="<?= e(route('purchase_orders.show', ['id' => $o['id']])) ?>"><?= e($o['po_number']) ?></a><span class="cell-sub"><?= e($o['supplier_name']) ?></span></span><?= status_badge($o['status']) ?></li><?php endforeach; ?>
        </ul></div>
        <?php endif; ?>
    </div>
    <div class="col-xl-8">
        <div class="card"><div class="card-header"><h2 class="card-title">Items</h2></div>
            <div class="table-responsive"><table class="table table-hover">
                <thead><tr><th scope="col">Product</th><th scope="col" class="num">Quantity</th><th scope="col" class="num">Est. cost</th><th scope="col" class="num">Est. total</th><th scope="col">Preferred supplier</th><th scope="col" class="num">Ordered</th></tr></thead>
                <tbody>
                <?php foreach ($items as $i): ?>
                    <tr>
                        <td><a href="<?= e(route('products.show', ['id' => $i['product_id']])) ?>"><?= e($i['product_name']) ?></a><span class="cell-sub"><?= e($i['product_code']) ?></span></td>
                        <td class="num"><?= e(qty($i['quantity'])) ?> <span class="small text-muted"><?= e($i['unit_code']) ?></span></td>
                        <td class="num small"><?= $i['estimated_unit_cost'] !== null ? e(number_format((float) $i['estimated_unit_cost'], 4)) : '—' ?></td>
                        <td class="num"><?= e(money((float) $i['quantity'] * (float) $i['estimated_unit_cost'], false)) ?></td>
                        <td class="small"><?= e($i['supplier_name'] ?? '—') ?></td>
                        <td class="num"><?= e(qty($i['ordered_qty'])) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
        </div>
    </div>
</div>
