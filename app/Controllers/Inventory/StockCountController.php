<?php
declare(strict_types=1);

namespace App\Controllers\Inventory;

use App\Controllers\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Exceptions\BusinessRuleException;
use App\Exceptions\HttpException;
use App\Repositories\LocationRepository;
use App\Repositories\StockCountRepository;
use App\Services\Department\StockCountService;

final class StockCountController extends Controller
{
    public function __construct(
        private StockCountRepository $counts,
        private LocationRepository $locations,
        private StockCountService $service,
    ) {
    }

    public function index(Request $request): Response
    {
        $filters = $this->filters($request, ['status']);
        $perm = $this->gate()->can('counts.approve') ? 'counts.approve' : 'counts.perform';
        return view('counts/index', [
            'title'     => 'Stock counts',
            'page'      => $this->counts->search($filters, $this->page($request), $this->gate()->departmentScope($perm)),
            'filters'   => $filters,
            'locations' => $this->gate()->can('counts.perform') ? $this->locations->options($this->gate()->departmentScope('counts.perform'), ['transit']) : [],
        ]);
    }

    public function store(Request $request): Response
    {
        $data = Validator::validate($request->all(), [
            'location_id' => 'required|integer|exists:inventory_locations,id',
            'type'        => 'required|in:full,cycle,spot',
            'notes'       => 'nullable|string|max:255',
        ]);
        $id = $this->service->start($data['location_id'], $data['type'], $data['notes'], $this->userId());
        flash('success', 'Count started. Enter the physical quantity for each batch.');
        return redirect(route('counts.show', ['id' => $id]));
    }

    public function show(Request $request, int $id): Response
    {
        $count = $this->findOr404($this->counts->find($id), 'Count');
        $g = $this->gate();
        $dept = (int) $count['department_id'];
        if (!$g->can('counts.perform', $dept) && !$g->can('counts.approve', $dept)) {
            throw new HttpException(404, 'Count not found.');
        }
        $isApprover = $g->can('counts.approve', $dept) && (int) $count['started_by'] !== $this->userId();
        return view('counts/show', [
            'title'      => $count['count_number'],
            'count'      => $count,
            'items'      => $this->counts->items($id),
            'canCount'   => $count['status'] === 'in_progress' && $g->can('counts.perform', $dept),
            'canApprove' => $count['status'] === 'submitted' && $isApprover,
            // Blind count: system quantities are only shown to reviewers or once the count is finished.
            'showSystem' => $isApprover || in_array($count['status'], ['approved', 'cancelled'], true),
            'canCancel'  => in_array($count['status'], ['in_progress', 'submitted'], true) && $g->can('counts.perform', $dept),
            'showValues' => $g->can('valuation.view'),
            'scripts'    => ['vendor/html5-qrcode/html5-qrcode.min.js', 'js/components/scanner.js', 'js/pages/count-scan.js'],
        ]);
    }

    public function save(Request $request, int $id): Response
    {
        $counted = [];
        foreach ((array) $request->input('counted', []) as $itemId => $qty) {
            if (!ctype_digit((string) $itemId)) {
                continue;
            }
            $qty = trim((string) $qty);
            if ($qty !== '' && !is_numeric($qty)) {
                throw new BusinessRuleException('Counted quantities must be numbers.');
            }
            $counted[(int) $itemId] = $qty === '' ? null : (float) $qty;
        }
        $submit = $request->input('action') === 'submit';
        $this->service->saveCounts($id, $counted, $this->userId(), $submit);
        flash('success', $submit ? 'Count submitted for review.' : 'Progress saved.');
        return redirect(route('counts.show', ['id' => $id]));
    }

    public function approve(Request $request, int $id): Response
    {
        $adjId = $this->service->approve($id, $this->userId());
        flash('success', $adjId ? 'Count approved. Variances were posted as a stock adjustment.' : 'Count approved. No variances found.');
        return redirect(route('counts.show', ['id' => $id]));
    }

    public function cancel(Request $request, int $id): Response
    {
        $this->service->cancel($id, $this->userId());
        flash('success', 'Count cancelled.');
        return redirect(route('counts.show', ['id' => $id]));
    }
}
