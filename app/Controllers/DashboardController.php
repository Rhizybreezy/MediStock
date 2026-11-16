<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Repositories\DepartmentRepository;
use App\Services\Dashboard\DashboardResolver;
use App\Services\Dashboard\DashboardService;
use App\Services\Dashboard\RoleDashboardService;
use App\Services\Intelligence\AnomalyService;
use App\Services\Intelligence\RedistributionService;
use App\Services\Procurement\PurchaseRequestService;
use App\Services\Procurement\SupplierPerformanceService;
use App\Services\Intelligence\AlertService;
use App\Services\Intelligence\ExpiryService;
use App\Services\Intelligence\ForecastService;

final class DashboardController extends Controller
{
    public function __construct(
        private DashboardService $dashboard,
        private DepartmentRepository $departments,
        private ForecastService $forecast,
        private ExpiryService $expiry,
        private AlertService $alerts,
        private DashboardResolver $resolver,
        private RoleDashboardService $roles,
    ) {
    }

    /**
     * One dashboard per role (see DashboardResolver). Every dashboard is read-only;
     * actions link to the normal pages, which re-check permissions.
     */
    public function index(Request $request): Response
    {
        $this->alerts->runIfDue(15); // keeps notifications fresh even without cron (throttled)
        $type = $this->resolver->type();
        $scope = $this->dashboard->scope();
        $data = [
            'type'       => $type,
            'title'      => DashboardResolver::TITLES[$type],
            'showValues' => $this->dashboard->showValues(),
            'scopeNames' => $scope === null ? null : array_column($this->departments->options($scope), 'name'),
            'aiPrompts'  => RoleDashboardService::aiPrompts($type),
            'stats'      => $this->dashboard->summary(),
            'scripts'    => ['vendor/chartjs/chart.umd.min.js', 'js/pages/dashboard.js'],
        ];

        switch ($type) {
            case 'manager':
                $data += ['insights' => $this->insights($scope), 'departments' => $this->dashboard->departmentSummaries(), 'm' => $this->roles->manager()];
                break;
            case 'inventory':
                $data += ['insights' => $this->insights($scope), 'movements' => $this->dashboard->recentMovements(8),
                          'm' => $this->roles->inventory(app(RedistributionService::class), app(AnomalyService::class))];
                break;
            case 'procurement':
                $data += ['m' => $this->roles->procurement(app(PurchaseRequestService::class), app(SupplierPerformanceService::class))];
                $data['scripts'] = [];
                break;
            case 'pharmacist':
            case 'storekeeper':
                $data += ['insights' => $this->insights($scope), 'm' => $this->roles->department($type)];
                $data['scripts'] = [];
                break;
            default: // admin and general: the full hospital overview
                $data += ['insights' => $this->insights($scope), 'movements' => $this->dashboard->recentMovements(8),
                          'departments' => $this->dashboard->departmentSummaries()];
                if ($type === 'admin') {
                    $data['admin'] = $this->roles->adminPanel();
                }
        }
        return view('dashboard/' . ($type === 'admin' ? 'index' : ($type === 'general' ? 'index' : $type)), $data);
    }

    /** Stock-out and expiry insights for the viewer's scope. */
    private function insights(?array $scope): array
    {
        $insights = ['stockout' => [], 'expiry' => null, 'dept' => null];
        if ($scope === null || $scope) {
            $dept = $scope === null ? null : $scope[0];
            $insights['stockout'] = array_slice(array_values(array_filter($this->forecast->forecast($dept), fn ($r) => in_array($r['urgency'], ['critical', 'high'], true))), 0, 6);
            $insights['expiry'] = $this->expiry->analyse($scope, 180);
            $insights['dept'] = $dept;
        }
        return $insights;
    }

    /** JSON chart data: GET /api/dashboard/charts */
    public function charts(Request $request): Response
    {
        return json($this->dashboard->charts());
    }
}
