<?php /** @var App\Core\Paginator $page */ ?>
<div class="page-header">
    <div>
        <nav aria-label="breadcrumb"><ol class="breadcrumb"><li class="breadcrumb-item">Intelligence</li><li class="breadcrumb-item active" aria-current="page">Cold chain</li></ol></nav>
        <h1>Cold-chain monitoring</h1>
        <div class="subtitle">Temperature readings for refrigerators, freezers and cold rooms. Out-of-range readings require a corrective action and alert responsible staff.</div>
    </div>
</div>
<div class="row g-3 mb-3">
    <?php foreach ($locations as $l): $stale = !$l['last_at'] || strtotime($l['last_at']) < time() - 86400; ?>
    <div class="col-md-6 col-xl-3">
        <a class="stat-link" href="<?= e(route('cold_chain.index', [], ['location_id' => $l['id']])) ?>">
        <div class="card h-100 <?= (int) ($chartLoc['id'] ?? 0) === (int) $l['id'] ? 'border-primary' : '' ?>"><div class="card-body">
            <div class="d-flex justify-content-between"><div class="fw-semibold"><?= e($l['name']) ?></div><i class="bi bi-thermometer-snow text-info" aria-hidden="true"></i></div>
            <div class="small text-muted mb-2"><?= e($l['department_name']) ?> · range <?= e(qty($l['min_temp_c'])) ?>–<?= e(qty($l['max_temp_c'])) ?> °C</div>
            <div class="fs-3 fw-bold <?= (int) $l['last_out'] ? 'text-danger' : '' ?>"><?= $l['last_temp'] !== null ? e(number_format((float) $l['last_temp'], 1)) . ' °C' : '—' ?></div>
            <div class="small <?= $stale ? 'text-warning-emphasis' : 'text-muted' ?>"><?= $l['last_at'] ? 'Last reading ' . e(time_ago($l['last_at'])) : 'No readings yet' ?><?= $stale && $l['last_at'] ? ' — overdue' : '' ?></div>
            <div class="small mt-1"><?= (int) $l['excursions_30d'] ?> excursion(s) in 30 days · <?= (int) $l['batches_held'] ?> batch(es) stored</div>
        </div></div>
        </a>
    </div>
    <?php endforeach; ?>
    <?php if (!$locations): ?><div class="col-12"><div class="card"><?= partial('partials/empty', ['icon' => 'bi-thermometer', 'title' => 'No cold-chain locations', 'text' => 'Mark refrigerators and freezers as cold chain in Admin → Storage locations.']) ?></div></div><?php endif; ?>
</div>
<?php if ($locations): ?>
<div class="row g-3 mb-3">
    <?php if ($canRecord): ?>
    <div class="col-xl-4">
        <div class="card h-100"><div class="card-header"><h2 class="card-title">Record a reading</h2></div><div class="card-body">
            <form method="post" action="<?= e(route('cold_chain.store')) ?>" novalidate>
                <?= csrf_field() ?>
                <div class="mb-2"><label class="form-label required" for="location_id">Location</label><select class="form-select" id="location_id" name="location_id"><?php foreach ($locations as $l): ?><option value="<?= (int) $l['id'] ?>" data-min="<?= e((string) $l['min_temp_c']) ?>" data-max="<?= e((string) $l['max_temp_c']) ?>"<?= (int) ($chartLoc['id'] ?? 0) === (int) $l['id'] ? ' selected' : '' ?>><?= e($l['name']) ?></option><?php endforeach; ?></select></div>
                <div class="row g-2 mb-2">
                    <div class="col-6"><label class="form-label required" for="temperature_c">Temperature (°C)</label><input type="number" step="0.1" class="form-control" id="temperature_c" name="temperature_c" required></div>
                    <div class="col-6"><label class="form-label" for="humidity_pct">Humidity (%)</label><input type="number" step="0.1" min="0" max="100" class="form-control" id="humidity_pct" name="humidity_pct"></div>
                </div>
                <div class="mb-3" id="action-wrap"><label class="form-label" for="corrective_action">Corrective action <span class="text-muted small">(required if out of range)</span></label><input class="form-control" id="corrective_action" name="corrective_action" maxlength="255" placeholder="e.g. door closed, stock moved to backup fridge"></div>
                <div class="line-hint mb-2" id="range-hint"></div>
                <button type="submit" class="btn btn-primary w-100">Save reading</button>
            </form>
        </div></div>
    </div>
    <?php endif; ?>
    <div class="<?= $canRecord ? 'col-xl-8' : 'col-12' ?>">
        <div class="card h-100"><div class="card-header"><h2 class="card-title"><?= e($chartLoc['name'] ?? '') ?> — last 14 days</h2></div><div class="card-body">
            <div class="chart-box sm"><canvas id="chart-temp" role="img" aria-label="Temperature chart"></canvas></div>
            <script type="application/json" id="temp-data"><?= json_encode(['series' => $series, 'min' => $chartLoc['min_temp_c'] ?? null, 'max' => $chartLoc['max_temp_c'] ?? null], JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
        </div></div>
    </div>
</div>
<div class="card">
    <form class="filter-bar" method="get">
        <select name="location_id" class="form-select" data-auto-submit aria-label="Location"><option value="">All locations</option><?php foreach ($locations as $l): ?><option value="<?= (int) $l['id'] ?>"<?= (string) $filters['location_id'] === (string) $l['id'] ? ' selected' : '' ?>><?= e($l['name']) ?></option><?php endforeach; ?></select>
        <select name="range" class="form-select" data-auto-submit aria-label="Range"><option value="">All readings</option><option value="out"<?= $filters['range'] === 'out' ? ' selected' : '' ?>>Out of range only</option></select>
    </form>
    <?php if ($page->items): ?>
    <div class="table-responsive"><table class="table table-sm table-hover">
        <thead><tr><th scope="col">Recorded</th><th scope="col">Location</th><th scope="col" class="num">Temperature</th><th scope="col" class="num">Humidity</th><th scope="col">Status</th><th scope="col">Corrective action</th><th scope="col">By</th></tr></thead>
        <tbody><?php foreach ($page->items as $t): ?><tr><td class="small text-nowrap"><?= e(format_date($t['recorded_at'], true)) ?></td><td class="small"><?= e($t['location_name']) ?></td><td class="num fw-semibold <?= (int) $t['is_out_of_range'] ? 'text-danger' : '' ?>"><?= e(number_format((float) $t['temperature_c'], 1)) ?> °C</td><td class="num small"><?= $t['humidity_pct'] !== null ? e(qty($t['humidity_pct'])) . '%' : '—' ?></td><td><?= (int) $t['is_out_of_range'] ? '<span class="badge text-bg-danger">Out of range</span>' : '<span class="badge text-bg-success">OK</span>' ?></td><td class="small"><?= e($t['corrective_action'] ?? '') ?></td><td class="small"><?= e($t['recorded_by_name']) ?></td></tr><?php endforeach; ?></tbody>
    </table></div>
    <?php else: ?><?= partial('partials/empty', ['icon' => 'bi-thermometer-half', 'title' => 'No readings']) ?><?php endif; ?>
    <?= partial('partials/pagination', ['page' => $page]) ?>
</div>
<?php endif; ?>
