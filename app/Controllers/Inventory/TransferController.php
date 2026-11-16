<?php
declare(strict_types=1);

namespace App\Controllers\Inventory;

use App\Controllers\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Exceptions\BusinessRuleException;
use App\Exceptions\HttpException;
use App\Repositories\DepartmentRepository;
use App\Repositories\LocationRepository;
use App\Repositories\MovementRepository;
use App\Repositories\TransferRepository;
use App\Services\Department\TransferService;

/** Department transfers and returns to store (approval → dispatch → receipt). */
final class TransferController extends Controller
{
    public function __construct(
        private TransferRepository $transfers,
        private LocationRepository $locations,
        private DepartmentRepository $departments,
        private MovementRepository $movements,
        private TransferService $service,
    ) {
    }

    public function index(Request $request): Response
    {
        $filters = $this->filters($request, ['q', 'status', 'type']);
        if (!in_array($filters['type'], ['transfer', 'return_to_store'], true)) {
            $filters['type'] = null;
        }
        $page = $this->transfers->search(
            array_merge($filters, ['type' => $filters['type'] ?: 'transfer_or_return']),
            $this->page($request),
            $this->gate()->departmentScope('stock.view'),
            'any'
        );
        return view('transfers/index', ['title' => 'Transfers & returns', 'page' => $page, 'filters' => $filters]);
    }

    public function create(Request $request): Response
    {
        $type = $request->query('type') === 'return_to_store' ? 'return_to_store' : 'transfer';
        $central = $this->departments->centralStoreId();
        return view('transfers/create', [
            'title'        => $type === 'return_to_store' ? 'Return stock to store' : 'Transfer stock',
            'type'         => $type,
            'sources'      => $this->locations->options($this->gate()->departmentScope('transfers.create'), ['transit']),
            'destinations' => $type === 'return_to_store'
                ? $this->locations->options(null, ['transit'], $central)
                : $this->locations->options(null, ['transit', 'quarantine']),
            'scripts'      => ['vendor/html5-qrcode/html5-qrcode.min.js', 'js/components/scanner.js', 'js/components/line-items.js', 'js/pages/issue-form.js'],
        ]);
    }

    public function store(Request $request): Response
    {
        $type = $request->input('type') === 'return_to_store' ? 'return_to_store' : 'transfer';
        $header = Validator::validate($request->all(), [
            'source_location_id'      => 'required|integer|exists:inventory_locations,id',
            'destination_location_id' => 'required|integer|exists:inventory_locations,id|different:source_location_id',
            'reason'                  => 'required|string|min:3|max:255',
        ], ['source_location_id' => 'From', 'destination_location_id' => 'To']);
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
        $id = $this->service->create($type, $header, $lines, $this->userId());
        flash('success', 'Submitted for approval.');
        return redirect(route('transfers.show', ['id' => $id]));
    }

    /** Shared detail page for issues, transfers and returns. */
    public function show(Request $request, int $id): Response
    {
        $t = $this->findOr404($this->transfers->find($id), 'Transfer');
        $g = $this->gate();
        if (!$g->can('stock.view', (int) $t['source_department_id']) && !$g->can('stock.view', (int) $t['destination_department_id'])) {
            throw new HttpException(404, 'Transfer not found.');
        }
        $open = in_array($t['status'], ['dispatched', 'partially_received'], true);
        $uid = $this->userId();
        return view('inventory/issues/show', [
            'title'       => $t['transfer_number'],
            't'           => $t,
            'items'       => $this->transfers->items($id),
            'movements'   => $g->can('stock.movements.view') ? $this->movements->forTransaction('stock_transfer', $id) : [],
            'canConfirm'  => $open && $g->can('stock.confirm_receipt', (int) $t['destination_department_id']),
            'canReturn'   => $open && ($g->can('stock.issue', (int) $t['source_department_id']) || ($t['type'] !== 'issue' && $g->can('transfers.create', (int) $t['source_department_id']))),
            'canApprove'  => $t['status'] === 'pending_approval' && $g->can('transfers.approve', (int) $t['source_department_id']) && (int) $t['requested_by'] !== $uid,
            'ownTransfer' => (int) $t['requested_by'] === $uid,
            'canDispatch' => $t['status'] === 'approved' && ($g->can('transfers.create', (int) $t['source_department_id']) || $g->can('stock.issue', (int) $t['source_department_id'])),
            'canCancel'   => in_array($t['status'], ['pending_approval', 'approved'], true) && $g->can('transfers.create', (int) $t['source_department_id']),
        ]);
    }

    public function approve(Request $request, int $id): Response
    {
        if ($request->input('decision') === 'reject') {
            $data = Validator::validate($request->all(), ['rejection_reason' => 'required|string|min:3|max:255'], ['rejection_reason' => 'Reason']);
            $this->service->reject($id, $data['rejection_reason'], $this->userId());
            flash('success', 'Transfer rejected.');
        } else {
            $approved = [];
            foreach ((array) $request->input('approved', []) as $itemId => $qty) {
                if (ctype_digit((string) $itemId) && is_numeric($qty)) {
                    $approved[(int) $itemId] = (float) $qty;
                }
            }
            $this->service->approve($id, $approved, $this->userId());
            flash('success', 'Transfer approved. It can now be dispatched.');
        }
        return redirect(route('transfers.show', ['id' => $id]));
    }

    public function dispatch(Request $request, int $id): Response
    {
        $this->service->dispatch($id, $this->userId());
        flash('success', 'Dispatched. Stock is in transit until the destination confirms receipt.');
        return redirect(route('transfers.show', ['id' => $id]));
    }

    public function cancel(Request $request, int $id): Response
    {
        $this->service->cancel($id, $this->userId());
        flash('success', 'Transfer cancelled.');
        return redirect(route('transfers.show', ['id' => $id]));
    }
}
