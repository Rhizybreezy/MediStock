<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Repositories\AuditLogRepository;

final class AuditLogController extends Controller
{
    public function __construct(private AuditLogRepository $logs)
    {
    }

    public function index(Request $request): Response
    {
        $filters = $this->filters($request, ['q', 'user_id', 'action', 'entity_type', 'from', 'to']);
        foreach (['from', 'to'] as $d) {
            if ($filters[$d] && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $filters[$d])) {
                $filters[$d] = null;
            }
        }
        return view('admin/audit/index', [
            'title'       => 'Audit log',
            'page'        => $this->logs->search($filters, $this->page($request)),
            'filters'     => $filters,
            'actions'     => $this->logs->distinctActions(),
            'entityTypes' => $this->logs->distinctEntityTypes(),
            'users'       => $this->logs->userOptions(),
        ]);
    }

    public function show(Request $request, int $id): Response
    {
        return view('admin/audit/show', [
            'title' => 'Audit entry #' . $id,
            'log'   => $this->findOr404($this->logs->find($id), 'Audit entry'),
        ]);
    }
}
