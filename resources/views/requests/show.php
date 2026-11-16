<?php
$prioClass = ['emergency' => 'danger', 'urgent' => 'warning', 'routine' => 'secondary'];
$issuing = $canIssue && $sources;
?>
<div class="page-header">
    <div>
        <nav aria-label="breadcrumb"><ol class="breadcrumb"><li class="breadcrumb-item"><a href="<?= e(route('requests.index')) ?>">Stock requests</a></li><li class="breadcrumb-item active" aria-current="page"><?= e($r['request_number']) ?></li></ol></nav>
        <h1><?= e($r['request_number']) ?></h1>
        <div class="subtitle"><?= status_badge($r['status']) ?> <span class="badge text-bg-<?= $prioClass[$r['priority']] ?> badge-status"><?= e(label($r['priority'])) ?></span> · <?= e($r['requesting_department']) ?> → from <?= e($r['supplying_department']) ?></div>
    </div>
    <div class="d-flex gap-2 flex-wrap">
        <?php if ($canSubmit): ?><form method="post" action="<?= e(route('requests.submit', ['id' => $r['id']])) ?>"><?= csrf_field() ?><button class="btn btn-primary" type="submit"><i class="bi bi-send me-1" aria-hidden="true"></i>Submit</button></form><?php endif; ?>
        <?php if ($canCancel): ?><form method="post" action="<?= e(route('requests.cancel', ['id' => $r['id']])) ?>" data-confirm="Cancel this request?" data-confirm-button="Cancel request" data-confirm-variant="danger"><?= csrf_field() ?><button class="btn btn-outline-danger" type="submit">Cancel request</button></form><?php endif; ?>
    </div>
</div>

<div class="row g-3">
<div class="col-xl-4">
    <div class="card mb-3"><div class="card-body">
        <dl class="dl-grid">
            <dt>Deliver to</dt><dd><?= e($r['destination_location']) ?></dd>
            <dt>Requested by</dt><dd><?= e($r['requested_by_name']) ?><br><span class="small text-muted"><?= e(format_date($r['submitted_at'] ?? $r['created_at'], true)) ?></span></dd>
            <dt>Needed by</dt><dd><?= e(format_date($r['needed_by'])) ?></dd>
            <?php if ($r['notes']): ?><dt>Notes</dt><dd><?= e($r['notes']) ?></dd><?php endif; ?>
            <?php if ($r['reviewed_by']): ?><dt>Reviewed by</dt><dd><?= e($r['reviewed_by_name']) ?><br><span class="small text-muted"><?= e(format_date($r['reviewed_at'], true)) ?></span></dd><?php endif; ?>
            <?php if ($r['review_notes']): ?><dt>Review notes</dt><dd><?= e($r['review_notes']) ?></dd><?php endif; ?>
        </dl>
    </div></div>
    <?php if ($issues): ?>
    <div class="card mb-3">
        <div class="card-header"><h2 class="card-title">Issues against this request</h2></div>
        <ul class="list-group list-group-flush list-compact">
            <?php foreach ($issues as $iss): ?>
                <li class="list-group-item d-flex justify-content-between align-items-center">
                    <span><a href="<?= e(route('inventory.issues.show', ['id' => $iss['id']])) ?>"><?= e($iss['transfer_number']) ?></a><span class="cell-sub"><?= e(format_date($iss['dispatched_at'], true)) ?> · <?= e($iss['dispatched_by_name'] ?? '') ?></span></span>
                    <span class="badge <?= $iss['status'] === 'received' ? 'text-bg-success' : 'text-bg-warning' ?> badge-status"><?= e($iss['status'] === 'dispatched' ? 'In transit' : label($iss['status'])) ?></span>
                </li>
            <?php endforeach; ?>
        </ul>
    </div>
    <?php endif; ?>
    <?php if ($r['status'] === 'submitted' && $ownRequest && can('requests.approve')): ?>
        <div class="alert alert-info small"><i class="bi bi-info-circle me-1" aria-hidden="true"></i>You raised this request, so another authorised person must review it.</div>
    <?php endif; ?>
</div>

