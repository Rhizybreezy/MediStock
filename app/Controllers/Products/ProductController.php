<?php
declare(strict_types=1);

namespace App\Controllers\Products;

use App\Controllers\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Exceptions\HttpException;
use App\Exceptions\ValidationException;
use App\Repositories\BatchRepository;
use App\Repositories\CategoryRepository;
use App\Repositories\MovementRepository;
use App\Repositories\ProductRepository;
use App\Repositories\UnitRepository;
use App\Services\Catalogue\ProductService;
use App\Services\Support\UploadService;

final class ProductController extends Controller
{
    public const STORAGE = [
        'room_temp' => 'Room temperature (15–25 °C)', 'cool' => 'Cool (8–15 °C)', 'refrigerated' => 'Refrigerated (2–8 °C)',
        'frozen' => 'Frozen (≤ −15 °C)', 'controlled' => 'Controlled / locked storage',
    ];

    public function __construct(
        private ProductRepository $products,
        private CategoryRepository $categories,
        private UnitRepository $units,
        private BatchRepository $batches,
        private MovementRepository $movements,
        private ProductService $service,
        private UploadService $uploads,
    ) {
    }

    public function index(Request $request): Response
    {
        $filters = $this->filters($request, ['q', 'category_id', 'active', 'stock', 'flag']);
        if ($filters['active'] === null) {
            $filters['active'] = '1';
        }
        $scope = $this->gate()->can('stock.view') ? $this->gate()->departmentScope('stock.view') : [];
        return view('products/index', [
            'title'      => 'Products & medicines',
            'page'       => $this->products->search($filters, $this->page($request), $scope),
            'filters'    => $filters,
            'categories' => $this->categories->options(false),
            'showValues' => $this->gate()->can('valuation.view'),
            'scoped'     => $scope !== null,
        ]);
    }

    public function show(Request $request, int $id): Response
    {
        $product = $this->findOr404($this->products->find($id), 'Product');
        $canStock = $this->gate()->can('stock.view');
        $scope = $canStock ? $this->gate()->departmentScope('stock.view') : [];
        return view('products/show', [
            'title'      => $product['name'],
            'product'    => $product,
            'packUnits'  => $this->products->packUnits($id),
            'locations'  => $canStock ? $this->products->stockByLocation($id, $scope) : [],
            'batches'    => $canStock ? $this->batches->forProduct($id, $scope) : [],
            'movements'  => $this->gate()->can('stock.movements.view') ? $this->movements->forProduct($id, $this->gate()->departmentScope('stock.movements.view')) : [],
            'canStock'   => $canStock,
            'showValues' => $this->gate()->can('valuation.view'),
            'storage'    => self::STORAGE,
            'forecast'   => $canStock && $scope === null ? (app(\App\Services\Intelligence\ForecastService::class)->forecast(null, $id)[0] ?? null) : null,
        ]);
    }

    public function create(Request $request): Response
    {
        return $this->form(null);
    }

    public function edit(Request $request, int $id): Response
    {
        return $this->form($this->findOr404($this->products->find($id), 'Product'));
    }

    private function form(?array $product): Response
    {
        return view('products/form', [
            'title'      => $product ? 'Edit ' . $product['name'] : 'Register product',
            'product'    => $product,
            'categories' => $this->categories->options(),
            'units'      => $this->units->options(),
            'packUnits'  => $product ? $this->products->packUnits((int) $product['id']) : [],
            'storage'    => self::STORAGE,
            'scripts'    => ['js/pages/product-form.js'],
        ]);
    }

    public function store(Request $request): Response
    {
        [$data, $packs] = $this->validated($request);
        $id = $this->service->create($data, $packs, $request->file('image'), $this->userId());
        flash('success', 'Product registered.');
        return redirect(route('products.show', ['id' => $id]));
    }

