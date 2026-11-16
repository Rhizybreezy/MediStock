<?php
/**
 * Hospital-wide overview. Used by the System Administrator (with the system panel)
 * and by users who only hold custom roles.
 * @var string $type
 * @var array $stats
 * @var array $movements
 * @var array $departments
 * @var array $insights
 * @var bool $showValues
 * @var ?array $scopeNames
 * @var string[] $aiPrompts
 * @var array|null $admin  system panel (administrators only)
 */
$typeClass = [
    'RECEIVED' => 'mv-in', 'ADJUSTED_IN' => 'mv-in', 'RETURNED' => 'mv-move', 'ISSUED' => 'mv-move', 'TRANSFERRED' => 'mv-move',
    'CONSUMED' => 'mv-out', 'DAMAGED' => 'mv-out', 'EXPIRED' => 'mv-out', 'WRITTEN_OFF' => 'mv-out', 'ADJUSTED_OUT' => 'mv-adj',
    'QUARANTINED' => 'mv-adj',
];
$admin = $admin ?? null;
?>
<?= partial('dashboard/partials/header', ['role' => $admin ? 'System Administrator' : 'Overview', 'scopeNames' => $scopeNames, 'actions' => $admin['actions'] ?? []]) ?>
<?php if ($admin): ?><?= partial('dashboard/partials/admin', ['a' => $admin]) ?><?php endif; ?>

