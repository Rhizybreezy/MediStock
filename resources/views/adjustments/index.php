<?php /** @var App\Core\Paginator $page */ ?>
<div class="page-header">
    <div>
        <nav aria-label="breadcrumb"><ol class="breadcrumb"><li class="breadcrumb-item">Departments</li><li class="breadcrumb-item active" aria-current="page">Adjustments</li></ol></nav>
        <h1>Stock adjustments</h1>
        <div class="subtitle">Damage, loss, expiry disposal, write-offs and corrections. Stock changes only after a second person approves.</div>
    </div>
    <?php if (can('adjustments.request')): ?><a href="<?= e(route('adjustments.create')) ?>" class="btn btn-primary"><i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Request adjustment</a><?php endif; ?>
</div>
<div class="card">
    <form class="filter-bar" method="get">
        <input type="search" name="q" class="form-control search-input" placeholder="Adjustment number" value="<?= e($filters['q']) ?>" aria-label="Search">
        <select name="status" class="form-select" data-auto-submit aria-label="Status">
            <option value="">Any status</option>
            <?php foreach (['pending', 'posted', 'rejected'] as $s): ?><option value="<?= $s ?>"<?= $filters['status'] === $s ? ' selected' : '' ?>><?= e(label($s)) ?></option><?php endforeach; ?>
        </select>
        <select name="reason" class="form-select" data-auto-submit aria-label="Reason">
            <option value="">Any reason</option>
            <?php foreach ($reasons as $k => [$label]): ?><option value="<?= e($k) ?>"<?= $filters['reason'] === $k ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
        </select>
    </form>
    <?php if ($page->items): ?>
    <div class="table-responsive"><table class="table table-hover">
        <thead><tr><th scope="col">Number</th><th scope="col">Reason</th><th scope="col">Location</th><th scope="col" class="num">Lines</th><?php if ($showValues): ?><th scope="col" class="num">Value change</th><?php endif; ?><th scope="col">Status</th><th scope="col">Requested</th></tr></thead>
        <tbody>
        <?php foreach ($page->items as $a): ?>
            <tr>
                <td><a class="fw-semibold" href="<?= e(route('adjustments.show', ['id' => $a['id']])) ?>"><?= e($a['adjustment_number']) ?></a></td>
                <td class="small"><?= e($reasons[$a['reason_code']][0] ?? label($a['reason_code'])) ?></td>
                <td class="small"><?= e($a['location_name']) ?><span class="cell-sub"><?= e($a['department_name']) ?></span></td>
                <td class="num"><?= (int) $a['line_count'] ?></td>
                <?php if ($showValues): ?><td class="num <?= (float) $a['value_change'] < 0 ? 'text-danger' : 'text-success' ?>"><?= e(money($a['value_change'], false)) ?></td><?php endif; ?>
                <td><?= status_badge($a['status']) ?></td>
                <td class="small text-nowrap"><?= e(time_ago($a['created_at'])) ?><span class="cell-sub"><?= e($a['requested_by_name']) ?></span></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
    <?php else: ?>
        <?= partial('partials/empty', ['icon' => 'bi-sliders2', 'title' => 'No adjustments']) ?>
    <?php endif; ?>
    <?= partial('partials/pagination', ['page' => $page]) ?>
</div>
