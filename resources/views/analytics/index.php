<?php $k = $kpi; $m = fn ($v) => $showValues ? money($v, false) : '—'; $pct = fn ($v) => $v === null ? '—' : number_format($v * 100, 1) . '%'; ?>
<div class="page-header">
    <div>
        <nav aria-label="breadcrumb"><ol class="breadcrumb"><li class="breadcrumb-item">Analytics</li><li class="breadcrumb-item active" aria-current="page">Overview</li></ol></nav>
        <h1>Analytics overview</h1>
        <div class="subtitle"><?= e(format_date($f['from'])) ?> – <?= e(format_date($f['to'])) ?> (<?= (int) $k['days'] ?> days)<?= $showValues ? ' · values in ' . e(setting('currency_code', 'USD')) : '' ?></div>
    </div>
</div>
<div class="card mb-3"><?= partial('analytics/_filters', ['f' => $f, 'departments' => $departments]) ?></div>
<div class="row g-3 mb-3">
    <div class="col-6 col-xl-3"><div class="card stat-card"><div class="stat-icon tone-primary"><i class="bi bi-cash-stack" aria-hidden="true"></i></div><div><div class="stat-label">Inventory value now</div><div class="stat-value"><?= e($m($k['value'])) ?></div><div class="stat-meta"><?= $k['turnover_days'] !== null ? '≈ ' . e(number_format($k['turnover_days'], 0)) . ' days of use on hand' : '&nbsp;' ?></div></div></div></div>
    <div class="col-6 col-xl-3"><div class="card stat-card"><div class="stat-icon tone-info"><i class="bi bi-clipboard-pulse" aria-hidden="true"></i></div><div><div class="stat-label">Consumption</div><div class="stat-value"><?= e($m($k['used'])) ?></div><div class="stat-meta <?= ($k['used_change'] ?? 0) > 0.15 ? 'text-warning-emphasis' : '' ?>"><?= $k['used_change'] !== null ? (($k['used_change'] > 0 ? '+' : '') . e(number_format($k['used_change'] * 100, 1)) . '% vs previous period') : 'no previous data' ?></div></div></div></div>
    <div class="col-6 col-xl-3"><div class="card stat-card"><div class="stat-icon tone-danger"><i class="bi bi-trash3" aria-hidden="true"></i></div><div><div class="stat-label">Wastage</div><div class="stat-value"><?= e($m($k['lost'])) ?></div><div class="stat-meta"><?= e($pct($k['loss_rate'])) ?> of stock used or lost</div></div></div></div>
    <div class="col-6 col-xl-3"><div class="card stat-card"><div class="stat-icon tone-success"><i class="bi bi-receipt" aria-hidden="true"></i></div><div><div class="stat-label">Purchases received</div><div class="stat-value"><?= $k['spend'] !== null ? e($m($k['spend'])) : '—' ?></div><div class="stat-meta"><?= $k['spend'] !== null ? 'PO and direct purchases' : 'hospital-wide only' ?></div></div></div></div>
    <div class="col-6 col-xl-3"><a class="stat-link" href="<?= e(route('intelligence.stockout', [], ['urgency' => 'critical'])) ?>"><div class="card stat-card"><div class="stat-icon tone-danger"><i class="bi bi-exclamation-octagon" aria-hidden="true"></i></div><div><div class="stat-label">Critical stock-out risks</div><div class="stat-value"><?= (int) $k['critical'] ?></div><div class="stat-meta"><?= (int) $k['reorder'] ?> more to reorder now (estimates)</div></div></div></a></div>
    <div class="col-6 col-xl-3"><a class="stat-link" href="<?= e(route('intelligence.expiry', [], ['days' => 90])) ?>"><div class="card stat-card"><div class="stat-icon tone-warning"><i class="bi bi-hourglass-split" aria-hidden="true"></i></div><div><div class="stat-label">Expiry risk (90 days)</div><div class="stat-value"><?= e($m($k['expiry_risk'])) ?></div><div class="stat-meta">estimated value likely to expire unused</div></div></div></a></div>
    <div class="col-6 col-xl-3"><div class="card stat-card"><div class="stat-icon tone-danger"><i class="bi bi-calendar-x" aria-hidden="true"></i></div><div><div class="stat-label">Expired stock held</div><div class="stat-value"><?= e($m($k['expired_value'])) ?></div><div class="stat-meta">awaiting disposal</div></div></div></div>
    <div class="col-6 col-xl-3"><div class="card stat-card"><div class="stat-icon tone-neutral"><i class="bi bi-calendar-range" aria-hidden="true"></i></div><div><div class="stat-label">Previous period use</div><div class="stat-value"><?= e($m($k['used_prev'])) ?></div><div class="stat-meta">same length before <?= e(format_date($f['from'])) ?></div></div></div></div>
</div>
<div class="row g-3 mb-3">
    <div class="col-xl-8"><div class="card h-100"><div class="card-header"><h2 class="card-title">Monthly consumption and wastage</h2></div><div class="card-body">
        <div class="chart-box" data-analytics-chart="trend"><canvas role="img" aria-label="Monthly consumption and wastage"></canvas></div>
        <script type="application/json" data-chart-for="trend"><?= json_encode(['type' => 'bar', 'labels' => array_column($k['trend'], 'ym'), 'series' => [
            ['label' => 'Consumption', 'data' => array_map('floatval', array_column($k['trend'], 'used'))], ['label' => 'Wastage', 'data' => array_map('floatval', array_column($k['trend'], 'lost'))]]], JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
    </div></div></div>
    <div class="col-xl-4"><div class="card h-100"><div class="card-header"><h2 class="card-title">Reports</h2></div>
        <div class="list-group list-group-flush">
            <?php foreach ($reports as $key => [$label, $icon]): ?>
                <a class="list-group-item list-group-item-action d-flex align-items-center gap-2" href="<?= e(route('analytics.report', ['key' => $key], array_filter(['from' => $f['from'], 'to' => $f['to'], 'department_id' => $f['department_id']]))) ?>"><i class="bi <?= e($icon) ?> text-muted" aria-hidden="true"></i><?= e($label) ?><i class="bi bi-chevron-right ms-auto small text-muted" aria-hidden="true"></i></a>
            <?php endforeach; ?>
        </div>
    </div></div>
</div>
<?php if (!$showValues): ?><p class="small text-muted"><i class="bi bi-lock me-1" aria-hidden="true"></i>Monetary values require the “View inventory valuation” permission.</p><?php endif; ?>
