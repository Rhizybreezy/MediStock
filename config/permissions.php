<?php
/**
 * Permission catalogue + default role matrix.
 *
 * - `permissions` is the master list (code => [module, description]). The seeder
 *   inserts any missing codes, so adding a permission here and re-running
 *   `php bin/console db:seed` makes it available in Admin → Roles.
 * - `roles` defines the seeded system roles and their DEFAULT permissions.
 *   Administrators can change role permissions later from the UI; re-seeding
 *   never removes permissions an admin has granted.
 * - scope = 'global'     → role sees/acts on all departments.
 *   scope = 'department' → role is limited to the user's assigned departments.
 */
return [
    'permissions' => [
        // Dashboard & notifications
        'dashboard.view'            => ['Dashboard', 'View the dashboard'],
        'notifications.view'        => ['Dashboard', 'View own notifications'],

        // Administration
        'users.manage'              => ['Administration', 'Create, edit, activate and unlock users'],
        'roles.manage'              => ['Administration', 'Create roles and assign permissions'],
        'departments.manage'        => ['Administration', 'Manage hospital departments'],
        'locations.manage'          => ['Administration', 'Manage storage locations'],
        'settings.manage'           => ['Administration', 'Change system settings and thresholds'],
        'audit.view'                => ['Administration', 'View the audit log'],

        // Catalogue
        'products.view'             => ['Products', 'View products and medicines'],
        'products.manage'           => ['Products', 'Register and edit products'],
        'categories.manage'         => ['Products', 'Manage categories and units of measure'],

        // Stock
        'stock.view'                => ['Inventory', 'View stock balances and batches'],
        'stock.movements.view'      => ['Inventory', 'View the stock movement ledger'],
        'stock.issue'               => ['Inventory', 'Issue / dispatch stock'],
        'stock.confirm_receipt'     => ['Inventory', 'Confirm receipt of issued or transferred stock'],
        'stock.opening_balance'     => ['Inventory', 'Record opening balances'],
        'stock.reconcile'           => ['Inventory', 'Run stock reconciliation'],
        'consumption.record'        => ['Inventory', 'Record consumption / dispensing'],
        'adjustments.request'       => ['Inventory', 'Request stock adjustments (damage, loss, correction)'],
        'adjustments.approve'       => ['Inventory', 'Approve stock adjustments'],
        'counts.perform'            => ['Inventory', 'Perform stock counts'],
        'counts.approve'            => ['Inventory', 'Approve stock count variances'],
        'reservations.manage'       => ['Inventory', 'Request emergency stock reservations'],
        'reservations.approve'      => ['Inventory', 'Approve emergency stock reservations'],
        'recalls.manage'            => ['Inventory', 'Manage batch recalls'],
        'temperature.record'        => ['Inventory', 'Record cold-chain temperatures'],
        'codes.print'               => ['Inventory', 'Generate and print QR codes / barcodes'],
        'department_levels.manage'  => ['Inventory', 'Set department minimum / reorder / maximum stock levels'],

        // Department workflows
        'requests.create'           => ['Requests & Transfers', 'Submit department stock requests'],
        'requests.approve'          => ['Requests & Transfers', 'Review and approve stock requests'],
        'transfers.create'          => ['Requests & Transfers', 'Create stock transfers and returns'],
        'transfers.approve'         => ['Requests & Transfers', 'Approve stock transfers'],

        // Procurement
        'suppliers.view'            => ['Procurement', 'View suppliers'],
        'suppliers.manage'          => ['Procurement', 'Register and edit suppliers'],
        'purchase_requests.create'  => ['Procurement', 'Create purchase requests'],
        'purchase_requests.approve' => ['Procurement', 'Approve purchase requests'],
        'purchase_orders.manage'    => ['Procurement', 'Create and manage purchase orders'],
        'purchase_orders.approve'   => ['Procurement', 'Approve purchase orders'],
        'goods_receipts.create'     => ['Procurement', 'Receive goods against purchase orders'],
        'goods_receipts.direct'     => ['Procurement', 'Receive goods without a purchase order (donations)'],

        // Analytics
        'analytics.view'            => ['Analytics', 'View analytics and reports'],
        'reports.export'            => ['Analytics', 'Export reports (PDF / CSV)'],
        'valuation.view'            => ['Analytics', 'View inventory valuation and costs'],

        // Equipment
        'equipment.view'            => ['Equipment', 'View medical equipment'],
        'equipment.manage'          => ['Equipment', 'Register equipment and maintenance'],

        // AI
        'ai.use'                    => ['AI Assistant', 'Use the AI inventory assistant'],
        'ai.propose'                => ['AI Assistant', 'Let the AI prepare draft purchase requests / transfers'],
    ],

    'roles' => [
        'admin' => [
            'name' => 'System Administrator', 'scope' => 'global',
            'description' => 'Manages users, roles, departments, settings and audit logs.',
            'permissions' => [
                'dashboard.view', 'notifications.view', 'users.manage', 'roles.manage',
                'departments.manage', 'locations.manage', 'settings.manage', 'audit.view',
                'products.view', 'stock.view', 'stock.movements.view', 'stock.reconcile',
                'analytics.view', 'valuation.view', 'suppliers.view', 'equipment.view', 'ai.use',
            ],
        ],
        'management' => [
            'name' => 'Hospital Management', 'scope' => 'global',
            'description' => 'Hospital-wide visibility, analytics, valuation and designated approvals.',
            'permissions' => [
                'dashboard.view', 'notifications.view', 'audit.view', 'products.view',
                'stock.view', 'stock.movements.view', 'requests.approve', 'transfers.approve',
                'adjustments.approve', 'reservations.approve', 'suppliers.view',
                'purchase_requests.approve', 'purchase_orders.approve',
                'analytics.view', 'reports.export', 'valuation.view', 'equipment.view', 'ai.use',
            ],
        ],
        'inventory_manager' => [
            'name' => 'Inventory Manager', 'scope' => 'global',
            'description' => 'Runs the central store: products, batches, issues, requests and transfers.',
            'permissions' => [
                'department_levels.manage',
                'dashboard.view', 'notifications.view', 'locations.manage', 'products.view',
                'products.manage', 'categories.manage', 'stock.view', 'stock.movements.view',
                'stock.issue', 'stock.confirm_receipt', 'stock.opening_balance', 'stock.reconcile',
                'consumption.record', 'adjustments.request', 'adjustments.approve',
                'counts.perform', 'counts.approve', 'reservations.manage', 'recalls.manage',
                'temperature.record', 'codes.print', 'requests.create', 'requests.approve',
                'transfers.create', 'transfers.approve', 'suppliers.view',
                'purchase_requests.create', 'goods_receipts.create', 'goods_receipts.direct',
                'analytics.view', 'reports.export', 'valuation.view',
                'equipment.view', 'equipment.manage', 'ai.use', 'ai.propose',
            ],
        ],
        'pharmacist' => [
            'name' => 'Pharmacist', 'scope' => 'department',
            'description' => 'Manages pharmacy medicines, batches, expiry, dispensing and requests.',
            'permissions' => [
                'department_levels.manage',
                'dashboard.view', 'notifications.view', 'products.view', 'stock.view',
                'stock.movements.view', 'stock.issue', 'stock.confirm_receipt',
                'consumption.record', 'adjustments.request', 'counts.perform',
                'reservations.manage', 'recalls.manage', 'temperature.record', 'codes.print',
                'requests.create', 'transfers.create', 'purchase_requests.create',
                'analytics.view', 'ai.use', 'ai.propose',
            ],
        ],
        'storekeeper' => [
            'name' => 'Department Storekeeper', 'scope' => 'department',
            'description' => 'Department stock: requests, receipts, consumption, returns and damages.',
            'permissions' => [
                'department_levels.manage',
                'dashboard.view', 'notifications.view', 'products.view', 'stock.view',
                'stock.movements.view', 'stock.confirm_receipt', 'consumption.record',
                'adjustments.request', 'counts.perform', 'temperature.record',
                'requests.create', 'transfers.create', 'analytics.view',
                'equipment.view', 'ai.use',
            ],
        ],
        'procurement' => [
            'name' => 'Procurement Officer', 'scope' => 'global',
            'description' => 'Suppliers, purchase requests, purchase orders and deliveries.',
            'permissions' => [
                'dashboard.view', 'notifications.view', 'products.view', 'stock.view',
                'suppliers.view', 'suppliers.manage', 'purchase_requests.create',
                'purchase_orders.manage', 'analytics.view', 'reports.export',
                'valuation.view', 'ai.use', 'ai.propose',
            ],
        ],
    ],
];
