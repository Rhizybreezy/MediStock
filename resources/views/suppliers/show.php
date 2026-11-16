<?php $s = $supplier; ?>
<div class="page-header">
    <div>
        <nav aria-label="breadcrumb"><ol class="breadcrumb"><li class="breadcrumb-item"><a href="<?= e(route('suppliers.index')) ?>">Suppliers</a></li><li class="breadcrumb-item active" aria-current="page"><?= e($s['supplier_code']) ?></li></ol></nav>
        <h1><?= e($s['name']) ?></h1>
        <div class="subtitle"><span class="code-chip"><?= e($s['supplier_code']) ?></span> · <?= status_badge($s['status'] === 'blacklisted' ? 'rejected' : $s['status']) ?> <?= $s['status'] === 'blacklisted' ? '<span class="small text-danger">Blacklisted</span>' : '' ?></div>
    </div>
    <div class="d-flex gap-2">
        <?php if (can('purchase_orders.manage') && $s['status'] === 'active'): ?><a href="<?= e(route('purchase_orders.create', [], ['supplier_id' => $s['id']])) ?>" class="btn btn-outline-secondary"><i class="bi bi-file-earmark-plus me-1" aria-hidden="true"></i>New PO</a><?php endif; ?>
        <?php if ($canManage): ?><a href="<?= e(route('suppliers.edit', ['id' => $s['id']])) ?>" class="btn btn-primary"><i class="bi bi-pencil me-1" aria-hidden="true"></i>Edit</a><?php endif; ?>
    </div>
