<?php $icons = ['critical' => 'bi-exclamation-octagon-fill text-danger', 'warning' => 'bi-exclamation-triangle-fill text-warning', 'info' => 'bi-info-circle-fill text-info'];
$types = ['consumption_spike' => 'Consumption spike', 'repeated_losses' => 'Repeated losses', 'frequent_adjustments' => 'Frequent adjustments', 'count_discrepancy' => 'Count discrepancy', 'transit_overdue' => 'Stuck in transit', 'loss_surge' => 'Loss surge']; ?>
<div class="page-header">
    <div>
        <nav aria-label="breadcrumb"><ol class="breadcrumb"><li class="breadcrumb-item">Intelligence</li><li class="breadcrumb-item active" aria-current="page">Anomalies</li></ol></nav>
        <h1>Anomalies &amp; discrepancies</h1>
        <div class="subtitle">Unusual patterns worth reviewing — they are not proof of error or misconduct. Sensitivity: mean + <?= e(qty(setting('anomaly_sigma', 3))) ?>σ (System settings).</div>
    </div>
</div>
<div class="card">
    <?php if ($anomalies): ?>
    <div class="list-group list-group-flush list-compact">
        <?php foreach ($anomalies as $a): ?>
        <a href="<?= e(url($a['link'])) ?>" class="list-group-item list-group-item-action d-flex gap-3 align-items-start">
            <i class="bi <?= e($icons[$a['severity']]) ?> fs-5 mt-1" aria-hidden="true"></i>
            <span class="flex-grow-1 min-w-0">
                <span class="d-flex justify-content-between gap-2 flex-wrap"><span class="fw-semibold"><?= e($a['title']) ?></span><span class="small text-muted"><?= e($types[$a['type']] ?? $a['type']) ?> · <?= e(format_date($a['date'])) ?></span></span>
                <span class="small d-block"><?= e($a['detail']) ?></span>
            </span>
        </a>
        <?php endforeach; ?>
    </div>
    <?php else: ?>
        <?= partial('partials/empty', ['icon' => 'bi-check2-circle', 'title' => 'Nothing unusual detected', 'text' => 'Spikes, repeated losses, count discrepancies and stuck deliveries appear here.']) ?>
    <?php endif; ?>
</div>
