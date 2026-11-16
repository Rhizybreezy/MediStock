<?php
declare(strict_types=1);

namespace App\Controllers\Inventory;

use App\Controllers\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Exceptions\HttpException;
use App\Repositories\GoodsReceiptRepository;
use App\Repositories\LocationRepository;
use App\Services\Stock\ReceivingService;

final class ReceiptController extends Controller
{
    public function __construct(
        private GoodsReceiptRepository $receipts,
        private LocationRepository $locations,
        private ReceivingService $service,
    ) {
    }

    public function index(Request $request): Response
    {
        $filters = $this->filters($request, ['q', 'type']);
        $scope = $this->gate()->departmentScope('stock.view');
        return view('inventory/receipts/index', [
            'title'      => 'Goods receipts',
            'page'       => $this->receipts->search($filters, $this->page($request), $scope),
            'filters'    => $filters,
            'types'      => ReceivingService::TYPES + ['purchase_order' => 'Purchase order'],
            'canReceive' => $this->gate()->canAny(['goods_receipts.direct', 'stock.opening_balance']),
            'canReceivePo' => $this->gate()->can('goods_receipts.create'),
            'showValues' => $this->gate()->can('valuation.view'),
        ]);
    }

    public function create(Request $request): Response
    {
        $types = [];
        foreach (ReceivingService::TYPES as $key => $label) {
            if ($this->gate()->can($key === 'opening_balance' ? 'stock.opening_balance' : 'goods_receipts.direct')) {
                $types[$key] = $label;
            }
        }
        if (!$types) {
            throw new \App\Exceptions\AuthorizationException();
        }
        $perm = isset($types['direct']) ? 'goods_receipts.direct' : 'stock.opening_balance';
        $db = app(\App\Core\Database::class);
        return view('inventory/receipts/create', [
            'title'     => 'Receive stock',
            'types'     => $types,
            'locations' => $this->locations->options($this->gate()->departmentScope($perm), ['transit', 'quarantine']),
            'suppliers' => $db->fetchAll("SELECT id, name FROM suppliers WHERE status = 'active' ORDER BY name"),
            'token'     => bin2hex(random_bytes(16)),
            'scripts'   => ['vendor/html5-qrcode/html5-qrcode.min.js', 'js/components/scanner.js', 'js/components/line-items.js', 'js/pages/receipt-form.js'],
        ]);
    }

    public function store(Request $request): Response
    {
        $header = Validator::validate($request->all(), [
            'receipt_type'          => 'required|in:' . implode(',', array_keys(ReceivingService::TYPES)),
            'receiving_location_id' => 'required|integer|exists:inventory_locations,id',
            'supplier_id'           => 'nullable|integer|exists:suppliers,id',
            'delivery_note_number'  => 'nullable|string|max:60',
            'invoice_number'        => 'nullable|string|max:60',
            'notes'                 => 'nullable|string|max:1000',
            'submission_token'      => 'required|string|regex:/^[a-f0-9]{32}$/',
        ], ['receiving_location_id' => 'Receiving location']);

        $lines = [];
        foreach ($this->lines($request, ['product_id', 'lot_number', 'manufacture_date', 'expiry_date', 'quantity', 'unit_id', 'unit_cost', 'qty_rejected', 'rejection_reason']) as $i => $l) {
            $n = $i + 1;
            if (!ctype_digit((string) $l['product_id'])) {
                throw new \App\Exceptions\BusinessRuleException("Line $n: choose a product.");
            }
            if (!is_numeric($l['quantity'])) {
                throw new \App\Exceptions\BusinessRuleException("Line $n: enter a quantity.");
            }
            if ($l['unit_cost'] !== null && (!is_numeric($l['unit_cost']) || (float) $l['unit_cost'] < 0)) {
                throw new \App\Exceptions\BusinessRuleException("Line $n: unit cost must be a positive number.");
            }
            if ($l['qty_rejected'] !== null && (!is_numeric($l['qty_rejected']) || (float) $l['qty_rejected'] < 0)) {
                throw new \App\Exceptions\BusinessRuleException("Line $n: rejected quantity must be a positive number.");
            }
            $lines[] = [
                'product_id'       => (int) $l['product_id'],
                'lot_number'       => $l['lot_number'] !== null ? mb_substr((string) $l['lot_number'], 0, 60) : null,
                'manufacture_date' => self::dateOrNull($l['manufacture_date']),
                'expiry_date'      => self::dateOrNull($l['expiry_date']),
                'quantity'         => (float) $l['quantity'],
                'unit_id'          => ctype_digit((string) $l['unit_id']) ? (int) $l['unit_id'] : null,
                'unit_cost'        => (float) ($l['unit_cost'] ?? 0),
                'qty_rejected'     => (float) ($l['qty_rejected'] ?? 0),
                'rejection_reason' => $l['rejection_reason'],
            ];
        }

        [$id, $created] = $this->service->receive($header, $lines, $this->userId());
        flash($created ? 'success' : 'info', $created ? 'Stock received and added to inventory.' : 'This receipt was already recorded (duplicate submission ignored).');
        return redirect(route('inventory.receipts.show', ['id' => $id]));
    }

