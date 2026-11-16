<?php $pct = fn ($v) => $v === null ? '—' : number_format($v * 100, 0) . '%'; ?>
<div class="page-header">
    <div>
        <nav aria-label="breadcrumb"><ol class="breadcrumb"><li class="breadcrumb-item"><a href="<?= e(route('suppliers.index')) ?>">Suppliers</a></li><li class="breadcrumb-item active" aria-current="page">Performance</li></ol></nav>
        <h1>Supplier performance</h1>
        <div class="subtitle">Calculated from purchase orders and goods receipts. Rating = 5 × (40% on-time + 30% fill rate + 30% acceptance). Small samples are shown with their PO count.</div>
    </div>
    <form method="get"><select name="days" class="form-select" data-auto-submit aria-label="Period"><?php foreach ([90 => 'Last 90 days', 180 => 'Last 6 months', 365 => 'Last 12 months', 730 => 'Last 2 years'] as $d => $l): ?><option value="<?= $d ?>"<?= $days === $d ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></form>
</div>
<div class="card">
    <?php if ($rows): ?>
    <div class="table-responsive"><table class="table table-hover">
        <thead><tr><th scope="col">Supplier</th><th scope="col" class="num">POs</th><?php if ($showSpend): ?><th scope="col" class="num">Spend</th><?php endif; ?><th scope="col" class="num">Delivered</th><th scope="col" class="num">On time</th><th scope="col" class="num">Avg lead</th><th scope="col" class="num">Fill rate</th><th scope="col" class="num">Rejected</th><th scope="col" class="num">Overdue</th><th scope="col" class="num">Rating</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
            <tr>
                <td><a href="<?= e(route('suppliers.show', ['id' => $r['id']])) ?>" class="fw-semibold"><?= e($r['name']) ?></a><span class="cell-sub"><?= e($r['supplier_code']) ?> · quoted lead <?= (int) $r['default_lead_time_days'] ?> d</span></td>
                <td class="num"><?= (int) $r['po_count'] ?></td>
                <?php if ($showSpend): ?><td class="num"><?= e(money($r['spend'], false)) ?></td><?php endif; ?>
                <td class="num"><?= (int) $r['deliveries'] ?></td>
                <td class="num"><?= e($pct($r['on_time_rate'])) ?></td>
                <td class="num"><?= $r['avg_lead_days'] !== null ? e(number_format($r['avg_lead_days'], 1)) . ' d' : '—' ?></td>
                <td class="num"><?= e($pct($r['fill_rate'])) ?></td>
                <td class="num"><?= e($pct($r['rejection_rate'])) ?></td>
                <td class="num <?= $r['late_open_pos'] ? 'text-danger fw-semibold' : '' ?>"><?= (int) $r['late_open_pos'] ?></td>
                <td class="num fw-semibold"><?= $r['rating'] !== null ? e(number_format($r['rating'], 1)) : '—' ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
    <?php else: ?><?= partial('partials/empty', ['icon' => 'bi-speedometer2', 'title' => 'No suppliers yet']) ?><?php endif; ?>
</div>
