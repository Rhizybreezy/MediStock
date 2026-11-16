<?php
/**
 * Hospital management dashboard: analysis and approvals only (no create / edit actions).
 * @var array $stats
 * @var array $m            RoleDashboardService::manager()
 * @var array $insights
 * @var array $departments
 * @var bool $showValues
 * @var ?array $scopeNames
 * @var string[] $aiPrompts
 */
$amount = fn (array $x) => $showValues ? money($x['value'], false) : qty($x['qty']) . ' units';
$pending = array_sum(array_column($m['approvals'], 'count'));
?>
<?= partial('dashboard/partials/header', ['role' => 'Hospital Management', 'scopeNames' => $scopeNames, 'actions' => $m['actions']]) ?>

<div class="row g-3 mb-3">
    <?= partial('dashboard/partials/stat', ['label' => 'Inventory value', 'value' => $stats['stock_value'] !== null ? money($stats['stock_value'], false) : qty($stats['stock_qty']), 'meta' => $stats['stock_value'] !== null ? setting('currency_code', 'USD') . ' at purchase cost' : 'Units in stock', 'icon' => 'bi-cash-stack', 'tone' => 'success', 'url' => can('analytics.view') ? route('analytics.report', ['key' => 'valuation']) : null]) ?>
    <?= partial('dashboard/partials/stat', ['label' => 'Consumption (30 days)', 'value' => $amount($m['consumption_30']), 'meta' => 'Used on patients and dispensed', 'icon' => 'bi-clipboard-pulse', 'tone' => 'primary', 'url' => can('analytics.view') ? route('analytics.report', ['key' => 'consumption']) : null]) ?>
    <?= partial('dashboard/partials/stat', ['label' => 'Wastage (90 days)', 'value' => $amount($m['wastage_90']), 'meta' => 'Expired, damaged and written off', 'icon' => 'bi-trash3', 'tone' => 'danger', 'url' => can('analytics.view') ? route('analytics.report', ['key' => 'wastage']) : null]) ?>
    <?= partial('dashboard/partials/stat', ['label' => 'Purchases this month', 'value' => $amount($m['received_month']), 'meta' => 'Goods received from suppliers', 'icon' => 'bi-receipt', 'tone' => 'info', 'url' => can('analytics.view') ? route('analytics.report', ['key' => 'purchasing']) : null]) ?>
    <?= partial('dashboard/partials/stat', ['label' => 'Stock availability', 'value' => $m['availability'] !== null ? $m['availability'] . ' %' : '—', 'meta' => 'Stocked products with usable stock', 'icon' => 'bi-check2-circle', 'tone' => ($m['availability'] ?? 100) >= 95 ? 'success' : 'warning']) ?>
    <?= partial('dashboard/partials/stat', ['label' => 'Low / out of stock', 'value' => number_format($stats['low_stock']) . ' / ' . number_format($stats['out_of_stock']), 'meta' => 'At or below reorder level / none usable', 'icon' => 'bi-arrow-down-circle', 'tone' => 'warning', 'url' => route('intelligence.stockout')]) ?>
    <?= partial('dashboard/partials/stat', ['label' => 'Expiring soon', 'value' => number_format($stats['expiring_soon']), 'meta' => 'Batches within ' . (int) $stats['expiry_days'] . ' days · ' . number_format($stats['expired']) . ' expired', 'icon' => 'bi-hourglass-split', 'tone' => 'warning', 'url' => route('intelligence.expiry')]) ?>
    <?= partial('dashboard/partials/stat', ['label' => 'Awaiting your decision', 'value' => number_format($pending), 'meta' => $pending ? 'Approvals submitted by staff' : 'Nothing waiting', 'icon' => 'bi-hand-thumbs-up', 'tone' => $pending ? 'warning' : 'success']) ?>
</div>

<div class="row g-3 mb-3">
    <div class="col-xl-4">
        <?= partial('dashboard/partials/queue', ['title' => 'Awaiting your approval', 'icon' => 'bi-hand-thumbs-up', 'items' => $m['approvals'], 'empty' => 'Nothing is waiting for your decision.']) ?>
    </div>
    <div class="col-xl-8">
        <div class="card h-100">
            <div class="card-header">
                <h3 class="card-title">Monthly consumption</h3>
                <span class="small text-muted"><?= $showValues ? 'Value (' . e(setting('currency_code', 'USD')) . ')' : 'Units' ?> · last 12 months</span>
            </div>
            <div class="card-body"><div class="chart-box" data-chart="consumption"><canvas id="chart-consumption" aria-label="Monthly consumption chart" role="img"></canvas></div></div>
        </div>
    </div>
</div>

<?= partial('dashboard/partials/risks', ['insights' => $insights, 'showValues' => $showValues]) ?>

<div class="row g-3 mb-3">
    <div class="col-xl-6">
        <div class="card h-100">
            <div class="card-header"><h3 class="card-title">Inventory flow</h3><span class="small text-muted">Stock in vs. stock out of the hospital</span></div>
            <div class="card-body"><div class="chart-box sm" data-chart="flow"><canvas id="chart-flow" aria-label="Inventory in and out trend chart" role="img"></canvas></div></div>
        </div>
    </div>
    <div class="col-xl-6">
        <div class="card h-100">
            <div class="card-header"><h3 class="card-title">Stock by category</h3><span class="small text-muted"><?= $showValues ? 'Value' : 'Units' ?></span></div>
            <div class="card-body"><div class="chart-box sm" data-chart="categories"><canvas id="chart-categories" aria-label="Stock by category chart" role="img"></canvas></div></div>
        </div>
    </div>
</div>

<div class="row g-3">
    <div class="col-xl-8">
        <div class="card h-100">
            <div class="card-header"><h3 class="card-title">Departments</h3><span class="small text-muted">Current holdings</span></div>
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead><tr><th scope="col">Department</th><th scope="col" class="num">Products</th><th scope="col" class="num">Units</th><?php if ($showValues): ?><th scope="col" class="num">Value</th><?php endif; ?><th scope="col" class="num">Pending req.</th></tr></thead>
                    <tbody>
                    <?php foreach ($departments as $d): ?>
                        <tr>
                            <td><?= e($d['name']) ?><?php if ((int) $d['is_central_store'] === 1): ?> <span class="badge text-bg-primary ms-1">Central</span><?php endif; ?></td>
                            <td class="num"><?= number_format((int) $d['products']) ?></td>
                            <td class="num"><?= e(qty($d['qty'])) ?></td>
                            <?php if ($showValues): ?><td class="num"><?= e(money($d['value'], false)) ?></td><?php endif; ?>
                            <td class="num"><?= number_format((int) $d['pending_requests']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <div class="col-xl-4"><?= partial('dashboard/partials/ai', ['prompts' => $aiPrompts]) ?></div>
</div>
