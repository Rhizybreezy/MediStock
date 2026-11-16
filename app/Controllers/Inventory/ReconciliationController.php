<?php
declare(strict_types=1);

namespace App\Controllers\Inventory;

use App\Controllers\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Services\Stock\ReconciliationService;
use App\Services\Support\AuditService;

final class ReconciliationController extends Controller
{
    public function __construct(private ReconciliationService $service, private AuditService $audit)
    {
    }

    public function index(Request $request): Response
    {
        return view('inventory/reconciliation', [
            'title'  => 'Stock reconciliation',
            'result' => Session::getFlash('reconciliation'),
        ]);
    }

    public function run(Request $request): Response
    {
        $result = $this->service->run();
        $this->audit->log('reconcile', 'stock', null, sprintf('Reconciliation: %d checked, %d mismatch(es)', $result['checked'], count($result['mismatches'])));
        Session::flash('reconciliation', $result);
        return redirect(route('inventory.reconciliation'));
    }
}
