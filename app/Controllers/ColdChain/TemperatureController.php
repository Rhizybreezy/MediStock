<?php
declare(strict_types=1);

namespace App\Controllers\ColdChain;

use App\Controllers\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Repositories\TemperatureRepository;
use App\Services\ColdChain\TemperatureService;

final class TemperatureController extends Controller
{
    public function __construct(private TemperatureRepository $logs, private TemperatureService $service)
    {
    }

    public function index(Request $request): Response
    {
        $scope = $this->gate()->departmentScope($this->gate()->can('temperature.record') ? 'temperature.record' : 'stock.view');
        $filters = $this->filters($request, ['location_id', 'range']);
        $locations = $this->logs->locations($scope);
        $chartLoc = (int) ($filters['location_id'] ?: ($locations[0]['id'] ?? 0));
        $chartRow = array_values(array_filter($locations, fn ($l) => (int) $l['id'] === $chartLoc))[0] ?? null;
        return view('cold_chain/index', [
            'title'     => 'Cold chain',
            'locations' => $locations,
            'page'      => $this->logs->search($filters, $this->page($request), $scope),
            'filters'   => $filters,
            'chartLoc'  => $chartRow,
            'series'    => $chartRow ? $this->logs->series($chartLoc, 14) : [],
            'canRecord' => $this->gate()->can('temperature.record'),
            'scripts'   => ['vendor/chartjs/chart.umd.min.js', 'js/pages/cold-chain.js'],
        ]);
    }

    public function store(Request $request): Response
    {
        $data = Validator::validate($request->all(), [
            'location_id'       => 'required|integer|exists:inventory_locations,id',
            'temperature_c'     => 'required|numeric|between:-90,60',
            'humidity_pct'      => 'nullable|numeric|between:0,100',
            'corrective_action' => 'nullable|string|max:255',
        ], ['temperature_c' => 'Temperature']);
        $result = $this->service->record($data, $this->userId());
        flash($result['out_of_range'] ? 'warning' : 'success', $result['out_of_range'] ? 'Out-of-range reading recorded. Responsible staff have been alerted.' : 'Temperature recorded.');
        return redirect(route('cold_chain.index', [], ['location_id' => $data['location_id']]));
    }
}
