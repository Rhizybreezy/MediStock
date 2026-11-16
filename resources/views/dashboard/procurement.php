<?php
/**
 * Procurement officer dashboard: suppliers, purchase requests, purchase orders and deliveries.
 * @var array $m          RoleDashboardService::procurement()
 * @var bool $showValues
 * @var ?array $scopeNames
 * @var string[] $aiPrompts
 */
$amount = fn (array $x) => $showValues ? money($x['value'], false) : qty($x['qty']) . ' units';
$poStatus = ['draft' => ['Draft', 'secondary'], 'pending_approval' => ['Awaiting approval', 'warning'], 'approved' => ['Approved', 'primary'], 'sent' => ['Sent', 'info'], 'partially_received' => ['Part received', 'info']];
?>
<?= partial('dashboard/partials/header', ['role' => 'Procurement Officer', 'scopeNames' => $scopeNames, 'actions' => $m['actions']]) ?>

<div class="row g-3 mb-3">
    <?php if ($m['low_stock_suggestions'] !== null): ?>
        <?= partial('dashboard/partials/stat', ['label' => 'Items to reorder', 'value' => number_format($m['low_stock_suggestions']), 'meta' => 'At or below reorder level', 'icon' => 'bi-lightbulb', 'tone' => $m['low_stock_suggestions'] ? 'warning' : 'success', 'url' => route('purchase_requests.low_stock')]) ?>
    <?php endif; ?>
    <?= partial('dashboard/partials/stat', ['label' => 'Approved requests to order', 'value' => number_format($m['prs_to_order']), 'meta' => 'Turn them into purchase orders', 'icon' => 'bi-cart-check', 'tone' => 'primary', 'url' => route('purchase_orders.from_requests')]) ?>
    <?= partial('dashboard/partials/stat', ['label' => 'Draft / awaiting approval', 'value' => number_format($m['pos_draft']) . ' / ' . number_format($m['pos_pending']), 'meta' => 'Purchase orders', 'icon' => 'bi-file-earmark-text', 'tone' => 'neutral', 'url' => route('purchase_orders.index', [], ['status' => 'draft'])]) ?>
    <?= partial('dashboard/partials/stat', ['label' => 'Open deliveries', 'value' => number_format($m['pos_open']), 'meta' => $m['pos_late'] ? $m['pos_late'] . ' overdue' : 'None overdue', 'icon' => 'bi-truck', 'tone' => $m['pos_late'] ? 'danger' : 'info', 'url' => route('purchase_orders.index', [], ['status' => $m['pos_late'] ? 'late' : 'to_receive'])]) ?>
    <?= partial('dashboard/partials/stat', ['label' => 'Received this month', 'value' => $amount($m['spend_month']), 'meta' => 'Last month: ' . $amount($m['spend_last']), 'icon' => 'bi-receipt', 'tone' => 'success']) ?>
</div>

<div class="row g-3 mb-3">
    <div class="col-xl-8">
        <div class="card h-100">
            <div class="card-header">
                <h3 class="card-title"><i class="bi bi-file-earmark-text me-1" aria-hidden="true"></i>Open purchase orders</h3>
                <a class="small" href="<?= e(route('purchase_orders.index', [], ['status' => 'open'])) ?>">All open <i class="bi bi-arrow-right" aria-hidden="true"></i></a>
            </div>
            <?php if ($m['open_pos']): ?>
            <div class="table-responsive">
                <table class="table table-sm table-hover mb-0">
                    <thead><tr><th scope="col">PO</th><th scope="col">Supplier</th><th scope="col">Status</th><th scope="col">Expected</th><?php if ($showValues): ?><th scope="col" class="num">Total</th><?php endif; ?></tr></thead>
                    <tbody>
                    <?php foreach ($m['open_pos'] as $po): [$st, $tone] = $poStatus[$po['status']] ?? [label($po['status']), 'secondary']; ?>
                        <tr>
                            <td><a href="<?= e(route('purchase_orders.show', ['id' => (int) $po['id']])) ?>"><?= e($po['po_number']) ?></a></td>
                            <td><?= e($po['supplier_name']) ?></td>
                            <td><span class="badge text-bg-<?= e($tone) ?> badge-status"><?= e($st) ?></span></td>
                            <td class="small<?= (int) $po['is_late'] ? ' text-danger fw-semibold' : '' ?>"><?= $po['expected_delivery_date'] ? e(format_date($po['expected_delivery_date'])) . ((int) $po['is_late'] ? ' · overdue' : '') : '—' ?></td>
                            <?php if ($showValues): ?><td class="num"><?= e(money($po['total_amount'], false)) ?></td><?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php else: ?>
                <?= partial('partials/empty', ['icon' => 'bi-file-earmark-text', 'title' => 'No open purchase orders']) ?>
            <?php endif; ?>
        </div>
    </div>
    <div class="col-xl-4"><?= partial('dashboard/partials/ai', ['prompts' => $aiPrompts]) ?></div>
