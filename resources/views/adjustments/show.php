<?php $total = 0; foreach ($items as $i) { $total += (float) $i['quantity_change'] * (float) $i['unit_cost']; } ?>
<div class="page-header">
    <div>
        <nav aria-label="breadcrumb"><ol class="breadcrumb"><li class="breadcrumb-item"><a href="<?= e(route('adjustments.index')) ?>">Adjustments</a></li><li class="breadcrumb-item active" aria-current="page"><?= e($adj['adjustment_number']) ?></li></ol></nav>
        <h1><?= e($adj['adjustment_number']) ?></h1>
        <div class="subtitle"><?= status_badge($adj['status']) ?> · <?= e($reasons[$adj['reason_code']][0] ?? label($adj['reason_code'])) ?> · <?= e($adj['location_name']) ?></div>
    </div>
</div>
<div class="row g-3">
    <div class="col-lg-4">
        <div class="card mb-3"><div class="card-body">
            <dl class="dl-grid">
                <dt>Location</dt><dd><?= e($adj['location_name']) ?><br><span class="small text-muted"><?= e($adj['department_name']) ?></span></dd>
                <dt>Requested by</dt><dd><?= e($adj['requested_by_name']) ?><br><span class="small text-muted"><?= e(format_date($adj['created_at'], true)) ?></span></dd>
                <?php if ($adj['approved_by']): ?><dt><?= $adj['status'] === 'rejected' ? 'Rejected by' : 'Approved by' ?></dt><dd><?= e($adj['approved_by_name']) ?><br><span class="small text-muted"><?= e(format_date($adj['approved_at'], true)) ?></span></dd><?php endif; ?>
                <?php if ($adj['rejection_reason']): ?><dt>Rejection reason</dt><dd><?= e($adj['rejection_reason']) ?></dd><?php endif; ?>
                <dt>Explanation</dt><dd><?= e($adj['notes'] ?? '—') ?></dd>
                <?php if ($adj['stock_count_id']): ?><dt>From count</dt><dd><a href="<?= e(route('counts.show', ['id' => $adj['stock_count_id']])) ?>">View stock count</a></dd><?php endif; ?>
                <?php if ($showValues): ?><dt>Value change</dt><dd class="fw-semibold <?= $total < 0 ? 'text-danger' : 'text-success' ?>"><?= e(money($total)) ?></dd><?php endif; ?>
            </dl>
        </div></div>
        <?php if ($canApprove): ?>
        <div class="card border-warning-subtle"><div class="card-body">
            <h2 class="card-title mb-2">Your decision</h2>
            <form method="post" action="<?= e(route('adjustments.decide', ['id' => $adj['id']])) ?>" class="mb-3" data-confirm="Approve and post this adjustment to the stock ledger? This cannot be undone (a correction would need a new adjustment)." data-confirm-button="Approve & post">
                <?= csrf_field() ?><input type="hidden" name="decision" value="approve">
                <button type="submit" class="btn btn-primary w-100"><i class="bi bi-check2-circle me-1" aria-hidden="true"></i>Approve &amp; post</button>
            </form>
            <form method="post" action="<?= e(route('adjustments.decide', ['id' => $adj['id']])) ?>">
                <?= csrf_field() ?><input type="hidden" name="decision" value="reject">
                <input class="form-control mb-2" name="rejection_reason" required minlength="3" maxlength="255" placeholder="Reason for rejecting" aria-label="Rejection reason">
                <button type="submit" class="btn btn-outline-danger w-100">Reject</button>
            </form>
        </div></div>
        <?php elseif ($adj['status'] === 'pending' && $own): ?>
            <div class="alert alert-info small"><i class="bi bi-info-circle me-1" aria-hidden="true"></i>Waiting for approval by another authorised person.</div>
        <?php endif; ?>
    </div>
    <div class="col-lg-8">
        <div class="card">
            <div class="card-header"><h2 class="card-title">Lines</h2></div>
            <div class="table-responsive"><table class="table table-hover">
                <thead><tr><th scope="col">Product</th><th scope="col">Batch</th><th scope="col">Expiry</th><th scope="col" class="num">Change</th><?php if ($showValues): ?><th scope="col" class="num">Value</th><?php endif; ?><th scope="col">Note</th></tr></thead>
                <tbody>
                <?php foreach ($items as $i): ?>
                    <tr>
                        <td><?= e($i['product_name']) ?><span class="cell-sub"><?= e($i['product_code']) ?></span></td>
                        <td><a class="code-chip" href="<?= e(route('inventory.batches.show', ['id' => $i['batch_id']])) ?>"><?= e($i['batch_code']) ?></a><span class="cell-sub">Lot <?= e($i['lot_number']) ?></span></td>
                        <td class="small"><?= expiry_badge($i['expiry_date']) ?></td>
                        <td class="num fw-semibold <?= (float) $i['quantity_change'] < 0 ? 'text-danger' : 'text-success' ?>"><?= (float) $i['quantity_change'] > 0 ? '+' : '' ?><?= e(qty($i['quantity_change'])) ?> <span class="small text-muted fw-normal"><?= e($i['unit_code']) ?></span></td>
                        <?php if ($showValues): ?><td class="num small"><?= e(money((float) $i['quantity_change'] * (float) $i['unit_cost'], false)) ?></td><?php endif; ?>
                        <td class="small"><?= e($i['reason'] ?? '') ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
        </div>
    </div>
</div>
