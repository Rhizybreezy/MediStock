<?php
/**
 * Inventory manager dashboard: runs the whole inventory (central store and departments).
 * @var array $stats
 * @var array $m          RoleDashboardService::inventory()
 * @var array $insights
 * @var array $movements
 * @var bool $showValues
 * @var ?array $scopeNames
 * @var string[] $aiPrompts
 */
$typeClass = [
    'RECEIVED' => 'mv-in', 'ADJUSTED_IN' => 'mv-in', 'RETURNED' => 'mv-move', 'ISSUED' => 'mv-move', 'TRANSFERRED' => 'mv-move',
    'CONSUMED' => 'mv-out', 'DAMAGED' => 'mv-out', 'EXPIRED' => 'mv-out', 'WRITTEN_OFF' => 'mv-out', 'ADJUSTED_OUT' => 'mv-adj',
    'QUARANTINED' => 'mv-adj',
];
$counts = ['Approved requests to issue' => $m['to_issue'], 'Purchase orders to receive' => $m['to_receive'], 'Stock in transit' => $m['in_transit'], 'Counts in progress' => $m['counts_open'], 'Open recalls' => $m['recalls_open']];
$work = array_map(fn ($w) => $w + ['count' => $counts[$w['label']] ?? 0], $m['work']);
?>
<?= partial('dashboard/partials/header', ['role' => 'Inventory Manager', 'scopeNames' => $scopeNames, 'actions' => $m['actions']]) ?>

<div class="row g-3 mb-3">
    <?= partial('dashboard/partials/stat', ['label' => 'Low stock', 'value' => number_format($stats['low_stock']), 'meta' => 'At or below reorder level', 'icon' => 'bi-arrow-down-circle', 'tone' => 'warning', 'url' => route('inventory.stock', [], ['stock' => 'low'])]) ?>
    <?= partial('dashboard/partials/stat', ['label' => 'Out of stock', 'value' => number_format($stats['out_of_stock']), 'meta' => 'No usable stock', 'icon' => 'bi-x-octagon', 'tone' => 'danger', 'url' => route('inventory.stock', [], ['stock' => 'out'])]) ?>
    <?= partial('dashboard/partials/stat', ['label' => 'Expiring soon', 'value' => number_format($stats['expiring_soon']), 'meta' => 'Batches within ' . (int) $stats['expiry_days'] . ' days', 'icon' => 'bi-hourglass-split', 'tone' => 'warning', 'url' => route('inventory.batches.index', [], ['expiry' => '90'])]) ?>
    <?= partial('dashboard/partials/stat', ['label' => 'Expired, still in stock', 'value' => number_format($stats['expired']), 'meta' => 'Quarantine or dispose', 'icon' => 'bi-calendar-x', 'tone' => 'danger', 'url' => route('inventory.batches.index', [], ['expiry' => 'expired'])]) ?>
    <?= partial('dashboard/partials/stat', ['label' => 'Requests to issue', 'value' => number_format($m['to_issue']), 'meta' => number_format($stats['pending_requests']) . ' waiting for review', 'icon' => 'bi-box-arrow-right', 'tone' => 'primary', 'url' => route('requests.index', [], ['status' => 'to_issue'])]) ?>
    <?= partial('dashboard/partials/stat', ['label' => 'Deliveries expected', 'value' => number_format($m['to_receive']), 'meta' => $m['late_deliveries'] ? $m['late_deliveries'] . ' overdue' : 'None overdue', 'icon' => 'bi-truck', 'tone' => $m['late_deliveries'] ? 'danger' : 'info', 'url' => route('inventory.receipts.po')]) ?>
    <?= partial('dashboard/partials/stat', ['label' => 'Redistribution ideas', 'value' => number_format($m['redistribution']), 'meta' => 'Move surplus where it is needed', 'icon' => 'bi-shuffle', 'tone' => 'info', 'url' => route('intelligence.redistribution')]) ?>
    <?= partial('dashboard/partials/stat', ['label' => 'Anomalies', 'value' => number_format($m['anomalies']), 'meta' => 'Unusual activity to review', 'icon' => 'bi-activity', 'tone' => $m['anomalies'] ? 'warning' : 'success', 'url' => route('intelligence.anomalies')]) ?>
</div>

<div class="row g-3 mb-3">
    <div class="col-xl-4">
        <?= partial('dashboard/partials/queue', ['title' => 'Awaiting your approval', 'icon' => 'bi-hand-thumbs-up', 'items' => $m['approvals'], 'empty' => 'Nothing is waiting for your decision.']) ?>
    </div>
    <div class="col-xl-4">
        <?= partial('dashboard/partials/queue', ['title' => 'Store work', 'icon' => 'bi-list-task', 'items' => $work, 'empty' => 'No open store work.']) ?>
    </div>
    <div class="col-xl-4"><?= partial('dashboard/partials/ai', ['prompts' => $aiPrompts]) ?></div>
</div>

<?= partial('dashboard/partials/risks', ['insights' => $insights, 'showValues' => $showValues]) ?>

<div class="row g-3 mb-3">
    <div class="col-xl-6">
        <div class="card h-100">
            <div class="card-header"><h3 class="card-title">Inventory flow</h3><span class="small text-muted">Stock in vs. stock out · 12 months</span></div>
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

<div class="card">
    <div class="card-header">
        <h3 class="card-title">Recent stock movements</h3>
        <?php if (can('stock.movements.view')): ?><a class="small" href="<?= e(route('inventory.movements')) ?>">Movement ledger <i class="bi bi-arrow-right" aria-hidden="true"></i></a><?php endif; ?>
    </div>
    <?php if ($movements): ?>
    <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead><tr><th scope="col">When</th><th scope="col">Type</th><th scope="col">Product</th><th scope="col">Batch</th><th scope="col" class="num">Quantity</th><th scope="col">From → To</th><th scope="col">By</th></tr></thead>
            <tbody>
            <?php foreach ($movements as $mv): ?>
                <tr>
                    <td class="text-nowrap"><span title="<?= e(format_date($mv['created_at'], true)) ?>"><?= e(time_ago($mv['created_at'])) ?></span></td>
                    <td><span class="mv <?= e($typeClass[$mv['movement_type']] ?? 'mv-move') ?>"><?= e(str_replace('_', ' ', $mv['movement_type'])) ?></span></td>
                    <td><?= e($mv['product_name']) ?><span class="cell-sub"><?= e($mv['product_code']) ?></span></td>
                    <td><span class="code-chip"><?= e($mv['batch_code']) ?></span></td>
                    <td class="num"><?= e(qty($mv['quantity'])) ?> <span class="text-muted small"><?= e($mv['unit']) ?></span></td>
                    <td class="small"><?= e($mv['from_location'] ?? 'Outside') ?> <i class="bi bi-arrow-right text-muted" aria-hidden="true"></i> <?= e($mv['to_location'] ?? 'Outside') ?></td>
                    <td class="small"><?= e($mv['user_name']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php else: ?>
        <?= partial('partials/empty', ['icon' => 'bi-arrow-left-right', 'title' => 'No stock movements yet']) ?>
    <?php endif; ?>
</div>
