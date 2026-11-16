<?php
/** Small helper partial: formats a 0..1 rate or "—". */
$pct = fn ($v) => $v === null ? '—' : number_format($v * 100, 0) . '%';
?>
<div class="row g-3">
    <div class="col-6 col-lg"><div class="card stat-card"><div><div class="stat-label">Rating</div><div class="stat-value"><?= $m['rating'] !== null ? e(number_format($m['rating'], 1)) . '<span class="fs-6 text-muted"> / 5</span>' : '—' ?></div><div class="stat-meta"><?= (int) $m['deliveries'] ?> PO(s) delivered</div></div></div></div>
    <div class="col-6 col-lg"><div class="card stat-card"><div><div class="stat-label">On time</div><div class="stat-value"><?= e($pct($m['on_time_rate'])) ?></div><div class="stat-meta">first delivery by expected date</div></div></div></div>
    <div class="col-6 col-lg"><div class="card stat-card"><div><div class="stat-label">Avg lead time</div><div class="stat-value"><?= $m['avg_lead_days'] !== null ? e(number_format($m['avg_lead_days'], 1)) . ' d' : '—' ?></div><div class="stat-meta">quoted <?= (int) $m['default_lead_time_days'] ?> d</div></div></div></div>
    <div class="col-6 col-lg"><div class="card stat-card"><div><div class="stat-label">Fill rate</div><div class="stat-value"><?= e($pct($m['fill_rate'])) ?></div><div class="stat-meta">accepted ÷ ordered</div></div></div></div>
    <div class="col-6 col-lg"><div class="card stat-card"><div><div class="stat-label">Rejected at receipt</div><div class="stat-value"><?= e($pct($m['rejection_rate'])) ?></div><div class="stat-meta"><?= (int) $m['late_open_pos'] ?> open PO(s) overdue</div></div></div></div>
</div>
