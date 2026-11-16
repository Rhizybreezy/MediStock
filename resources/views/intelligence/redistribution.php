<div class="page-header">
    <div>
        <nav aria-label="breadcrumb"><ol class="breadcrumb"><li class="breadcrumb-item">Intelligence</li><li class="breadcrumb-item active" aria-current="page">Redistribution</li></ol></nav>
        <h1>Smart redistribution</h1>
        <div class="subtitle">Departments with surplus (above maximum, more than <?= (int) setting('redistribution_cover_days', 90) ?> days of cover, unused, or likely to expire) matched with departments running short. Creating a transfer only makes a <strong>draft</strong> that still needs approval.</div>
    </div>
</div>
<div class="card">
    <?php if ($suggestions): ?>
    <div class="table-responsive"><table class="table table-hover">
        <thead><tr><th scope="col">Product</th><th scope="col">From</th><th scope="col">To</th><th scope="col" class="num">Suggested qty</th><th scope="col">Why</th><?php if ($canCreate): ?><th scope="col" class="table-actions"><span class="visually-hidden">Action</span></th><?php endif; ?></tr></thead>
        <tbody>
        <?php foreach ($suggestions as $s): ?>
            <tr>
                <td><a href="<?= e(route('products.show', ['id' => $s['product_id']])) ?>"><?= e($s['product_name']) ?></a><span class="cell-sub"><?= e($s['product_code']) ?></span></td>
                <td class="small"><?= e($s['from_department']) ?></td>
                <td class="small"><?= e($s['to_department']) ?></td>
                <td class="num fw-semibold"><?= e(qty($s['qty'])) ?> <span class="small text-muted fw-normal"><?= e($s['unit_code']) ?></span></td>
                <td class="small"><?= e($s['reason']) ?></td>
                <?php if ($canCreate): ?>
                <td class="table-actions">
                    <?php if (can('transfers.create', (int) $s['from_department_id'])): ?>
                    <form method="post" action="<?= e(route('intelligence.redistribution.create')) ?>" data-confirm="Create a draft transfer of <?= e(qty($s['qty'])) ?> <?= e($s['unit_code']) ?> from <?= e($s['from_department']) ?> to <?= e($s['to_department']) ?>? It still needs approval." data-confirm-button="Create draft transfer">
                        <?= csrf_field() ?>
                        <input type="hidden" name="product_id" value="<?= (int) $s['product_id'] ?>"><input type="hidden" name="from_location_id" value="<?= (int) $s['from_location_id'] ?>"><input type="hidden" name="to_location_id" value="<?= (int) $s['to_location_id'] ?>"><input type="hidden" name="quantity" value="<?= e((string) $s['qty']) ?>"><input type="hidden" name="reason" value="<?= e($s['reason']) ?>">
                        <button type="submit" class="btn btn-sm btn-outline-primary">Propose transfer</button>
                    </form>
                    <?php else: ?><span class="small text-muted">Source department must propose</span><?php endif; ?>
                </td>
                <?php endif; ?>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
    <?php else: ?>
        <?= partial('partials/empty', ['icon' => 'bi-shuffle', 'title' => 'No redistribution opportunities', 'text' => 'No department currently has surplus that another department needs. Department levels (Department stock) improve these suggestions.']) ?>
    <?php endif; ?>
</div>
