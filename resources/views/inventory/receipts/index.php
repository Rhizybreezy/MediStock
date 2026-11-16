<?php /** @var App\Core\Paginator $page */ ?>
<div class="page-header">
    <div>
        <nav aria-label="breadcrumb"><ol class="breadcrumb"><li class="breadcrumb-item">Inventory</li><li class="breadcrumb-item active" aria-current="page">Goods receipts</li></ol></nav>
        <h1>Goods receipts</h1>
        <div class="subtitle">Every receipt creates batches and RECEIVED entries in the stock ledger.</div>
    </div>
    <div class="d-flex gap-2">
        <?php if ($canReceivePo): ?><a href="<?= e(route('inventory.receipts.po')) ?>" class="btn btn-primary"><i class="bi bi-file-earmark-check me-1" aria-hidden="true"></i>Receive against PO</a><?php endif; ?>
        <?php if ($canReceive): ?><a href="<?= e(route('inventory.receipts.create')) ?>" class="btn btn-outline-secondary"><i class="bi bi-box-arrow-in-down me-1" aria-hidden="true"></i>Direct receipt</a><?php endif; ?>
    </div>
</div>
<div class="card">
    <form class="filter-bar" method="get" role="search">
        <input type="search" name="q" class="form-control search-input" placeholder="GRN, delivery note or invoice number" value="<?= e($filters['q']) ?>" aria-label="Search receipts">
        <select name="type" class="form-select" data-auto-submit aria-label="Receipt type">
            <option value="">All types</option>
            <?php foreach ($types as $k => $label): ?><option value="<?= e($k) ?>"<?= $filters['type'] === $k ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
        </select>
        <button type="submit" class="btn btn-outline-secondary"><i class="bi bi-search" aria-hidden="true"></i><span class="visually-hidden">Search</span></button>
    </form>
    <?php if ($page->items): ?>
    <div class="table-responsive"><table class="table table-hover">
        <thead><tr>
            <th scope="col"><?= sort_link('number', 'GRN') ?></th><th scope="col"><?= sort_link('date', 'Received') ?></th><th scope="col">Type</th>
            <th scope="col">Location</th><th scope="col">Supplier</th><th scope="col" class="num">Lines</th>
            <?php if ($showValues): ?><th scope="col" class="num">Value</th><?php endif; ?><th scope="col">By</th>
        </tr></thead>
        <tbody>
        <?php foreach ($page->items as $g): ?>
            <tr>
                <td><a class="fw-semibold" href="<?= e(route('inventory.receipts.show', ['id' => $g['id']])) ?>"><?= e($g['grn_number']) ?></a><?php if ($g['delivery_note_number']): ?><span class="cell-sub">DN <?= e($g['delivery_note_number']) ?></span><?php endif; ?></td>
                <td class="small text-nowrap"><?= e(format_date($g['received_at'], true)) ?></td>
                <td class="small"><?= e($types[$g['receipt_type']] ?? label($g['receipt_type'])) ?></td>
                <td class="small"><?= e($g['location_name']) ?></td>
                <td class="small"><?= e($g['supplier_name'] ?? '—') ?></td>
                <td class="num"><?= (int) $g['line_count'] ?></td>
                <?php if ($showValues): ?><td class="num"><?= e(money($g['total_value'], false)) ?></td><?php endif; ?>
                <td class="small"><?= e($g['received_by_name']) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
    <?php else: ?>
        <?= partial('partials/empty', ['icon' => 'bi-box-arrow-in-down', 'title' => 'No receipts yet', 'text' => 'Received stock will be listed here.']) ?>
    <?php endif; ?>
    <?= partial('partials/pagination', ['page' => $page]) ?>
</div>
