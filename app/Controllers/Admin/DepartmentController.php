<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Repositories\DepartmentRepository;
use App\Services\Admin\DepartmentService;

final class DepartmentController extends Controller
{
    public const TYPES = ['central_store', 'pharmacy', 'clinical', 'laboratory', 'theatre', 'equipment_store', 'other'];

    public function __construct(private DepartmentRepository $departments, private DepartmentService $service)
    {
    }

    public function index(Request $request): Response
    {
        $filters = $this->filters($request, ['q', 'active']);
        return view('admin/departments/index', [
            'title'   => 'Departments',
            'page'    => $this->departments->search($filters, $this->page($request)),
            'filters' => $filters,
        ]);
    }

    public function create(Request $request): Response
    {
        return $this->form(null);
    }

    public function edit(Request $request, int $id): Response
    {
        return $this->form($this->findOr404($this->departments->find($id), 'Department'));
    }

    private function form(?array $department): Response
    {
        $db = app(\App\Core\Database::class);
        return view('admin/departments/form', [
            'title'      => $department ? 'Edit department' : 'New department',
            'department' => $department,
            'types'      => self::TYPES,
            'users'      => $db->fetchAll("SELECT id, full_name FROM users WHERE status = 'active' ORDER BY full_name"),
        ]);
    }

    public function store(Request $request): Response
    {
        $data = $this->validated($request, null);
        $id = $this->service->create($data);
        flash('success', "Department {$data['name']} was created with a default store location.");
        return redirect(route('admin.departments.index'));
    }

    public function update(Request $request, int $id): Response
    {
        $this->findOr404($this->departments->find($id), 'Department');
        $this->service->update($id, $this->validated($request, $id));
        flash('success', 'Department saved.');
        return redirect(route('admin.departments.index'));
    }

    public function toggle(Request $request, int $id): Response
    {
        $active = $this->service->toggle($id);
        flash('success', $active ? 'Department activated.' : 'Department deactivated.');
        return redirect($request->previousUrl('/admin/departments'));
    }

    private function validated(Request $request, ?int $id): array
    {
        $data = Validator::validate($request->all(), [
            'code'             => 'required|string|min:2|max:20|unique:departments,code' . ($id ? ",$id" : '') . '|regex:/^[A-Za-z0-9_-]+$/',
            'name'             => 'required|string|min:2|max:100|unique:departments,name' . ($id ? ",$id" : ''),
            'type'             => 'required|in:' . implode(',', self::TYPES),
            'is_central_store' => 'nullable|boolean',
            'description'      => 'nullable|string|max:255',
            'head_user_id'     => 'nullable|integer|exists:users,id',
            'phone_extension'  => 'nullable|string|max:20',
        ]);
        $data['is_central_store'] = (int) ($data['is_central_store'] ?? 0);
        return $data;
    }
}
