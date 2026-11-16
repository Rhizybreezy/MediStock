<?php
/**
 * Department storekeeper dashboard: the department's own stock, deliveries, requests and consumption.
 * @var array $stats
 * @var array $m          RoleDashboardService::department('storekeeper')
 * @var array $insights
 * @var bool $showValues
 * @var ?array $scopeNames
 * @var string[] $aiPrompts
 */
$incoming = count($m['incoming']);
$d = fn (string $block) => partial('dashboard/partials/department', ['block' => $block, 'm' => $m, 'showValues' => $showValues]);
?>
<?= partial('dashboard/partials/header', ['role' => 'Department Storekeeper', 'scopeNames' => $scopeNames, 'actions' => $m['actions']]) ?>

<div class="row g-3 mb-3">
    <?= partial('dashboard/partials/stat', ['label' => 'Deliveries to confirm', 'value' => number_format($incoming), 'meta' => $incoming ? 'Confirm what physically arrived' : 'Nothing waiting', 'icon' => 'bi-box-seam', 'tone' => $incoming ? 'warning' : 'success', 'url' => can('stock.confirm_receipt') ? route('inventory.incoming') : null]) ?>
    <?= partial('dashboard/partials/stat', ['label' => 'Low / out of stock', 'value' => number_format($stats['low_stock']) . ' / ' . number_format($stats['out_of_stock']), 'meta' => 'Against your department levels', 'icon' => 'bi-arrow-down-circle', 'tone' => $stats['out_of_stock'] ? 'danger' : 'warning', 'url' => route('departments.index')]) ?>
    <?= partial('dashboard/partials/stat', ['label' => 'Items in stock', 'value' => number_format($m['products_in_stock']), 'meta' => number_format($stats['expiring_soon']) . ' batch(es) expiring soon', 'icon' => 'bi-boxes', 'tone' => 'primary', 'url' => route('inventory.stock')]) ?>
    <?= partial('dashboard/partials/stat', ['label' => 'Used today', 'value' => qty($m['used_today']), 'meta' => qty($m['used_7d']) . ' units in the last 7 days', 'icon' => 'bi-clipboard-pulse', 'tone' => 'info', 'url' => can('consumption.record') ? route('consumption.index') : null]) ?>
</div>

<div class="row g-3 mb-3">
    <div class="col-xl-6"><?= $d('incoming') ?></div>
    <div class="col-xl-6"><?= $d('requests') ?></div>
</div>

<div class="row g-3 mb-3">
    <div class="col-xl-6"><?= $d('low') ?></div>
    <div class="col-xl-6"><?= $d('expiring') ?></div>
</div>

<?= partial('dashboard/partials/risks', ['insights' => $insights, 'showValues' => $showValues]) ?>

<div class="row g-3">
    <?php if ($m['cold_chain']): ?><div class="col-xl-6"><?= $d('cold') ?></div><?php endif; ?>
    <div class="col-xl-<?= $m['cold_chain'] ? '6' : '12' ?>"><?= partial('dashboard/partials/ai', ['prompts' => $aiPrompts]) ?></div>
</div>