    /** Choose a purchase order to receive against. */
    public function poIndex(Request $request): Response
    {
        return view('inventory/receipts/po_index', ['title' => 'Receive against purchase order', 'orders' => app(\App\Repositories\PurchaseOrderRepository::class)->receivable()]);
    }

    public function poCreate(Request $request, int $id): Response
    {
        $orders = app(\App\Repositories\PurchaseOrderRepository::class);
        $po = $this->findOr404($orders->find($id), 'Purchase order');
        if (!in_array($po['status'], ['approved', 'sent', 'partially_received'], true)) {
            flash('warning', 'Goods can only be received against approved purchase orders.');
            return redirect(route('purchase_orders.show', ['id' => $id]));
        }
        return view('inventory/receipts/po_create', [
            'title'     => 'Receive ' . $po['po_number'],
            'po'        => $po,
            'items'     => array_values(array_filter($orders->items($id), fn ($i) => (float) $i['outstanding_qty'] > 0)),
            'locations' => $this->locations->options($this->gate()->departmentScope('goods_receipts.create'), ['transit', 'quarantine']),
            'token'     => bin2hex(random_bytes(16)),
            'tolerance' => (float) setting('overreceipt_tolerance_pct', 0),
            'scripts'   => ['js/pages/po-receipt.js'],
        ]);
    }

    public function poStore(Request $request, int $id): Response
    {
        $header = Validator::validate($request->all(), [
            'receiving_location_id' => 'required|integer|exists:inventory_locations,id',
            'delivery_note_number'  => 'required|string|max:60',
            'invoice_number'        => 'nullable|string|max:60',
            'notes'                 => 'nullable|string|max:1000',
            'submission_token'      => 'required|string|regex:/^[a-f0-9]{32}$/',
        ], ['delivery_note_number' => 'Delivery note number']);
        $lines = [];
        foreach ($this->lines($request, ['purchase_order_item_id', 'lot_number', 'expiry_date', 'manufacture_date', 'quantity', 'qty_rejected', 'rejection_reason']) as $i => $l) {
            $n = $i + 1;
            if (!ctype_digit((string) $l['purchase_order_item_id'])) {
                continue;
            }
            foreach (['quantity', 'qty_rejected'] as $k) {
                if ($l[$k] !== null && (!is_numeric($l[$k]) || (float) $l[$k] < 0)) {
                    throw new \App\Exceptions\BusinessRuleException("Line $n: quantities must be positive numbers.");
                }
            }
            $lines[] = [
                'purchase_order_item_id' => (int) $l['purchase_order_item_id'], 'lot_number' => $l['lot_number'],
                'expiry_date' => self::dateOrNull($l['expiry_date']), 'manufacture_date' => self::dateOrNull($l['manufacture_date']),
                'quantity' => (float) ($l['quantity'] ?? 0), 'qty_rejected' => (float) ($l['qty_rejected'] ?? 0), 'rejection_reason' => $l['rejection_reason'],
            ];
        }
        [$grnId, $created] = $this->service->receivePurchaseOrder($id, $header, $lines, $this->userId());
        flash($created ? 'success' : 'info', $created ? 'Goods received against the purchase order and added to stock.' : 'This receipt was already recorded (duplicate submission ignored).');
        return redirect(route('inventory.receipts.show', ['id' => $grnId]));
    }

    public function show(Request $request, int $id): Response
    {
        $grn = $this->findOr404($this->receipts->find($id), 'Goods receipt');
        if (!$this->gate()->can('stock.view', (int) $grn['department_id'])) {
            throw new HttpException(404, 'Goods receipt not found.');
        }
        return view('inventory/receipts/show', [
            'title'      => $grn['grn_number'],
            'grn'        => $grn,
            'items'      => $this->receipts->items($id),
            'types'      => ReceivingService::TYPES + ['purchase_order' => 'Purchase order'],
            'showValues' => $this->gate()->can('valuation.view'),
        ]);
    }
}