    public function update(Request $request, int $id): Response
    {
        $this->findOr404($this->products->find($id), 'Product');
        [$data, $packs] = $this->validated($request);
        $this->service->update($id, $data, $packs, $request->file('image'), $request->bool('remove_image'));
        flash('success', 'Product saved.');
        return redirect(route('products.show', ['id' => $id]));
    }

    public function toggle(Request $request, int $id): Response
    {
        $active = $this->service->toggle($id);
        flash('success', $active ? 'Product activated.' : 'Product deactivated. It can no longer be received or issued.');
        return redirect($request->previousUrl('/products/' . $id));
    }

    /** Serves a product image from storage (outside the web root). */
    public function image(Request $request, int $id): Response
    {
        $product = $this->findOr404($this->products->find($id), 'Product');
        $path = $this->uploads->path($product['image_path']);
        if ($path === null) {
            throw new HttpException(404, 'Image not found.');
        }
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($path);
        return (new Response((string) file_get_contents($path), 200, [
            'Content-Type'  => $mime,
            'Cache-Control' => 'private, max-age=86400',
        ]));
    }

    private function validated(Request $request): array
    {
        $data = Validator::validate($request->all(), [
            'name'                   => 'required|string|min:2|max:150',
            'generic_name'           => 'nullable|string|max:150',
            'category_id'            => 'required|integer|exists:inventory_categories,id',
            'base_unit_id'           => 'required|integer|exists:units_of_measure,id',
            'dosage_form'            => 'nullable|string|max:60',
            'strength'               => 'nullable|string|max:60',
            'manufacturer'           => 'nullable|string|max:150',
            'description'            => 'nullable|string|max:2000',
            'storage_condition'      => 'required|in:' . implode(',', array_keys(self::STORAGE)),
            'storage_notes'          => 'nullable|string|max:255',
            'is_batch_tracked'       => 'nullable|boolean',
            'is_expiry_tracked'      => 'nullable|boolean',
            'is_critical'            => 'nullable|boolean',
            'is_controlled'          => 'nullable|boolean',
            'is_active'              => 'nullable|boolean',
            'min_level'              => 'required|numeric|min:0|max:99999999',
            'reorder_level'          => 'required|numeric|min:0|max:99999999',
            'max_level'              => 'required|numeric|min:0|max:99999999',
            'default_lead_time_days' => 'nullable|integer|min:0|max:365',
        ], ['base_unit_id' => 'Base unit', 'default_lead_time_days' => 'Lead time']);
        foreach (['is_batch_tracked', 'is_expiry_tracked', 'is_critical', 'is_controlled', 'is_active'] as $flag) {
            $data[$flag] = (int) ($data[$flag] ?? 0);
        }

        $packs = [];
        $errors = [];
        foreach ((array) $request->input('packs', []) as $i => $row) {
            if (!is_array($row) || (empty($row['unit_id']) && empty($row['factor_to_base']))) {
                continue;
            }
            if (!ctype_digit((string) ($row['unit_id'] ?? '')) || !is_numeric($row['factor_to_base'] ?? null)) {
                $errors["packs.$i"][] = 'Each pack unit needs a unit and a numeric conversion.';
                continue;
            }
            $gtin = trim((string) ($row['gtin_barcode'] ?? ''));
            if ($gtin !== '' && !preg_match('/^[0-9A-Za-z-]{6,50}$/', $gtin)) {
                $errors["packs.$i"][] = 'Barcode may only contain letters, digits and dashes (6–50 characters).';
                continue;
            }
            $packs[] = [
                'unit_id' => (int) $row['unit_id'], 'factor_to_base' => round((float) $row['factor_to_base'], 3),
                'gtin_barcode' => $gtin ?: null, 'is_purchase_unit' => !empty($row['is_purchase_unit']) ? 1 : 0,
                'is_issue_unit' => !empty($row['is_issue_unit']) ? 1 : 0,
            ];
        }
        if ($errors) {
            throw new ValidationException($errors, reset($errors)[0]);
        }
        return [$data, $packs];
    }
}
