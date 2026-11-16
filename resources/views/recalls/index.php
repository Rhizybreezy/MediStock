<?php /** @var App\Core\Paginator $page */ $sev = ['low' => 'secondary', 'medium' => 'info', 'high' => 'warning', 'critical' => 'danger']; ?>
<div class="page-header">
    <div>
        <nav aria-label="breadcrumb"><ol class="breadcrumb"><li class="breadcrumb-item">Departments</li><li class="breadcrumb-item active" aria-current="page">Recalls</li></ol></nav>
        <h1>Batch recalls</h1>
        <div class="subtitle">Recalled batches are blocked from issue immediately; trace where they went and quarantine remaining stock.</div>
    </div>
    <?php if (can('recalls.manage')): ?><a href="<?= e(route('recalls.create')) ?>" class="btn btn-danger"><i class="bi bi-exclamation-octagon me-1" aria-hidden="true"></i>Start recall</a><?php endif; ?>
</div>
<div class="card">
    <form class="filter-bar" method="get"><select name="status" class="form-select" data-auto-submit aria-label="Status"><option value="">Any status</option><?php foreach (['open', 'in_progress', 'closed'] as $s): ?><option value="<?= $s ?>"<?= $filters['status'] === $s ? ' selected' : '' ?>><?= e(label($s)) ?></option><?php endforeach; ?></select></form>
    <?php if ($page->items): ?>
    <div class="table-responsive"><table class="table table-hover">
        <thead><tr><th scope="col">Recall</th><th scope="col">Product / batch</th><th scope="col">Severity</th><th scope="col">Source</th><th scope="col" class="num">Not yet quarantined</th><th scope="col">Status</th><th scope="col">Started</th></tr></thead>
        <tbody>
        <?php foreach ($page->items as $r): ?>
            <tr>
                <td><a class="fw-semibold" href="<?= e(route('recalls.show', ['id' => $r['id']])) ?>"><?= e($r['recall_number']) ?></a></td>
                <td><?= e($r['product_name']) ?><span class="cell-sub"><?= e($r['batch_code']) ?> · lot <?= e($r['lot_number']) ?></span></td>
                <td><span class="badge text-bg-<?= $sev[$r['severity']] ?> badge-status"><?= e(label($r['severity'])) ?></span></td>
                <td class="small"><?= e(label($r['source'])) ?></td>
                <td class="num <?= (float) $r['unquarantined_qty'] > 0 && $r['status'] !== 'closed' ? 'text-danger fw-semibold' : '' ?>"><?= e(qty($r['unquarantined_qty'])) ?></td>
                <td><?= status_badge($r['status'] === 'closed' ? 'posted' : 'pending') ?> <span class="small"><?= e(label($r['status'])) ?></span></td>
                <td class="small"><?= e(format_date($r['created_at'])) ?><span class="cell-sub"><?= e($r['initiated_by_name']) ?></span></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
    <?php else: ?>
        <?= partial('partials/empty', ['icon' => 'bi-shield-check', 'title' => 'No recalls']) ?>
    <?php endif; ?>
    <?= partial('partials/pagination', ['page' => $page]) ?>
</div>
