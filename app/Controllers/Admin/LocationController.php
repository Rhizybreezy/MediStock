<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Repositories\DepartmentRepository;
use App\Repositories\LocationRepository;
use App\Services\Admin\LocationService;

final class LocationController extends Controller
{
    /** 'transit' is system-managed and intentionally not offered. */
    public const TYPES = ['store', 'shelf', 'refrigerator', 'freezer', 'cabinet', 'quarantine'];

    public function __construct(
        private LocationRepository $locations,
        private DepartmentRepository $departments,
        private LocationService $service,
    ) {
    }

    public function index(Request $request): Response
    {
        $filters = $this->filters($request, ['q', 'department_id', 'type']);
        return view('admin/locations/index', [
            'title'       => 'Storage locations',
            'page'        => $this->locations->search($filters, $this->page($request)),
            'filters'     => $filters,
            'departments' => $this->departments->options(null, false),
            'types'       => array_merge(self::TYPES, ['transit']),
        ]);
    }

    public function create(Request $request): Response
    {
        return $this->form(null);
    }

    public function edit(Request $request, int $id): Response
    {
        $location = $this->findOr404($this->locations->find($id), 'Location');
        if ($location['type'] === 'transit') {
            flash('warning', 'The In Transit location is managed by the system and cannot be edited.');
            return redirect(route('admin.locations.index'));
        }
        return $this->form($location);
    }

    private function form(?array $location): Response
    {
        return view('admin/locations/form', [
            'title'       => $location ? 'Edit location' : 'New location',
            'location'    => $location,
            'departments' => $this->departments->options(),
            'types'       => self::TYPES,
            'scripts'     => ['js/pages/location-form.js'],
        ]);
    }

    public function store(Request $request): Response
    {
        $data = $this->validated($request, null);
        $this->service->create($data);
        flash('success', "Location {$data['name']} was created.");
        return redirect(route('admin.locations.index'));
    }

    public function update(Request $request, int $id): Response
    {
        $this->findOr404($this->locations->find($id), 'Location');
        $this->service->update($id, $this->validated($request, $id));
        flash('success', 'Location saved.');
        return redirect(route('admin.locations.index'));
    }

    public function toggle(Request $request, int $id): Response
    {
        $active = $this->service->toggle($id);
        flash('success', $active ? 'Location activated.' : 'Location deactivated.');
        return redirect($request->previousUrl('/admin/locations'));
    }

    private function validated(Request $request, ?int $id): array
    {
        $data = Validator::validate($request->all(), [
            'department_id' => 'required|integer|exists:departments,id',
            'code'          => 'required|string|min:2|max:30|unique:inventory_locations,code' . ($id ? ",$id" : '') . '|regex:/^[A-Za-z0-9_-]+$/',
            'name'          => 'required|string|min:2|max:100',
            'type'          => 'required|in:' . implode(',', self::TYPES),
            'is_cold_chain' => 'nullable|boolean',
            'min_temp_c'    => 'nullable|numeric|between:-90,60',
            'max_temp_c'    => 'nullable|numeric|between:-90,60',
            'description'   => 'nullable|string|max:255',
        ]);
        // Name must be unique within the department
        $dup = app(\App\Core\Database::class)->fetchValue(
            'SELECT id FROM inventory_locations WHERE department_id = :d AND name = :n AND id <> :id',
            ['d' => $data['department_id'], 'n' => $data['name'], 'id' => $id ?? 0]
        );
        if ($dup) {
            throw \App\Exceptions\ValidationException::withMessage('name', 'This department already has a location with that name.');
        }
        $data['is_cold_chain'] = (int) ($data['is_cold_chain'] ?? 0);
        return $data;
    }
}
