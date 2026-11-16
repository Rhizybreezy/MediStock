<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\Validator;
use App\Repositories\DepartmentRepository;
use App\Repositories\RoleRepository;
use App\Repositories\UserRepository;
use App\Services\Admin\UserService;

final class UserController extends Controller
{
    public function __construct(
        private UserRepository $users,
        private RoleRepository $roles,
        private DepartmentRepository $departments,
        private UserService $service,
    ) {
    }

    public function index(Request $request): Response
    {
        $filters = $this->filters($request, ['q', 'status', 'role_id', 'department_id']);
        return view('admin/users/index', [
            'title'       => 'Users',
            'page'        => $this->users->search($filters, $this->page($request)),
            'filters'     => $filters,
            'roles'       => $this->roles->options(),
            'departments' => $this->departments->options(null, false),
            'counts'      => $this->users->statusCounts(),
        ]);
    }

    public function create(Request $request): Response
    {
        return $this->form(null);
    }

    public function edit(Request $request, int $id): Response
    {
        return $this->form($this->findOr404($this->users->find($id), 'User'));
    }

    private function form(?array $user): Response
    {
        $id = $user ? (int) $user['id'] : null;
        return view('admin/users/form', [
            'title'          => $user ? 'Edit user' : 'New user',
            'user'           => $user,
            'roles'          => $this->roles->options(),
            'departments'    => $this->departments->options(null, false),
            'userRoleIds'    => $id ? $this->users->roleIds($id) : [],
            'userDepts'      => $id ? $this->users->departmentAssignments($id) : [],
            'scripts'        => ['js/pages/user-form.js'],
        ]);
    }

    public function store(Request $request): Response
    {
        $data = $this->validated($request, null);
        $id = $this->service->create(
            $data,
            $data['roles'],
            $data['departments'] ?? [],
            $data['primary_department_id'] ?? null,
            $data['password'],
            (bool) $data['must_change_password'],
            $this->userId()
        );
        flash('success', "User {$data['username']} was created.");
        return redirect(route('admin.users.edit', ['id' => $id]));
    }

    public function update(Request $request, int $id): Response
    {
        $this->findOr404($this->users->find($id), 'User');
        $data = $this->validated($request, $id);
        $this->service->update($id, $data, $data['roles'], $data['departments'] ?? [], $data['primary_department_id'] ?? null);
        flash('success', 'User details were saved.');
        return redirect(route('admin.users.edit', ['id' => $id]));
    }

    public function status(Request $request, int $id): Response
    {
        $status = (string) $request->input('status');
        $this->service->setStatus($id, $status, $this->userId());
        flash('success', $status === 'active' ? 'The account is active.' : 'The account was deactivated.');
        return redirect($request->previousUrl('/admin/users'));
    }

    public function resetPassword(Request $request, int $id): Response
    {
        $user = $this->findOr404($this->users->find($id), 'User');
        $temp = $this->service->resetPassword($id);
        // Shown once on the next page; the user must change it at first sign-in.
        Session::flash('temp_password', ['username' => $user['username'], 'password' => $temp]);
        return redirect(route('admin.users.edit', ['id' => $id]));
    }

    private function validated(Request $request, ?int $id): array
    {
        $rules = [
            'full_name'             => 'required|string|min:3|max:120',
            'username'              => 'required|string|min:3|max:50|unique:users,username' . ($id ? ",$id" : '') . '|regex:/^[A-Za-z0-9._-]+$/',
            'email'                 => 'required|email|max:150|unique:users,email' . ($id ? ",$id" : ''),
            'phone'                 => 'nullable|string|max:30',
            'roles'                 => 'required|array|min:1',
            'roles.*'               => 'integer|exists:roles,id',
            'departments'           => 'nullable|array',
            'departments.*'         => 'integer|exists:departments,id',
            'primary_department_id' => 'nullable|integer',
        ];
        if ($id === null) {
            $rules['password'] = 'required|string|max:200|password|confirmed';
            $rules['must_change_password'] = 'nullable|boolean';
        }
        $data = Validator::validate($request->all(), $rules);
        $data['roles'] = array_map('intval', $data['roles']);
        $data['departments'] = array_map('intval', $data['departments'] ?? []);
        $data['must_change_password'] = $data['must_change_password'] ?? 0;
        return $data;
    }
}
