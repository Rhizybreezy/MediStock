<?php $s = $result['summary']; $fmt = fn ($n) => rtrim(rtrim(number_format((float) $n, 1), '0'), '.'); ?>
<div class="page-header">
    <div>
        <nav aria-label="breadcrumb"><ol class="breadcrumb"><li class="breadcrumb-item">Intelligence</li><li class="breadcrumb-item active" aria-current="page">Expiry risk</li></ol></nav>
        <h1>Expiry risk</h1>
        <div class="subtitle">Each location's batches are simulated first-expiry-first-out against that location's recent usage. Quantities “at risk” are <strong>estimates</strong> of what may expire unused if usage stays the same.</div>
    </div>
    <form method="get" class="d-flex gap-2">
        <select name="days" class="form-select" data-auto-submit aria-label="Horizon"><?php foreach ([30, 90, 180, 365] as $d): ?><option value="<?= $d ?>"<?= $horizon === $d ? ' selected' : '' ?>>Expiring within <?= $d ?> days</option><?php endforeach; ?></select>
        <select name="risk" class="form-select" data-auto-submit aria-label="Show"><option value="at_risk"<?= $risk === 'at_risk' ? ' selected' : '' ?>>Only at-risk batches</option><option value="all"<?= $risk === 'all' ? ' selected' : '' ?>>All expiring batches</option></select>
    </form>
</div>
<div class="row g-3 mb-3">
    <?php if ($showValues): ?>
    <div class="col-6 col-lg-3"><div class="card stat-card"><div class="stat-icon tone-danger"><i class="bi bi-hourglass-bottom" aria-hidden="true"></i></div><div><div class="stat-label">At risk ≤ 30 days</div><div class="stat-value"><?= e(money($s['risk_30'], false)) ?></div><div class="stat-meta">estimated value</div></div></div></div>
    <div class="col-6 col-lg-3"><div class="card stat-card"><div class="stat-icon tone-warning"><i class="bi bi-hourglass-split" aria-hidden="true"></i></div><div><div class="stat-label">At risk ≤ 90 days</div><div class="stat-value"><?= e(money($s['risk_90'], false)) ?></div><div class="stat-meta">estimated value</div></div></div></div>
    <div class="col-6 col-lg-3"><div class="card stat-card"><div class="stat-icon tone-info"><i class="bi bi-hourglass" aria-hidden="true"></i></div><div><div class="stat-label">At risk ≤ 180 days</div><div class="stat-value"><?= e(money($s['risk_180'], false)) ?></div><div class="stat-meta"><?= (int) $s['batches_at_risk'] ?> batch-location(s)</div></div></div></div>
    <div class="col-6 col-lg-3"><div class="card stat-card"><div class="stat-icon tone-danger"><i class="bi bi-calendar-x" aria-hidden="true"></i></div><div><div class="stat-label">Already expired</div><div class="stat-value"><?= e(money($s['expired_value'], false)) ?></div><div class="stat-meta"><?= (int) $s['expired_batches'] ?> batch-location(s)</div></div></div></div>
    <?php else: ?>
    <div class="col-6 col-lg-3"><div class="card stat-card"><div><div class="stat-label">Batches at risk</div><div class="stat-value"><?= (int) $s['batches_at_risk'] ?></div></div></div></div>
    <div class="col-6 col-lg-3"><div class="card stat-card"><div><div class="stat-label">Expired batches with stock</div><div class="stat-value"><?= (int) $s['expired_batches'] ?></div></div></div></div>
    <?php endif; ?>
</div>
<?php if ($result['expired']): ?>
<div class="card mb-3 border-danger-subtle">
    <div class="card-header"><h2 class="card-title text-danger"><i class="bi bi-calendar-x me-1" aria-hidden="true"></i>Expired stock — do not use</h2><?php if (can('adjustments.request')): ?><a class="btn btn-sm btn-outline-danger" href="<?= e(route('adjustments.create')) ?>">Record disposal</a><?php endif; ?></div>
    <div class="table-responsive"><table class="table table-sm table-hover">
        <thead><tr><th scope="col">Product</th><th scope="col">Batch</th><th scope="col">Location</th><th scope="col">Expired</th><th scope="col" class="num">Quantity</th><?php if ($showValues): ?><th scope="col" class="num">Value</th><?php endif; ?></tr></thead>
        <tbody><?php foreach ($result['expired'] as $e): ?><tr><td><?= e($e['product_name']) ?></td><td><a class="code-chip" href="<?= e(route('inventory.batches.show', ['id' => $e['batch_id']])) ?>"><?= e($e['batch_code']) ?></a></td><td class="small"><?= e($e['location_name']) ?><?= $e['location_type'] === 'quarantine' ? ' <span class="badge text-bg-warning">Quarantined</span>' : '' ?><span class="cell-sub"><?= e($e['department_name']) ?></span></td><td class="small text-danger"><?= e(format_date($e['expiry_date'])) ?> (<?= (int) $e['days_expired'] ?> d ago)</td><td class="num"><?= e(qty($e['quantity'])) ?> <?= e($e['unit_code']) ?></td><?php if ($showValues): ?><td class="num"><?= e(money($e['value'], false)) ?></td><?php endif; ?></tr><?php endforeach; ?></tbody>
    </table></div>
</div>
<?php endif; ?>
<div class="card">
    <div class="card-header"><h2 class="card-title">Batches expiring within <?= (int) $horizon ?> days</h2></div>
    <?php if ($result['rows']): ?>
    <div class="table-responsive"><table class="table table-hover">
        <thead><tr><th scope="col">Product / batch</th><th scope="col">Location</th><th scope="col">Expiry</th><th scope="col" class="num">On hand</th><th scope="col" class="num">Use / day</th><th scope="col" class="num">Expected use</th><th scope="col" class="num">At risk</th><?php if ($showValues): ?><th scope="col" class="num">Value at risk</th><?php endif; ?><th scope="col">Suggestion (for review)</th></tr></thead>
        <tbody>
        <?php foreach ($result['rows'] as $r): ?>
            <tr>
                <td><?= e($r['product_name']) ?><span class="cell-sub"><a href="<?= e(route('inventory.batches.show', ['id' => $r['batch_id']])) ?>"><?= e($r['batch_code']) ?></a> · lot <?= e($r['lot_number']) ?></span></td>
                <td class="small"><?= e($r['location_name']) ?><span class="cell-sub"><?= e($r['department_name']) ?></span></td>
                <td class="small"><?= expiry_badge($r['expiry_date']) ?></td>
                <td class="num"><?= e(qty($r['quantity'])) ?></td>
                <td class="num small" title="<?= e($r['assumption']) ?>"><?= e($fmt($r['rate'])) ?></td>
                <td class="num small"><?= e(qty(round($r['expected_use']))) ?></td>
                <td class="num fw-semibold <?= $r['risk'] === 'high' ? 'text-danger' : ($r['risk'] === 'medium' ? 'text-warning-emphasis' : 'text-success') ?>"><?= e(qty(round($r['at_risk']))) ?></td>
                <?php if ($showValues): ?><td class="num"><?= e(money($r['value_at_risk'], false)) ?></td><?php endif; ?>
                <td class="small"><?= e($r['suggestion']) ?><span class="cell-sub"><?= e($r['assumption']) ?></span></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
    <?php else: ?>
        <?= partial('partials/empty', ['icon' => 'bi-check2-circle', 'title' => 'No expiry risk in this window', 'text' => 'Batches in this window are expected to be used before they expire.']) ?>
    <?php endif; ?>
</div>
