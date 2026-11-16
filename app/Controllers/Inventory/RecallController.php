<?php
declare(strict_types=1);

namespace App\Controllers\Inventory;

use App\Controllers\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Repositories\BatchRepository;
use App\Repositories\RecallRepository;
use App\Services\Department\RecallService;

final class RecallController extends Controller
{
    public function __construct(
        private RecallRepository $recalls,
        private BatchRepository $batches,
        private RecallService $service,
    ) {
    }

    public function index(Request $request): Response
    {
        $filters = $this->filters($request, ['status']);
        return view('recalls/index', ['title' => 'Batch recalls', 'page' => $this->recalls->search($filters, $this->page($request)), 'filters' => $filters]);
    }

    public function create(Request $request): Response
    {
        $batch = $request->query('batch_id') ? $this->batches->find((int) $request->query('batch_id')) : null;
        return view('recalls/create', ['title' => 'Start batch recall', 'batch' => $batch, 'scripts' => ['js/pages/recall-form.js']]);
    }

    public function store(Request $request): Response
    {
        $data = Validator::validate($request->all(), [
            'batch_id' => 'required|integer|exists:product_batches,id',
            'reason'   => 'required|string|min:5|max:2000',
            'severity' => 'required|in:low,medium,high,critical',
            'source'   => 'required|in:manufacturer,regulator,supplier,internal',
        ], ['batch_id' => 'Batch']);
        $id = $this->service->create($data, $this->userId());
        flash('success', 'Recall started. The batch is blocked from issue and affected departments were notified.');
        return redirect(route('recalls.show', ['id' => $id]));
    }

    public function show(Request $request, int $id): Response
    {
        $trace = $this->service->trace($id);
        return view('recalls/show', ['title' => $trace['recall']['recall_number'], 'trace' => $trace]);
    }

    public function quarantine(Request $request, int $id): Response
    {
        $n = $this->service->quarantine($id, $this->userId());
        flash('success', "Recalled stock moved to quarantine from $n location(s).");
        return redirect(route('recalls.show', ['id' => $id]));
    }

    public function close(Request $request, int $id): Response
    {
        $data = Validator::validate($request->all(), ['closure_notes' => 'required|string|min:5|max:2000'], ['closure_notes' => 'Closure notes']);
        $this->service->close($id, $data['closure_notes'], $this->userId());
        flash('success', 'Recall closed.');
        return redirect(route('recalls.show', ['id' => $id]));
    }
}
