<?php
/**
 * Pharmacist dashboard: pharmacy medicines, dispensing, expiry and cold chain.
 * @var array $stats
 * @var array $m          RoleDashboardService::department('pharmacist')
 * @var array $insights
 * @var bool $showValues
 * @var ?array $scopeNames
 * @var string[] $aiPrompts
 */
$incoming = count($m['incoming']);
$coldIssues = count(array_filter($m['cold_chain'], fn ($c) => (int) $c['is_out_of_range'] === 1 || (int) $c['is_overdue'] === 1));
$d = fn (string $block) => partial('dashboard/partials/department', ['block' => $block, 'm' => $m, 'showValues' => $showValues]);
?>
<?= partial('dashboard/partials/header', ['role' => 'Pharmacist', 'scopeNames' => $scopeNames, 'actions' => $m['actions']]) ?>

<div class="row g-3 mb-3">
    <?= partial('dashboard/partials/stat', ['label' => 'Medicines in stock', 'value' => number_format($m['products_in_stock']), 'meta' => 'Products with usable stock', 'icon' => 'bi-capsule', 'tone' => 'primary', 'url' => route('inventory.stock')]) ?>
    <?= partial('dashboard/partials/stat', ['label' => 'Low / out of stock', 'value' => number_format($stats['low_stock']) . ' / ' . number_format($stats['out_of_stock']), 'meta' => 'Against your department levels', 'icon' => 'bi-arrow-down-circle', 'tone' => $stats['out_of_stock'] ? 'danger' : 'warning', 'url' => route('departments.index')]) ?>
    <?= partial('dashboard/partials/stat', ['label' => 'Expiring soon', 'value' => number_format($stats['expiring_soon']), 'meta' => number_format($stats['expired']) . ' expired batch(es) still on shelf', 'icon' => 'bi-hourglass-split', 'tone' => $stats['expired'] ? 'danger' : 'warning', 'url' => route('inventory.batches.index', [], ['expiry' => '90'])]) ?>
    <?= partial('dashboard/partials/stat', ['label' => 'Dispensed today', 'value' => qty($m['used_today']), 'meta' => qty($m['used_7d']) . ' units in the last 7 days', 'icon' => 'bi-clipboard-pulse', 'tone' => 'info', 'url' => can('consumption.record') ? route('consumption.index') : null]) ?>
</div>

<?php if ($incoming || $coldIssues): ?>
<div class="alert alert-info d-flex flex-wrap gap-3 align-items-center small">
    <?php if ($incoming): ?><span><i class="bi bi-box-seam me-1" aria-hidden="true"></i><strong><?= $incoming ?></strong> deliver<?= $incoming === 1 ? 'y' : 'ies' ?> waiting for you to confirm.</span><?php endif; ?>
    <?php if ($coldIssues): ?><span class="text-danger"><i class="bi bi-thermometer-high me-1" aria-hidden="true"></i><strong><?= $coldIssues ?></strong> fridge<?= $coldIssues === 1 ? '' : 's' ?> need attention (out of range or reading due).</span><?php endif; ?>
</div>
<?php endif; ?>

<div class="row g-3 mb-3">
    <div class="col-xl-8"><?= $d('expiring') ?></div>
    <div class="col-xl-4"><?= $m['cold_chain'] ? $d('cold') : partial('dashboard/partials/ai', ['prompts' => $aiPrompts]) ?></div>
</div>

<?= partial('dashboard/partials/risks', ['insights' => $insights, 'showValues' => $showValues]) ?>

<div class="row g-3 mb-3">
    <div class="col-xl-6"><?= $d('low') ?></div>
    <div class="col-xl-6"><?= $d('incoming') ?></div>
</div>

<div class="row g-3">
    <div class="col-xl-8"><?= $d('requests') ?></div>
    <?php if ($m['cold_chain']): ?><div class="col-xl-4"><?= partial('dashboard/partials/ai', ['prompts' => $aiPrompts]) ?></div><?php endif; ?>
</div>
