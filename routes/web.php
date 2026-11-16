<?php
/**
 * Web (HTML) routes.
 * Every protected route sits inside the 'auth' group and declares its permission
 * with ->middleware('can:<permission>'). Services re-check department-level access.
 *
 * @var App\Core\Router $router
 */

use App\Controllers\Admin\AuditLogController;
use App\Controllers\Ai\AssistantController;
use App\Controllers\Analytics\AnalyticsController;
use App\Controllers\Admin\DepartmentController;
use App\Controllers\Admin\LocationController;
use App\Controllers\Admin\RoleController;
use App\Controllers\Admin\SettingsController;
use App\Controllers\Admin\UserController;
use App\Controllers\Auth\LoginController;
use App\Controllers\Auth\PasswordController;
use App\Controllers\Catalogue\CategoryController;
use App\Controllers\Codes\CodeController;
use App\Controllers\ColdChain\TemperatureController;
use App\Controllers\Intelligence\IntelligenceController;
use App\Controllers\Departments\DepartmentStockController;
use App\Controllers\Inventory\AdjustmentController;
use App\Controllers\Inventory\ConsumptionController;
use App\Controllers\Inventory\RecallController;
use App\Controllers\Inventory\ReservationController;
use App\Controllers\Inventory\StockCountController;
use App\Controllers\Inventory\TransferController;
use App\Controllers\Requests\StockRequestController;
use App\Controllers\Catalogue\UnitController;
use App\Controllers\Inventory\BatchController;
use App\Controllers\Inventory\IssueController;
use App\Controllers\Inventory\MovementController;
use App\Controllers\Inventory\ReceiptController;
use App\Controllers\Inventory\ReconciliationController;
use App\Controllers\Inventory\StockController;
use App\Controllers\Procurement\PurchaseOrderController;
use App\Controllers\Procurement\PurchaseRequestController;
use App\Controllers\Procurement\SupplierController;
use App\Controllers\Products\ProductController;
use App\Controllers\DashboardController;
use App\Controllers\NotificationController;

// ---- Guest ---------------------------------------------------------------
$router->group(['middleware' => ['guest']], function ($router) {
    $router->get('/login', [LoginController::class, 'show'])->name('login');
    $router->post('/login', [LoginController::class, 'login'])->name('login.attempt');
});

