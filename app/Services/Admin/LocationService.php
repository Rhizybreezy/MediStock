<?php
declare(strict_types=1);

namespace App\Services\Admin;

use App\Exceptions\BusinessRuleException;
use App\Repositories\LocationRepository;
use App\Services\Support\AuditService;

/**
 * Storage location rules:
 * - the single 'transit' location is system-managed (not creatable/editable in type)
 * - cold-chain locations need a valid temperature range
 * - a location holding stock cannot be deactivated
 */
final class LocationService
{
    public function __construct(private LocationRepository $locations, private AuditService $audit)
    {
    }

    public function create(array $data): int
    {
        $data = $this->normalize($data);
        $id = $this->locations->create($data);
        $this->audit->log('create', 'location', $id, "Created location {$data['name']}", null, $data);
        return $id;
    }

    public function update(int $id, array $data): void
    {
        $loc = $this->locations->find($id) ?? throw new BusinessRuleException('Location not found.');
        if ($loc['type'] === 'transit') {
            throw new BusinessRuleException('The In Transit location is managed by the system.');
        }
        $data = $this->normalize($data);
        if ((int) $loc['department_id'] !== (int) $data['department_id'] && $this->locations->stockOnHand($id) > 0) {
            throw new BusinessRuleException('This location holds stock and cannot be moved to another department.');
        }
        unset($loc['department_name']);
        $this->locations->update($id, $data);
        [$old, $new] = AuditService::diff($loc, $data);
        if ($new) {
            $this->audit->log('update', 'location', $id, "Updated location {$data['name']}", $old, $new);
        }
    }

    public function toggle(int $id): bool
    {
        $loc = $this->locations->find($id) ?? throw new BusinessRuleException('Location not found.');
        if ($loc['type'] === 'transit') {
            throw new BusinessRuleException('The In Transit location is managed by the system.');
        }
        $activate = (int) $loc['is_active'] === 0;
        if (!$activate && $this->locations->stockOnHand($id) > 0) {
            throw new BusinessRuleException('This location still holds stock. Move it before deactivating.');
        }
        $this->locations->update($id, ['is_active' => $activate ? 1 : 0]);
        $this->audit->log($activate ? 'activate' : 'deactivate', 'location', $id, ($activate ? 'Activated' : 'Deactivated') . " location {$loc['name']}");
        return $activate;
    }

    private function normalize(array $data): array
    {
        if (($data['type'] ?? '') === 'transit') {
            throw new BusinessRuleException('In Transit locations cannot be created manually.');
        }
        $data['code'] = strtoupper((string) $data['code']);
        $cold = (int) ($data['is_cold_chain'] ?? 0) === 1 || in_array($data['type'], ['refrigerator', 'freezer'], true);
        $data['is_cold_chain'] = $cold ? 1 : 0;
        if ($cold) {
            if ($data['min_temp_c'] === null || $data['max_temp_c'] === null) {
                throw new BusinessRuleException('Cold-chain locations need a minimum and maximum temperature.');
            }
            if ((float) $data['min_temp_c'] >= (float) $data['max_temp_c']) {
                throw new BusinessRuleException('Minimum temperature must be lower than maximum temperature.');
            }
        } else {
            $data['min_temp_c'] = null;
            $data['max_temp_c'] = null;
        }
        return $data;
    }
}
