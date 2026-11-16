<?php include __DIR__ . '/_badges.php';
$fmt = fn ($n, $d = 1) => $n === null ? '—' : rtrim(rtrim(number_format((float) $n, $d), '0'), '.');
$selectable = $canPurchase || $canRequest; ?>
<div class="page-header">
    <div>
        <nav aria-label="breadcrumb"><ol class="breadcrumb"><li class="breadcrumb-item">Intelligence</li><li class="breadcrumb-item active" aria-current="page">Stock-out forecast</li></ol></nav>
        <h1>Stock-out forecast</h1>
        <div class="subtitle"><?= $deptName ? 'Department view: ' . e($deptName) . ' (internal supply time)' : 'Hospital-wide view (supplier lead times, open purchase orders)' ?>. All figures are <strong>estimates</strong> based on recent usage — open a product to see the assumptions.</div>
    </div>
    <form method="get" class="d-flex gap-2">
        <select name="department_id" class="form-select" data-auto-submit aria-label="View">
            <?php if (app(\App\Core\Gate::class)->departmentScope('stock.view') === null): ?><option value="">Hospital-wide</option><?php endif; ?>
            <?php foreach ($departments as $d): if ((int) $d['is_central_store'] === 1) { continue; } ?><option value="<?= (int) $d['id'] ?>"<?= $dept === (int) $d['id'] ? ' selected' : '' ?>><?= e($d['name']) ?></option><?php endforeach; ?>
        </select>
    </form>
</div>
<div class="row g-3 mb-3">
    <?php foreach (['critical' => ['Critical', 'danger', 'bi-exclamation-octagon'], 'high' => ['Reorder now', 'warning', 'bi-arrow-down-circle'], 'medium' => ['Reorder soon', 'info', 'bi-clock'], 'ok' => ['Sufficient', 'success', 'bi-check-circle']] as $k => [$l, $tone, $icon]): ?>
    <div class="col-6 col-lg-3"><a class="stat-link" href="<?= e(url(request()->path(), array_filter(['urgency' => $k, 'department_id' => $dept]))) ?>"><div class="card stat-card"><div class="stat-icon tone-<?= $tone ?>"><i class="bi <?= $icon ?>" aria-hidden="true"></i></div><div><div class="stat-label"><?= e($l) ?></div><div class="stat-value"><?= (int) ($counts[$k] ?? 0) ?></div></div></div></a></div>
    <?php endforeach; ?>
