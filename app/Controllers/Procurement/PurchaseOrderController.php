<?php
declare(strict_types=1);

namespace App\Controllers\Procurement;

use App\Controllers\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Exceptions\BusinessRuleException;
use App\Repositories\DepartmentRepository;
use App\Repositories\LocationRepository;
use App\Repositories\ProductRepository;
use App\Repositories\PurchaseOrderRepository;
use App\Repositories\PurchaseRequestRepository;
use App\Repositories\SupplierRepository;
use App\Services\Procurement\PurchaseOrderService;
use App\Services\Reporting\PdfExporter;

final class PurchaseOrderController extends Controller
{
    public function __construct(
        private PurchaseOrderRepository $orders,
        private PurchaseOrderService $service,
        private SupplierRepository $suppliers,
        private LocationRepository $locations,
        private DepartmentRepository $departments,
        private ProductRepository $products,
        private PurchaseRequestRepository $requests,
        private PdfExporter $pdf,
    ) {
    }

    public function index(Request $request): Response
    {
        $filters = $this->filters($request, ['q', 'status', 'supplier_id']);
        if ($filters['status'] === null) {
            $filters['status'] = 'open';
        }
        if ($filters['status'] === 'all') {
            $filters['status'] = '';
        }
        return view('purchase_orders/index', [
            'title'     => 'Purchase orders',
            'page'      => $this->orders->search($filters, $this->page($request)),
            'filters'   => $filters,
            'suppliers' => $this->suppliers->options(false),
            'toOrder'   => count($this->requests->itemsToOrder()),
        ]);
    }

    public function create(Request $request): Response
    {
        return $this->form(null);
    }

    public function edit(Request $request, int $id): Response
    {
        $po = $this->findOr404($this->orders->find($id), 'Purchase order');
        if ($po['status'] !== 'draft') {
            flash('warning', 'Only draft purchase orders can be edited.');
            return redirect(route('purchase_orders.show', ['id' => $id]));
        }
        return $this->form($po);
    }

    private function form(?array $po): Response
    {
        $initial = [];
        if ($po) {
            foreach ($this->orders->items((int) $po['id']) as $it) {
                $p = $this->products->find((int) $it['product_id']);
                $initial[] = [
                    'product' => ['id' => (int) $p['id'], 'code' => $p['product_code'], 'name' => $p['name'], 'unit' => $p['unit_code'], 'detail' => '', 'units' => $this->products->unitChoices((int) $p['id'])],
                    'values'  => ['quantity' => (float) $it['ordered_qty'], 'unit_id' => (int) $it['order_unit_id'], 'unit_price' => (float) $it['unit_price'], 'purchase_request_item_id' => $it['purchase_request_item_id']],
                ];
            }
        }
        return view('purchase_orders/form', [
            'title'     => $po ? 'Edit ' . $po['po_number'] : 'New purchase order',
            'po'        => $po,
            'suppliers' => $this->suppliers->options(),
            'locations' => $this->locations->options(null, ['transit', 'quarantine'], $this->departments->centralStoreId()),
            'initial'   => $initial,
            'scripts'   => ['js/components/line-items.js', 'js/pages/po-form.js'],
        ]);
    }

    public function store(Request $request): Response
    {
        [$header, $lines] = $this->validated($request);
        $id = $this->service->saveDraft(null, $header, $lines, $this->userId());
        flash('success', 'Draft purchase order created.');
        return redirect(route('purchase_orders.show', ['id' => $id]));
    }

    public function update(Request $request, int $id): Response
    {
        [$header, $lines] = $this->validated($request);
        $this->service->saveDraft($id, $header, $lines, $this->userId());
        flash('success', 'Purchase order saved.');
        return redirect(route('purchase_orders.show', ['id' => $id]));
    }

    public function show(Request $request, int $id): Response
    {
        $po = $this->findOr404($this->orders->find($id), 'Purchase order');
        $g = $this->gate();
        return view('purchase_orders/show', [
            'title'      => $po['po_number'],
            'po'         => $po,
            'items'      => $this->orders->items($id),
            'receipts'   => $this->orders->receipts($id),
            'canManage'  => $g->can('purchase_orders.manage'),
            'canDecide'  => $po['status'] === 'pending_approval' && $g->can('purchase_orders.approve') && (int) $po['created_by'] !== $this->userId(),
            'canReceive' => in_array($po['status'], ['approved', 'sent', 'partially_received'], true) && $g->can('goods_receipts.create'),
            'own'        => (int) $po['created_by'] === $this->userId(),
        ]);
    }

