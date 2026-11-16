<?php include __DIR__ . '/_badges.php'; $fmt = fn ($n, $d = 1) => $n === null ? '—' : rtrim(rtrim(number_format((float) $n, $d), '0'), '.'); ?>
<div class="page-header">
    <div>
        <nav aria-label="breadcrumb"><ol class="breadcrumb"><li class="breadcrumb-item"><a href="<?= e(route('intelligence.stockout', [], array_filter(['department_id' => $dept]))) ?>">Stock-out forecast</a></li><li class="breadcrumb-item active" aria-current="page"><?= e($product['product_code']) ?></li></ol></nav>
        <h1><?= e($product['name']) ?></h1>
        <div class="subtitle"><?= $dept ? 'Department forecast' : 'Hospital-wide forecast' ?> · <a href="<?= e(route('products.show', ['id' => $product['id']])) ?>">Product details</a></div>
    </div>
</div>
<?php if (!$f): ?>
    <div class="card"><?= partial('partials/empty', ['icon' => 'bi-graph-down', 'title' => 'No forecast available', 'text' => 'This product is not stocked or used in this view.']) ?></div>
<?php else: ?>
<div class="row g-3 mb-3">
    <div class="col-6 col-lg-3"><div class="card stat-card"><div><div class="stat-label">Status</div><div class="stat-value fs-6 mt-1"><?= $urgencyBadge($f['urgency']) ?></div><div class="stat-meta">confidence: <?= e($confidenceText[$f['confidence']] ?? '—') ?></div></div></div></div>
    <div class="col-6 col-lg-3"><div class="card stat-card"><div><div class="stat-label">Usable stock</div><div class="stat-value"><?= e(qty($f['usable'])) ?></div><div class="stat-meta"><?= e($f['unit_code']) ?></div></div></div></div>
    <div class="col-6 col-lg-3"><div class="card stat-card"><div><div class="stat-label">Estimated days left</div><div class="stat-value"><?= e($fmt($f['days_remaining'])) ?></div><div class="stat-meta"><?= $f['stockout_date'] ? 'around ' . e(format_date($f['stockout_date'])) : 'no stock-out projected' ?></div></div></div></div>
    <div class="col-6 col-lg-3"><div class="card stat-card"><div><div class="stat-label">Suggested reorder</div><div class="stat-value"><?= $f['reorder_qty'] > 0 ? e(qty($f['reorder_qty'])) : '—' ?></div><div class="stat-meta">reorder point <?= e($fmt($f['reorder_point'])) ?></div></div></div></div>
</div>
<div class="row g-3">
    <div class="col-xl-7">
        <div class="card h-100"><div class="card-header"><h2 class="card-title">Weekly consumption</h2><span class="small text-muted">last 26 weeks</span></div><div class="card-body">
            <div class="chart-box" data-chart="forecast"><canvas id="chart-forecast" role="img" aria-label="Weekly consumption chart"></canvas></div>
            <script type="application/json" id="forecast-data"><?= json_encode(['weeks' => $weekly, 'adc' => $f['adc']], JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
        </div></div>
    </div>
    <div class="col-xl-5">
        <div class="card mb-3"><div class="card-header"><h2 class="card-title">How this estimate was made</h2></div><div class="card-body">
            <ul class="small mb-3 ps-3">
                <?php foreach ($f['assumptions'] as $a): ?><li class="mb-1"><?= e($a) ?></li><?php endforeach; ?>
            </ul>
            <dl class="dl-grid small">
                <dt>Use per day</dt><dd><?= e($fmt($f['adc'], 2)) ?> <?= e($f['unit_code']) ?></dd>
                <dt>Variability (σ)</dt><dd><?= e($fmt($f['sigma'], 2)) ?> per day</dd>
                <dt>History used</dt><dd><?= (int) $f['history_days'] ?> days</dd>
                <dt>Safety stock</dt><dd><?= e($fmt($f['safety_stock'])) ?></dd>
                <dt>Reorder point</dt><dd><?= e($fmt($f['reorder_point'])) ?> (use × lead time + safety stock)</dd>
                <dt>Configured levels</dt><dd>reorder <?= e(qty($f['reorder_level'])) ?> · max <?= $f['max_level'] > 0 ? e(qty($f['max_level'])) : '—' ?></dd>
            </dl>
            <p class="small text-muted mb-0"><i class="bi bi-info-circle me-1" aria-hidden="true"></i>These are projections, not guarantees. Sudden changes in demand, supplier delays or recalls will change the outcome.</p>
        </div></div>
    </div>
</div>
<?php endif; ?>
