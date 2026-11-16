<?php
/**
 * Building blocks for the department dashboards (pharmacist and storekeeper).
 * @var string $block      which block to render
 * @var array  $m          RoleDashboardService::department()
 * @var bool   $showValues
 */
$reqStatus = ['draft' => ['Draft', 'secondary'], 'submitted' => ['Waiting for review', 'warning'], 'approved' => ['Approved', 'primary'], 'partially_approved' => ['Part approved', 'primary'], 'partially_issued' => ['Part issued', 'info'], 'issued' => ['Issued · confirm receipt', 'info']];
switch ($block):
case 'incoming': ?>
<div class="card h-100">
    <div class="card-header">
        <h3 class="card-title"><i class="bi bi-box-seam me-1" aria-hidden="true"></i>Deliveries to confirm</h3>
        <?php if (can('stock.confirm_receipt')): ?><a class="small" href="<?= e(route('inventory.incoming')) ?>">Incoming <i class="bi bi-arrow-right" aria-hidden="true"></i></a><?php endif; ?>
    </div>
    <?php if ($m['incoming']): ?>
    <div class="list-group list-group-flush">
        <?php foreach ($m['incoming'] as $t): ?>
            <a class="list-group-item list-group-item-action" href="<?= e(route('inventory.issues.show', ['id' => (int) $t['id']])) ?>">
                <div class="d-flex justify-content-between gap-2"><strong><?= e($t['transfer_number']) ?></strong><span class="small text-muted"><?= $t['dispatched_at'] ? e(time_ago($t['dispatched_at'])) : '' ?></span></div>
                <div class="small text-muted">From <?= e($t['source_department']) ?> → <?= e($t['destination_location']) ?> · <?= (int) $t['line_count'] ?> item<?= (int) $t['line_count'] === 1 ? '' : 's' ?><?= $t['status'] === 'partially_received' ? ' · partly received' : '' ?></div>
            </a>
        <?php endforeach; ?>
    </div>
    <div class="card-body py-2 small text-muted border-top">Stock is not usable in your department until you confirm what arrived.</div>
    <?php else: ?>
        <?= partial('partials/empty', ['icon' => 'bi-check2-circle', 'title' => 'Nothing waiting', 'text' => 'Deliveries sent to your department appear here.']) ?>
    <?php endif; ?>
</div>
<?php break;
case 'requests': ?>
<div class="card h-100">
    <div class="card-header">
        <h3 class="card-title"><i class="bi bi-clipboard-check me-1" aria-hidden="true"></i>My department's requests</h3>
        <a class="small" href="<?= e(route('requests.index', [], ['status' => 'open'])) ?>">All <i class="bi bi-arrow-right" aria-hidden="true"></i></a>
    </div>
    <?php if ($m['requests']): ?>
    <div class="table-responsive">
        <table class="table table-sm table-hover mb-0">
            <thead><tr><th scope="col">Request</th><th scope="col">Status</th><th scope="col">Priority</th><th scope="col">Created</th></tr></thead>
            <tbody>
            <?php foreach ($m['requests'] as $r): [$st, $tone] = $reqStatus[$r['status']] ?? [label($r['status']), 'secondary']; ?>
                <tr>
                    <td><a href="<?= e(route('requests.show', ['id' => (int) $r['id']])) ?>"><?= e($r['request_number']) ?></a><span class="cell-sub"><?= e($r['department_name']) ?></span></td>
                    <td><span class="badge text-bg-<?= e($tone) ?> badge-status"><?= e($st) ?></span></td>
                    <td><span class="badge <?= $r['priority'] === 'routine' ? 'text-bg-light border' : 'text-bg-danger' ?>"><?= e(label($r['priority'])) ?></span></td>
                    <td class="small"><?= e(time_ago($r['created_at'])) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php else: ?>
        <?= partial('partials/empty', ['icon' => 'bi-clipboard', 'title' => 'No open requests', 'text' => can('requests.create') ? 'Use “Request stock” to ask the store for supplies.' : '']) ?>
    <?php endif; ?>
