<?php /* Shared filter bar for analytics pages. */ $req = request(); ?>
<form class="filter-bar" method="get">
    <?php if ($dated ?? true): ?>
    <label class="small text-muted" for="from">From</label><input type="date" id="from" name="from" class="form-control" value="<?= e($f['from']) ?>" max="<?= date('Y-m-d') ?>">
    <label class="small text-muted" for="to">To</label><input type="date" id="to" name="to" class="form-control" value="<?= e($f['to']) ?>" max="<?= date('Y-m-d') ?>">
    <div class="btn-group btn-group-sm" role="group" aria-label="Quick ranges">
        <?php foreach ([30 => '30d', 90 => '90d', 180 => '6m', 365 => '12m'] as $d => $l): ?>
            <a class="btn btn-outline-secondary" href="<?= e(url($req->path(), array_merge($req->queryAll(), ['from' => date('Y-m-d', strtotime('-' . ($d - 1) . ' days')), 'to' => date('Y-m-d')]))) ?>"><?= $l ?></a>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
    <?php if (empty($hideDept)): ?>
    <select name="department_id" class="form-select" data-auto-submit aria-label="Department">
        <option value=""><?= app(\App\Core\Gate::class)->departmentScope('stock.view') === null ? 'All departments' : 'My departments' ?></option>
        <?php foreach ($departments as $d): ?><option value="<?= (int) $d['id'] ?>"<?= (int) $f['department_id'] === (int) $d['id'] ? ' selected' : '' ?>><?= e($d['name']) ?></option><?php endforeach; ?>
    </select>
    <?php endif; ?>
    <?php if (!empty($categories)): ?>
    <select name="category_id" class="form-select" data-auto-submit aria-label="Category">
        <option value="">All categories</option>
        <?php foreach ($categories as $c): ?><option value="<?= (int) $c['id'] ?>"<?= (int) $f['category_id'] === (int) $c['id'] ? ' selected' : '' ?>><?= e($c['name']) ?></option><?php endforeach; ?>
    </select>
    <?php endif; ?>
    <?php if (!empty($report['groups'])): ?>
    <select name="group" class="form-select" data-auto-submit aria-label="Group by">
        <?php foreach ($report['groups'] as $k => $l): ?><option value="<?= e($k) ?>"<?= ($report['group'] ?? '') === $k ? ' selected' : '' ?>>By <?= e(strtolower($l)) ?></option><?php endforeach; ?>
    </select>
    <?php endif; ?>
    <?php if (!empty($report['idle'])): ?>
    <select name="days" class="form-select" data-auto-submit aria-label="Idle period"><?php foreach ([30, 60, 90, 180, 365] as $d): ?><option value="<?= $d ?>"<?= (int) $report['idle'] === $d ? ' selected' : '' ?>>No movement in <?= $d ?> days</option><?php endforeach; ?></select>
    <?php endif; ?>
    <?php if (!empty($report['types'])): ?>
    <select name="type" class="form-select" data-auto-submit aria-label="Movement type"><option value="">All types</option><?php foreach ($report['types'] as $t): ?><option value="<?= e($t) ?>"<?= $f['type'] === $t ? ' selected' : '' ?>><?= e(label(strtolower($t))) ?></option><?php endforeach; ?></select>
    <?php endif; ?>
    <button type="submit" class="btn btn-outline-secondary"><i class="bi bi-funnel" aria-hidden="true"></i> Apply</button>
</form>
