<?php
declare(strict_types=1);

namespace App\Controllers\Procurement;

use App\Controllers\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Exceptions\BusinessRuleException;
use App\Exceptions\HttpException;
use App\Repositories\DepartmentRepository;
use App\Repositories\PurchaseRequestRepository;
use App\Repositories\SupplierRepository;
use App\Services\Procurement\PurchaseRequestService;

final class PurchaseRequestController extends Controller
{
    public function __construct(
        private PurchaseRequestRepository $requests,
        private PurchaseRequestService $service,
        private DepartmentRepository $departments,
        private SupplierRepository $suppliers,
    ) {
    }

    public function index(Request $request): Response
    {
        $filters = $this->filters($request, ['q', 'status']);
        $g = $this->gate();
        $scope = $g->can('purchase_requests.approve') || $g->can('purchase_orders.manage') ? null : $g->departmentScope('purchase_requests.create');
        return view('purchase_requests/index', ['title' => 'Purchase requests', 'page' => $this->requests->search($filters, $this->page($request), $scope), 'filters' => $filters]);
    }

    public function create(Request $request): Response
    {
        return view('purchase_requests/create', [
            'title'       => 'New purchase request',
            'departments' => $this->departments->options($this->gate()->departmentScope('purchase_requests.create')),
            'suppliers'   => $this->suppliers->options(),
            'scripts'     => ['js/components/line-items.js', 'js/pages/pr-form.js'],
        ]);
    }

    public function lowStock(Request $request): Response
    {
        return view('purchase_requests/low_stock', [
            'title'       => 'Reorder low-stock items',
            'rows'        => $this->service->lowStockSuggestions(),
            'departments' => $this->departments->options($this->gate()->departmentScope('purchase_requests.create')),
            'suppliers'   => $this->suppliers->options(),
        ]);
    }

    public function store(Request $request): Response
    {
        $header = Validator::validate($request->all(), [
            'department_id' => 'required|integer|exists:departments,id',
            'priority'      => 'required|in:routine,urgent,emergency',
            'justification' => 'required|string|min:5|max:2000',
        ]);
        $fromLowStock = $request->input('source') === 'reorder_suggestion';
        $lines = [];
        foreach ($this->lines($request, ['include', 'product_id', 'quantity', 'unit_id', 'estimated_unit_cost', 'preferred_supplier_id', 'notes']) as $i => $l) {
            if ($fromLowStock && empty($l['include'])) {
                continue;
            }
            if (!ctype_digit((string) $l['product_id']) || !is_numeric($l['quantity'])) {
                throw new BusinessRuleException('Line ' . ($i + 1) . ': choose a product and enter a quantity.');
            }
            if ($l['estimated_unit_cost'] !== null && (!is_numeric($l['estimated_unit_cost']) || (float) $l['estimated_unit_cost'] < 0)) {
                throw new BusinessRuleException('Line ' . ($i + 1) . ': estimated cost must be a positive number.');
            }
            $lines[] = [
                'product_id' => (int) $l['product_id'], 'quantity' => (float) $l['quantity'],
                'unit_id' => ctype_digit((string) $l['unit_id']) ? (int) $l['unit_id'] : null,
                'estimated_unit_cost' => $l['estimated_unit_cost'] !== null ? (float) $l['estimated_unit_cost'] : null,
                'preferred_supplier_id' => ctype_digit((string) $l['preferred_supplier_id']) ? (int) $l['preferred_supplier_id'] : null,
                'notes' => $l['notes'] !== null ? mb_substr((string) $l['notes'], 0, 255) : null,
            ];
        }
        $submit = $request->input('action') !== 'draft';
        $id = $this->service->create($header, $lines, $submit, $this->userId(), $fromLowStock ? 'reorder_suggestion' : 'manual');
        flash('success', $submit ? 'Purchase request submitted for approval.' : 'Purchase request saved as draft.');
        return redirect(route('purchase_requests.show', ['id' => $id]));
    }

    public function show(Request $request, int $id): Response
    {
        $pr = $this->findOr404($this->requests->find($id), 'Purchase request');
        $g = $this->gate();
        $dept = (int) $pr['department_id'];
        if (!$g->can('purchase_requests.create', $dept) && !$g->can('purchase_requests.approve', $dept) && !$g->can('purchase_orders.manage')) {
            throw new HttpException(404, 'Purchase request not found.');
        }
        return view('purchase_requests/show', [
            'title'      => $pr['pr_number'],
            'pr'         => $pr,
            'items'      => $this->requests->items($id),
            'orders'     => $this->requests->orders($id),
            'canDecide'  => $pr['status'] === 'submitted' && $g->can('purchase_requests.approve', $dept) && (int) $pr['requested_by'] !== $this->userId(),
            'canSubmit'  => $pr['status'] === 'draft' && $g->can('purchase_requests.create', $dept),
            'canCancel'  => in_array($pr['status'], ['draft', 'submitted', 'approved'], true) && (float) $pr['ordered_total'] == 0.0 && $g->can('purchase_requests.create', $dept),
            'canOrder'   => in_array($pr['status'], ['approved', 'partially_ordered'], true) && $g->can('purchase_orders.manage'),
            'own'        => (int) $pr['requested_by'] === $this->userId(),
        ]);
    }

    public function submit(Request $request, int $id): Response
    {
        $this->service->submit($id, $this->userId());
        flash('success', 'Submitted for approval.');
        return redirect(route('purchase_requests.show', ['id' => $id]));
    }

    public function decide(Request $request, int $id): Response
    {
        $approve = $request->input('decision') === 'approve';
        $reason = trim((string) $request->input('rejection_reason', ''));
        $this->service->decide($id, $approve, $reason !== '' ? mb_substr($reason, 0, 255) : null, $this->userId());
        flash('success', $approve ? 'Purchase request approved.' : 'Purchase request rejected.');
        return redirect(route('purchase_requests.show', ['id' => $id]));
    }

    public function cancel(Request $request, int $id): Response
    {
        $this->service->cancel($id, $this->userId());
        flash('success', 'Purchase request cancelled.');
        return redirect(route('purchase_requests.show', ['id' => $id]));
    }
}