// ---- Authenticated -------------------------------------------------------
$router->group(['middleware' => ['auth']], function ($router) {
    $router->post('/logout', [LoginController::class, 'logout'])->name('logout');
    $router->get('/password/change', [PasswordController::class, 'show'])->name('password.change');
    $router->post('/password/change', [PasswordController::class, 'update'])->name('password.update');

    $router->get('/', [DashboardController::class, 'index'])->name('dashboard')->middleware('can:dashboard.view');

    $router->get('/notifications', [NotificationController::class, 'index'])->name('notifications.index')->middleware('can:notifications.view');
    $router->post('/notifications/read-all', [NotificationController::class, 'markAllRead'])->name('notifications.read_all')->middleware('can:notifications.view');

    // ---- Codes & scanning -----------------------------------------------------
    $router->get('/scan', [CodeController::class, 'scanner'])->name('scan')->middleware('can:products.view');
    $router->get('/codes/product/{id}', [CodeController::class, 'productImage'])->name('codes.product')->middleware('can:products.view');
    $router->get('/codes/batch/{id}', [CodeController::class, 'batchImage'])->name('codes.batch')->middleware('can:products.view');
    $router->get('/labels', [CodeController::class, 'labels'])->name('codes.labels')->middleware('can:codes.print');

    // ---- Products & catalogue ----------------------------------------------
    $router->get('/products', [ProductController::class, 'index'])->name('products.index')->middleware('can:products.view');
    $router->get('/products/create', [ProductController::class, 'create'])->name('products.create')->middleware('can:products.manage');
    $router->post('/products', [ProductController::class, 'store'])->name('products.store')->middleware('can:products.manage');
    $router->get('/products/{id}', [ProductController::class, 'show'])->name('products.show')->middleware('can:products.view');
    $router->get('/products/{id}/image', [ProductController::class, 'image'])->name('products.image')->middleware('can:products.view');
    $router->get('/products/{id}/edit', [ProductController::class, 'edit'])->name('products.edit')->middleware('can:products.manage');
    $router->post('/products/{id}', [ProductController::class, 'update'])->name('products.update')->middleware('can:products.manage');
    $router->post('/products/{id}/toggle', [ProductController::class, 'toggle'])->name('products.toggle')->middleware('can:products.manage');

    $router->group(['prefix' => 'catalogue', 'middleware' => ['can:categories.manage']], function ($router) {
        $router->get('/categories', [CategoryController::class, 'index'])->name('catalogue.categories.index');
        $router->get('/categories/create', [CategoryController::class, 'create'])->name('catalogue.categories.create');
        $router->post('/categories', [CategoryController::class, 'store'])->name('catalogue.categories.store');
        $router->get('/categories/{id}/edit', [CategoryController::class, 'edit'])->name('catalogue.categories.edit');
        $router->post('/categories/{id}', [CategoryController::class, 'update'])->name('catalogue.categories.update');
        $router->get('/units', [UnitController::class, 'index'])->name('catalogue.units.index');
        $router->get('/units/create', [UnitController::class, 'create'])->name('catalogue.units.create');
        $router->post('/units', [UnitController::class, 'store'])->name('catalogue.units.store');
        $router->get('/units/{id}/edit', [UnitController::class, 'edit'])->name('catalogue.units.edit');
        $router->post('/units/{id}', [UnitController::class, 'update'])->name('catalogue.units.update');
    });

    // ---- Inventory ----------------------------------------------------------
    $router->group(['prefix' => 'inventory'], function ($router) {
        $router->get('/', [StockController::class, 'index'])->name('inventory.stock')->middleware('can:stock.view');

        $router->get('/batches', [BatchController::class, 'index'])->name('inventory.batches.index')->middleware('can:stock.view');
        $router->get('/batches/{id}', [BatchController::class, 'show'])->name('inventory.batches.show')->middleware('can:stock.view');
        $router->get('/batches/code/{code}', [BatchController::class, 'byCode'])->name('inventory.batches.code')->middleware('can:stock.view');

        $router->get('/receipts', [ReceiptController::class, 'index'])->name('inventory.receipts.index')->middleware('can:stock.view');
        $router->get('/receipts/create', [ReceiptController::class, 'create'])->name('inventory.receipts.create')->middleware('can:goods_receipts.direct|stock.opening_balance');
        $router->post('/receipts', [ReceiptController::class, 'store'])->name('inventory.receipts.store')->middleware('can:goods_receipts.direct|stock.opening_balance');
        $router->get('/receipts/po', [ReceiptController::class, 'poIndex'])->name('inventory.receipts.po')->middleware('can:goods_receipts.create');
        $router->get('/receipts/po/{id}', [ReceiptController::class, 'poCreate'])->name('inventory.receipts.po.create')->middleware('can:goods_receipts.create');
        $router->post('/receipts/po/{id}', [ReceiptController::class, 'poStore'])->name('inventory.receipts.po.store')->middleware('can:goods_receipts.create');
        $router->get('/receipts/{id}', [ReceiptController::class, 'show'])->name('inventory.receipts.show')->middleware('can:stock.view');

        $router->get('/issues', [IssueController::class, 'index'])->name('inventory.issues.index')->middleware('can:stock.issue');
        $router->get('/issues/create', [IssueController::class, 'create'])->name('inventory.issues.create')->middleware('can:stock.issue');
        $router->post('/issues', [IssueController::class, 'store'])->name('inventory.issues.store')->middleware('can:stock.issue');
        $router->get('/issues/{id}', [TransferController::class, 'show'])->name('inventory.issues.show')->middleware('can:stock.view');
        $router->post('/issues/{id}/confirm', [IssueController::class, 'confirm'])->name('inventory.issues.confirm')->middleware('can:stock.confirm_receipt');
        $router->post('/issues/{id}/return', [IssueController::class, 'returnOutstanding'])->name('inventory.issues.return')->middleware('can:stock.issue');
        $router->get('/incoming', [IssueController::class, 'incoming'])->name('inventory.incoming')->middleware('can:stock.confirm_receipt');

        $router->get('/movements', [MovementController::class, 'index'])->name('inventory.movements')->middleware('can:stock.movements.view');
        $router->get('/reconciliation', [ReconciliationController::class, 'index'])->name('inventory.reconciliation')->middleware('can:stock.reconcile');
        $router->post('/reconciliation', [ReconciliationController::class, 'run'])->name('inventory.reconciliation.run')->middleware('can:stock.reconcile');
    });

    // ---- Departments ----------------------------------------------------------
    $router->get('/departments', [DepartmentStockController::class, 'index'])->name('departments.index')->middleware('can:stock.view');
    $router->get('/departments/{id}', [DepartmentStockController::class, 'show'])->name('departments.show')->middleware('can:stock.view');
    $router->post('/departments/{id}/levels', [DepartmentStockController::class, 'saveLevels'])->name('departments.levels')->middleware('can:department_levels.manage');

    $router->get('/requests', [StockRequestController::class, 'index'])->name('requests.index')->middleware('can:requests.create|requests.approve|stock.issue');
    $router->get('/requests/create', [StockRequestController::class, 'create'])->name('requests.create')->middleware('can:requests.create');
    $router->post('/requests', [StockRequestController::class, 'store'])->name('requests.store')->middleware('can:requests.create');
    $router->get('/requests/{id}', [StockRequestController::class, 'show'])->name('requests.show')->middleware('can:requests.create|requests.approve|stock.issue|stock.view');
    $router->post('/requests/{id}/submit', [StockRequestController::class, 'submit'])->name('requests.submit')->middleware('can:requests.create');
    $router->post('/requests/{id}/cancel', [StockRequestController::class, 'cancel'])->name('requests.cancel')->middleware('can:requests.create');
    $router->post('/requests/{id}/review', [StockRequestController::class, 'review'])->name('requests.review')->middleware('can:requests.approve');
    $router->post('/requests/{id}/issue', [StockRequestController::class, 'issue'])->name('requests.issue')->middleware('can:stock.issue');
    $router->post('/requests/{id}/close', [StockRequestController::class, 'close'])->name('requests.close')->middleware('can:requests.approve');

    $router->get('/transfers', [TransferController::class, 'index'])->name('transfers.index')->middleware('can:stock.view');
    $router->get('/transfers/create', [TransferController::class, 'create'])->name('transfers.create')->middleware('can:transfers.create');
    $router->post('/transfers', [TransferController::class, 'store'])->name('transfers.store')->middleware('can:transfers.create');
    $router->get('/transfers/{id}', [TransferController::class, 'show'])->name('transfers.show')->middleware('can:stock.view');
    $router->post('/transfers/{id}/approve', [TransferController::class, 'approve'])->name('transfers.approve')->middleware('can:transfers.approve');
    $router->post('/transfers/{id}/dispatch', [TransferController::class, 'dispatch'])->name('transfers.dispatch')->middleware('can:transfers.create|stock.issue');
    $router->post('/transfers/{id}/cancel', [TransferController::class, 'cancel'])->name('transfers.cancel')->middleware('can:transfers.create');

    $router->get('/consumption', [ConsumptionController::class, 'index'])->name('consumption.index')->middleware('can:stock.view');
    $router->get('/consumption/create', [ConsumptionController::class, 'create'])->name('consumption.create')->middleware('can:consumption.record');
    $router->post('/consumption', [ConsumptionController::class, 'store'])->name('consumption.store')->middleware('can:consumption.record');

    $router->get('/adjustments', [AdjustmentController::class, 'index'])->name('adjustments.index')->middleware('can:adjustments.request|adjustments.approve');
    $router->get('/adjustments/create', [AdjustmentController::class, 'create'])->name('adjustments.create')->middleware('can:adjustments.request');
    $router->post('/adjustments', [AdjustmentController::class, 'store'])->name('adjustments.store')->middleware('can:adjustments.request');
    $router->get('/adjustments/{id}', [AdjustmentController::class, 'show'])->name('adjustments.show')->middleware('can:adjustments.request|adjustments.approve|stock.view');
    $router->post('/adjustments/{id}/decide', [AdjustmentController::class, 'approve'])->name('adjustments.decide')->middleware('can:adjustments.approve');

    $router->get('/counts', [StockCountController::class, 'index'])->name('counts.index')->middleware('can:counts.perform|counts.approve');
    $router->post('/counts', [StockCountController::class, 'store'])->name('counts.store')->middleware('can:counts.perform');
    $router->get('/counts/{id}', [StockCountController::class, 'show'])->name('counts.show')->middleware('can:counts.perform|counts.approve');
    $router->post('/counts/{id}', [StockCountController::class, 'save'])->name('counts.save')->middleware('can:counts.perform');
    $router->post('/counts/{id}/approve', [StockCountController::class, 'approve'])->name('counts.approve')->middleware('can:counts.approve');
    $router->post('/counts/{id}/cancel', [StockCountController::class, 'cancel'])->name('counts.cancel')->middleware('can:counts.perform');

    $router->get('/reservations', [ReservationController::class, 'index'])->name('reservations.index')->middleware('can:reservations.manage|reservations.approve');
    $router->post('/reservations', [ReservationController::class, 'store'])->name('reservations.store')->middleware('can:reservations.manage');
    $router->post('/reservations/{id}', [ReservationController::class, 'decide'])->name('reservations.decide')->middleware('can:reservations.manage|reservations.approve');

    $router->get('/recalls', [RecallController::class, 'index'])->name('recalls.index')->middleware('can:recalls.manage|stock.view');
    $router->get('/recalls/create', [RecallController::class, 'create'])->name('recalls.create')->middleware('can:recalls.manage');
    $router->post('/recalls', [RecallController::class, 'store'])->name('recalls.store')->middleware('can:recalls.manage');
    $router->get('/recalls/{id}', [RecallController::class, 'show'])->name('recalls.show')->middleware('can:recalls.manage|stock.view');
    $router->post('/recalls/{id}/quarantine', [RecallController::class, 'quarantine'])->name('recalls.quarantine')->middleware('can:recalls.manage');
    $router->post('/recalls/{id}/close', [RecallController::class, 'close'])->name('recalls.close')->middleware('can:recalls.manage');

    // ---- AI assistant -----------------------------------------------------------
    $router->get('/assistant', [AssistantController::class, 'index'])->name('ai.index')->middleware('can:ai.use');
    $router->post('/assistant/conversations/{id}', [AssistantController::class, 'update'])->name('ai.conversation.update')->middleware('can:ai.use');

    // ---- Analytics & reports --------------------------------------------------
    $router->get('/analytics', [AnalyticsController::class, 'index'])->name('analytics.index')->middleware('can:analytics.view');
    $router->get('/analytics/reports/{key}', [AnalyticsController::class, 'report'])->name('analytics.report')->middleware('can:analytics.view');

    // ---- Intelligence ---------------------------------------------------------
    $router->get('/intelligence/stockout', [IntelligenceController::class, 'stockout'])->name('intelligence.stockout')->middleware('can:stock.view');
    $router->get('/intelligence/stockout/{id}', [IntelligenceController::class, 'stockoutProduct'])->name('intelligence.stockout.product')->middleware('can:stock.view');
    $router->get('/intelligence/expiry', [IntelligenceController::class, 'expiry'])->name('intelligence.expiry')->middleware('can:stock.view');
    $router->get('/intelligence/redistribution', [IntelligenceController::class, 'redistribution'])->name('intelligence.redistribution')->middleware('can:stock.view');
    $router->post('/intelligence/redistribution', [IntelligenceController::class, 'createRedistribution'])->name('intelligence.redistribution.create')->middleware('can:transfers.create');
    $router->get('/intelligence/anomalies', [IntelligenceController::class, 'anomalies'])->name('intelligence.anomalies')->middleware('can:analytics.view|adjustments.approve');
    $router->get('/cold-chain', [TemperatureController::class, 'index'])->name('cold_chain.index')->middleware('can:temperature.record|stock.view');
    $router->post('/cold-chain', [TemperatureController::class, 'store'])->name('cold_chain.store')->middleware('can:temperature.record');

    // ---- Procurement ----------------------------------------------------------
    $router->get('/suppliers', [SupplierController::class, 'index'])->name('suppliers.index')->middleware('can:suppliers.view');
    $router->get('/suppliers/performance', [SupplierController::class, 'performance'])->name('suppliers.performance')->middleware('can:suppliers.view');
    $router->get('/suppliers/create', [SupplierController::class, 'create'])->name('suppliers.create')->middleware('can:suppliers.manage');
    $router->post('/suppliers', [SupplierController::class, 'store'])->name('suppliers.store')->middleware('can:suppliers.manage');
    $router->get('/suppliers/{id}', [SupplierController::class, 'show'])->name('suppliers.show')->middleware('can:suppliers.view');
    $router->get('/suppliers/{id}/edit', [SupplierController::class, 'edit'])->name('suppliers.edit')->middleware('can:suppliers.manage');
    $router->post('/suppliers/{id}', [SupplierController::class, 'update'])->name('suppliers.update')->middleware('can:suppliers.manage');
    $router->post('/suppliers/{id}/products', [SupplierController::class, 'saveProduct'])->name('suppliers.products.save')->middleware('can:suppliers.manage');
    $router->post('/suppliers/{id}/products/{productId}/remove', [SupplierController::class, 'removeProduct'])->name('suppliers.products.remove')->middleware('can:suppliers.manage');

    $router->get('/purchase-requests', [PurchaseRequestController::class, 'index'])->name('purchase_requests.index')->middleware('can:purchase_requests.create|purchase_requests.approve|purchase_orders.manage');
    $router->get('/purchase-requests/create', [PurchaseRequestController::class, 'create'])->name('purchase_requests.create')->middleware('can:purchase_requests.create');
    $router->get('/purchase-requests/low-stock', [PurchaseRequestController::class, 'lowStock'])->name('purchase_requests.low_stock')->middleware('can:purchase_requests.create');
    $router->post('/purchase-requests', [PurchaseRequestController::class, 'store'])->name('purchase_requests.store')->middleware('can:purchase_requests.create');
    $router->get('/purchase-requests/{id}', [PurchaseRequestController::class, 'show'])->name('purchase_requests.show')->middleware('can:purchase_requests.create|purchase_requests.approve|purchase_orders.manage');
    $router->post('/purchase-requests/{id}/submit', [PurchaseRequestController::class, 'submit'])->name('purchase_requests.submit')->middleware('can:purchase_requests.create');
    $router->post('/purchase-requests/{id}/decide', [PurchaseRequestController::class, 'decide'])->name('purchase_requests.decide')->middleware('can:purchase_requests.approve');
    $router->post('/purchase-requests/{id}/cancel', [PurchaseRequestController::class, 'cancel'])->name('purchase_requests.cancel')->middleware('can:purchase_requests.create');

    $router->get('/purchase-orders', [PurchaseOrderController::class, 'index'])->name('purchase_orders.index')->middleware('can:purchase_orders.manage|purchase_orders.approve|goods_receipts.create');
    $router->get('/purchase-orders/create', [PurchaseOrderController::class, 'create'])->name('purchase_orders.create')->middleware('can:purchase_orders.manage');
    $router->post('/purchase-orders', [PurchaseOrderController::class, 'store'])->name('purchase_orders.store')->middleware('can:purchase_orders.manage');
    $router->get('/purchase-orders/from-requests', [PurchaseOrderController::class, 'fromRequests'])->name('purchase_orders.from_requests')->middleware('can:purchase_orders.manage');
    $router->post('/purchase-orders/from-requests', [PurchaseOrderController::class, 'storeFromRequests'])->name('purchase_orders.from_requests.store')->middleware('can:purchase_orders.manage');
    $router->get('/purchase-orders/{id}', [PurchaseOrderController::class, 'show'])->name('purchase_orders.show')->middleware('can:purchase_orders.manage|purchase_orders.approve|goods_receipts.create');
    $router->get('/purchase-orders/{id}/edit', [PurchaseOrderController::class, 'edit'])->name('purchase_orders.edit')->middleware('can:purchase_orders.manage');
    $router->post('/purchase-orders/{id}', [PurchaseOrderController::class, 'update'])->name('purchase_orders.update')->middleware('can:purchase_orders.manage');
    $router->get('/purchase-orders/{id}/pdf', [PurchaseOrderController::class, 'pdf'])->name('purchase_orders.pdf')->middleware('can:purchase_orders.manage|purchase_orders.approve|goods_receipts.create');
    $router->post('/purchase-orders/{id}/action', [PurchaseOrderController::class, 'action'])->name('purchase_orders.action')->middleware('can:purchase_orders.manage|purchase_orders.approve');

    // ---- Administration ----------------------------------------------------
    $router->group(['prefix' => 'admin'], function ($router) {
        $router->group(['middleware' => ['can:users.manage']], function ($router) {
            $router->get('/users', [UserController::class, 'index'])->name('admin.users.index');
            $router->get('/users/create', [UserController::class, 'create'])->name('admin.users.create');
            $router->post('/users', [UserController::class, 'store'])->name('admin.users.store');
            $router->get('/users/{id}/edit', [UserController::class, 'edit'])->name('admin.users.edit');
            $router->post('/users/{id}', [UserController::class, 'update'])->name('admin.users.update');
            $router->post('/users/{id}/status', [UserController::class, 'status'])->name('admin.users.status');
            $router->post('/users/{id}/reset-password', [UserController::class, 'resetPassword'])->name('admin.users.reset_password');
        });

        $router->group(['middleware' => ['can:roles.manage']], function ($router) {
            $router->get('/roles', [RoleController::class, 'index'])->name('admin.roles.index');
            $router->get('/roles/create', [RoleController::class, 'create'])->name('admin.roles.create');
            $router->post('/roles', [RoleController::class, 'store'])->name('admin.roles.store');
            $router->get('/roles/{id}/edit', [RoleController::class, 'edit'])->name('admin.roles.edit');
            $router->post('/roles/{id}', [RoleController::class, 'update'])->name('admin.roles.update');
            $router->post('/roles/{id}/delete', [RoleController::class, 'destroy'])->name('admin.roles.delete');
        });

        $router->group(['middleware' => ['can:departments.manage']], function ($router) {
            $router->get('/departments', [DepartmentController::class, 'index'])->name('admin.departments.index');
            $router->get('/departments/create', [DepartmentController::class, 'create'])->name('admin.departments.create');
            $router->post('/departments', [DepartmentController::class, 'store'])->name('admin.departments.store');
            $router->get('/departments/{id}/edit', [DepartmentController::class, 'edit'])->name('admin.departments.edit');
            $router->post('/departments/{id}', [DepartmentController::class, 'update'])->name('admin.departments.update');
            $router->post('/departments/{id}/toggle', [DepartmentController::class, 'toggle'])->name('admin.departments.toggle');
        });

        $router->group(['middleware' => ['can:locations.manage']], function ($router) {
            $router->get('/locations', [LocationController::class, 'index'])->name('admin.locations.index');
            $router->get('/locations/create', [LocationController::class, 'create'])->name('admin.locations.create');
            $router->post('/locations', [LocationController::class, 'store'])->name('admin.locations.store');
            $router->get('/locations/{id}/edit', [LocationController::class, 'edit'])->name('admin.locations.edit');
            $router->post('/locations/{id}', [LocationController::class, 'update'])->name('admin.locations.update');
            $router->post('/locations/{id}/toggle', [LocationController::class, 'toggle'])->name('admin.locations.toggle');
        });

        $router->get('/settings', [SettingsController::class, 'index'])->name('admin.settings')->middleware('can:settings.manage');
        $router->post('/settings', [SettingsController::class, 'update'])->name('admin.settings.update')->middleware('can:settings.manage');

        $router->get('/audit-logs', [AuditLogController::class, 'index'])->name('admin.audit.index')->middleware('can:audit.view');
        $router->get('/audit-logs/{id}', [AuditLogController::class, 'show'])->name('admin.audit.show')->middleware('can:audit.view');
    });
});
