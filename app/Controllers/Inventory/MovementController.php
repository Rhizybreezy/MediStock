<?php
declare(strict_types=1);

namespace App\Controllers\Inventory;

use App\Controllers\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Repositories\DepartmentRepository;
use App\Services\Stock\StockService;

final class MovementController extends Controller
{
    public function __construct(private \App\Repositories\MovementRepository $movements, private DepartmentRepository $departments)
    {
    }

    public function index(Request $request): Response
    {
        $filters = $this->filters($request, ['q', 'type', 'department_id', 'product_id', 'batch_id', 'from', 'to']);
        foreach (['from', 'to'] as $d) {
            if ($filters[$d] && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $filters[$d])) {
                $filters[$d] = null;
            }
        }
        if ($filters['type'] && !in_array($filters['type'], StockService::MOVEMENT_TYPES, true)) {
            $filters['type'] = null;
        }
        $scope = $this->gate()->departmentScope('stock.movements.view');
        return view('inventory/movements', [
            'title'       => 'Stock movement ledger',
            'page'        => $this->movements->search($filters, $this->page($request), $scope),
            'filters'     => $filters,
            'types'       => StockService::MOVEMENT_TYPES,
            'departments' => $this->departments->options($scope),
            'showValues'  => $this->gate()->can('valuation.view'),
        ]);
    }
}
