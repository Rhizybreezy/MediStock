<?php
/**
 * Stock-out risks and expiry risk side by side.
 * @var array $insights   ['stockout' => list, 'expiry' => ?array, 'dept' => ?int]
 * @var bool  $showValues
 */
?>
<?php if (!empty($insights['expiry']) || !empty($insights['stockout'])): $ex = $insights['expiry']; ?>
<div class="row g-3 mb-3">
    <div class="col-xl-7">
        <div class="card h-100">
            <div class="card-header">
                <h3 class="card-title"><i class="bi bi-graph-down-arrow me-1 text-danger" aria-hidden="true"></i>Stock-out risks</h3>
                <a class="small" href="<?= e(route('intelligence.stockout', [], array_filter(['department_id' => $insights['dept'] ?? null, 'urgency' => 'action']))) ?>">Full forecast <i class="bi bi-arrow-right" aria-hidden="true"></i></a>
            </div>
            <?php if ($insights['stockout']): ?>
            <div class="table-responsive"><table class="table table-sm table-hover mb-0">
                <thead><tr><th scope="col">Product</th><th scope="col" class="num">Usable</th><th scope="col" class="num">Days left</th><th scope="col">Status</th></tr></thead>
                <tbody><?php foreach ($insights['stockout'] as $r): ?><tr>
                    <td><a href="<?= e(route('intelligence.stockout.product', ['id' => $r['product_id']], array_filter(['department_id' => $insights['dept'] ?? null]))) ?>"><?= e($r['name']) ?></a></td>
                    <td class="num"><?= e(qty($r['usable'])) ?> <span class="small text-muted"><?= e($r['unit_code']) ?></span></td>
                    <td class="num fw-semibold <?= $r['urgency'] === 'critical' ? 'text-danger' : '' ?>"><?= $r['days_remaining'] !== null ? e(number_format($r['days_remaining'], 1)) : '—' ?></td>
                    <td><?= $r['urgency'] === 'critical' ? '<span class="badge text-bg-danger badge-status">Critical</span>' : '<span class="badge text-bg-warning badge-status">Reorder now</span>' ?></td>
                </tr><?php endforeach; ?></tbody>
            </table></div>
            <div class="card-body py-2 small text-muted border-top">Estimates based on recent usage; see each product for assumptions.</div>
            <?php else: ?>
                <?= partial('partials/empty', ['icon' => 'bi-check2-circle', 'title' => 'No stock-out risks', 'text' => 'No product is projected to run out before it can be replenished.']) ?>
            <?php endif; ?>
        </div>
    </div>
    <div class="col-xl-5">
        <div class="card h-100">
            <div class="card-header">
                <h3 class="card-title"><i class="bi bi-hourglass-split me-1 text-warning" aria-hidden="true"></i>Expiry risk (180 days)</h3>
                <a class="small" href="<?= e(route('intelligence.expiry')) ?>">Details <i class="bi bi-arrow-right" aria-hidden="true"></i></a>
            </div>
            <div class="card-body">
                <?php if ($ex): $sm = $ex['summary']; ?>
                <div class="row g-2 mb-3 text-center">
                    <div class="col-4"><div class="small text-muted">≤ 30 days</div><div class="fw-bold text-danger"><?= $showValues ? e(money($sm['risk_30'], false)) : '—' ?></div></div>
                    <div class="col-4"><div class="small text-muted">≤ 90 days</div><div class="fw-bold text-warning-emphasis"><?= $showValues ? e(money($sm['risk_90'], false)) : '—' ?></div></div>
                    <div class="col-4"><div class="small text-muted">Expired</div><div class="fw-bold text-danger"><?= $showValues ? e(money($sm['expired_value'], false)) : (int) $sm['expired_batches'] ?></div></div>
                </div>
                <?php $top = array_slice(array_values(array_filter($ex['rows'], fn ($r) => $r['at_risk'] > 0)), 0, 4); ?>
                <?php if ($top): ?><ul class="list-unstyled small mb-0">
                    <?php foreach ($top as $r): ?><li class="d-flex justify-content-between border-bottom py-1"><span><?= e($r['product_name']) ?> <span class="text-muted">· <?= e($r['location_name']) ?></span></span><span class="text-nowrap"><strong><?= e(qty(round($r['at_risk']))) ?></strong> in <?= (int) $r['days_to_expiry'] ?> d</span></li><?php endforeach; ?>
                </ul><?php else: ?><p class="small text-muted mb-0">No stock is expected to expire unused in the next 180 days.</p><?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>
