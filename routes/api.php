<?php
/**
 * JSON API routes (fetch/AJAX). Same authentication, CSRF and permission rules
 * as web routes. Responses: {ok:true, data:...} or {ok:false, error:{...}}.
 *
 * @var App\Core\Router $router
 */

use App\Controllers\Ai\AssistantController;
use App\Controllers\Api\LookupController;
use App\Controllers\Codes\CodeController;
use App\Controllers\DashboardController;
use App\Controllers\NotificationController;

$router->group(['prefix' => 'api', 'middleware' => ['auth']], function ($router) {
    $router->get('/dashboard/charts', [DashboardController::class, 'charts'])->name('api.dashboard.charts')->middleware('can:dashboard.view');

    $router->post('/ai/chat', [AssistantController::class, 'chat'])->name('api.ai.chat')->middleware('can:ai.use');
    $router->get('/ai/conversations/{id}', [AssistantController::class, 'transcript'])->name('api.ai.transcript')->middleware('can:ai.use');
    $router->get('/scan/resolve', [CodeController::class, 'resolve'])->name('api.scan.resolve')->middleware('can:products.view');
    $router->get('/products/search', [LookupController::class, 'products'])->name('api.products.search')->middleware('can:products.view');
    $router->get('/stock/batches', [LookupController::class, 'batchesAt'])->name('api.stock.batches')->middleware('can:stock.view');
    $router->get('/batches/search', [LookupController::class, 'batchSearch'])->name('api.batches.search')->middleware('can:recalls.manage');
    $router->get('/stock/allocation', [LookupController::class, 'allocation'])->name('api.stock.allocation')->middleware('can:stock.view');

    $router->get('/notifications', [NotificationController::class, 'summary'])->name('api.notifications')->middleware('can:notifications.view');
    $router->post('/notifications/{id}/read', [NotificationController::class, 'markRead'])->name('api.notifications.read')->middleware('can:notifications.view');
    $router->post('/notifications/read-all', [NotificationController::class, 'markAllRead'])->name('api.notifications.read_all')->middleware('can:notifications.view');
});
