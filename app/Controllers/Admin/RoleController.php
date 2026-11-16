<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Repositories\RoleRepository;
use App\Services\Admin\RoleService;

final class RoleController extends Controller
{
    public function __construct(private RoleRepository $roles, private RoleService $service)
    {
    }

    public function index(Request $request): Response
    {
        return view('admin/roles/index', ['title' => 'Roles & permissions', 'roles' => $this->roles->allWithCounts()]);
    }

    public function create(Request $request): Response
    {
        return $this->form(null);
    }

    public function edit(Request $request, int $id): Response
    {
        return $this->form($this->findOr404($this->roles->find($id), 'Role'));
    }

    private function form(?array $role): Response
    {
        return view('admin/roles/form', [
            'title'       => $role ? 'Edit role: ' . $role['name'] : 'New role',
            'role'        => $role,
            'modules'     => $this->roles->permissionsByModule(),
            'selected'    => $role ? $this->roles->permissionIds((int) $role['id']) : [],
            'scripts'     => ['js/pages/role-form.js'],
        ]);
    }

    public function store(Request $request): Response
    {
        $data = $this->validated($request, null);
        $id = $this->service->create($data, $data['permissions'] ?? []);
        flash('success', "Role {$data['name']} was created.");
        return redirect(route('admin.roles.edit', ['id' => $id]));
    }

    public function update(Request $request, int $id): Response
    {
        $this->findOr404($this->roles->find($id), 'Role');
        $data = $this->validated($request, $id);
        $this->service->update($id, $data, $data['permissions'] ?? []);
        flash('success', 'Role permissions were saved. They apply immediately.');
        return redirect(route('admin.roles.edit', ['id' => $id]));
    }

    public function destroy(Request $request, int $id): Response
    {
        $this->service->delete($id);
        flash('success', 'The role was deleted.');
        return redirect(route('admin.roles.index'));
    }

    private function validated(Request $request, ?int $id): array
    {
        return Validator::validate($request->all(), [
            'name'          => 'required|string|min:3|max:100|unique:roles,name' . ($id ? ",$id" : ''),
            'description'   => 'nullable|string|max:255',
            'scope'         => 'required|in:global,department',
            'permissions'   => 'nullable|array',
            'permissions.*' => 'integer|exists:permissions,id',
        ]);
    }
}
