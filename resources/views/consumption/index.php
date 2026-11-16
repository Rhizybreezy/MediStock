<?php /** @var App\Core\Paginator $page */ ?>
<div class="page-header">
    <div>
        <nav aria-label="breadcrumb"><ol class="breadcrumb"><li class="breadcrumb-item">Departments</li><li class="breadcrumb-item active" aria-current="page">Consumption</li></ol></nav>
        <h1>Consumption records</h1>
        <div class="subtitle">Stock used or dispensed by departments. Batches are taken first-expiry-first-out. No patient data is stored.</div>
    </div>
    <?php if (can('consumption.record')): ?><a href="<?= e(route('consumption.create')) ?>" class="btn btn-primary"><i class="bi bi-clipboard-pulse me-1" aria-hidden="true"></i>Record consumption</a><?php endif; ?>
</div>
<div class="card">
    <form class="filter-bar" method="get">
        <select name="department_id" class="form-select" data-auto-submit aria-label="Department">
            <option value="">All departments</option>
            <?php foreach ($departments as $d): ?><option value="<?= (int) $d['id'] ?>"<?= (string) $filters['department_id'] === (string) $d['id'] ? ' selected' : '' ?>><?= e($d['name']) ?></option><?php endforeach; ?>
        </select>
        <select name="purpose" class="form-select" data-auto-submit aria-label="Purpose">
            <option value="">Any purpose</option>
            <?php foreach ($purposes as $k => $v): ?><option value="<?= e($k) ?>"<?= $filters['purpose'] === $k ? ' selected' : '' ?>><?= e($v) ?></option><?php endforeach; ?>
        </select>
        <input type="date" name="from" class="form-control" value="<?= e($filters['from']) ?>" aria-label="From">
        <input type="date" name="to" class="form-control" value="<?= e($filters['to']) ?>" aria-label="To">
        <button type="submit" class="btn btn-outline-secondary"><i class="bi bi-funnel" aria-hidden="true"></i> Filter</button>
    </form>
    <?php if ($page->items): ?>
    <div class="table-responsive"><table class="table table-hover">
        <thead><tr><th scope="col">When</th><th scope="col">Department / location</th><th scope="col">Products</th><th scope="col">Purpose</th><th scope="col" class="num">Lines</th><?php if ($showValues): ?><th scope="col" class="num">Value</th><?php endif; ?><th scope="col">Recorded by</th></tr></thead>
        <tbody>
        <?php foreach ($page->items as $c): ?>
            <tr>
                <td class="small text-nowrap"><?= e(format_date($c['created_at'], true)) ?><span class="cell-sub"><a href="<?= e(route('inventory.movements', [], ['q' => $c['txn_number']])) ?>"><?= e($c['txn_number']) ?></a></span></td>
                <td class="small"><?= e($c['department_name']) ?><span class="cell-sub"><?= e($c['location_name'] ?? '') ?></span></td>
                <td class="small"><?= e($c['products']) ?><?php if ($c['notes']): ?><span class="cell-sub"><?= e($c['notes']) ?></span><?php endif; ?></td>
                <td class="small"><?= e($purposes[$c['purpose']] ?? $c['reason'] ?? '—') ?></td>
                <td class="num"><?= (int) $c['line_count'] ?></td>
                <?php if ($showValues): ?><td class="num"><?= e(money($c['value'], false)) ?></td><?php endif; ?>
                <td class="small"><?= e($c['user_name']) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
    <?php else: ?>
        <?= partial('partials/empty', ['icon' => 'bi-clipboard-pulse', 'title' => 'No consumption recorded', 'text' => 'Recorded usage feeds the consumption analytics and stock-out forecasts.']) ?>
    <?php endif; ?>
    <?= partial('partials/pagination', ['page' => $page]) ?>
</div>
