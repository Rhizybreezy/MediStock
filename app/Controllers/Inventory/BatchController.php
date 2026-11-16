<?php
declare(strict_types=1);

namespace App\Controllers\Inventory;

use App\Controllers\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Exceptions\HttpException;
use App\Repositories\BatchRepository;
use App\Repositories\DepartmentRepository;
use App\Repositories\MovementRepository;

final class BatchController extends Controller
{
    public function __construct(
        private BatchRepository $batches,
        private MovementRepository $movements,
        private DepartmentRepository $departments,
    ) {
    }

    public function index(Request $request): Response
    {
        $filters = $this->filters($request, ['q', 'product_id', 'department_id', 'expiry', 'status', 'show']);
        $scope = $this->gate()->departmentScope('stock.view');
        if (!empty($filters['department_id']) && $scope !== null && !in_array((int) $filters['department_id'], $scope, true)) {
            $filters['department_id'] = null;
        }
        return view('inventory/batches/index', [
            'title'       => 'Batches',
            'page'        => $this->batches->search($filters, $this->page($request), $scope),
            'filters'     => $filters,
            'departments' => $this->departments->options($scope),
            'scoped'      => $scope !== null,
            'showValues'  => $this->gate()->can('valuation.view'),
            'warningDays' => (int) setting('expiry_warning_days', 90),
            'criticalDays'=> (int) setting('expiry_critical_days', 30),
        ]);
    }

    public function show(Request $request, int $id): Response
    {
        $batch = $this->findOr404($this->batches->find($id), 'Batch');
        $scope = $this->gate()->departmentScope('stock.view');
        $locations = $this->batches->locations($id, $scope);
        $movements = $this->gate()->can('stock.movements.view') ? $this->movements->forBatch($id, $this->gate()->departmentScope('stock.movements.view')) : [];
        // Department users may only open batches that touched their departments.
        if ($scope !== null && !$locations && !$movements) {
            throw new HttpException(404, 'Batch not found.');
        }
        return view('inventory/batches/show', [
            'title'      => 'Batch ' . $batch['batch_code'],
            'batch'      => $batch,
            'locations'  => $locations,
            'movements'  => $movements,
            'showValues' => $this->gate()->can('valuation.view'),
        ]);
    }

    /** /inventory/batches/code/{code} — open a batch by its system code (used by scanning later). */
    public function byCode(Request $request, string $code): Response
    {
        $batch = $this->batches->findByCode(strtoupper($code));
        if (!$batch) {
            throw new HttpException(404, 'No batch with that code.');
        }
        return redirect(route('inventory.batches.show', ['id' => $batch['id']]));
    }
}
