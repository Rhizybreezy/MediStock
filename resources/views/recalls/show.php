<?php $r = $trace['recall']; $sev = ['low' => 'secondary', 'medium' => 'info', 'high' => 'warning', 'critical' => 'danger'];
$outside = array_filter($trace['holding'], fn ($h) => !in_array($h['type'], ['quarantine', 'transit'], true)); ?>
<div class="page-header">
    <div>
        <nav aria-label="breadcrumb"><ol class="breadcrumb"><li class="breadcrumb-item"><a href="<?= e(route('recalls.index')) ?>">Recalls</a></li><li class="breadcrumb-item active" aria-current="page"><?= e($r['recall_number']) ?></li></ol></nav>
        <h1><?= e($r['recall_number']) ?>: <?= e($r['product_name']) ?></h1>
        <div class="subtitle">Batch <span class="code-chip"><?= e($r['batch_code']) ?></span> · lot <?= e($r['lot_number']) ?> · <span class="badge text-bg-<?= $sev[$r['severity']] ?>"><?= e(label($r['severity'])) ?></span> · <?= e(label($r['status'])) ?></div>
    </div>
    <?php if (can('recalls.manage') && $r['status'] !== 'closed' && $outside): ?>
        <form method="post" action="<?= e(route('recalls.quarantine', ['id' => $r['id']])) ?>" data-confirm="Move all remaining stock of this batch (in locations you manage) into quarantine?" data-confirm-button="Quarantine stock" data-confirm-variant="danger"><?= csrf_field() ?><button type="submit" class="btn btn-danger"><i class="bi bi-shield-lock me-1" aria-hidden="true"></i>Quarantine all stock</button></form>
    <?php endif; ?>
</div>
<div class="alert alert-danger"><i class="bi bi-exclamation-octagon-fill me-1" aria-hidden="true"></i><strong>This batch is blocked.</strong> It cannot be issued, transferred or consumed. Reason: <?= e($r['reason']) ?></div>
<div class="row g-3">
    <div class="col-lg-6">
        <div class="card mb-3">
            <div class="card-header"><h2 class="card-title">Where the batch is now</h2></div>
            <?php if ($trace['holding']): ?>
            <div class="table-responsive"><table class="table table-hover">
                <thead><tr><th scope="col">Location</th><th scope="col">Department</th><th scope="col" class="num">Quantity</th></tr></thead>
                <tbody>
                <?php foreach ($trace['holding'] as $h): ?>
                    <tr><td><?= e($h['location_name']) ?> <?= $h['type'] === 'quarantine' ? '<span class="badge text-bg-warning">Quarantined</span>' : ($h['type'] === 'transit' ? '<span class="badge text-bg-info">In transit</span>' : '<span class="badge text-bg-danger">Action needed</span>') ?></td><td class="small"><?= e($h['department_name']) ?></td><td class="num"><?= e(qty($h['quantity'])) ?> <?= e($r['unit_code']) ?></td></tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
            <?php else: ?>
                <?= partial('partials/empty', ['icon' => 'bi-check2-circle', 'title' => 'No stock remaining', 'text' => 'All stock of this batch has been used, disposed of or returned.']) ?>
            <?php endif; ?>
        </div>
        <?php if (can('recalls.manage') && $r['status'] !== 'closed'): ?>
        <div class="card"><div class="card-body">
            <h2 class="card-title mb-1">Close recall</h2>
            <p class="small text-muted">After quarantined stock has been returned to the supplier or written off (Adjustments → Write-off).</p>
            <form method="post" action="<?= e(route('recalls.close', ['id' => $r['id']])) ?>">
                <?= csrf_field() ?>
                <textarea class="form-control mb-2" name="closure_notes" rows="2" required minlength="5" maxlength="2000" placeholder="How was the recall resolved?" aria-label="Closure notes"></textarea>
                <button type="submit" class="btn btn-outline-secondary">Close recall</button>
            </form>
        </div></div>
        <?php elseif ($r['status'] === 'closed'): ?>
            <div class="card"><div class="card-body"><dl class="dl-grid"><dt>Closed</dt><dd><?= e(format_date($r['closed_at'], true)) ?> by <?= e($r['closed_by_name']) ?></dd><dt>Resolution</dt><dd><?= e($r['closure_notes']) ?></dd></dl></div></div>
        <?php endif; ?>
    </div>
    <div class="col-lg-6">
        <div class="card">
            <div class="card-header"><h2 class="card-title">Distribution history</h2><span class="small text-muted">Every location that received this batch</span></div>
            <?php if ($trace['received']): ?>
            <div class="table-responsive"><table class="table table-hover">
                <thead><tr><th scope="col">Location</th><th scope="col" class="num">Received</th><th scope="col" class="num">Consumed</th><th scope="col">First received</th></tr></thead>
                <tbody>
                <?php foreach ($trace['received'] as $d): ?>
                    <tr><td><?= e($d['location_name']) ?><span class="cell-sub"><?= e($d['department_name']) ?></span></td><td class="num"><?= e(qty($d['received_qty'])) ?></td><td class="num <?= (float) $d['consumed_qty'] > 0 ? 'text-danger fw-semibold' : '' ?>"><?= e(qty($d['consumed_qty'])) ?></td><td class="small"><?= e(format_date($d['first_received'], true)) ?></td></tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
            <div class="card-body border-top small text-muted"><i class="bi bi-info-circle me-1" aria-hidden="true"></i>Consumed quantities may already have been used for patients. Follow clinical recall procedures where required.</div>
            <?php else: ?>
                <?= partial('partials/empty', ['icon' => 'bi-diagram-3', 'title' => 'No distribution recorded']) ?>
            <?php endif; ?>
        </div>
    </div>
</div>