</div>

<div class="row g-3">
    <div class="col-xl-6">
        <div class="card h-100">
            <div class="card-header">
                <h3 class="card-title"><i class="bi bi-cart-check me-1" aria-hidden="true"></i>Approved requests waiting for a PO</h3>
                <a class="small" href="<?= e(route('purchase_requests.index', [], ['status' => 'approved'])) ?>">All <i class="bi bi-arrow-right" aria-hidden="true"></i></a>
            </div>
            <?php if ($m['prs_waiting']): ?>
            <div class="table-responsive">
                <table class="table table-sm table-hover mb-0">
                    <thead><tr><th scope="col">PR</th><th scope="col">Department</th><th scope="col">Priority</th><th scope="col" class="num">Lines</th><th scope="col">Approved</th></tr></thead>
                    <tbody>
                    <?php foreach ($m['prs_waiting'] as $pr): ?>
                        <tr>
                            <td><a href="<?= e(route('purchase_requests.show', ['id' => (int) $pr['id']])) ?>"><?= e($pr['pr_number']) ?></a></td>
                            <td class="small"><?= e($pr['department_name']) ?></td>
                            <td><span class="badge <?= $pr['priority'] === 'routine' ? 'text-bg-light border' : 'text-bg-danger' ?>"><?= e(label($pr['priority'])) ?></span></td>
                            <td class="num"><?= (int) $pr['line_count'] ?></td>
                            <td class="small"><?= $pr['approved_at'] ? e(time_ago($pr['approved_at'])) : '—' ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php else: ?>
                <?= partial('partials/empty', ['icon' => 'bi-check2-circle', 'title' => 'Nothing waiting to be ordered']) ?>
            <?php endif; ?>
        </div>
    </div>
    <div class="col-xl-6">
        <div class="card h-100">
            <div class="card-header">
                <h3 class="card-title"><i class="bi bi-truck-front me-1" aria-hidden="true"></i>Top suppliers (12 months)</h3>
                <a class="small" href="<?= e(route('suppliers.performance')) ?>">Performance <i class="bi bi-arrow-right" aria-hidden="true"></i></a>
            </div>
            <?php if ($m['suppliers']): ?>
            <div class="table-responsive">
                <table class="table table-sm table-hover mb-0">
                    <thead><tr><th scope="col">Supplier</th><th scope="col" class="num">On time</th><th scope="col" class="num">Fill rate</th><th scope="col" class="num">Rating</th></tr></thead>
                    <tbody>
                    <?php foreach ($m['suppliers'] as $s): ?>
                        <tr>
                            <td><a href="<?= e(route('suppliers.show', ['id' => (int) $s['id']])) ?>"><?= e($s['name']) ?></a><span class="cell-sub"><?= (int) $s['deliveries'] ?> deliveries</span></td>
                            <td class="num"><?= $s['on_time_rate'] !== null ? e(number_format($s['on_time_rate'] * 100)) . ' %' : '—' ?></td>
                            <td class="num"><?= $s['fill_rate'] !== null ? e(number_format($s['fill_rate'] * 100)) . ' %' : '—' ?></td>
                            <td class="num fw-semibold"><?= $s['rating'] !== null ? e(number_format($s['rating'], 1)) . ' / 5' : '—' ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php else: ?>
                <?= partial('partials/empty', ['icon' => 'bi-truck-front', 'title' => 'No deliveries recorded yet', 'text' => 'Ratings appear once goods are received against purchase orders.']) ?>
            <?php endif; ?>
        </div>
    </div>
</div>
