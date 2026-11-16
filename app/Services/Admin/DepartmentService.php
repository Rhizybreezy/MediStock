<?php
declare(strict_types=1);

namespace App\Services\Admin;

use App\Core\Database;
use App\Exceptions\BusinessRuleException;
use App\Repositories\DepartmentRepository;
use App\Repositories\LocationRepository;
use App\Services\Support\AuditService;

/**
 * Department rules:
 * - exactly one central store; it cannot be deactivated
 * - a new department automatically gets a main store location
 * - a department holding stock cannot be deactivated
 */
final class DepartmentService
{
    public function __construct(
        private Database $db,
        private DepartmentRepository $departments,
        private LocationRepository $locations,
        private AuditService $audit,
    ) {
    }

    public function create(array $data): int
    {
        if ((int) ($data['is_central_store'] ?? 0) === 1 && $this->departments->centralStoreId() !== null) {
            throw new BusinessRuleException('A central store already exists. There can only be one.');
        }
        return $this->db->transaction(function () use ($data) {
            $data['code'] = strtoupper($data['code']);
            $id = $this->departments->create($data);
            $this->locations->create([
                'department_id' => $id,
                'code'          => $this->locations->nextCodeFor($data['code'], 'MAIN'),
                'name'          => $data['name'] . ' - Store',
                'type'          => 'store',
            ]);
            $this->audit->log('create', 'department', $id, "Created department {$data['name']}", null, $data);
            return $id;
        });
    }

    public function update(int $id, array $data): void
    {
        $dept = $this->departments->find($id) ?? throw new BusinessRuleException('Department not found.');
        $data['code'] = strtoupper($data['code']);
        if ((int) $data['is_central_store'] === 1 && $this->departments->centralStoreId($id) !== null) {
            throw new BusinessRuleException('Another department is already the central store.');
        }
        if ((int) $dept['is_central_store'] === 1 && (int) $data['is_central_store'] === 0) {
            throw new BusinessRuleException('The central store designation cannot be removed from this department.');
        }
        $this->departments->update($id, $data);
        [$old, $new] = AuditService::diff($dept, $data);
        if ($new) {
            $this->audit->log('update', 'department', $id, "Updated department {$data['name']}", $old, $new);
        }
    }

    public function toggle(int $id): bool
    {
        $dept = $this->departments->find($id) ?? throw new BusinessRuleException('Department not found.');
        $activate = (int) $dept['is_active'] === 0;
        if (!$activate) {
            if ((int) $dept['is_central_store'] === 1) {
                throw new BusinessRuleException('The central store cannot be deactivated.');
            }
            if ($this->departments->stockOnHand($id) > 0) {
                throw new BusinessRuleException('This department still holds stock. Transfer or return it before deactivating.');
            }
        }
        $this->departments->update($id, ['is_active' => $activate ? 1 : 0]);
        $this->audit->log($activate ? 'activate' : 'deactivate', 'department', $id, ($activate ? 'Activated' : 'Deactivated') . " department {$dept['name']}");
        return $activate;
    }
}
