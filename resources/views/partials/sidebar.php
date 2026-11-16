<?php
$router = \App\Core\App::instance()->router;
$path = request()->path();
// Live counters shown next to some menu items
$badges = [];
if (can('stock.confirm_receipt')) {
    $badges['incoming'] = app(\App\Repositories\TransferRepository::class)->countIncoming(app(\App\Core\Gate::class)->departmentScope('stock.confirm_receipt'));
}
$gate = app(\App\Core\Gate::class);
$db = app(\App\Core\Database::class);
$scopeIn = function (?array $scope, string $column) {
    if ($scope === null) {
        return ['', []];
    }
    [$in, $p] = \App\Core\Database::inClause($scope ?: [0], 'sb' . substr(md5($column), 0, 4));
    return [" AND $column IN $in", $p];
};
if (can('requests.approve')) {
    [$sql, $p] = $scopeIn($gate->departmentScope('requests.approve'), 'supplying_department_id');
    $badges['requests'] = (int) $db->fetchValue("SELECT COUNT(*) FROM stock_requests WHERE status = 'submitted'$sql", $p);
} elseif (can('stock.issue')) {
    [$sql, $p] = $scopeIn($gate->departmentScope('stock.issue'), 'supplying_department_id');
    $badges['requests'] = (int) $db->fetchValue("SELECT COUNT(*) FROM stock_requests WHERE status IN ('approved','partially_approved','partially_issued')$sql", $p);
}
if (can('transfers.approve')) {
    [$sql, $p] = $scopeIn($gate->departmentScope('transfers.approve'), 'source_department_id');
    $badges['transfers'] = (int) $db->fetchValue("SELECT COUNT(*) FROM stock_transfers WHERE status = 'pending_approval'$sql", $p);
}
if (can('purchase_requests.approve')) {
    $badges['prs'] = (int) $db->fetchValue("SELECT COUNT(*) FROM purchase_requests WHERE status = 'submitted'");
}
if (can('purchase_orders.approve')) {
    $badges['pos'] = (int) $db->fetchValue("SELECT COUNT(*) FROM purchase_orders WHERE status = 'pending_approval'");
} elseif (can('purchase_orders.manage')) {
    $badges['pos'] = (int) $db->fetchValue("SELECT COUNT(DISTINCT pr.id) FROM purchase_requests pr WHERE pr.status IN ('approved','partially_ordered')");
}
if (can('adjustments.approve')) {
    [$sql, $p] = $scopeIn($gate->departmentScope('adjustments.approve'), 'l.department_id');
    $badges['adjustments'] = (int) $db->fetchValue("SELECT COUNT(*) FROM stock_adjustments a JOIN inventory_locations l ON l.id = a.location_id WHERE a.status = 'pending'$sql", $p);
}
?>
<nav class="sidebar" aria-label="Main navigation">
    <a class="sidebar-brand" href="<?= e(url('/')) ?>">
        <img src="<?= e(asset('images/logo.svg')) ?>" alt="">
        <span>
            <span class="brand-name d-block">MediStock</span>
            <span class="brand-sub">Intelligence</span>
        </span>
    </a>
    <div class="sidebar-nav">
        <?php foreach (config('navigation', []) as $section):
            $items = array_filter($section['items'], function ($item) use ($router) {
                if (!$router->hasRoute($item['route'])) {
                    return false;
                }
                foreach ($item['can'] as $perm) {
                    if (can($perm)) {
                        return true;
                    }
                }
                return false;
            });
            if (!$items) {
                continue;
            } ?>
            <?php if ($section['heading']): ?>
                <div class="sidebar-heading"><?= e($section['heading']) ?></div>
            <?php endif; ?>
            <?php foreach ($items as $item):
                $active = !empty($item['exact']) ? $path === $item['match'] : str_starts_with($path, $item['match']); ?>
                <a class="sidebar-link<?= $active ? ' active' : '' ?>" href="<?= e(route($item['route'], $item['params'] ?? [])) ?>"<?= $active ? ' aria-current="page"' : '' ?>>
                    <i class="bi <?= e($item['icon']) ?>" aria-hidden="true"></i>
                    <span><?= e($item['label']) ?></span>
                    <?php if (!empty($item['badge']) && !empty($badges[$item['badge']])): ?>
                        <span class="badge rounded-pill bg-warning text-dark ms-auto" aria-label="<?= (int) $badges[$item['badge']] ?> pending"><?= (int) $badges[$item['badge']] ?></span>
                    <?php endif; ?>
                </a>
            <?php endforeach; ?>
        <?php endforeach; ?>
    </div>
    <div class="sidebar-footer">
        <?= e(setting('hospital_name', '')) ?><br>
        v<?= e(config('app.version')) ?>
    </div>
</nav>
