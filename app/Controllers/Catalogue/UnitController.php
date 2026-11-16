<?php
declare(strict_types=1);

namespace App\Controllers\Catalogue;

use App\Controllers\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Repositories\UnitRepository;
use App\Services\Catalogue\CatalogueService;

final class UnitController extends Controller
{
    public function __construct(private UnitRepository $units, private CatalogueService $service)
    {
    }

    public function index(Request $request): Response
    {
        return view('catalogue/units/index', ['title' => 'Units of measure', 'units' => $this->units->allWithCounts()]);
    }

    public function create(Request $request): Response
    {
        return view('catalogue/units/form', ['title' => 'New unit', 'unit' => null]);
    }

    public function edit(Request $request, int $id): Response
    {
        return view('catalogue/units/form', ['title' => 'Edit unit', 'unit' => $this->findOr404($this->units->find($id), 'Unit')]);
    }

    public function store(Request $request): Response
    {
        $this->service->saveUnit(null, $this->validated($request, null));
        flash('success', 'Unit created.');
        return redirect(route('catalogue.units.index'));
    }

    public function update(Request $request, int $id): Response
    {
        $this->findOr404($this->units->find($id), 'Unit');
        $this->service->saveUnit($id, $this->validated($request, $id));
        flash('success', 'Unit saved.');
        return redirect(route('catalogue.units.index'));
    }

    private function validated(Request $request, ?int $id): array
    {
        $data = Validator::validate($request->all(), [
            'code'           => 'required|string|min:1|max:10|unique:units_of_measure,code' . ($id ? ",$id" : '') . '|regex:/^[A-Za-z0-9_-]+$/',
            'name'           => 'required|string|min:2|max:50|unique:units_of_measure,name' . ($id ? ",$id" : ''),
            'allows_decimal' => 'nullable|boolean',
            'is_active'      => 'nullable|boolean',
        ]);
        $data['allows_decimal'] = (int) ($data['allows_decimal'] ?? 0);
        $data['is_active'] = (int) ($data['is_active'] ?? 0);
        return $data;
    }
}
