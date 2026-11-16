<?php
declare(strict_types=1);

namespace App\Controllers\Intelligence;

use App\Controllers\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Exceptions\BusinessRuleException;
use App\Exceptions\HttpException;
use App\Repositories\DepartmentRepository;
use App\Repositories\ProductRepository;
use App\Services\Department\TransferService;
use App\Services\Intelligence\AnomalyService;
use App\Services\Intelligence\ExpiryService;
use App\Services\Intelligence\ForecastService;
use App\Services\Intelligence\RedistributionService;

final class IntelligenceController extends Controller
{
    public function __construct(
        private ForecastService $forecast,
        private ExpiryService $expiry,
        private RedistributionService $redistribution,
        private AnomalyService $anomalies,
        private DepartmentRepository $departments,
        private ProductRepository $products,
        private TransferService $transfers,
    ) {
    }

    /** Resolve which view the user may see: null = hospital-wide, or a department id in scope. */
    private function departmentView(Request $request): ?int
    {
        $scope = $this->gate()->departmentScope('stock.view');
        $requested = $request->query('department_id');
        if ($scope === null) {
            return ctype_digit((string) $requested) ? (int) $requested : null;
        }
        if (!$scope) {
            throw new HttpException(403, 'You are not assigned to any department.');
        }
        return ctype_digit((string) $requested) && in_array((int) $requested, $scope, true) ? (int) $requested : $scope[0];
    }

    public function stockout(Request $request): Response
    {
        $dept = $this->departmentView($request);
        $rows = $this->forecast->forecast($dept);
        $urgency = (string) $request->query('urgency', '');
        $q = mb_strtolower(trim((string) $request->query('q', '')));
        $counts = array_count_values(array_column($rows, 'urgency'));
        $rows = array_values(array_filter($rows, function ($r) use ($urgency, $q) {
            if ($urgency === 'action' && !in_array($r['urgency'], ['critical', 'high', 'medium'], true)) {
                return false;
            }
            if ($urgency !== '' && $urgency !== 'action' && $r['urgency'] !== $urgency) {
                return false;
            }
            return $q === '' || str_contains(mb_strtolower($r['name'] . ' ' . $r['product_code']), $q);
        }));
        return view('intelligence/stockout', [
            'title'       => 'Stock-out forecast',
            'rows'        => $rows,
            'counts'      => $counts,
            'urgency'     => $urgency,
            'q'           => $q,
            'dept'        => $dept,
            'deptName'    => $dept ? ($this->departments->find($dept)['name'] ?? '') : null,
            'departments' => $this->departments->options($this->gate()->departmentScope('stock.view')),
            'canPurchase' => $dept === null && $this->gate()->can('purchase_requests.create'),
            'canRequest'  => $dept !== null && $this->gate()->can('requests.create', $dept),
            'centralId'   => $this->departments->centralStoreId(),
        ]);
    }

    public function stockoutProduct(Request $request, int $id): Response
    {
        $product = $this->findOr404($this->products->find($id), 'Product');
        $dept = $this->departmentView($request);
        $row = $this->forecast->forecast($dept, $id)[0] ?? null;
        return view('intelligence/stockout_product', [
            'title'   => 'Forecast: ' . $product['name'],
            'product' => $product,
            'f'       => $row,
            'dept'    => $dept,
            'weekly'  => $this->forecast->weeklyConsumption($id, $dept, 26),
            'scripts' => ['vendor/chartjs/chart.umd.min.js', 'js/pages/forecast-chart.js'],
        ]);
    }

    public function expiry(Request $request): Response
    {
        $scope = $this->gate()->departmentScope('stock.view');
        $horizon = in_array((int) $request->query('days'), [30, 90, 180, 365], true) ? (int) $request->query('days') : 180;
        $result = $this->expiry->analyse($scope, $horizon);
        if ($request->query('risk') === 'at_risk') {
            $result['rows'] = array_values(array_filter($result['rows'], fn ($r) => $r['at_risk'] > 0));
        }
        return view('intelligence/expiry', [
            'title'      => 'Expiry risk',
            'result'     => $result,
            'horizon'    => $horizon,
            'risk'       => (string) $request->query('risk', 'at_risk'),
            'showValues' => $this->gate()->can('valuation.view'),
        ]);
    }

    public function redistribution(Request $request): Response
    {
        return view('intelligence/redistribution', [
            'title'       => 'Smart redistribution',
            'suggestions' => $this->redistribution->suggestions($this->gate()->departmentScope('stock.view')),
            'canCreate'   => $this->gate()->can('transfers.create'),
        ]);
    }

    /** Create a DRAFT redistribution transfer from a suggestion (still needs approval). */
    public function createRedistribution(Request $request): Response
    {
        $from = $request->int('from_location_id');
        $to = $request->int('to_location_id');
        $product = $request->int('product_id');
        $qty = (float) $request->input('quantity');
        if ($qty <= 0) {
            throw new BusinessRuleException('Quantity must be greater than zero.');
        }
        $id = $this->transfers->create('redistribution', [
            'source_location_id' => $from, 'destination_location_id' => $to,
            'reason' => mb_substr('Redistribution suggestion: ' . (string) $request->input('reason', ''), 0, 255),
        ], [['product_id' => $product, 'quantity' => $qty]], $this->userId(), 'redistribution_engine');
        flash('success', 'Draft redistribution transfer created. It must be approved before stock moves.');
        return redirect(route('transfers.show', ['id' => $id]));
    }

    public function anomalies(Request $request): Response
    {
        return view('intelligence/anomalies', [
            'title'     => 'Anomalies',
            'anomalies' => $this->anomalies->detect($this->gate()->departmentScope('stock.view')),
        ]);
    }
}