</div>
<?php break;
case 'low': ?>
<div class="card h-100">
    <div class="card-header">
        <h3 class="card-title"><i class="bi bi-arrow-down-circle me-1 text-warning" aria-hidden="true"></i>Running low</h3>
        <a class="small" href="<?= e(route('departments.index')) ?>">Department stock <i class="bi bi-arrow-right" aria-hidden="true"></i></a>
    </div>
    <?php if ($m['low_items']): ?>
    <div class="table-responsive">
        <table class="table table-sm table-hover mb-0">
            <thead><tr><th scope="col">Product</th><th scope="col" class="num">Usable</th><th scope="col" class="num">Reorder at</th></tr></thead>
            <tbody>
            <?php foreach ($m['low_items'] as $l): ?>
                <tr>
                    <td><a href="<?= e(route('products.show', ['id' => (int) $l['product_id']])) ?>"><?= e($l['name']) ?></a><span class="cell-sub"><?= e($l['department_name']) ?></span></td>
                    <td class="num fw-semibold <?= (float) $l['usable_qty'] <= 0 ? 'text-danger' : 'text-warning-emphasis' ?>"><?= e(qty($l['usable_qty'])) ?> <span class="small text-muted"><?= e($l['unit']) ?></span></td>
                    <td class="num small"><?= e(qty($l['reorder_level'])) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php else: ?>
        <?= partial('partials/empty', ['icon' => 'bi-check2-circle', 'title' => 'Nothing below its reorder level', 'text' => 'Levels are set per department under Department stock.']) ?>
    <?php endif; ?>
</div>
<?php break;
case 'expiring': ?>
<div class="card h-100">
    <div class="card-header">
        <h3 class="card-title"><i class="bi bi-hourglass-split me-1 text-warning" aria-hidden="true"></i>Expiring within <?= (int) $m['expiry_days'] ?> days</h3>
        <a class="small" href="<?= e(route('inventory.batches.index', [], ['expiry' => '90'])) ?>">Batches <i class="bi bi-arrow-right" aria-hidden="true"></i></a>
    </div>
    <?php if ($m['expiring']): ?>
    <div class="table-responsive">
        <table class="table table-sm table-hover mb-0">
            <thead><tr><th scope="col">Product · batch</th><th scope="col">Location</th><th scope="col" class="num">Qty</th><th scope="col">Expiry</th></tr></thead>
            <tbody>
            <?php foreach ($m['expiring'] as $x): ?>
                <tr>
                    <td><?= e($x['product_name']) ?><span class="cell-sub"><a href="<?= e(route('inventory.batches.show', ['id' => (int) $x['batch_id']])) ?>" class="code-chip"><?= e($x['batch_code']) ?></a></span></td>
                    <td class="small"><?= e($x['location_name']) ?></td>
                    <td class="num"><?= e(qty($x['qty'])) ?> <span class="small text-muted"><?= e($x['unit']) ?></span></td>
                    <td class="small"><?= expiry_badge($x['expiry_date']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <div class="card-body py-2 small text-muted border-top">Use these first (FEFO), or return them to store if you will not use them in time.</div>
    <?php else: ?>
        <?= partial('partials/empty', ['icon' => 'bi-check2-circle', 'title' => 'Nothing expiring soon']) ?>
    <?php endif; ?>
</div>
<?php break;
case 'cold': ?>
<?php if ($m['cold_chain']): ?>
<div class="card h-100">
    <div class="card-header">
        <h3 class="card-title"><i class="bi bi-thermometer-snow me-1 text-info" aria-hidden="true"></i>Cold chain</h3>
        <?php if (can('temperature.record')): ?><a class="small" href="<?= e(route('cold_chain.index')) ?>">Record reading <i class="bi bi-arrow-right" aria-hidden="true"></i></a><?php endif; ?>
    </div>
    <ul class="list-group list-group-flush">
        <?php foreach ($m['cold_chain'] as $c): $bad = (int) $c['is_out_of_range'] === 1; $late = (int) $c['is_overdue'] === 1; ?>
            <li class="list-group-item d-flex justify-content-between align-items-center gap-2">
                <div>
                    <div><?= e($c['name']) ?></div>
                    <div class="small text-muted">Range <?= e(rtrim(rtrim((string) $c['min_temp_c'], '0'), '.')) ?>–<?= e(rtrim(rtrim((string) $c['max_temp_c'], '0'), '.')) ?> °C<?= $c['recorded_at'] ? ' · ' . e(time_ago($c['recorded_at'])) : '' ?></div>
                </div>
                <div class="text-end">
                    <?php if ($c['temperature_c'] === null): ?>
                        <span class="badge text-bg-warning">No readings</span>
                    <?php else: ?>
                        <span class="fs-5 fw-semibold <?= $bad ? 'text-danger' : 'text-success' ?>"><?= e(number_format((float) $c['temperature_c'], 1)) ?> °C</span>
                        <?php if ($late): ?><div><span class="badge text-bg-warning">Reading due</span></div><?php endif; ?>
                    <?php endif; ?>
                </div>
            </li>
        <?php endforeach; ?>
    </ul>
</div>
<?php endif; ?>
<?php break;
endswitch;
