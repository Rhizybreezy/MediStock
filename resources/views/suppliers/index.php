<?php /** @var App\Core\Paginator $page */ ?>
<div class="page-header">
    <div>
        <nav aria-label="breadcrumb"><ol class="breadcrumb"><li class="breadcrumb-item">Procurement</li><li class="breadcrumb-item active" aria-current="page">Suppliers</li></ol></nav>
        <h1>Suppliers</h1>
        <div class="subtitle">Supplier register, quoted prices and delivery performance.</div>
    </div>
    <div class="d-flex gap-2">
        <a href="<?= e(route('suppliers.performance')) ?>" class="btn btn-outline-secondary"><i class="bi bi-speedometer2 me-1" aria-hidden="true"></i>Performance</a>
        <?php if (can('suppliers.manage')): ?><a href="<?= e(route('suppliers.create')) ?>" class="btn btn-primary"><i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Register supplier</a><?php endif; ?>
    </div>
</div>
<div class="card">
    <form class="filter-bar" method="get" role="search">
        <input type="search" name="q" class="form-control search-input" placeholder="Name, code or contact" value="<?= e($filters['q']) ?>" aria-label="Search suppliers">
        <select name="status" class="form-select" data-auto-submit aria-label="Status">
            <option value="">Any status</option>
            <?php foreach (['active', 'inactive', 'blacklisted'] as $s): ?><option value="<?= $s ?>"<?= $filters['status'] === $s ? ' selected' : '' ?>><?= e(label($s)) ?></option><?php endforeach; ?>
        </select>
    </form>
    <?php if ($page->items): ?>
    <div class="table-responsive"><table class="table table-hover">
        <thead><tr><th scope="col"><?= sort_link('code', 'Code') ?></th><th scope="col"><?= sort_link('name', 'Supplier') ?></th><th scope="col">Contact</th><th scope="col" class="num">Lead time</th><th scope="col" class="num">Products</th><th scope="col" class="num">Open POs</th><th scope="col"><?= sort_link('rating', 'Rating') ?></th><th scope="col">Status</th></tr></thead>
        <tbody>
        <?php foreach ($page->items as $s): ?>
            <tr>
                <td><span class="code-chip"><?= e($s['supplier_code']) ?></span></td>
                <td><a class="fw-semibold" href="<?= e(route('suppliers.show', ['id' => $s['id']])) ?>"><?= e($s['name']) ?></a><span class="cell-sub"><?= e($s['payment_terms'] ?? '') ?></span></td>
                <td class="small"><?= e($s['contact_person'] ?? '—') ?><span class="cell-sub"><?= e(trim(($s['phone'] ?? '') . ' ' . ($s['email'] ?? ''))) ?></span></td>
                <td class="num"><?= (int) $s['default_lead_time_days'] ?> d</td>
                <td class="num"><?= (int) $s['product_count'] ?></td>
                <td class="num"><?= (int) $s['open_pos'] ?></td>
                <td><?= $s['rating_cache'] !== null ? '<span class="fw-semibold">' . e(number_format((float) $s['rating_cache'], 1)) . '</span><span class="text-muted small"> / 5</span>' : '<span class="small text-muted">No deliveries yet</span>' ?></td>
                <td><?= status_badge($s['status'] === 'blacklisted' ? 'rejected' : $s['status']) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
    <?php else: ?>
        <?= partial('partials/empty', ['icon' => 'bi-truck-front', 'title' => 'No suppliers', 'text' => can('suppliers.manage') ? 'Register your first supplier.' : 'No suppliers match.']) ?>
    <?php endif; ?>
    <?= partial('partials/pagination', ['page' => $page]) ?>
</div>
