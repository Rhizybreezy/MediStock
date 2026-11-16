<?php
declare(strict_types=1);

namespace App\Services\Catalogue;

use App\Exceptions\BusinessRuleException;
use App\Repositories\CategoryRepository;
use App\Repositories\UnitRepository;
use App\Services\Support\AuditService;

/** Inventory categories and units of measure. */
final class CatalogueService
{
    public function __construct(
        private CategoryRepository $categories,
        private UnitRepository $units,
        private AuditService $audit,
    ) {
    }

    public function saveCategory(?int $id, array $data): int
    {
        $data['code'] = strtoupper($data['code']);
        if ($id !== null && (int) ($data['parent_id'] ?? 0) === $id) {
            throw new BusinessRuleException('A category cannot be its own parent.');
        }
        if ($id === null) {
            $id = $this->categories->create($data);
            $this->audit->log('create', 'category', $id, "Created category {$data['name']}", null, $data);
            return $id;
        }
        $old = $this->categories->find($id) ?? throw new BusinessRuleException('Category not found.');
        $this->categories->update($id, $data);
        [$o, $n] = AuditService::diff($old, $data);
        if ($n) {
            $this->audit->log('update', 'category', $id, "Updated category {$data['name']}", $o, $n);
        }
        return $id;
    }

    public function saveUnit(?int $id, array $data): int
    {
        $data['code'] = strtoupper($data['code']);
        if ($id === null) {
            $id = $this->units->create($data);
            $this->audit->log('create', 'unit', $id, "Created unit {$data['name']}", null, $data);
            return $id;
        }
        $old = $this->units->find($id) ?? throw new BusinessRuleException('Unit not found.');
        $this->units->update($id, $data);
        [$o, $n] = AuditService::diff($old, $data);
        if ($n) {
            $this->audit->log('update', 'unit', $id, "Updated unit {$data['name']}", $o, $n);
        }
        return $id;
    }
}
