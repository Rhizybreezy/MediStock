<?php
declare(strict_types=1);

namespace App\Controllers\Inventory;

use App\Controllers\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Exceptions\BusinessRuleException;
use App\Repositories\ConsumptionRepository;
use App\Repositories\DepartmentRepository;
use App\Repositories\LocationRepository;
use App\Services\Department\ConsumptionService;

final class ConsumptionController extends Controller
{
    public function __construct(
        private ConsumptionRepository $records,
        private LocationRepository $locations,
        private DepartmentRepository $departments,
        private ConsumptionService $service,
    ) {
    }

    public function index(Request $request): Response
    {
        $filters = $this->filters($request, ['department_id', 'purpose', 'from', 'to']);
        foreach (['from', 'to'] as $d) {
            if ($filters[$d] && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $filters[$d])) {
                $filters[$d] = null;
            }
        }
        $scope = $this->gate()->departmentScope('stock.view');
        return view('consumption/index', [
            'title'       => 'Consumption records',
            'page'        => $this->records->search($filters, $this->page($request), $scope),
            'filters'     => $filters,
            'purposes'    => ConsumptionService::PURPOSES,
            'departments' => $this->departments->options($scope),
            'showValues'  => $this->gate()->can('valuation.view'),
        ]);
    }

    public function create(Request $request): Response
    {
        return view('consumption/create', [
            'title'     => 'Record consumption',
            'locations' => $this->locations->options($this->gate()->departmentScope('consumption.record'), ['transit', 'quarantine']),
            'purposes'  => ConsumptionService::PURPOSES,
            'scripts'   => ['vendor/html5-qrcode/html5-qrcode.min.js', 'js/components/scanner.js', 'js/components/line-items.js', 'js/pages/issue-form.js'],
        ]);
    }

    public function store(Request $request): Response
    {
        $header = Validator::validate($request->all(), [
            'source_location_id' => 'required|integer|exists:inventory_locations,id',
            'purpose'            => 'required|in:' . implode(',', array_keys(ConsumptionService::PURPOSES)),
            'notes'              => 'nullable|string|max:500',
        ], ['source_location_id' => 'Location']);
        $lines = [];
        foreach ($this->lines($request, ['product_id', 'quantity', 'unit_id', 'batch_id']) as $i => $l) {
            if (!ctype_digit((string) $l['product_id']) || !is_numeric($l['quantity'])) {
                throw new BusinessRuleException('Line ' . ($i + 1) . ': choose a product and enter a quantity.');
            }
            $lines[] = [
                'product_id' => (int) $l['product_id'], 'quantity' => (float) $l['quantity'],
                'unit_id' => ctype_digit((string) $l['unit_id']) ? (int) $l['unit_id'] : null,
                'batch_id' => ctype_digit((string) $l['batch_id']) ? (int) $l['batch_id'] : null,
            ];
        }
        $this->service->record(['location_id' => $header['source_location_id'], 'purpose' => $header['purpose'], 'notes' => $header['notes']], $lines, $this->userId());
        flash('success', 'Consumption recorded.');
        return redirect(route('consumption.index'));
    }
}