</div>
<div class="card">
    <form class="filter-bar" method="get" role="search">
        <?php if ($dept): ?><input type="hidden" name="department_id" value="<?= (int) $dept ?>"><?php endif; ?>
        <input type="search" name="q" class="form-control search-input" placeholder="Product" value="<?= e($q) ?>" aria-label="Search">
        <select name="urgency" class="form-select" data-auto-submit aria-label="Urgency">
            <option value="">All products</option>
            <option value="action"<?= $urgency === 'action' ? ' selected' : '' ?>>Needs action</option>
            <?php foreach (['critical' => 'Critical', 'high' => 'Reorder now', 'medium' => 'Reorder soon', 'ok' => 'OK', 'insufficient' => 'Not enough data', 'no_demand' => 'No recent use'] as $k => $v): ?><option value="<?= $k ?>"<?= $urgency === $k ? ' selected' : '' ?>><?= e($v) ?></option><?php endforeach; ?>
        </select>
        <button type="submit" class="btn btn-outline-secondary"><i class="bi bi-search" aria-hidden="true"></i><span class="visually-hidden">Search</span></button>
    </form>
    <?php if ($rows): ?>
    <?php if ($canPurchase): ?>
    <form method="post" action="<?= e(route('purchase_requests.store')) ?>">
        <?= csrf_field() ?><input type="hidden" name="source" value="reorder_suggestion"><input type="hidden" name="department_id" value="<?= (int) $centralId ?>"><input type="hidden" name="priority" value="urgent"><input type="hidden" name="justification" value="Forecast-based reorder: items at risk of stock-out"><input type="hidden" name="action" value="draft">
    <?php elseif ($canRequest): ?>
    <form method="get" action="<?= e(route('requests.create')) ?>">
    <?php endif; ?>
    <div class="table-responsive"><table class="table table-hover">
        <thead><tr>
            <?php if ($canPurchase): ?><th scope="col"><span class="visually-hidden">Select</span></th><?php endif; ?>
            <th scope="col">Product</th><th scope="col" class="num">Usable</th><th scope="col" class="num">Use / day</th><th scope="col" class="num">Days left</th>
            <th scope="col">Runs out ≈</th><th scope="col" class="num">Lead time</th><th scope="col" class="num">On order</th><th scope="col" class="num">Suggested</th><th scope="col">Status</th><th scope="col">Confidence</th>
        </tr></thead>
        <tbody>
        <?php foreach ($rows as $i => $r): ?>
            <tr>
                <?php if ($canPurchase): ?><td><?php if ($r['reorder_qty'] > 0): ?><input class="form-check-input" type="checkbox" name="lines[<?= $i ?>][include]" value="1" <?= in_array($r['urgency'], ['critical', 'high'], true) ? 'checked' : '' ?> aria-label="Include <?= e($r['name']) ?>"><input type="hidden" name="lines[<?= $i ?>][product_id]" value="<?= (int) $r['product_id'] ?>"><input type="hidden" name="lines[<?= $i ?>][quantity]" value="<?= e((string) $r['reorder_qty']) ?>"><?php endif; ?></td><?php endif; ?>
                <td><a href="<?= e(route('intelligence.stockout.product', ['id' => $r['product_id']], array_filter(['department_id' => $dept]))) ?>" class="fw-semibold"><?= e($r['name']) ?></a><?= $r['is_critical'] ? ' <span class="badge text-bg-danger">Critical item</span>' : '' ?><span class="cell-sub"><?= e($r['product_code']) ?> · <?= e($r['unit_code']) ?><?= $r['proxy'] ? ' · rate estimated from issues' : '' ?></span></td>
                <td class="num"><?= e(qty($r['usable'])) ?></td>
                <td class="num"><?= e($fmt($r['adc'])) ?></td>
                <td class="num fw-semibold <?= $r['urgency'] === 'critical' ? 'text-danger' : '' ?>"><?= $r['days_remaining'] !== null ? e($fmt($r['days_remaining'])) : '—' ?></td>
                <td class="small"><?= $r['stockout_date'] ? e(format_date($r['stockout_date'])) : '—' ?></td>
                <td class="num small"><?= (int) $r['lead_time'] ?> d</td>
                <td class="num small"><?= $r['on_order'] > 0 ? e(qty($r['on_order'])) . ($r['po_in_time'] ? ' <i class="bi bi-check2 text-success" title="Arrives before stock-out" aria-label="arrives in time"></i>' : ' <i class="bi bi-exclamation-triangle text-warning" title="Expected after stock-out" aria-label="arrives late"></i>') : '—' ?></td>
                <td class="num"><?= $r['reorder_qty'] > 0 ? e(qty($r['reorder_qty'])) : '—' ?></td>
                <td><?= $urgencyBadge($r['urgency']) ?></td>
                <td class="small"><?= e($confidenceText[$r['confidence']] ?? '—') ?><span class="cell-sub"><?= (int) $r['history_days'] ?> d history</span></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
    <?php if ($canPurchase): ?>
        <div class="card-body border-top d-flex justify-content-between align-items-center flex-wrap gap-2">
            <span class="small text-muted">Suggested = use × (lead time + <?= (int) setting('review_period_days', 14) ?>-day review period) + safety stock − usable − on order, capped at the maximum level and rounded to the purchase pack.</span>
            <button type="submit" class="btn btn-primary"><i class="bi bi-file-earmark-plus me-1" aria-hidden="true"></i>Create draft purchase request from selected</button>
        </div>
    </form>
    <?php elseif ($canRequest): ?>
        <div class="card-body border-top d-flex justify-content-end"><button type="submit" class="btn btn-primary"><i class="bi bi-clipboard-plus me-1" aria-hidden="true"></i>Create stock request</button></div>
    </form>
    <?php endif; ?>
    <?php else: ?>
        <?= partial('partials/empty', ['icon' => 'bi-graph-down-arrow', 'title' => 'No products to show', 'text' => 'Forecasts need recorded consumption. Try a different filter.']) ?>
    <?php endif; ?>
</div>
