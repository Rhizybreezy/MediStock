<?php $b = $batch; $onHand = array_sum(array_map(fn ($l) => (float) $l['quantity'], $locations)); $expired = $b['expiry_date'] && $b['expiry_date'] < date('Y-m-d'); ?>
<div class="page-header">
    <div>
        <nav aria-label="breadcrumb"><ol class="breadcrumb"><li class="breadcrumb-item"><a href="<?= e(route('inventory.batches.index')) ?>">Batches</a></li><li class="breadcrumb-item active" aria-current="page"><?= e($b['batch_code']) ?></li></ol></nav>
        <h1>Batch <?= e($b['batch_code']) ?></h1>
        <div class="subtitle"><a href="<?= e(route('products.show', ['id' => $b['product_id']])) ?>"><?= e($b['product_name']) ?></a> · Lot <?= e($b['lot_number']) ?> · <?= status_badge($b['status']) ?></div>
    </div>
    <div class="d-flex gap-2">
        <?php if (can('codes.print')): ?><a class="btn btn-outline-secondary" target="_blank" rel="noopener" href="<?= e(route('codes.labels', [], ['batches' => $b['id']])) ?>"><i class="bi bi-printer me-1" aria-hidden="true"></i>Print labels</a><?php endif; ?>
        <?php if (can('recalls.manage') && !in_array($b['status'], ['recalled'], true)): ?><a class="btn btn-outline-danger" href="<?= e(route('recalls.create', [], ['batch_id' => $b['id']])) ?>"><i class="bi bi-exclamation-octagon me-1" aria-hidden="true"></i>Recall</a><?php endif; ?>
    </div>
</div>
<?php if ($expired): ?>
    <div class="alert alert-danger"><i class="bi bi-calendar-x me-1" aria-hidden="true"></i><strong>This batch is expired.</strong> It cannot be issued or used. Handle it according to hospital disposal policy (expiry write-off is available with stock adjustments).</div>
<?php elseif (in_array($b['status'], ['recalled', 'quarantined'], true)): ?>
    <div class="alert alert-warning"><i class="bi bi-exclamation-octagon me-1" aria-hidden="true"></i>This batch is <strong><?= e($b['status']) ?></strong> and is blocked from issue.</div>
<?php endif; ?>
<div class="row g-3">
    <div class="col-lg-4">
        <div class="card"><div class="card-body">
            <dl class="dl-grid">
                <dt>Product</dt><dd><?= e($b['product_name']) ?><br><span class="small text-muted"><?= e($b['product_code']) ?></span></dd>
                <dt>Batch code</dt><dd><span class="code-chip"><?= e($b['batch_code']) ?></span></dd>
                <dt>Lot number</dt><dd><?= e($b['lot_number']) ?></dd>
                <dt>Expiry</dt><dd><?= expiry_badge($b['expiry_date']) ?></dd>
                <dt>Manufactured</dt><dd><?= e(format_date($b['manufacture_date'])) ?></dd>
                <dt>Supplier</dt><dd><?= e($b['supplier_name'] ?? '—') ?></dd>
                <dt>Received</dt><dd><?= e(format_date($b['received_at'], true)) ?><?= $b['created_by_name'] ? '<br><span class="small text-muted">by ' . e($b['created_by_name']) . '</span>' : '' ?></dd>
                <dt>Qty received</dt><dd><?= e(qty($b['quantity_received'])) ?> <?= e($b['unit_code']) ?></dd>
                <dt>On hand</dt><dd class="fw-semibold"><?= e(qty($onHand)) ?> <?= e($b['unit_code']) ?></dd>
                <dt>Batch label</dt><dd><img src="<?= e(route('codes.batch', ['id' => $b['id']])) ?>" alt="QR code MSB:<?= e($b['batch_code']) ?>" width="96" height="96" style="background:#fff;border-radius:4px"><br><code class="small">MSB:<?= e($b['batch_code']) ?></code></dd>
                <?php if ($showValues): ?><dt>Unit cost</dt><dd><?= e(number_format((float) $b['unit_cost'], 4)) ?> <?= e(setting('currency_code', 'USD')) ?></dd><?php endif; ?>
            </dl>
        </div></div>
    </div>
    <div class="col-lg-8">
        <div class="card mb-3">
            <div class="card-header"><h2 class="card-title">Current locations</h2></div>
            <?php if ($locations): ?>
            <div class="table-responsive"><table class="table table-hover">
                <thead><tr><th scope="col">Location</th><th scope="col">Department</th><th scope="col" class="num">Quantity</th><th scope="col">Last change</th></tr></thead>
                <tbody>
                <?php foreach ($locations as $l): ?>
                    <tr><td><?= e($l['location_name']) ?><?= $l['type'] === 'transit' ? ' <span class="badge text-bg-warning">In transit</span>' : '' ?></td><td class="small"><?= e($l['department_name']) ?></td><td class="num"><?= e(qty($l['quantity'])) ?></td><td class="small"><?= e(time_ago($l['updated_at'])) ?></td></tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
            <?php else: ?>
                <?= partial('partials/empty', ['icon' => 'bi-box', 'title' => 'No stock left', 'text' => 'This batch has no remaining quantity in locations you can see.']) ?>
            <?php endif; ?>
        </div>
        <?php if ($movements): ?>
        <div class="card">
            <div class="card-header"><h2 class="card-title">Traceability</h2><span class="small text-muted">Every movement of this batch</span></div>
            <div class="table-responsive"><table class="table table-sm table-hover">
                <thead><tr><th scope="col">When</th><th scope="col">Type</th><th scope="col" class="num">Qty</th><th scope="col">From → To</th><th scope="col">Reference</th><th scope="col">By</th></tr></thead>
                <tbody>
                <?php foreach ($movements as $m): ?>
                    <tr>
                        <td class="small text-nowrap"><?= e(format_date($m['created_at'], true)) ?></td>
                        <td><?= movement_badge($m['movement_type']) ?></td>
                        <td class="num"><?= e(qty($m['quantity'])) ?></td>
                        <td class="small"><?= e($m['from_location'] ?? 'Outside') ?> → <?= e($m['to_location'] ?? 'Outside') ?></td>
                        <td class="small"><span class="code-chip"><?= e($m['txn_number']) ?></span></td>
                        <td class="small"><?= e($m['user_name']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
        </div>
        <?php endif; ?>
    </div>
</div>
