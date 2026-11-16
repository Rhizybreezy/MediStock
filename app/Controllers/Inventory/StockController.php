<?php
declare(strict_types=1);

namespace App\Controllers\Inventory;

use App\Controllers\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Repositories\CategoryRepository;
use App\Repositories\DepartmentRepository;
use App\Repositories\ProductRepository;

/** Stock on hand per product (hospital-wide or for the user's departments). */
final class StockController extends Controller
{
    public function __construct(
        private ProductRepository $products,
        private CategoryRepository $categories,
        private DepartmentRepository $departments,
    ) {
    }

    public function index(Request $request): Response
    {
        $filters = $this->filters($request, ['q', 'category_id', 'stock', 'department_id']);
        $filters['active'] = '1';
        $scope = $this->gate()->departmentScope('stock.view');
        // Department users see what they hold by default (not every product in the catalogue).
        if ($scope !== null && $request->query('stock') === null) {
            $filters['stock'] = 'in';
        }
        // A department filter narrows the scope (never widens it).
        if (!empty($filters['department_id'])) {
            $dept = (int) $filters['department_id'];
            $scope = ($scope === null || in_array($dept, $scope, true)) ? [$dept] : [];
        }
        return view('inventory/stock', [
            'title'       => 'Stock on hand',
            'page'        => $this->products->search($filters, $this->page($request), $scope),
            'filters'     => $filters,
            'categories'  => $this->categories->options(false),
            'departments' => $this->departments->options($this->gate()->departmentScope('stock.view')),
            'showValues'  => $this->gate()->can('valuation.view'),
        ]);
    }
}