<div class="col-xl-8">
<?php if ($canReview): ?>
<form method="post" action="<?= e(route('requests.review', ['id' => $r['id']])) ?>">
<?= csrf_field() ?>
<?php elseif ($issuing): ?>
<form method="post" action="<?= e(route('requests.issue', ['id' => $r['id']])) ?>" data-confirm="Dispatch the entered quantities? Batches are chosen first-expiry-first-out." data-confirm-button="Dispatch">
<?= csrf_field() ?>
<?php endif; ?>
<div class="card mb-3">
    <div class="card-header">
        <h2 class="card-title">Items</h2>
        <?php if ($issuing): ?>
            <div class="d-flex align-items-center gap-2">
                <label for="source_location_id" class="small text-muted mb-0">Issue from</label>
                <select class="form-select form-select-sm" id="source_location_id" name="source_location_id" style="width:auto" data-reload-source>
                    <?php foreach ($sources as $s): ?><option value="<?= (int) $s['id'] ?>"<?= (int) $s['id'] === $sourceId ? ' selected' : '' ?>><?= e($s['name']) ?></option><?php endforeach; ?>
                </select>
            </div>
        <?php endif; ?>
    </div>
    <div class="table-responsive"><table class="table table-hover">
        <thead><tr>
            <th scope="col">Product</th><th scope="col" class="num">Requested</th><th scope="col" class="num">Approved</th>
            <th scope="col" class="num">Issued</th><th scope="col" class="num">Received</th>
            <?php if ($canReview): ?><th scope="col" style="width:9rem">Approve qty</th><?php endif; ?>
            <?php if ($issuing): ?><th scope="col" class="num">Available</th><th scope="col" style="width:9rem">Issue now</th><?php endif; ?>
        </tr></thead>
        <tbody>
        <?php foreach ($items as $i): $remaining = (float) $i['approved_qty'] - (float) $i['issued_qty']; ?>
            <tr>
                <td><a href="<?= e(route('products.show', ['id' => $i['product_id']])) ?>"><?= e($i['product_name']) ?></a><span class="cell-sub"><?= e($i['product_code']) ?> · <?= e($i['unit_code']) ?></span></td>
                <td class="num"><?= e(qty($i['requested_qty'])) ?></td>
                <td class="num"><?= $i['approved_qty'] !== null ? e(qty($i['approved_qty'])) : '—' ?></td>
                <td class="num"><?= e(qty($i['issued_qty'])) ?></td>
                <td class="num"><?= e(qty($i['received_qty'])) ?></td>
                <?php if ($canReview): ?><td><input type="number" class="form-control form-control-sm" name="approved[<?= (int) $i['id'] ?>]" min="0" max="<?= e(qty($i['requested_qty'])) ?>" step="any" value="<?= e(qty($i['requested_qty'])) ?>" aria-label="Approve quantity for <?= e($i['product_name']) ?>"></td><?php endif; ?>
                <?php if ($issuing): ?>
                    <td class="num small <?= ($i['available'] ?? 0) < $remaining ? 'text-danger' : '' ?>"><?= e(qty($i['available'] ?? 0)) ?></td>
                    <td><?php if ($remaining > 0): ?><input type="number" class="form-control form-control-sm" name="issue[<?= (int) $i['id'] ?>]" min="0" max="<?= e(qty($remaining)) ?>" step="any" value="<?= e(qty(min($remaining, (float) ($i['available'] ?? 0)))) ?>" aria-label="Issue now: <?= e($i['product_name']) ?>"><?php else: ?><span class="small text-muted">Done</span><?php endif; ?></td>
                <?php endif; ?>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
    <?php if ($canReview): ?>
        <div class="card-body border-top">
            <label class="form-label" for="review_notes">Review notes</label>
            <input class="form-control mb-3" id="review_notes" name="review_notes" maxlength="255" placeholder="Required when rejecting; optional when approving">
            <div class="d-flex gap-2 justify-content-end flex-wrap">
                <button type="submit" name="decision" value="reject" class="btn btn-outline-danger">Reject request</button>
                <button type="submit" name="decision" value="approve" class="btn btn-primary"><i class="bi bi-check2-circle me-1" aria-hidden="true"></i>Approve quantities</button>
            </div>
        </div>
    <?php elseif ($issuing): ?>
        <div class="card-body border-top d-flex justify-content-end"><button type="submit" class="btn btn-primary"><i class="bi bi-truck me-1" aria-hidden="true"></i>Dispatch to <?= e($r['requesting_department']) ?></button></div>
    <?php endif; ?>
</div>
<?php if ($canReview || $issuing): ?></form><?php endif; ?>

<?php if ($canClose): ?>
<div class="card"><div class="card-body">
    <h2 class="card-title mb-1">Close remaining quantities</h2>
    <p class="small text-muted">Use when the rest cannot be supplied. The request is completed with what has been issued.</p>
    <form method="post" action="<?= e(route('requests.close', ['id' => $r['id']])) ?>" class="row g-2" data-confirm="Close the remaining unissued quantities on this request?" data-confirm-button="Close remaining" data-confirm-variant="warning">
        <?= csrf_field() ?>
        <div class="col-md-8"><input class="form-control" name="reason" required minlength="3" maxlength="200" placeholder="Reason (e.g. out of stock, supplier delay)" aria-label="Reason"></div>
        <div class="col-md-4"><button type="submit" class="btn btn-outline-warning w-100">Close remaining</button></div>
    </form>
</div></div>
<?php endif; ?>
</div>
</div>