    public function pdf(Request $request, int $id): Response
    {
        $po = $this->findOr404($this->orders->find($id), 'Purchase order');
        if (in_array($po['status'], ['draft', 'pending_approval', 'cancelled'], true)) {
            throw new BusinessRuleException('Only approved purchase orders can be printed for the supplier.');
        }
        return $this->pdf->render('purchase_orders/pdf', ['title' => $po['po_number'], 'po' => $po, 'items' => $this->orders->items($id)], $po['po_number'] . '.pdf');
    }

    public function fromRequests(Request $request): Response
    {
        $items = $this->requests->itemsToOrder();
        return view('purchase_orders/from_requests', [
            'title'     => 'Order approved requests',
            'items'     => $items,
            'quotes'    => $this->suppliers->quotesFor(array_unique(array_map(fn ($i) => (int) $i['product_id'], $items))),
            'suppliers' => $this->suppliers->options(),
            'locations' => $this->locations->options(null, ['transit', 'quarantine'], $this->departments->centralStoreId()),
        ]);
    }

    public function storeFromRequests(Request $request): Response
    {
        $location = $request->int('delivery_location_id');
        $selection = [];
        foreach ((array) $request->input('sel', []) as $itemId => $row) {
            if (!ctype_digit((string) $itemId) || !is_array($row) || empty($row['include'])) {
                continue;
            }
            if (!is_numeric($row['quantity'] ?? null) || !is_numeric($row['unit_price'] ?? null)) {
                throw new BusinessRuleException('Each selected item needs a quantity and a unit price.');
            }
            $selection[(int) $itemId] = ['supplier_id' => (int) ($row['supplier_id'] ?? 0), 'quantity' => (float) $row['quantity'], 'unit_price' => (float) $row['unit_price']];
        }
        $ids = $this->service->createFromRequests($selection, $location, $this->userId());
        flash('success', count($ids) . ' draft purchase order(s) created, one per supplier. Review and submit them for approval.');
        return redirect(count($ids) === 1 ? route('purchase_orders.show', ['id' => $ids[0]]) : route('purchase_orders.index', [], ['status' => 'draft']));
    }

    public function action(Request $request, int $id): Response
    {
        $action = (string) $request->input('action');
        $reason = trim((string) $request->input('reason', ''));
        $uid = $this->userId();
        match ($action) {
            'submit'  => $this->service->submit($id, $uid),
            'approve' => $this->service->decide($id, true, null, $uid),
            'return'  => $this->service->decide($id, false, $reason ?: null, $uid),
            'send'    => $this->service->markSent($id, $uid),
            'cancel'  => $reason !== '' ? $this->service->cancel($id, $reason, $uid) : throw new BusinessRuleException('Give a reason for cancelling.'),
            'close'   => $reason !== '' ? $this->service->close($id, $reason, $uid) : throw new BusinessRuleException('Give a reason for closing.'),
            default   => throw new BusinessRuleException('Unknown action.'),
        };
        $messages = ['submit' => 'Submitted for approval.', 'approve' => 'Purchase order approved.', 'return' => 'Returned to draft for changes.',
                     'send' => 'Marked as sent to the supplier.', 'cancel' => 'Purchase order cancelled.', 'close' => 'Purchase order closed.'];
        flash('success', $messages[$action]);
        return redirect(route('purchase_orders.show', ['id' => $id]));
    }

    private function validated(Request $request): array
    {
        $header = Validator::validate($request->all(), [
            'supplier_id'            => 'required|integer|exists:suppliers,id',
            'delivery_location_id'   => 'required|integer|exists:inventory_locations,id',
            'expected_delivery_date' => 'nullable|date',
            'tax_amount'             => 'nullable|numeric|min:0|max:99999999',
            'notes'                  => 'nullable|string|max:2000',
        ], ['delivery_location_id' => 'Delivery location']);
        $lines = [];
        foreach ($this->lines($request, ['product_id', 'quantity', 'unit_id', 'unit_price', 'purchase_request_item_id']) as $i => $l) {
            if (!ctype_digit((string) $l['product_id']) || !is_numeric($l['quantity']) || !is_numeric($l['unit_price']) || !ctype_digit((string) $l['unit_id'])) {
                throw new BusinessRuleException('Line ' . ($i + 1) . ': product, quantity, unit and unit price are required.');
            }
            $lines[] = [
                'product_id' => (int) $l['product_id'], 'order_unit_id' => (int) $l['unit_id'], 'quantity' => (float) $l['quantity'],
                'unit_price' => (float) $l['unit_price'], 'purchase_request_item_id' => ctype_digit((string) $l['purchase_request_item_id']) ? (int) $l['purchase_request_item_id'] : null,
            ];
        }
        return [$header, $lines];
    }
}
