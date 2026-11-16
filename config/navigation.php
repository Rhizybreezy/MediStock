<?php
/**
 * Sidebar navigation. Items are shown only if the user holds one of the listed
 * permissions AND the route exists, so menus never link to unbuilt modules.
 * 'match' = path prefix used to highlight the active item.
 */
return [
    [
        'heading' => null,
        'items' => [
            ['label' => 'Dashboard', 'icon' => 'bi-grid-1x2', 'route' => 'dashboard', 'match' => '/', 'exact' => true, 'can' => ['dashboard.view']],
            ['label' => 'Notifications', 'icon' => 'bi-bell', 'route' => 'notifications.index', 'match' => '/notifications', 'can' => ['notifications.view']],
            ['label' => 'AI assistant', 'icon' => 'bi-stars', 'route' => 'ai.index', 'match' => '/assistant', 'can' => ['ai.use']],
        ],
    ],
    [
        'heading' => 'Inventory',
        'items' => [
            ['label' => 'Scan', 'icon' => 'bi-upc-scan', 'route' => 'scan', 'match' => '/scan', 'can' => ['products.view']],
            ['label' => 'Stock on hand', 'icon' => 'bi-boxes', 'route' => 'inventory.stock', 'match' => '/inventory', 'exact' => true, 'can' => ['stock.view']],
            ['label' => 'Products', 'icon' => 'bi-capsule', 'route' => 'products.index', 'match' => '/products', 'can' => ['products.view']],
            ['label' => 'Batches & expiry', 'icon' => 'bi-upc', 'route' => 'inventory.batches.index', 'match' => '/inventory/batches', 'can' => ['stock.view']],
            ['label' => 'Goods receipts', 'icon' => 'bi-box-arrow-in-down', 'route' => 'inventory.receipts.index', 'match' => '/inventory/receipts', 'can' => ['goods_receipts.direct', 'stock.opening_balance', 'goods_receipts.create']],
            ['label' => 'Stock issues', 'icon' => 'bi-box-arrow-up-right', 'route' => 'inventory.issues.index', 'match' => '/inventory/issues', 'can' => ['stock.issue']],
            ['label' => 'Incoming deliveries', 'icon' => 'bi-truck', 'route' => 'inventory.incoming', 'match' => '/inventory/incoming', 'can' => ['stock.confirm_receipt'], 'badge' => 'incoming'],
            ['label' => 'Movement ledger', 'icon' => 'bi-journal-arrow-down', 'route' => 'inventory.movements', 'match' => '/inventory/movements', 'can' => ['stock.movements.view']],
            ['label' => 'Reconciliation', 'icon' => 'bi-check2-square', 'route' => 'inventory.reconciliation', 'match' => '/inventory/reconciliation', 'can' => ['stock.reconcile']],
        ],
    ],
    [
        'heading' => 'Analytics',
        'items' => [
            ['label' => 'Analytics overview', 'icon' => 'bi-bar-chart-line', 'route' => 'analytics.index', 'match' => '/analytics', 'exact' => true, 'can' => ['analytics.view']],
            ['label' => 'Reports', 'icon' => 'bi-file-earmark-bar-graph', 'route' => 'analytics.report', 'params' => ['key' => 'valuation'], 'match' => '/analytics/reports', 'can' => ['analytics.view']],
        ],
    ],
    [
        'heading' => 'Intelligence',
        'items' => [
            ['label' => 'Stock-out forecast', 'icon' => 'bi-graph-down-arrow', 'route' => 'intelligence.stockout', 'match' => '/intelligence/stockout', 'can' => ['stock.view']],
            ['label' => 'Expiry risk', 'icon' => 'bi-hourglass-split', 'route' => 'intelligence.expiry', 'match' => '/intelligence/expiry', 'can' => ['stock.view']],
            ['label' => 'Redistribution', 'icon' => 'bi-shuffle', 'route' => 'intelligence.redistribution', 'match' => '/intelligence/redistribution', 'can' => ['transfers.create', 'transfers.approve']],
            ['label' => 'Anomalies', 'icon' => 'bi-activity', 'route' => 'intelligence.anomalies', 'match' => '/intelligence/anomalies', 'can' => ['analytics.view', 'adjustments.approve']],
            ['label' => 'Cold chain', 'icon' => 'bi-thermometer-snow', 'route' => 'cold_chain.index', 'match' => '/cold-chain', 'can' => ['temperature.record']],
        ],
    ],
    [
        'heading' => 'Departments',
        'items' => [
            ['label' => 'Department stock', 'icon' => 'bi-building', 'route' => 'departments.index', 'match' => '/departments', 'can' => ['stock.view']],
            ['label' => 'Stock requests', 'icon' => 'bi-clipboard-check', 'route' => 'requests.index', 'match' => '/requests', 'can' => ['requests.create', 'requests.approve', 'stock.issue'], 'badge' => 'requests'],
            ['label' => 'Transfers & returns', 'icon' => 'bi-arrow-left-right', 'route' => 'transfers.index', 'match' => '/transfers', 'can' => ['transfers.create', 'transfers.approve'], 'badge' => 'transfers'],
            ['label' => 'Consumption', 'icon' => 'bi-clipboard-pulse', 'route' => 'consumption.index', 'match' => '/consumption', 'can' => ['consumption.record']],
            ['label' => 'Adjustments', 'icon' => 'bi-sliders2', 'route' => 'adjustments.index', 'match' => '/adjustments', 'can' => ['adjustments.request', 'adjustments.approve'], 'badge' => 'adjustments'],
            ['label' => 'Stock counts', 'icon' => 'bi-list-check', 'route' => 'counts.index', 'match' => '/counts', 'can' => ['counts.perform', 'counts.approve']],
            ['label' => 'Reservations', 'icon' => 'bi-shield-plus', 'route' => 'reservations.index', 'match' => '/reservations', 'can' => ['reservations.manage', 'reservations.approve']],
            ['label' => 'Recalls', 'icon' => 'bi-exclamation-octagon', 'route' => 'recalls.index', 'match' => '/recalls', 'can' => ['recalls.manage']],
        ],
    ],
    [
        'heading' => 'Procurement',
        'items' => [
            ['label' => 'Suppliers', 'icon' => 'bi-truck-front', 'route' => 'suppliers.index', 'match' => '/suppliers', 'can' => ['suppliers.view']],
            ['label' => 'Purchase requests', 'icon' => 'bi-file-earmark-plus', 'route' => 'purchase_requests.index', 'match' => '/purchase-requests', 'can' => ['purchase_requests.create', 'purchase_requests.approve'], 'badge' => 'prs'],
            ['label' => 'Purchase orders', 'icon' => 'bi-file-earmark-text', 'route' => 'purchase_orders.index', 'match' => '/purchase-orders', 'can' => ['purchase_orders.manage', 'purchase_orders.approve'], 'badge' => 'pos'],
            ['label' => 'Receive against PO', 'icon' => 'bi-box-arrow-in-down', 'route' => 'inventory.receipts.po', 'match' => '/inventory/receipts/po', 'can' => ['goods_receipts.create']],
        ],
    ],
    [
        'heading' => 'Catalogue',
        'items' => [
            ['label' => 'Categories', 'icon' => 'bi-tags', 'route' => 'catalogue.categories.index', 'match' => '/catalogue/categories', 'can' => ['categories.manage']],
            ['label' => 'Units of measure', 'icon' => 'bi-rulers', 'route' => 'catalogue.units.index', 'match' => '/catalogue/units', 'can' => ['categories.manage']],
        ],
    ],
    [
        'heading' => 'Administration',
        'items' => [
            ['label' => 'Users', 'icon' => 'bi-people', 'route' => 'admin.users.index', 'match' => '/admin/users', 'can' => ['users.manage']],
            ['label' => 'Roles & permissions', 'icon' => 'bi-shield-lock', 'route' => 'admin.roles.index', 'match' => '/admin/roles', 'can' => ['roles.manage']],
            ['label' => 'Departments', 'icon' => 'bi-building', 'route' => 'admin.departments.index', 'match' => '/admin/departments', 'can' => ['departments.manage']],
            ['label' => 'Storage locations', 'icon' => 'bi-geo-alt', 'route' => 'admin.locations.index', 'match' => '/admin/locations', 'can' => ['locations.manage']],
            ['label' => 'System settings', 'icon' => 'bi-sliders', 'route' => 'admin.settings', 'match' => '/admin/settings', 'can' => ['settings.manage']],
            ['label' => 'Audit log', 'icon' => 'bi-journal-text', 'route' => 'admin.audit.index', 'match' => '/admin/audit-logs', 'can' => ['audit.view']],
        ],
    ],
];
