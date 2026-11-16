<?php $statusText = ['in_progress' => 'In progress', 'submitted' => 'Awaiting approval', 'approved' => 'Approved', 'cancelled' => 'Cancelled']; ?>
<div class="page-header">
    <div>
        <nav aria-label="breadcrumb"><ol class="breadcrumb"><li class="breadcrumb-item"><a href="<?= e(route('counts.index')) ?>">Stock counts</a></li><li class="breadcrumb-item active" aria-current="page"><?= e($count['count_number']) ?></li></ol></nav>
        <h1><?= e($count['count_number']) ?></h1>
        <div class="subtitle"><?= e($count['location_name']) ?> · <?= e(label($count['type'])) ?> count · <strong><?= e($statusText[$count['status']] ?? $count['status']) ?></strong> · started by <?= e($count['started_by_name']) ?> <?= e(time_ago($count['created_at'])) ?></div>
    </div>
    <div class="d-flex gap-2">
        <?php if ($canApprove): ?>
            <form method="post" action="<?= e(route('counts.approve', ['id' => $count['id']])) ?>" data-confirm="Approve this count? All variances will be posted to the stock ledger as a count adjustment." data-confirm-button="Approve & post variances"><?= csrf_field() ?><button type="submit" class="btn btn-primary"><i class="bi bi-check2-circle me-1" aria-hidden="true"></i>Approve count</button></form>
        <?php endif; ?>
        <?php if ($canCancel): ?>
            <form method="post" action="<?= e(route('counts.cancel', ['id' => $count['id']])) ?>" data-confirm="Cancel this count? Nothing will be posted." data-confirm-button="Cancel count" data-confirm-variant="danger"><?= csrf_field() ?><button type="submit" class="btn btn-outline-danger">Cancel</button></form>
        <?php endif; ?>
    </div>
</div>
<?php if ($count['status'] === 'submitted' && !$canApprove): ?><div class="alert alert-info small"><i class="bi bi-info-circle me-1" aria-hidden="true"></i>Submitted. Awaiting approval by a different authorised person.</div><?php endif; ?>
<?php if ($canCount): ?><form method="post" action="<?= e(route('counts.save', ['id' => $count['id']])) ?>"><?= csrf_field() ?><?php endif; ?>
<div class="card">
    <div class="card-header"><h2 class="card-title">Batches at this location</h2><?php if ($canCount): ?><span class="d-flex align-items-center gap-2"><span class="small text-muted">Enter what you physically find (0 if missing).</span><button type="button" class="btn btn-sm btn-outline-primary" data-count-scan><i class="bi bi-upc-scan me-1" aria-hidden="true"></i>Scan batch</button></span><?php endif; ?></div>
    <?php if ($items): ?>
    <div class="table-responsive"><table class="table table-hover">
        <thead><tr>
            <th scope="col">Product</th><th scope="col">Batch</th><th scope="col">Expiry</th>
            <?php if ($showSystem): ?><th scope="col" class="num">System</th><?php endif; ?>
            <th scope="col" <?= $canCount ? 'style="width:10rem"' : 'class="num"' ?>>Counted</th>
            <?php if ($showSystem): ?><th scope="col" class="num">Variance</th><?php if ($showValues): ?><th scope="col" class="num">Value</th><?php endif; ?><?php endif; ?>
        </tr></thead>
        <tbody>
        <?php foreach ($items as $i): $var = $i['variance'] !== null ? (float) $i['variance'] : null; ?>
            <tr>
                <td><?= e($i['product_name']) ?><span class="cell-sub"><?= e($i['product_code']) ?> · <?= e($i['unit_code']) ?></span></td>
                <td><span class="code-chip"><?= e($i['batch_code']) ?></span><span class="cell-sub">Lot <?= e($i['lot_number']) ?></span></td>
                <td class="small"><?= expiry_badge($i['expiry_date']) ?></td>
                <?php if ($showSystem): ?><td class="num"><?= e(qty($i['system_qty'])) ?></td><?php endif; ?>
                <td class="<?= $canCount ? '' : 'num' ?>"><?php if ($canCount): ?><input type="number" min="0" step="any" class="form-control form-control-sm" data-batch-code="<?= e($i['batch_code']) ?>" name="counted[<?= (int) $i['id'] ?>]" value="<?= $i['counted_qty'] !== null ? e(qty($i['counted_qty'])) : '' ?>" aria-label="Counted: <?= e($i['product_name']) ?> <?= e($i['batch_code']) ?>"><?php else: ?><?= $i['counted_qty'] !== null ? e(qty($i['counted_qty'])) : '—' ?><?php endif; ?></td>
                <?php if ($showSystem): ?>
                    <td class="num fw-semibold <?= $var === null ? '' : ($var < 0 ? 'text-danger' : ($var > 0 ? 'text-success' : 'text-muted')) ?>"><?= $var === null ? '—' : (($var > 0 ? '+' : '') . e(qty($var))) ?></td>
                    <?php if ($showValues): ?><td class="num small"><?= $var ? e(money($var * (float) $i['unit_cost'], false)) : '—' ?></td><?php endif; ?>
                <?php endif; ?>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
    <?php else: ?>
        <?= partial('partials/empty', ['icon' => 'bi-box', 'title' => 'No stock recorded at this location', 'text' => 'Nothing to count.']) ?>
    <?php endif; ?>
    <?php if ($canCount && $items): ?>
    <div class="card-body border-top d-flex justify-content-end gap-2">
        <button type="submit" name="action" value="save" class="btn btn-outline-secondary">Save progress</button>
        <button type="submit" name="action" value="submit" class="btn btn-primary"><i class="bi bi-send me-1" aria-hidden="true"></i>Submit count</button>
    </div>
    <?php endif; ?>
</div>
<?php if ($canCount): ?></form><?php endif; ?>
