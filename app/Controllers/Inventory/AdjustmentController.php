<?php
declare(strict_types=1);

namespace App\Controllers\Inventory;

use App\Controllers\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Exceptions\BusinessRuleException;
use App\Exceptions\HttpException;
use App\Repositories\AdjustmentRepository;
use App\Repositories\LocationRepository;
use App\Services\Department\AdjustmentService;

final class AdjustmentController extends Controller
{
    public function __construct(
        private AdjustmentRepository $adjustments,
        private LocationRepository $locations,
        private AdjustmentService $service,
    ) {
    }

    public function index(Request $request): Response
    {
        $filters = $this->filters($request, ['q', 'status', 'reason']);
        $perm = $this->gate()->can('adjustments.approve') ? 'adjustments.approve' : 'adjustments.request';
        return view('adjustments/index', [
            'title'      => 'Stock adjustments',
            'page'       => $this->adjustments->search($filters, $this->page($request), $this->gate()->departmentScope($perm)),
            'filters'    => $filters,
            'reasons'    => AdjustmentService::REASONS,
            'showValues' => $this->gate()->can('valuation.view'),
        ]);
    }

    public function create(Request $request): Response
    {
        $reasons = AdjustmentService::REASONS;
        unset($reasons['count_variance']);
        return view('adjustments/create', [
            'title'     => 'Request stock adjustment',
            'locations' => $this->locations->options($this->gate()->departmentScope('adjustments.request'), ['transit']),
            'reasons'   => $reasons,
            'scripts'   => ['js/pages/adjustment-form.js'],
        ]);
    }

    public function store(Request $request): Response
    {
        $data = Validator::validate($request->all(), [
            'location_id' => 'required|integer|exists:inventory_locations,id',
            'reason_code' => 'required|in:' . implode(',', array_keys(AdjustmentService::REASONS)),
            'notes'       => 'required|string|min:5|max:1000',
        ], ['notes' => 'Explanation']);
        $lines = [];
        foreach ($this->lines($request, ['batch_id', 'quantity_change', 'reason']) as $i => $l) {
            if (!ctype_digit((string) $l['batch_id']) || !is_numeric($l['quantity_change'])) {
                throw new BusinessRuleException('Line ' . ($i + 1) . ': choose a batch and enter the quantity change.');
            }
            $lines[] = ['batch_id' => (int) $l['batch_id'], 'quantity_change' => (float) $l['quantity_change'], 'reason' => $l['reason']];
        }
        $id = $this->service->request($data['location_id'], $data['reason_code'], $lines, $data['notes'], $this->userId());
        flash('success', 'Adjustment submitted. Stock will change only after another authorised person approves it.');
        return redirect(route('adjustments.show', ['id' => $id]));
    }

    public function show(Request $request, int $id): Response
    {
        $adj = $this->findOr404($this->adjustments->find($id), 'Adjustment');
        $g = $this->gate();
        $dept = (int) $adj['department_id'];
        if (!$g->can('adjustments.request', $dept) && !$g->can('adjustments.approve', $dept) && !$g->can('stock.view', $dept)) {
            throw new HttpException(404, 'Adjustment not found.');
        }
        return view('adjustments/show', [
            'title'      => $adj['adjustment_number'],
            'adj'        => $adj,
            'items'      => $this->adjustments->items($id),
            'reasons'    => AdjustmentService::REASONS,
            'canApprove' => $adj['status'] === 'pending' && $g->can('adjustments.approve', $dept) && (int) $adj['requested_by'] !== $this->userId(),
            'own'        => (int) $adj['requested_by'] === $this->userId(),
            'showValues' => $g->can('valuation.view'),
        ]);
    }

    public function approve(Request $request, int $id): Response
    {
        if ($request->input('decision') === 'reject') {
            $data = Validator::validate($request->all(), ['rejection_reason' => 'required|string|min:3|max:255'], ['rejection_reason' => 'Reason']);
            $this->service->reject($id, $data['rejection_reason'], $this->userId());
            flash('success', 'Adjustment rejected. Stock was not changed.');
        } else {
            $this->service->approve($id, $this->userId());
            flash('success', 'Adjustment approved and posted to the stock ledger.');
        }
        return redirect(route('adjustments.show', ['id' => $id]));
    }
}