<div class="row g-3 mb-3">
    <div class="col-6 col-xl-3">
        <?php if (can('products.view')): ?><a class="stat-link" href="<?= e(route('products.index')) ?>"><?php endif; ?>
        <div class="card stat-card">
            <div class="stat-icon tone-primary"><i class="bi bi-capsule" aria-hidden="true"></i></div>
            <div class="min-w-0">
                <div class="stat-label">Registered products</div>
                <div class="stat-value"><?= number_format($stats['products_total']) ?></div>
                <div class="stat-meta">Active medicines &amp; supplies</div>
            </div>
        </div>
        <?php if (can('products.view')): ?></a><?php endif; ?>
    </div>
    <div class="col-6 col-xl-3">
        <?php if (can('stock.view')): ?><a class="stat-link" href="<?= e(route('inventory.stock')) ?>"><?php endif; ?>
        <div class="card stat-card">
            <div class="stat-icon tone-info"><i class="bi bi-boxes" aria-hidden="true"></i></div>
            <div class="min-w-0">
                <div class="stat-label">Units in stock</div>
                <div class="stat-value"><?= e(qty($stats['stock_qty'])) ?></div>
                <div class="stat-meta"><?= number_format($stats['batches_in_stock']) ?> batch<?= $stats['batches_in_stock'] === 1 ? '' : 'es' ?> on hand</div>
            </div>
        </div>
        <?php if (can('stock.view')): ?></a><?php endif; ?>
    </div>
    <div class="col-6 col-xl-3">
        <div class="card stat-card">
            <div class="stat-icon tone-success"><i class="bi bi-cash-stack" aria-hidden="true"></i></div>
            <div class="min-w-0">
                <div class="stat-label">Inventory value</div>
                <?php if ($stats['stock_value'] !== null): ?>
                    <div class="stat-value"><?= e(money($stats['stock_value'], false)) ?></div>
                    <div class="stat-meta"><?= e(setting('currency_code', 'USD')) ?> · at batch purchase cost</div>
                <?php else: ?>
                    <div class="stat-value text-muted">—</div>
                    <div class="stat-meta">Requires valuation access</div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="col-6 col-xl-3">
        <?php if (can('stock.view')): ?><a class="stat-link" href="<?= e(route('inventory.stock', [], ['stock' => 'low'])) ?>"><?php endif; ?>
        <div class="card stat-card">
            <div class="stat-icon tone-warning"><i class="bi bi-arrow-down-circle" aria-hidden="true"></i></div>
            <div class="min-w-0">
                <div class="stat-label">Low stock</div>
                <div class="stat-value"><?= number_format($stats['low_stock']) ?></div>
                <div class="stat-meta">At or below reorder level</div>
            </div>
        </div>
        <?php if (can('stock.view')): ?></a><?php endif; ?>
    </div>
    <div class="col-6 col-xl-3">
        <?php if (can('stock.view')): ?><a class="stat-link" href="<?= e(route('inventory.stock', [], ['stock' => 'out'])) ?>"><?php endif; ?>
        <div class="card stat-card">
            <div class="stat-icon tone-danger"><i class="bi bi-x-octagon" aria-hidden="true"></i></div>
            <div class="min-w-0">
                <div class="stat-label">Out of stock</div>
                <div class="stat-value"><?= number_format($stats['out_of_stock']) ?></div>
                <div class="stat-meta">No usable stock available</div>
            </div>
        </div>
        <?php if (can('stock.view')): ?></a><?php endif; ?>
    </div>
    <div class="col-6 col-xl-3">
        <?php if (can('stock.view')): ?><a class="stat-link" href="<?= e(route('inventory.batches.index', [], ['expiry' => $stats['expiry_days'] <= 30 ? '30' : ($stats['expiry_days'] <= 90 ? '90' : '180')])) ?>"><?php endif; ?>
        <div class="card stat-card">
            <div class="stat-icon tone-warning"><i class="bi bi-hourglass-split" aria-hidden="true"></i></div>
            <div class="min-w-0">
                <div class="stat-label">Expiring soon</div>
                <div class="stat-value"><?= number_format($stats['expiring_soon']) ?></div>
                <div class="stat-meta">Batches within <?= (int) $stats['expiry_days'] ?> days</div>
            </div>
        </div>
        <?php if (can('stock.view')): ?></a><?php endif; ?>
    </div>
    <div class="col-6 col-xl-3">
        <?php if (can('stock.view')): ?><a class="stat-link" href="<?= e(route('inventory.batches.index', [], ['expiry' => 'expired'])) ?>"><?php endif; ?>
        <div class="card stat-card">
            <div class="stat-icon tone-danger"><i class="bi bi-calendar-x" aria-hidden="true"></i></div>
            <div class="min-w-0">
                <div class="stat-label">Expired batches</div>
                <div class="stat-value"><?= number_format($stats['expired']) ?></div>
                <div class="stat-meta">Still holding stock</div>
            </div>
        </div>
        <?php if (can('stock.view')): ?></a><?php endif; ?>
    </div>
    <div class="col-6 col-xl-3">
        <div class="card stat-card">
            <div class="stat-icon tone-neutral"><i class="bi bi-clipboard-check" aria-hidden="true"></i></div>
            <div class="min-w-0">
                <div class="stat-label">Pending requests</div>
                <div class="stat-value"><?= number_format($stats['pending_requests']) ?></div>
                <div class="stat-meta">
                    <?php if ($stats['pending_pos'] !== null): ?>
                        <?= number_format($stats['pending_pos']) ?> open purchase order<?= $stats['pending_pos'] === 1 ? '' : 's' ?>
                    <?php else: ?>
                        Department requests awaiting review
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<?= partial('dashboard/partials/risks', ['insights' => $insights, 'showValues' => $showValues]) ?>

