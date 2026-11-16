<div class="page-header">
    <div>
        <nav aria-label="breadcrumb"><ol class="breadcrumb"><li class="breadcrumb-item">Departments</li><li class="breadcrumb-item active" aria-current="page">Department stock</li></ol></nav>
        <h1>Department stock</h1>
        <div class="subtitle">Holdings, items below department reorder levels, open requests and incoming deliveries per department.</div>
    </div>
</div>
<div class="row g-3">
<?php foreach ($summaries as $d): ?>
    <div class="col-md-6 col-xl-4">
        <a class="stat-link" href="<?= e(route('departments.show', ['id' => $d['id']])) ?>">
        <div class="card h-100"><div class="card-body">
            <div class="d-flex justify-content-between align-items-start mb-2">
                <div><div class="fw-semibold"><?= e($d['name']) ?></div><div class="small text-muted"><?= e(label($d['type'])) ?></div></div>
                <?php if ((int) $d['is_central_store'] === 1): ?><span class="badge text-bg-primary">Central</span><?php endif; ?>
            </div>
            <div class="row g-2 small">
                <div class="col-6"><div class="text-muted">Products held</div><div class="fs-5 fw-semibold"><?= (int) $d['products_held'] ?></div></div>
                <div class="col-6"><div class="text-muted">Below reorder</div><div class="fs-5 fw-semibold <?= (int) $d['below_reorder'] > 0 ? 'text-warning-emphasis' : '' ?>"><?= (int) $d['below_reorder'] ?></div></div>
                <div class="col-6"><div class="text-muted">Open requests</div><div class="fw-semibold"><?= (int) $d['open_requests'] ?></div></div>
                <div class="col-6"><div class="text-muted">Incoming</div><div class="fw-semibold"><?= (int) $d['incoming'] ?></div></div>
                <?php if ($showValues): ?>
                <div class="col-6"><div class="text-muted">Stock value</div><div class="fw-semibold"><?= e(money($d['value'], false)) ?></div></div>
                <div class="col-6"><div class="text-muted">Used (30 days)</div><div class="fw-semibold"><?= e(money($d['consumption_30d'], false)) ?></div></div>
                <?php endif; ?>
            </div>
        </div></div>
        </a>
    </div>
<?php endforeach; ?>
<?php if (!$summaries): ?><div class="col-12"><div class="card"><?= partial('partials/empty', ['icon' => 'bi-building', 'title' => 'No departments available']) ?></div></div><?php endif; ?>
</div>
