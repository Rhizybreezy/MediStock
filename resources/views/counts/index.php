<?php /** @var App\Core\Paginator $page */ ?>
<div class="page-header">
    <div>
        <nav aria-label="breadcrumb"><ol class="breadcrumb"><li class="breadcrumb-item">Departments</li><li class="breadcrumb-item active" aria-current="page">Stock counts</li></ol></nav>
        <h1>Stock counts</h1>
        <div class="subtitle">Blind physical counts. Counters don't see system quantities. Variances are posted only after approval by another person.</div>
    </div>
</div>
<?php if ($locations): ?>
<div class="card mb-3"><div class="card-body">
    <form method="post" action="<?= e(route('counts.store')) ?>" class="row g-2 align-items-end">
        <?= csrf_field() ?>
        <div class="col-md-4"><label class="form-label" for="location_id">Location to count</label>
            <select class="form-select" id="location_id" name="location_id" required>
                <?php $dept = null; foreach ($locations as $l): if ($dept !== $l['department_name']): if ($dept !== null): ?></optgroup><?php endif; $dept = $l['department_name']; ?><optgroup label="<?= e($dept) ?>"><?php endif; ?>
                    <option value="<?= (int) $l['id'] ?>"><?= e($l['name']) ?></option>
                <?php endforeach; if ($dept !== null): ?></optgroup><?php endif; ?>
            </select></div>
        <div class="col-md-2"><label class="form-label" for="type">Type</label><select class="form-select" id="type" name="type"><option value="cycle">Cycle</option><option value="full">Full</option><option value="spot">Spot check</option></select></div>
        <div class="col-md-4"><label class="form-label" for="notes">Notes</label><input class="form-control" id="notes" name="notes" maxlength="255"></div>
        <div class="col-md-2"><button type="submit" class="btn btn-primary w-100"><i class="bi bi-play-circle me-1" aria-hidden="true"></i>Start count</button></div>
    </form>
</div></div>
<?php endif; ?>
<div class="card">
    <form class="filter-bar" method="get">
        <select name="status" class="form-select" data-auto-submit aria-label="Status">
            <option value="">Any status</option>
            <?php foreach (['in_progress' => 'In progress', 'submitted' => 'Awaiting approval', 'approved' => 'Approved', 'cancelled' => 'Cancelled'] as $k => $v): ?><option value="<?= $k ?>"<?= $filters['status'] === $k ? ' selected' : '' ?>><?= e($v) ?></option><?php endforeach; ?>
        </select>
    </form>
    <?php if ($page->items): ?>
    <div class="table-responsive"><table class="table table-hover">
        <thead><tr><th scope="col">Count</th><th scope="col">Location</th><th scope="col">Type</th><th scope="col">Progress</th><th scope="col">Status</th><th scope="col">Started</th></tr></thead>
        <tbody>
        <?php foreach ($page->items as $c): $pct = (int) $c['item_count'] ? round(100 * (int) $c['counted_count'] / (int) $c['item_count']) : 100; ?>
            <tr>
                <td><a class="fw-semibold" href="<?= e(route('counts.show', ['id' => $c['id']])) ?>"><?= e($c['count_number']) ?></a></td>
                <td class="small"><?= e($c['location_name']) ?><span class="cell-sub"><?= e($c['department_name']) ?></span></td>
                <td class="small"><?= e(label($c['type'])) ?></td>
                <td style="min-width:140px"><div class="progress" role="progressbar" aria-valuenow="<?= $pct ?>" aria-valuemin="0" aria-valuemax="100" aria-label="Counted"><div class="progress-bar" style="width:<?= $pct ?>%"></div></div><span class="cell-sub"><?= (int) $c['counted_count'] ?>/<?= (int) $c['item_count'] ?> counted<?= $c['status'] !== 'in_progress' ? ' · ' . (int) $c['variance_count'] . ' variance(s)' : '' ?></span></td>
                <td><?= status_badge($c['status'] === 'in_progress' ? 'draft' : $c['status']) ?></td>
                <td class="small text-nowrap"><?= e(time_ago($c['created_at'])) ?><span class="cell-sub"><?= e($c['started_by_name']) ?></span></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
    <?php else: ?>
        <?= partial('partials/empty', ['icon' => 'bi-list-check', 'title' => 'No stock counts yet']) ?>
    <?php endif; ?>
    <?= partial('partials/pagination', ['page' => $page]) ?>
</div>