<div class="row g-3 mb-3">
    <div class="col-xl-8">
        <div class="card h-100">
            <div class="card-header">
                <h3 class="card-title">Monthly consumption</h3>
                <span class="small text-muted"><?= $showValues ? 'Value (' . e(setting('currency_code', 'USD')) . ')' : 'Units' ?> · last 12 months</span>
            </div>
            <div class="card-body">
                <div class="chart-box" data-chart="consumption">
                    <canvas id="chart-consumption" aria-label="Monthly consumption chart" role="img"></canvas>
                </div>
            </div>
        </div>
    </div>
    <div class="col-xl-4">
        <div class="card h-100">
            <div class="card-header">
                <h3 class="card-title">Stock by category</h3>
                <span class="small text-muted"><?= $showValues ? 'Value' : 'Units' ?></span>
            </div>
            <div class="card-body">
                <div class="chart-box" data-chart="categories">
                    <canvas id="chart-categories" aria-label="Stock by category chart" role="img"></canvas>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="row g-3 mb-3">
    <div class="col-xl-6">
        <div class="card h-100">
            <div class="card-header">
                <h3 class="card-title">Inventory flow</h3>
                <span class="small text-muted">Stock in vs. stock out of the hospital</span>
            </div>
            <div class="card-body">
                <div class="chart-box sm" data-chart="flow">
                    <canvas id="chart-flow" aria-label="Inventory in and out trend chart" role="img"></canvas>
                </div>
            </div>
        </div>
    </div>
    <div class="col-xl-6">
        <div class="card h-100">
            <div class="card-header">
                <h3 class="card-title">Departments</h3>
                <span class="small text-muted">Current holdings</span>
            </div>
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead><tr>
                        <th scope="col">Department</th>
                        <th scope="col" class="num">Products</th>
                        <th scope="col" class="num">Units</th>
                        <?php if ($showValues): ?><th scope="col" class="num">Value</th><?php endif; ?>
                        <th scope="col" class="num">Pending req.</th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($departments as $d): ?>
                        <tr>
                            <td>
                                <?= e($d['name']) ?>
                                <?php if ((int) $d['is_central_store'] === 1): ?><span class="badge text-bg-primary ms-1">Central</span><?php endif; ?>
                            </td>
                            <td class="num"><?= number_format((int) $d['products']) ?></td>
                            <td class="num"><?= e(qty($d['qty'])) ?></td>
                            <?php if ($showValues): ?><td class="num"><?= e(money($d['value'], false)) ?></td><?php endif; ?>
                            <td class="num"><?= number_format((int) $d['pending_requests']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$departments): ?>
                        <tr><td colspan="5"><?= partial('partials/empty', ['icon' => 'bi-building', 'title' => 'No departments', 'text' => 'No active departments are available to you.']) ?></td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<div class="row g-3">
<div class="col-xl-8">
<div class="card h-100">
    <div class="card-header">
        <h3 class="card-title">Recent stock movements</h3>
        <span class="small text-muted">Latest entries in the stock ledger</span>
    </div>
    <?php if ($movements): ?>
    <div class="table-responsive">
        <table class="table table-hover">
            <thead><tr>
                <th scope="col">When</th><th scope="col">Type</th><th scope="col">Product</th><th scope="col">Batch</th>
                <th scope="col" class="num">Quantity</th><th scope="col">From → To</th><th scope="col">By</th>
            </tr></thead>
            <tbody>
            <?php foreach ($movements as $m): ?>
                <tr>
                    <td class="text-nowrap"><span title="<?= e(format_date($m['created_at'], true)) ?>"><?= e(time_ago($m['created_at'])) ?></span></td>
                    <td><span class="mv <?= e($typeClass[$m['movement_type']] ?? 'mv-move') ?>"><?= e(str_replace('_', ' ', $m['movement_type'])) ?></span></td>
                    <td><?= e($m['product_name']) ?><span class="cell-sub"><?= e($m['product_code']) ?></span></td>
                    <td><span class="code-chip"><?= e($m['batch_code']) ?></span></td>
                    <td class="num"><?= e(qty($m['quantity'])) ?> <span class="text-muted small"><?= e($m['unit']) ?></span></td>
                    <td class="small"><?= e($m['from_location'] ?? 'Outside') ?> <i class="bi bi-arrow-right text-muted" aria-hidden="true"></i> <?= e($m['to_location'] ?? 'Outside') ?></td>
                    <td class="small"><?= e($m['user_name']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php else: ?>
        <?= partial('partials/empty', ['icon' => 'bi-arrow-left-right', 'title' => 'No stock movements yet', 'text' => 'Receipts, issues, transfers and consumption will appear here as soon as they are recorded.']) ?>
    <?php endif; ?>
</div>
</div>
<div class="col-xl-4"><?= partial('dashboard/partials/ai', ['prompts' => $aiPrompts]) ?></div>
</div>
