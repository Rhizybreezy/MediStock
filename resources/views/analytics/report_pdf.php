<?php $cols = $report['columns']; $num = fn ($c) => in_array($c['type'] ?? 'text', ['qty', 'money', 'int', 'pct', 'pct_change'], true); ?>
<h1 style="font-size:15pt"><?= e($report['title']) ?></h1>
<?php foreach ($meta as $line): ?><div class="small muted"><?= e($line) ?></div><?php endforeach; ?>
<p class="small"><?= e($report['description']) ?><?= in_array('money', array_column($cols, 'type'), true) ? ' Values in ' . e(setting('currency_code', 'USD')) . '.' : '' ?></p>
<?php foreach ($report['notes'] as $n): ?><p class="small"><?= e($n) ?></p><?php endforeach; ?>
<table style="font-size:7.5pt">
    <thead><tr><?php foreach ($cols as $c): ?><th class="<?= $num($c) ? 'num' : '' ?>"><?= e($c['label']) ?></th><?php endforeach; ?></tr></thead>
    <tbody>
    <?php foreach (array_slice($report['rows'], 0, 2000) as $row): $v = $fmt($cols, $row); ?>
        <tr><?php foreach ($cols as $ck => $c): ?><td class="<?= $num($c) ? 'num' : '' ?>"><?= e($v[$ck]) ?></td><?php endforeach; ?></tr>
    <?php endforeach; ?>
    <?php if ($report['totals']): $t = $fmt($cols, $report['totals']); ?>
        <tr><?php foreach ($cols as $ck => $c): ?><td class="<?= $num($c) ? 'num' : '' ?>" style="font-weight:bold;border-top:1px solid #333"><?= array_key_exists($ck, $report['totals']) ? e($t[$ck]) : '' ?></td><?php endforeach; ?></tr>
    <?php endif; ?>
    </tbody>
</table>
<?php if (count($report['rows']) > 2000): ?><p class="small muted">Showing the first 2,000 of <?= count($report['rows']) ?> rows. Use the CSV export for the complete data.</p><?php endif; ?>
