<?php
/**
 * One KPI card.
 * @var string  $label
 * @var string  $value  already formatted
 * @var string  $meta
 * @var string  $icon   bootstrap icon class
 * @var string  $tone   primary|info|success|warning|danger|neutral
 * @var ?string $url
 */
$url = $url ?? null;
?>
<div class="col-6 col-xl-3">
    <?php if ($url): ?><a class="stat-link" href="<?= e($url) ?>"><?php endif; ?>
    <div class="card stat-card h-100">
        <div class="stat-icon tone-<?= e($tone) ?>"><i class="bi <?= e($icon) ?>" aria-hidden="true"></i></div>
        <div class="min-w-0">
            <div class="stat-label"><?= e($label) ?></div>
            <div class="stat-value"><?= e($value) ?></div>
            <div class="stat-meta"><?= e($meta) ?></div>
        </div>
    </div>
    <?php if ($url): ?></a><?php endif; ?>
</div>
