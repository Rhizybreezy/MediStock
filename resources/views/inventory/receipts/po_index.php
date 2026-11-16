<div class="page-header">
    <div>
        <nav aria-label="breadcrumb"><ol class="breadcrumb"><li class="breadcrumb-item">Procurement</li><li class="breadcrumb-item active" aria-current="page">Receive against PO</li></ol></nav>
        <h1>Receive against purchase order</h1>
        <div class="subtitle">Choose the purchase order the delivery belongs to. Only approved purchase orders can be received.</div>
    </div>
</div>
<div class="card">
    <?php if ($orders): ?>
    <div class="table-responsive"><table class="table table-hover">
        <thead><tr><th scope="col">PO</th><th scope="col">Supplier</th><th scope="col">Expected</th><th scope="col">Status</th><th scope="col" class="table-actions"><span class="visually-hidden">Receive</span></th></tr></thead>
        <tbody><?php foreach ($orders as $o): ?>
            <tr><td><a href="<?= e(route('purchase_orders.show', ['id' => $o['id']])) ?>" class="fw-semibold"><?= e($o['po_number']) ?></a></td><td><?= e($o['supplier_name']) ?></td><td class="<?= (int) $o['is_late'] ? 'text-danger fw-semibold' : '' ?>"><?= e(format_date($o['expected_delivery_date'])) ?></td><td><?= status_badge($o['status'] === 'partially_received' ? 'pending' : 'approved') ?> <span class="small"><?= e(label($o['status'])) ?></span></td><td class="table-actions"><a class="btn btn-sm btn-primary" href="<?= e(route('inventory.receipts.po.create', ['id' => $o['id']])) ?>">Receive</a></td></tr>
        <?php endforeach; ?></tbody>
    </table></div>
    <?php else: ?><?= partial('partials/empty', ['icon' => 'bi-truck', 'title' => 'No deliveries expected', 'text' => 'Approved purchase orders awaiting delivery appear here.']) ?><?php endif; ?>
</div>
