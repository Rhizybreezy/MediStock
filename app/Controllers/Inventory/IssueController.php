<?php
declare(strict_types=1);

namespace App\Controllers\Inventory;

use App\Controllers\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Exceptions\BusinessRuleException;
use App\Repositories\DepartmentRepository;
use App\Repositories\LocationRepository;
use App\Repositories\TransferRepository;
use App\Services\Stock\IssueService;

/** Issues (store → department) and the incoming-deliveries inbox. */
final class IssueController extends Controller
{
    public function __construct(
        private TransferRepository $transfers,
        private LocationRepository $locations,
        private DepartmentRepository $departments,
        private IssueService $service,
    ) {
    }

    public function index(Request $request): Response
    {
        $filters = $this->filters($request, ['q', 'status']);
        $filters['type'] = 'issue';
        return view('inventory/issues/index', [
            'title'   => 'Stock issues',
            'mode'    => 'outgoing',
            'page'    => $this->transfers->search($filters, $this->page($request), $this->gate()->departmentScope('stock.issue'), 'outgoing'),
            'filters' => $filters,
        ]);
    }

    public function incoming(Request $request): Response
    {
        $filters = $this->filters($request, ['q', 'status']);
        if ($filters['status'] === null) {
            $filters['status'] = 'open';
        }
        return view('inventory/issues/index', [
            'title'   => 'Incoming deliveries',
            'mode'    => 'incoming',
            'page'    => $this->transfers->search($filters, $this->page($request), $this->gate()->departmentScope('stock.confirm_receipt'), 'incoming'),
            'filters' => $filters,
        ]);
    }

    public function create(Request $request): Response
    {
        return view('inventory/issues/create', [
            'title'        => 'Issue stock',
            'sources'      => $this->locations->options($this->gate()->departmentScope('stock.issue'), ['transit', 'quarantine']),
            'destinations' => $this->locations->options(null, ['transit', 'quarantine']),
            'scripts'      => ['vendor/html5-qrcode/html5-qrcode.min.js', 'js/components/scanner.js', 'js/components/line-items.js', 'js/pages/issue-form.js'],
        ]);
    }

    public function store(Request $request): Response
    {
        $header = Validator::validate($request->all(), [
            'source_location_id'      => 'required|integer|exists:inventory_locations,id',
            'destination_location_id' => 'required|integer|exists:inventory_locations,id|different:source_location_id',
            'reason'                  => 'nullable|string|max:255',
        ], ['source_location_id' => 'Issue from', 'destination_location_id' => 'Deliver to']);
        $lines = [];
        foreach ($this->lines($request, ['product_id', 'quantity', 'unit_id', 'batch_id']) as $i => $l) {
            $n = $i + 1;
            if (!ctype_digit((string) $l['product_id']) || !is_numeric($l['quantity'])) {
                throw new BusinessRuleException("Line $n: choose a product and enter a quantity.");
            }
            $lines[] = [
                'product_id' => (int) $l['product_id'],
                'quantity'   => (float) $l['quantity'],
                'unit_id'    => ctype_digit((string) $l['unit_id']) ? (int) $l['unit_id'] : null,
                'batch_id'   => ctype_digit((string) $l['batch_id']) ? (int) $l['batch_id'] : null,
            ];
        }
        $id = $this->service->dispatch($header, $lines, $this->userId());
        flash('success', 'Stock dispatched. It is now in transit until the receiving department confirms receipt.');
        return redirect(route('inventory.issues.show', ['id' => $id]));
    }

    public function confirm(Request $request, int $id): Response
    {
        $received = [];
        foreach ((array) $request->input('received', []) as $itemId => $qty) {
            if (!ctype_digit((string) $itemId)) {
                continue;
            }
            $qty = trim((string) $qty);
            if ($qty === '') {
                continue;
            }
            if (!is_numeric($qty)) {
                throw new BusinessRuleException('Received quantities must be numbers.');
            }
            $received[(int) $itemId] = (float) $qty;
        }
        $notes = trim((string) $request->input('notes', ''));
        $this->service->confirmReceipt($id, $received, $notes !== '' ? mb_substr($notes, 0, 255) : null, $this->userId());
        flash('success', 'Receipt confirmed. The stock is now in your department inventory.');
        return redirect(route('inventory.issues.show', ['id' => $id]));
    }

    public function returnOutstanding(Request $request, int $id): Response
    {
        $data = Validator::validate($request->all(), ['reason' => 'required|string|min:3|max:200']);
        $this->service->returnOutstanding($id, $data['reason'], $this->userId());
        flash('success', 'Outstanding in-transit stock was returned to the source location.');
        return redirect(route('inventory.issues.show', ['id' => $id]));
    }
}