</div>
<?php if ($metrics): ?><div class="mb-3"><?= partial('suppliers/_metrics', ['m' => $metrics]) ?></div><?php endif; ?>
<div class="row g-3">
    <div class="col-xl-4">
        <div class="card mb-3"><div class="card-header"><h2 class="card-title">Details</h2></div><div class="card-body">
            <dl class="dl-grid">
                <dt>Contact</dt><dd><?= e($s['contact_person'] ?? '—') ?></dd>
                <dt>Phone</dt><dd><?= e($s['phone'] ?? '—') ?></dd>
                <dt>Email</dt><dd><?= e($s['email'] ?? '—') ?></dd>
                <dt>Address</dt><dd><?= e($s['address'] ?? '—') ?></dd>
                <dt>Tax no.</dt><dd><?= e($s['tax_number'] ?? '—') ?></dd>
                <dt>Payment terms</dt><dd><?= e($s['payment_terms'] ?? '—') ?></dd>
                <dt>Lead time</dt><dd><?= (int) $s['default_lead_time_days'] ?> days</dd>
                <?php if ($s['notes']): ?><dt>Notes</dt><dd><?= nl2br(e($s['notes'])) ?></dd><?php endif; ?>
            </dl>
        </div></div>
        <div class="card"><div class="card-header"><h2 class="card-title">Recent purchase orders</h2></div>
            <?php if ($orders): ?><ul class="list-group list-group-flush list-compact">
                <?php foreach (array_slice($orders, 0, 8) as $o): ?><li class="list-group-item d-flex justify-content-between"><span><a href="<?= e(route('purchase_orders.show', ['id' => $o['id']])) ?>"><?= e($o['po_number']) ?></a><span class="cell-sub"><?= e(format_date($o['order_date'] ?? $o['created_at'])) ?></span></span><span><?= status_badge($o['status']) ?></span></li><?php endforeach; ?>
            </ul><?php else: ?><?= partial('partials/empty', ['icon' => 'bi-file-earmark-text', 'title' => 'No purchase orders yet']) ?><?php endif; ?>
        </div>
    </div>
    <div class="col-xl-8">
        <div class="card mb-3">
            <div class="card-header"><h2 class="card-title">Products &amp; quotes</h2><span class="small text-muted">Prices per base unit</span></div>
            <?php if ($products): ?>
            <div class="table-responsive"><table class="table table-hover">
                <thead><tr><th scope="col">Product</th><th scope="col">Supplier SKU</th><th scope="col" class="num">Quoted price</th><th scope="col">Valid until</th><th scope="col" class="num">Lead time</th><th scope="col">Preferred</th><?php if ($canManage): ?><th scope="col"><span class="visually-hidden">Remove</span></th><?php endif; ?></tr></thead>
                <tbody>
                <?php foreach ($products as $p): ?>
                    <tr>
                        <td><a href="<?= e(route('products.show', ['id' => $p['product_id']])) ?>"><?= e($p['product_name']) ?></a><span class="cell-sub"><?= e($p['product_code']) ?></span></td>
                        <td class="small"><?= e($p['supplier_sku'] ?? '—') ?></td>
                        <td class="num"><?= $p['quoted_unit_price'] !== null ? e(number_format((float) $p['quoted_unit_price'], 4)) . ' <span class="small text-muted">/' . e($p['unit_code']) . '</span>' : '—' ?></td>
                        <td class="small <?= $p['quote_valid_until'] && $p['quote_valid_until'] < date('Y-m-d') ? 'text-danger' : '' ?>"><?= e(format_date($p['quote_valid_until'])) ?></td>
                        <td class="num small"><?= $p['lead_time_days'] !== null ? (int) $p['lead_time_days'] . ' d' : '—' ?></td>
                        <td><?= (int) $p['is_preferred'] === 1 ? '<span class="badge text-bg-primary">Preferred</span>' : '' ?></td>
                        <?php if ($canManage): ?><td class="text-end"><form method="post" action="<?= e(route('suppliers.products.remove', ['id' => $s['id'], 'productId' => $p['product_id']])) ?>" data-confirm="Remove this product from the supplier?" data-confirm-button="Remove" data-confirm-variant="danger"><?= csrf_field() ?><button type="submit" class="btn btn-sm btn-link text-danger" aria-label="Remove"><i class="bi bi-x-lg" aria-hidden="true"></i></button></form></td><?php endif; ?>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
            <?php else: ?><?= partial('partials/empty', ['icon' => 'bi-capsule', 'title' => 'No products linked yet']) ?><?php endif; ?>
            <?php if ($canManage): ?>
            <div class="card-body border-top">
                <form method="post" action="<?= e(route('suppliers.products.save', ['id' => $s['id']])) ?>" class="row g-2 align-items-end" id="quote-form" novalidate>
                    <?= csrf_field() ?>
                    <div class="col-md-4"><label class="form-label" for="quote-product">Product</label><div class="picker" data-picker><input type="search" id="quote-product" class="form-control form-control-sm" placeholder="Search product…" autocomplete="off"><input type="hidden" name="product_id"></div></div>
                    <div class="col-md-2"><label class="form-label" for="quoted_unit_price">Price / base unit</label><input type="number" min="0" step="any" class="form-control form-control-sm" id="quoted_unit_price" name="quoted_unit_price"></div>
                    <div class="col-md-2"><label class="form-label" for="lead_time_days">Lead (days)</label><input type="number" min="0" max="365" class="form-control form-control-sm" id="lead_time_days" name="lead_time_days"></div>
                    <div class="col-md-2"><label class="form-label" for="quote_valid_until">Valid until</label><input type="date" class="form-control form-control-sm" id="quote_valid_until" name="quote_valid_until"></div>
                    <div class="col-md-2"><label class="form-label" for="supplier_sku">SKU</label><input class="form-control form-control-sm" id="supplier_sku" name="supplier_sku" maxlength="60"></div>
                    <div class="col-md-8"><div class="form-check"><input class="form-check-input" type="checkbox" id="is_preferred" name="is_preferred" value="1"><label class="form-check-label small" for="is_preferred">Preferred supplier for this product (used for reorder suggestions)</label></div></div>
                    <div class="col-md-4 text-end"><button type="submit" class="btn btn-sm btn-primary">Add / update quote</button></div>
                </form>
            </div>
            <?php endif; ?>
        </div>
        <?php if ($prices): ?>
        <div class="card"><div class="card-header"><h2 class="card-title">Price history</h2><span class="small text-muted">Prices actually ordered</span></div>
            <div class="table-responsive"><table class="table table-sm table-hover">
                <thead><tr><th scope="col">Date</th><th scope="col">PO</th><th scope="col">Product</th><th scope="col" class="num">Order price</th><th scope="col" class="num">Per base unit</th></tr></thead>
                <tbody><?php foreach ($prices as $pr): ?><tr><td class="small"><?= e(format_date($pr['order_date'])) ?></td><td class="small"><a href="<?= e(route('purchase_orders.show', ['id' => $pr['po_id']])) ?>"><?= e($pr['po_number']) ?></a></td><td class="small"><?= e($pr['product_name']) ?></td><td class="num small"><?= e(number_format((float) $pr['unit_price'], 2)) ?> / <?= e($pr['order_unit']) ?></td><td class="num small"><?= e(number_format((float) $pr['base_price'], 4)) ?> / <?= e($pr['unit_code']) ?></td></tr><?php endforeach; ?></tbody>
            </table></div>
        </div>
        <?php endif; ?>
    </div>
</div>
