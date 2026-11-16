<div class="page-header">
    <div>
        <nav aria-label="breadcrumb"><ol class="breadcrumb"><li class="breadcrumb-item">Inventory</li><li class="breadcrumb-item active" aria-current="page">Reconciliation</li></ol></nav>
        <h1>Stock reconciliation</h1>
        <div class="subtitle">Checks that every stored balance equals the sum of its ledger movements. Runs nightly via <code>php bin/console stock:reconcile</code>.</div>
    </div>
    <form method="post" action="<?= e(route('inventory.reconciliation.run')) ?>">
        <?= csrf_field() ?>
        <button type="submit" class="btn btn-primary"><i class="bi bi-play-circle me-1" aria-hidden="true"></i>Run reconciliation now</button>
    </form>
</div>
<?php if ($result === null): ?>
    <div class="card"><?= partial('partials/empty', ['icon' => 'bi-check2-square', 'title' => 'Not run yet in this session', 'text' => 'Click “Run reconciliation now” to verify balances against the ledger.']) ?></div>
<?php elseif (!$result['mismatches']): ?>
    <div class="alert alert-success"><i class="bi bi-check-circle-fill me-1" aria-hidden="true"></i><strong>All balances reconcile.</strong> <?= number_format($result['checked']) ?> location/batch balance(s) checked at <?= e(format_date($result['ran_at'], true)) ?>.</div>
<?php else: ?>
    <div class="alert alert-danger"><i class="bi bi-exclamation-octagon-fill me-1" aria-hidden="true"></i><strong><?= count($result['mismatches']) ?> mismatch(es) found</strong> out of <?= number_format($result['checked']) ?> checked. Balances were <strong>not</strong> changed automatically: investigate and correct with an approved data-correction adjustment.</div>
    <div class="card"><div class="table-responsive"><table class="table table-hover">
        <thead><tr><th scope="col">Location</th><th scope="col">Product</th><th scope="col">Batch</th><th scope="col" class="num">Ledger says</th><th scope="col" class="num">Balance says</th><th scope="col" class="num">Difference</th></tr></thead>
        <tbody>
        <?php foreach ($result['mismatches'] as $m): ?>
            <tr><td><?= e($m['location_name']) ?></td><td><?= e($m['product_name']) ?></td><td><span class="code-chip"><?= e($m['batch_code']) ?></span></td><td class="num"><?= e(qty($m['ledger_qty'])) ?></td><td class="num"><?= e(qty($m['balance_qty'])) ?></td><td class="num text-danger fw-semibold"><?= e(($m['difference'] > 0 ? '+' : '') . qty($m['difference'])) ?></td></tr>
        <?php endforeach; ?>
        </tbody>
    </table></div></div>
<?php endif; ?>
