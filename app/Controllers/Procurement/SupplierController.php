<?php
declare(strict_types=1);

namespace App\Controllers\Procurement;

use App\Controllers\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Repositories\PurchaseOrderRepository;
use App\Repositories\SupplierRepository;
use App\Services\Procurement\SupplierPerformanceService;
use App\Services\Procurement\SupplierService;

final class SupplierController extends Controller
{
    public function __construct(
        private SupplierRepository $suppliers,
        private SupplierService $service,
        private SupplierPerformanceService $performance,
        private PurchaseOrderRepository $orders,
    ) {
    }

    public function index(Request $request): Response
    {
        $filters = $this->filters($request, ['q', 'status']);
        return view('suppliers/index', ['title' => 'Suppliers', 'page' => $this->suppliers->search($filters, $this->page($request)), 'filters' => $filters]);
    }

    public function create(Request $request): Response
    {
        return view('suppliers/form', ['title' => 'Register supplier', 'supplier' => null]);
    }

    public function edit(Request $request, int $id): Response
    {
        return view('suppliers/form', ['title' => 'Edit supplier', 'supplier' => $this->findOr404($this->suppliers->find($id), 'Supplier')]);
    }

    public function store(Request $request): Response
    {
        $id = $this->service->save(null, $this->validated($request, null), $this->userId());
        flash('success', 'Supplier registered.');
        return redirect(route('suppliers.show', ['id' => $id]));
    }

    public function update(Request $request, int $id): Response
    {
        $this->findOr404($this->suppliers->find($id), 'Supplier');
        $this->service->save($id, $this->validated($request, $id), $this->userId());
        flash('success', 'Supplier saved.');
        return redirect(route('suppliers.show', ['id' => $id]));
    }

    public function show(Request $request, int $id): Response
    {
        $supplier = $this->findOr404($this->suppliers->find($id), 'Supplier');
        return view('suppliers/show', [
            'title'      => $supplier['name'],
            'supplier'   => $supplier,
            'products'   => $this->suppliers->products($id),
            'metrics'    => $this->performance->metrics($id)[0] ?? null,
            'prices'     => $this->gate()->can('valuation.view') || $this->gate()->can('purchase_orders.manage') ? $this->performance->priceHistory($id, 30) : [],
            'orders'     => $this->orders->search(['supplier_id' => $id], 1)->items,
            'canManage'  => $this->gate()->can('suppliers.manage'),
            'scripts'    => ['js/components/line-items.js', 'js/pages/supplier-show.js'],
        ]);
    }

    public function saveProduct(Request $request, int $id): Response
    {
        $this->findOr404($this->suppliers->find($id), 'Supplier');
        $data = Validator::validate($request->all(), [
            'product_id'        => 'required|integer|exists:products,id',
            'supplier_sku'      => 'nullable|string|max:60',
            'quoted_unit_price' => 'nullable|numeric|min:0|max:99999999',
            'quote_valid_until' => 'nullable|date',
            'lead_time_days'    => 'nullable|integer|min:0|max:365',
            'is_preferred'      => 'nullable|boolean',
        ], ['quoted_unit_price' => 'Price']);
        $this->service->saveProduct($id, $data);
        flash('success', 'Supplier product saved.');
        return redirect(route('suppliers.show', ['id' => $id]));
    }

    public function removeProduct(Request $request, int $id, int $productId): Response
    {
        $this->service->removeProduct($id, $productId);
        flash('success', 'Product removed from this supplier.');
        return redirect(route('suppliers.show', ['id' => $id]));
    }

    public function performance(Request $request): Response
    {
        $days = in_array((int) $request->query('days'), [90, 180, 365, 730], true) ? (int) $request->query('days') : 365;
        return view('suppliers/performance', [
            'title'   => 'Supplier performance',
            'rows'    => $this->performance->metrics(null, $days),
            'days'    => $days,
            'showSpend' => $this->gate()->can('valuation.view') || $this->gate()->can('purchase_orders.manage'),
        ]);
    }

    private function validated(Request $request, ?int $id): array
    {
        return Validator::validate($request->all(), [
            'name'                   => 'required|string|min:2|max:150|unique:suppliers,name' . ($id ? ",$id" : ''),
            'contact_person'         => 'nullable|string|max:120',
            'phone'                  => 'nullable|string|max:30',
            'email'                  => 'nullable|email|max:150',
            'address'                => 'nullable|string|max:255',
            'tax_number'             => 'nullable|string|max:50',
            'payment_terms'          => 'nullable|string|max:100',
            'default_lead_time_days' => 'required|integer|min:0|max:365',
            'status'                 => 'required|in:active,inactive,blacklisted',
            'notes'                  => 'nullable|string|max:2000',
        ], ['default_lead_time_days' => 'Lead time']);
    }
}
