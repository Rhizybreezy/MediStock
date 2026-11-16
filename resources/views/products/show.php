<?php
$p = $product;
$totalUsable = array_sum(array_map(fn ($l) => (float) $l['usable'], $locations));
$totalOnHand = array_sum(array_map(fn ($l) => (float) $l['on_hand'], $locations));
?>
<div class="page-header">
    <div>
        <nav aria-label="breadcrumb"><ol class="breadcrumb"><li class="breadcrumb-item"><a href="<?= e(route('products.index')) ?>">Products</a></li><li class="breadcrumb-item active" aria-current="page"><?= e($p['product_code']) ?></li></ol></nav>
        <h1><?= e($p['name']) ?></h1>
        <div class="subtitle">
            <span class="code-chip"><?= e($p['product_code']) ?></span> · <?= e($p['category_name']) ?>
            <?php if ((int) $p['is_active'] !== 1): ?> · <?= status_badge('inactive') ?><?php endif; ?>
            <?php if ((int) $p['is_critical'] === 1): ?> <span class="badge text-bg-danger">Critical</span><?php endif; ?>
            <?php if ((int) $p['is_controlled'] === 1): ?> <span class="badge text-bg-secondary"><i class="bi bi-lock" aria-hidden="true"></i> Controlled</span><?php endif; ?>
        </div>
    </div>
    <div class="d-flex gap-2 flex-wrap">
        <?php if (can('codes.print')): ?><a href="<?= e(route('codes.labels', [], ['products' => $p['id']])) ?>" class="btn btn-outline-secondary" target="_blank" rel="noopener"><i class="bi bi-printer me-1" aria-hidden="true"></i>Labels</a><?php endif; ?>
        <?php if (can('goods_receipts.direct')): ?><a href="<?= e(route('inventory.receipts.create', [], ['scan' => 'MSP:' . $p['product_code']])) ?>" class="btn btn-outline-secondary"><i class="bi bi-box-arrow-in-down me-1" aria-hidden="true"></i>Receive</a><?php endif; ?>
        <?php if (can('stock.issue')): ?><a href="<?= e(route('inventory.issues.create', [], ['scan' => 'MSP:' . $p['product_code']])) ?>" class="btn btn-outline-secondary"><i class="bi bi-box-arrow-up-right me-1" aria-hidden="true"></i>Issue</a><?php endif; ?>
        <?php if (can('products.manage')): ?>
            <a href="<?= e(route('products.edit', ['id' => $p['id']])) ?>" class="btn btn-primary"><i class="bi bi-pencil me-1" aria-hidden="true"></i>Edit</a>
            <form method="post" action="<?= e(route('products.toggle', ['id' => $p['id']])) ?>" <?= (int) $p['is_active'] === 1 ? 'data-confirm="Deactivate this product? It will no longer be available for receiving or issuing. Existing stock and history are kept." data-confirm-button="Deactivate" data-confirm-variant="danger"' : '' ?>>
                <?= csrf_field() ?>
                <button type="submit" class="btn <?= (int) $p['is_active'] === 1 ? 'btn-outline-danger' : 'btn-outline-primary' ?>"><?= (int) $p['is_active'] === 1 ? 'Deactivate' : 'Activate' ?></button>
            </form>
        <?php endif; ?>
    </div>
</div>

<?php if ($canStock): ?>
<div class="row g-3 mb-3">
    <div class="col-6 col-lg-3"><div class="card stat-card"><div class="stat-icon tone-primary"><i class="bi bi-boxes" aria-hidden="true"></i></div><div><div class="stat-label">Usable stock</div><div class="stat-value"><?= e(qty($totalUsable)) ?></div><div class="stat-meta"><?= e($p['unit_name']) ?></div></div></div></div>
    <div class="col-6 col-lg-3"><div class="card stat-card"><div class="stat-icon tone-neutral"><i class="bi bi-stack" aria-hidden="true"></i></div><div><div class="stat-label">Total on hand</div><div class="stat-value"><?= e(qty($totalOnHand)) ?></div><div class="stat-meta">incl. transit / expired</div></div></div></div>
    <div class="col-6 col-lg-3"><div class="card stat-card"><div class="stat-icon tone-warning"><i class="bi bi-arrow-repeat" aria-hidden="true"></i></div><div><div class="stat-label">Reorder level</div><div class="stat-value"><?= e(qty($p['reorder_level'])) ?></div><div class="stat-meta">min <?= e(qty($p['min_level'])) ?> · max <?= (float) $p['max_level'] > 0 ? e(qty($p['max_level'])) : '—' ?></div></div></div></div>
    <div class="col-6 col-lg-3"><div class="card stat-card"><div class="stat-icon tone-info"><i class="bi bi-activity" aria-hidden="true"></i></div><div><div class="stat-label">Status</div><div class="stat-value fs-6 mt-1"><?= stock_level_badge($totalUsable, (float) $p['reorder_level']) ?></div><div class="stat-meta"><?= count($batches) ?> batch(es) in stock</div></div></div></div>
