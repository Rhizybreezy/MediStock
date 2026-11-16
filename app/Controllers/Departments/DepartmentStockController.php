<?php
declare(strict_types=1);

namespace App\Controllers\Departments;

use App\Controllers\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Exceptions\BusinessRuleException;
use App\Exceptions\HttpException;
use App\Repositories\DepartmentRepository;
use App\Repositories\DepartmentStockRepository;
use App\Services\Department\DepartmentLevelService;

final class DepartmentStockController extends Controller
{
    public function __construct(
        private DepartmentStockRepository $stock,
        private DepartmentRepository $departments,
        private DepartmentLevelService $levels,
    ) {
    }

    public function index(Request $request): Response
    {
        $scope = $this->gate()->departmentScope('stock.view');
        $summaries = $this->stock->summaries($scope);
        if ($scope !== null && count($summaries) === 1) {
            return redirect(route('departments.show', ['id' => $summaries[0]['id']]));
        }
        return view('departments/index', [
            'title'      => 'Department stock',
            'summaries'  => $summaries,
            'showValues' => $this->gate()->can('valuation.view'),
        ]);
    }

    public function show(Request $request, int $id): Response
    {
        $dept = $this->findOr404($this->departments->find($id), 'Department');
        if (!$this->gate()->can('stock.view', $id)) {
            throw new HttpException(404, 'Department not found.');
        }
        $filter = in_array($request->query('filter'), ['low', 'catalogue'], true) ? $request->query('filter') : 'all';
        $q = mb_substr(trim((string) $request->query('q', '')), 0, 60) ?: null;
        $summary = array_values(array_filter($this->stock->summaries([$id]), fn ($s) => (int) $s['id'] === $id))[0] ?? null;
        return view('departments/show', [
            'title'      => $dept['name'],
            'dept'       => $dept,
            'summary'    => $summary,
            'rows'       => $this->stock->levels($id, $filter, $q),
            'filter'     => $filter,
            'q'          => $q,
            'canEdit'    => $this->gate()->can('department_levels.manage', $id),
            'showValues' => $this->gate()->can('valuation.view'),
        ]);
    }

    public function saveLevels(Request $request, int $id): Response
    {
        $rows = [];
        foreach ((array) $request->input('levels', []) as $productId => $r) {
            if (!ctype_digit((string) $productId) || !is_array($r)) {
                continue;
            }
            foreach (['min_level', 'reorder_level', 'max_level'] as $k) {
                $v = trim((string) ($r[$k] ?? ''));
                if ($v !== '' && (!is_numeric($v) || (float) $v < 0)) {
                    throw new BusinessRuleException('Stock levels must be positive numbers.');
                }
            }
            $rows[(int) $productId] = [
                'min_level' => (float) ($r['min_level'] ?? 0), 'reorder_level' => (float) ($r['reorder_level'] ?? 0),
                'max_level' => (float) ($r['max_level'] ?? 0), 'is_stocked' => !empty($r['is_stocked']) ? 1 : 0,
            ];
        }
        $n = $this->levels->save($id, $rows);
        flash('success', "Stock levels saved ($n product(s)).");
        return redirect($request->previousUrl('/departments/' . $id));
    }
}
