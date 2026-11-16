<?php $r = $report; $cols = $r['columns']; $alignNum = fn ($c) => in_array($c['type'] ?? 'text', ['qty', 'money', 'int', 'pct', 'pct_change'], true);
$exportQuery = array_filter(request()->queryAll(), fn ($v) => $v !== '' && $v !== null); ?>
<div class="page-header">
    <div>
        <nav aria-label="breadcrumb"><ol class="breadcrumb"><li class="breadcrumb-item"><a href="<?= e(route('analytics.index')) ?>">Analytics</a></li><li class="breadcrumb-item active" aria-current="page">Reports</li></ol></nav>
        <h1><?= e($r['title']) ?></h1>
        <div class="subtitle"><?= e($r['description']) ?></div>
    </div>
    <?php if ($canExport): ?>
    <div class="d-flex gap-2">
        <a class="btn btn-outline-secondary" href="<?= e(route('analytics.report', ['key' => $key], $exportQuery + ['format' => 'csv'])) ?>"><i class="bi bi-filetype-csv me-1" aria-hidden="true"></i>CSV</a>
        <a class="btn btn-outline-secondary" href="<?= e(route('analytics.report', ['key' => $key], $exportQuery + ['format' => 'pdf'])) ?>" target="_blank" rel="noopener"><i class="bi bi-filetype-pdf me-1" aria-hidden="true"></i>PDF</a>
    </div>
    <?php endif; ?>
</div>
<ul class="nav nav-pills mb-3 flex-wrap gap-1">
    <?php foreach ($reports as $k => [$label, $icon]): ?>
        <li class="nav-item"><a class="nav-link py-1 px-2 small<?= $k === $key ? ' active' : '' ?>" href="<?= e(route('analytics.report', ['key' => $k], array_filter(['from' => $f['from'], 'to' => $f['to'], 'department_id' => $f['department_id']]))) ?>"><i class="bi <?= e($icon) ?> me-1" aria-hidden="true"></i><?= e($label) ?></a></li>
    <?php endforeach; ?>
</ul>
<div class="card mb-3"><?= partial('analytics/_filters', ['f' => $f, 'departments' => $departments, 'categories' => in_array($key, ['valuation', 'consumption', 'purchasing', 'wastage', 'slow_moving', 'movements'], true) ? $categories : [], 'report' => $r, 'dated' => !empty($r['dated']), 'hideDept' => !empty($r['hospital_only'])]) ?></div>
<?php foreach ($r['notes'] as $note): ?><div class="alert alert-light border small"><i class="bi bi-info-circle me-1" aria-hidden="true"></i><?= e($note) ?></div><?php endforeach; ?>
<?php if ($r['chart'] && $r['rows']): ?>
<div class="card mb-3"><div class="card-body">
    <div class="chart-box" data-analytics-chart="report"><canvas role="img" aria-label="<?= e($r['title']) ?> chart"></canvas></div>
    <script type="application/json" data-chart-for="report"><?= json_encode($r['chart'], JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
</div></div>
<?php endif; ?>
<div class="card">
    <?php if ($r['rows']): ?>
    <div class="table-responsive"><table class="table table-sm table-hover">
        <thead><tr><?php foreach ($cols as $c): ?><th scope="col" class="<?= $alignNum($c) ? 'num' : '' ?>"><?= e($c['label']) ?></th><?php endforeach; ?></tr></thead>
        <tbody>
        <?php foreach (array_slice($r['rows'], 0, 1000) as $row): $v = $fmt($cols, $row); ?>
            <tr><?php foreach ($cols as $ck => $c): ?><td class="<?= $alignNum($c) ? 'num' : 'small' ?><?= ($c['type'] ?? '') === 'pct_change' && is_numeric($row[$ck] ?? null) ? ((float) $row[$ck] > 0.1 ? ' text-warning-emphasis' : '') : '' ?>"><?= e($v[$ck]) ?></td><?php endforeach; ?></tr>
        <?php endforeach; ?>
        </tbody>
        <?php if ($r['totals']): $t = $fmt($cols, $r['totals']); ?>
        <tfoot><tr class="fw-semibold"><?php foreach ($cols as $ck => $c): ?><td class="<?= $alignNum($c) ? 'num' : '' ?>"><?= array_key_exists($ck, $r['totals']) ? e($t[$ck]) : '' ?></td><?php endforeach; ?></tr></tfoot>
        <?php endif; ?>
    </table></div>
    <div class="table-footer"><span><?= number_format(count($r['rows'])) ?> row(s)<?= count($r['rows']) > 1000 ? ' — first 1,000 shown; export for all' : '' ?></span></div>
    <?php else: ?>
        <?= partial('partials/empty', ['icon' => 'bi-bar-chart', 'title' => 'No data for these filters', 'text' => 'Try a longer date range or a different department.']) ?>
    <?php endif; ?>
</div>
