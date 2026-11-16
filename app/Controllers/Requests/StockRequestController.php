<?php
declare(strict_types=1);

namespace App\Controllers\Requests;

use App\Controllers\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Exceptions\BusinessRuleException;
use App\Exceptions\HttpException;
use App\Repositories\DepartmentRepository;
use App\Repositories\LocationRepository;
use App\Repositories\StockRequestRepository;
use App\Services\Department\StockRequestService;
use App\Services\Stock\FefoAllocator;

final class StockRequestController extends Controller
{
    public function __construct(
        private StockRequestRepository $requests,
        private LocationRepository $locations,
        private DepartmentRepository $departments,
        private StockRequestService $service,
        private FefoAllocator $fefo,
    ) {
    }

    private function scopes(): array
    {
        $g = $this->gate();
        $own = $g->can('requests.create') ? $g->departmentScope('requests.create') : [];
        $supplyPerms = array_filter(['requests.approve', 'stock.issue'], fn ($p) => $g->can($p));
        $supply = [];
        foreach ($supplyPerms as $p) {
            $s = $g->departmentScope($p);
            if ($s === null) {
                $supply = null;
                break;
            }
            $supply = array_values(array_unique(array_merge($supply, $s)));
        }
        return [$own, $supply];
    }

    public function index(Request $request): Response
    {
        $filters = $this->filters($request, ['q', 'status', 'priority', 'department_id']);
        if ($filters['status'] === null) {
            $filters['status'] = $this->gate()->can('requests.approve') ? 'to_review' : 'open';
        }
        if ($filters['status'] === 'all') {
            $filters['status'] = '';
        }
        [$own, $supply] = $this->scopes();
        return view('requests/index', [
            'title'       => 'Stock requests',
            'page'        => $this->requests->search($filters, $this->page($request), $own, $supply),
            'filters'     => $filters,
            'departments' => $this->departments->options(),
        ]);
    }

    public function create(Request $request): Response
    {
        return view('requests/create', [
            'title'      => 'New stock request',
            'locations'  => $this->locations->options($this->gate()->departmentScope('requests.create'), ['transit', 'quarantine']),
            'suppliers'  => array_values(array_filter($this->departments->options(), fn ($d) => (int) $d['is_central_store'] === 1 || $d['type'] === 'pharmacy')),
            'scripts'    => ['js/components/line-items.js', 'js/pages/request-form.js'],
        ]);
    }

    public function store(Request $request): Response
    {
        $header = Validator::validate($request->all(), [
            'destination_location_id' => 'required|integer|exists:inventory_locations,id',
            'supplying_department_id' => 'nullable|integer|exists:departments,id',
            'priority'                => 'required|in:routine,urgent,emergency',
            'needed_by'               => 'nullable|date',
            'notes'                   => 'nullable|string|max:1000',
        ], ['destination_location_id' => 'Deliver to']);
        $lines = [];
        foreach ($this->lines($request, ['product_id', 'quantity', 'unit_id']) as $i => $l) {
            if (!ctype_digit((string) $l['product_id']) || !is_numeric($l['quantity'])) {
                throw new BusinessRuleException('Line ' . ($i + 1) . ': choose a product and enter a quantity.');
            }
            $lines[] = ['product_id' => (int) $l['product_id'], 'quantity' => (float) $l['quantity'], 'unit_id' => ctype_digit((string) $l['unit_id']) ? (int) $l['unit_id'] : null];
        }
        $submit = $request->input('action') !== 'draft';
        $id = $this->service->create($header, $lines, $submit, $this->userId());
        flash('success', $submit ? 'Request submitted for review.' : 'Request saved as draft.');
        return redirect(route('requests.show', ['id' => $id]));
    }

