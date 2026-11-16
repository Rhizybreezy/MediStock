<?php
declare(strict_types=1);

namespace App\Controllers\Inventory;

use App\Controllers\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Repositories\LocationRepository;
use App\Repositories\ReservationRepository;
use App\Services\Department\ReservationService;

final class ReservationController extends Controller
{
    public function __construct(
        private ReservationRepository $reservations,
        private LocationRepository $locations,
        private ReservationService $service,
    ) {
    }

    public function index(Request $request): Response
    {
        $filters = $this->filters($request, ['status']);
        $g = $this->gate();
        $perm = $g->can('reservations.approve') ? 'reservations.approve' : 'reservations.manage';
        return view('reservations/index', [
            'title'     => 'Emergency reservations',
            'page'      => $this->reservations->search($filters, $this->page($request), $g->departmentScope($perm)),
            'filters'   => $filters,
            'locations' => $g->can('reservations.manage') ? $this->locations->options($g->departmentScope('reservations.manage'), ['transit', 'quarantine']) : [],
            'uid'       => $this->userId(),
            'scripts'   => ['js/components/line-items.js', 'js/pages/reservation-form.js'],
        ]);
    }

    public function store(Request $request): Response
    {
        $data = Validator::validate($request->all(), [
            'location_id' => 'required|integer|exists:inventory_locations,id',
            'product_id'  => 'required|integer|exists:products,id',
            'quantity'    => 'required|numeric|min:0.001',
            'unit_id'     => 'nullable|integer',
            'purpose'     => 'required|string|min:5|max:255',
            'valid_until' => 'nullable|date',
        ]);
        if ($data['valid_until'] && $data['valid_until'] < date('Y-m-d')) {
            throw new \App\Exceptions\BusinessRuleException('“Valid until” cannot be in the past.');
        }
        $data['quantity'] = (float) $data['quantity'];
        $data['batch_id'] = null;
        $this->service->request($data, $this->userId());
        flash('success', 'Reservation requested. It becomes active once approved.');
        return redirect(route('reservations.index'));
    }

    public function decide(Request $request, int $id): Response
    {
        match ((string) $request->input('action')) {
            'approve' => $this->service->approve($id, $this->userId()),
            'reject'  => $this->service->reject($id, $this->userId()),
            'release' => $this->service->release($id, $this->userId()),
            default   => throw new \App\Exceptions\BusinessRuleException('Unknown action.'),
        };
        flash('success', 'Reservation updated.');
        return redirect(route('reservations.index'));
    }
}
