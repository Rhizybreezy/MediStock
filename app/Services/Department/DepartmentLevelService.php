<?php
declare(strict_types=1);

namespace App\Services\Department;

use App\Core\Database;
use App\Core\Gate;
use App\Exceptions\BusinessRuleException;
use App\Services\Support\AuditService;

/** Per-department stock levels (department_product_settings). */
final class DepartmentLevelService
{
    public function __construct(private Database $db, private Gate $gate, private AuditService $audit)
    {
    }

    /**
     * @param array<int, array{min_level:float, reorder_level:float, max_level:float, is_stocked:int}> $rows product_id => levels
     */
    public function save(int $departmentId, array $rows): int
    {
        $this->gate->authorize('department_levels.manage', $departmentId);
        $changed = 0;
        $this->db->transaction(function (Database $db) use ($departmentId, $rows, &$changed) {
            foreach ($rows as $productId => $r) {
                $min = (float) $r['min_level'];
                $reorder = (float) $r['reorder_level'];
                $max = (float) $r['max_level'];
                if ($min < 0 || $reorder < $min || ($max > 0 && $max < $reorder)) {
                    $name = $db->fetchValue('SELECT name FROM products WHERE id = :p', ['p' => $productId]);
                    throw new BusinessRuleException("$name: levels must satisfy minimum ≤ reorder ≤ maximum (maximum 0 = none).");
                }
                if (!$r['is_stocked'] && $min == 0 && $reorder == 0 && $max == 0) {
                    $changed += $db->execute('DELETE FROM department_product_settings WHERE department_id = :d AND product_id = :p', ['d' => $departmentId, 'p' => $productId]);
                    continue;
                }
                $db->execute(
                    'INSERT INTO department_product_settings (department_id, product_id, min_level, reorder_level, max_level, is_stocked)
                     VALUES (:d, :p, :mn, :ro, :mx, :st)
                     ON DUPLICATE KEY UPDATE min_level = VALUES(min_level), reorder_level = VALUES(reorder_level),
                                             max_level = VALUES(max_level), is_stocked = VALUES(is_stocked)',
                    ['d' => $departmentId, 'p' => (int) $productId, 'mn' => $min, 'ro' => $reorder, 'mx' => $max, 'st' => $r['is_stocked'] ? 1 : 0]
                );
                $changed++;
            }
        });
        cache_bump();
        $this->audit->log('update', 'department_levels', $departmentId, "Updated stock levels for $changed product(s)");
        return $changed;
    }
}