    public function show(Request $request, int $id): Response
    {
        $r = $this->findOr404($this->requests->find($id), 'Request');
        $g = $this->gate();
        $canSee = $g->can('requests.create', (int) $r['requesting_department_id']) || $g->can('stock.view', (int) $r['requesting_department_id'])
            || $g->can('requests.approve', (int) $r['supplying_department_id']) || $g->can('stock.issue', (int) $r['supplying_department_id']);
        if (!$canSee) {
            throw new HttpException(404, 'Request not found.');
        }
        $uid = $this->userId();
        $canIssue = in_array($r['status'], ['approved', 'partially_approved', 'partially_issued'], true) && $g->can('stock.issue', (int) $r['supplying_department_id']);
        $sources = $canIssue ? $this->locations->options(null, ['transit', 'quarantine'], (int) $r['supplying_department_id']) : [];
        $items = $this->requests->items($id);
        $sourceId = (int) ($request->query('source') ?: ($sources[0]['id'] ?? 0));
        if ($canIssue && $sourceId) {
            foreach ($items as &$item) {
                $item['available'] = array_sum(array_column($this->fefo->candidates((int) $item['product_id'], $sourceId), 'available'));
            }
            unset($item);
        }
        return view('requests/show', [
            'title'     => $r['request_number'],
            'r'         => $r,
            'items'     => $items,
            'issues'    => $this->requests->issues($id),
            'canReview' => $r['status'] === 'submitted' && $g->can('requests.approve', (int) $r['supplying_department_id']) && (int) $r['requested_by'] !== $uid,
            'ownRequest'=> (int) $r['requested_by'] === $uid,
            'canSubmit' => $r['status'] === 'draft' && $g->can('requests.create', (int) $r['requesting_department_id']),
            'canCancel' => in_array($r['status'], ['draft', 'submitted', 'approved', 'partially_approved'], true) && $g->can('requests.create', (int) $r['requesting_department_id'])
                           && !array_filter($items, fn ($i) => (float) $i['issued_qty'] > 0),
            'canIssue'  => $canIssue,
            'canClose'  => in_array($r['status'], ['approved', 'partially_approved', 'partially_issued'], true) && $g->can('requests.approve', (int) $r['supplying_department_id']),
            'sources'   => $sources,
            'sourceId'  => $sourceId,
        ]);
    }

    public function submit(Request $request, int $id): Response
    {
        $this->service->submit($id, $this->userId());
        flash('success', 'Request submitted for review.');
        return redirect(route('requests.show', ['id' => $id]));
    }

    public function cancel(Request $request, int $id): Response
    {
        $this->service->cancel($id, $this->userId());
        flash('success', 'Request cancelled.');
        return redirect(route('requests.show', ['id' => $id]));
    }

    public function review(Request $request, int $id): Response
    {
        $decision = $request->input('decision') === 'reject' ? 'reject' : 'approve';
        $approved = [];
        foreach ((array) $request->input('approved', []) as $itemId => $qty) {
            if (ctype_digit((string) $itemId) && is_numeric($qty)) {
                $approved[(int) $itemId] = (float) $qty;
            }
        }
        $notes = trim((string) $request->input('review_notes', ''));
        $this->service->review($id, $decision, $approved, $notes !== '' ? mb_substr($notes, 0, 255) : null, $this->userId());
        flash('success', $decision === 'reject' ? 'Request rejected.' : 'Request approved.');
        return redirect(route('requests.show', ['id' => $id]));
    }

    public function issue(Request $request, int $id): Response
    {
        $source = $request->int('source_location_id');
        $qtys = [];
        foreach ((array) $request->input('issue', []) as $itemId => $qty) {
            if (ctype_digit((string) $itemId) && is_numeric($qty) && (float) $qty > 0) {
                $qtys[(int) $itemId] = (float) $qty;
            }
        }
        $tid = $this->service->issue($id, $source, $qtys, $this->userId());
        flash('success', 'Stock dispatched against the request. The department will confirm receipt.');
        return redirect(route('inventory.issues.show', ['id' => $tid]));
    }

    public function close(Request $request, int $id): Response
    {
        $data = Validator::validate($request->all(), ['reason' => 'required|string|min:3|max:200']);
        $this->service->closeRemaining($id, $data['reason'], $this->userId());
        flash('success', 'Remaining quantities closed.');
        return redirect(route('requests.show', ['id' => $id]));
    }
}