</div>
<?php endif; ?>

<?php if (!empty($forecast) && $forecast['adc'] !== null): $f = $forecast; ?>
<div class="alert <?= in_array($f['urgency'], ['critical', 'high'], true) ? 'alert-warning' : 'alert-light border' ?> d-flex flex-wrap gap-3 align-items-center small">
    <i class="bi bi-graph-down-arrow fs-5" aria-hidden="true"></i>
    <span><strong>Forecast (estimate):</strong> using about <?= e(number_format((float) $f['adc'], 1)) ?> <?= e($f['unit_code']) ?>/day<?= $f['days_remaining'] !== null ? ', ≈' . e(number_format($f['days_remaining'], 0)) . ' days of stock left' : '' ?><?= $f['reorder_qty'] > 0 ? '; suggested reorder ' . e(qty($f['reorder_qty'])) : '' ?>.</span>
    <a class="ms-auto" href="<?= e(route('intelligence.stockout.product', ['id' => $p['id']])) ?>">How this was estimated <i class="bi bi-arrow-right" aria-hidden="true"></i></a>
</div>
<?php endif; ?>
<div class="row g-3">
    <div class="col-xl-4">
        <div class="card mb-3">
            <?php if ($p['image_path']): ?>
                <img src="<?= e(route('products.image', ['id' => $p['id']])) ?>" alt="<?= e($p['name']) ?>" class="card-img-top" style="max-height:220px;object-fit:contain;background:var(--ms-surface-2)">
            <?php endif; ?>
            <div class="card-header"><h2 class="card-title">Details</h2></div>
            <div class="card-body">
                <dl class="dl-grid">
                    <dt>Generic name</dt><dd><?= e($p['generic_name'] ?: '—') ?></dd>
                    <dt>Form / strength</dt><dd><?= e(trim(($p['dosage_form'] ?? '') . ' ' . ($p['strength'] ?? '')) ?: '—') ?></dd>
                    <dt>Manufacturer</dt><dd><?= e($p['manufacturer'] ?: '—') ?></dd>
                    <dt>Base unit</dt><dd><?= e($p['unit_name']) ?> (<?= e($p['unit_code']) ?>)</dd>
                    <dt>Packs</dt><dd><?php if ($packUnits): foreach ($packUnits as $pu): ?>1 <?= e($pu['unit_name']) ?> = <?= e(qty($pu['factor_to_base'])) ?> <?= e($p['unit_code']) ?><?= $pu['gtin_barcode'] ? ' <span class="code-chip">' . e($pu['gtin_barcode']) . '</span>' : '' ?><br><?php endforeach; else: ?>—<?php endif; ?></dd>
                    <dt>Storage</dt><dd><?= e($storage[$p['storage_condition']] ?? $p['storage_condition']) ?><?= $p['storage_notes'] ? '<br><span class="small text-muted">' . e($p['storage_notes']) . '</span>' : '' ?></dd>
                    <dt>Tracking</dt><dd class="small"><?= (int) $p['is_batch_tracked'] ? 'Lot numbers' : 'No lot numbers' ?> · <?= (int) $p['is_expiry_tracked'] ? 'Expiry dates' : 'No expiry' ?></dd>
                    <dt>Lead time</dt><dd><?= $p['default_lead_time_days'] !== null ? (int) $p['default_lead_time_days'] . ' days' : 'System default' ?></dd>
                    <dt>Product label</dt><dd><img src="<?= e(route('codes.product', ['id' => $p['id']])) ?>" alt="QR code MSP:<?= e($p['product_code']) ?>" width="96" height="96" style="background:#fff;border-radius:4px"><br><code class="small">MSP:<?= e($p['product_code']) ?></code></dd>
                    <dt>Registered</dt><dd class="small"><?= e(format_date($p['created_at'])) ?><?= $p['created_by_name'] ? ' by ' . e($p['created_by_name']) : '' ?></dd>
                </dl>
                <?php if ($p['description']): ?><p class="small mt-3 mb-0"><?= nl2br(e($p['description'])) ?></p><?php endif; ?>
            </div>
        </div>
    </div>
    <div class="col-xl-8">
        <?php if ($canStock): ?>
        <div class="card mb-3">
            <div class="card-header"><h2 class="card-title">Stock by location</h2></div>
            <?php if ($locations): ?>
            <div class="table-responsive"><table class="table table-hover">
                <thead><tr><th scope="col">Location</th><th scope="col" class="num">Usable</th><th scope="col" class="num">On hand</th><th scope="col">Next expiry</th><?php if ($showValues): ?><th scope="col" class="num">Value</th><?php endif; ?></tr></thead>
                <tbody>
                <?php foreach ($locations as $l): ?>
                    <tr>
                        <td><?= e($l['location_name']) ?><span class="cell-sub"><?= e($l['department_name']) ?><?= $l['location_type'] === 'transit' ? ' · awaiting receipt' : '' ?></span></td>
                        <td class="num"><?= e(qty($l['usable'])) ?></td>
                        <td class="num"><?= e(qty($l['on_hand'])) ?></td>
                        <td class="small"><?= $l['next_expiry'] ? expiry_badge($l['next_expiry']) : '—' ?></td>
                        <?php if ($showValues): ?><td class="num"><?= e(money($l['value'], false)) ?></td><?php endif; ?>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
            <?php else: ?>
                <?= partial('partials/empty', ['icon' => 'bi-box', 'title' => 'No stock', 'text' => 'This product has no stock in the locations you can see.']) ?>
            <?php endif; ?>
        </div>

        <div class="card mb-3">
            <div class="card-header"><h2 class="card-title">Batches in stock</h2><span class="small text-muted">First-expiry-first-out order</span></div>
            <?php if ($batches): ?>
            <div class="table-responsive"><table class="table table-hover">
                <thead><tr><th scope="col">Batch</th><th scope="col">Lot</th><th scope="col">Expiry</th><th scope="col" class="num">On hand</th><th scope="col">Status</th></tr></thead>
                <tbody>
                <?php foreach ($batches as $b): ?>
                    <tr>
                        <td><a href="<?= e(route('inventory.batches.show', ['id' => $b['id']])) ?>" class="code-chip"><?= e($b['batch_code']) ?></a></td>
                        <td class="small"><?= e($b['lot_number']) ?></td>
                        <td class="small"><?= expiry_badge($b['expiry_date']) ?></td>
                        <td class="num"><?= e(qty($b['on_hand'])) ?></td>
                        <td><?= status_badge($b['status']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
            <?php else: ?>
                <?= partial('partials/empty', ['icon' => 'bi-upc', 'title' => 'No batches in stock']) ?>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <?php if (can('stock.movements.view')): ?>
        <div class="card">
            <div class="card-header"><h2 class="card-title">Recent movements</h2><a class="small" href="<?= e(route('inventory.movements', [], ['product_id' => $p['id']])) ?>">Full history <i class="bi bi-arrow-right" aria-hidden="true"></i></a></div>
            <?php if ($movements): ?>
            <div class="table-responsive"><table class="table table-sm table-hover">
                <thead><tr><th scope="col">When</th><th scope="col">Type</th><th scope="col">Batch</th><th scope="col" class="num">Qty</th><th scope="col">From → To</th><th scope="col">By</th></tr></thead>
                <tbody>
                <?php foreach ($movements as $m): ?>
                    <tr>
                        <td class="small text-nowrap"><?= e(format_date($m['created_at'], true)) ?></td>
                        <td><?= movement_badge($m['movement_type']) ?></td>
                        <td><span class="code-chip"><?= e($m['batch_code']) ?></span></td>
                        <td class="num"><?= e(qty($m['quantity'])) ?></td>
                        <td class="small"><?= e($m['from_location'] ?? 'Outside') ?> → <?= e($m['to_location'] ?? 'Outside') ?></td>
                        <td class="small"><?= e($m['user_name']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
            <?php else: ?>
                <?= partial('partials/empty', ['icon' => 'bi-arrow-left-right', 'title' => 'No movements yet']) ?>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>
</div>
