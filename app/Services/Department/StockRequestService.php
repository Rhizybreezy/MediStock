<?php
declare(strict_types=1);

namespace App\Services\Department;

use App\Core\Database;
use App\Core\Gate;
use App\Exceptions\BusinessRuleException;
use App\Repositories\DepartmentRepository;
use App\Repositories\LocationRepository;
use App\Repositories\ProductRepository;
use App\Repositories\StockRequestRepository;
use App\Services\Stock\IssueService;
use App\Services\Stock\QuantityConverter;
use App\Services\Support\ApprovalRules;
use App\Services\Support\AuditService;
use App\Services\Support\DocumentNumberService;
use App\Services\Support\NotificationService;

/**
 * Department stock requests:
 *   draft → submitted → approved | partially_approved | rejected
 *         → partially_issued → issued → received      (cancelled possible before issue)
 *
 * Issuing uses IssueService (FEFO, transit, receipt confirmation); progress is
 * recomputed from the linked issues by RequestProgressService.
 */
final class StockRequestService
{
    public function __construct(
        private Database $db,
        private Gate $gate,
        private StockRequestRepository $requests,
        private ProductRepository $products,
        private LocationRepository $locations,
        private DepartmentRepository $departments,
        private QuantityConverter $converter,
        private IssueService $issues,
        private RequestProgressService $progress,
        private DocumentNumberService $numbers,
        private NotificationService $notifications,
        private AuditService $audit,
    ) {
    }

    public function create(array $header, array $lines, bool $submit, int $userId): int
    {
        $dest = $this->locations->find((int) $header['destination_location_id']) ?? throw new BusinessRuleException('Delivery location not found.');
        $deptId = (int) $dest['department_id'];
        $this->gate->authorize('requests.create', $deptId);
        if ((int) $dest['is_active'] !== 1 || in_array($dest['type'], ['transit', 'quarantine'], true)) {
            throw new BusinessRuleException('Choose an active store location to deliver to.');
        }
        $supplying = (int) ($header['supplying_department_id'] ?? 0) ?: $this->departments->centralStoreId();
        if (!$supplying || $supplying === $deptId) {
            throw new BusinessRuleException('Choose a different department to supply this request.');
        }
        $items = $this->normaliseLines($lines);

        $id = $this->db->transaction(function (Database $db) use ($header, $items, $deptId, $supplying, $dest, $submit, $userId) {
            $id = $db->insert('stock_requests', [
                'request_number'           => $this->numbers->next('REQ'),
                'requesting_department_id' => $deptId,
                'supplying_department_id'  => $supplying,
                'destination_location_id'  => (int) $dest['id'],
                'priority'                 => $header['priority'] ?? 'routine',
                'status'                   => $submit ? 'submitted' : 'draft',
                'needed_by'                => $header['needed_by'] ?? null,
                'notes'                    => $header['notes'] ?? null,
                'requested_by'             => $userId,
                'submitted_at'             => $submit ? date('Y-m-d H:i:s') : null,
            ]);
            foreach ($items as $productId => $qty) {
                $db->insert('stock_request_items', ['stock_request_id' => $id, 'product_id' => $productId, 'requested_qty' => $qty]);
            }
            return $id;
        });
        $this->audit->log('create', 'stock_request', $id, 'Created stock request ' . ($submit ? '(submitted)' : '(draft)'), null, ['items' => count($items)]);
        if ($submit) {
            $this->notifySubmitted($id);
        }
        return $id;
    }

    public function submit(int $id, int $userId): void
    {
        $r = $this->requests->find($id) ?? throw new BusinessRuleException('Request not found.');
        $this->gate->authorize('requests.create', (int) $r['requesting_department_id']);
        if ($r['status'] !== 'draft') {
            throw new BusinessRuleException('Only draft requests can be submitted.');
        }
        $this->db->update('stock_requests', ['status' => 'submitted', 'submitted_at' => date('Y-m-d H:i:s')], 'id = :id', ['id' => $id]);
        $this->audit->log('submit', 'stock_request', $id, "Submitted {$r['request_number']}");
        $this->notifySubmitted($id);
    }

    public function cancel(int $id, int $userId): void
    {
        $r = $this->requests->find($id) ?? throw new BusinessRuleException('Request not found.');
        $this->gate->authorize('requests.create', (int) $r['requesting_department_id']);
        if (!in_array($r['status'], ['draft', 'submitted', 'approved', 'partially_approved'], true)) {
            throw new BusinessRuleException('This request can no longer be cancelled.');
        }
        $this->db->update('stock_requests', ['status' => 'cancelled'], 'id = :id', ['id' => $id]);
        $this->audit->log('cancel', 'stock_request', $id, "Cancelled {$r['request_number']}");
    }

    /**
     * @param string $decision 'approve' | 'reject'
     * @param array<int, float> $approved item_id => approved quantity (base units)
     */
    public function review(int $id, string $decision, array $approved, ?string $notes, int $userId): void
    {
        $r = $this->db->transaction(function (Database $db) use ($id, $decision, $approved, $notes, $userId) {
            $r = $db->fetchOne('SELECT * FROM stock_requests WHERE id = :id FOR UPDATE', ['id' => $id]) ?? throw new BusinessRuleException('Request not found.');
            $this->gate->authorize('requests.approve', (int) $r['supplying_department_id']);
            ApprovalRules::assertDifferentUser((int) $r['requested_by'], $userId, 'stock request');
            if ($r['status'] !== 'submitted') {
                throw new BusinessRuleException('Only submitted requests can be reviewed.');
            }
            if ($decision === 'reject') {
                if (!$notes) {
                    throw new BusinessRuleException('Give a reason for rejecting the request.');
                }
                $db->update('stock_requests', ['status' => 'rejected', 'reviewed_by' => $userId, 'reviewed_at' => date('Y-m-d H:i:s'), 'review_notes' => $notes], 'id = :id', ['id' => $id]);
                return $r + ['new_status' => 'rejected'];
            }
            $partial = false;
            $any = false;
            foreach ($this->requests->items($id) as $item) {
                $qty = round((float) ($approved[(int) $item['id']] ?? $item['requested_qty']), 3);
                if ($qty < 0 || $qty > (float) $item['requested_qty'] + 1e-9) {
                    throw new BusinessRuleException("{$item['product_name']}: approved quantity must be between 0 and the requested " . qty($item['requested_qty']) . '.');
                }
                $partial = $partial || $qty < (float) $item['requested_qty'];
                $any = $any || $qty > 0;
                $db->update('stock_request_items', ['approved_qty' => $qty], 'id = :id', ['id' => $item['id']]);
            }
            $status = !$any ? 'rejected' : ($partial ? 'partially_approved' : 'approved');
            $db->update('stock_requests', ['status' => $status, 'reviewed_by' => $userId, 'reviewed_at' => date('Y-m-d H:i:s'), 'review_notes' => $notes], 'id = :id', ['id' => $id]);
            return $r + ['new_status' => $status];
        });
        cache_bump();
        $this->audit->log($r['new_status'] === 'rejected' ? 'reject' : 'approve', 'stock_request', $id, label($r['new_status']) . " {$r['request_number']}", null, ['notes' => $notes]);
        $this->notifications->notifyUser((int) $r['requested_by'], [
            'type' => 'request_reviewed', 'severity' => $r['new_status'] === 'rejected' ? 'warning' : 'info',
            'title' => "Request {$r['request_number']} " . str_replace('_', ' ', $r['new_status']),
            'message' => $notes ? "Reviewer note: $notes" : 'Your stock request has been reviewed.',
            'link_url' => '/requests/' . $id, 'entity_type' => 'stock_request', 'entity_id' => $id,
            'dedupe_key' => 'request_reviewed:' . $id,
        ]);
    }

    /**
     * Issue (all or part of) the approved quantities from a store of the supplying department.
     * @param array<int, float> $quantities item_id => quantity to issue now (base units)
     */
    public function issue(int $id, int $sourceLocationId, array $quantities, int $userId): int
    {
        $r = $this->requests->find($id) ?? throw new BusinessRuleException('Request not found.');
        if (!in_array($r['status'], ['approved', 'partially_approved', 'partially_issued'], true)) {
            throw new BusinessRuleException('This request is not ready to be issued.');
        }
        $source = $this->locations->find($sourceLocationId) ?? throw new BusinessRuleException('Source location not found.');
        if ((int) $source['department_id'] !== (int) $r['supplying_department_id']) {
            throw new BusinessRuleException('Issue from a store of the supplying department (' . $r['supplying_department'] . ').');
        }
        $lines = [];
        foreach ($this->requests->items($id) as $item) {
            $remaining = round((float) $item['approved_qty'] - (float) $item['issued_qty'], 3);
            $qty = round((float) ($quantities[(int) $item['id']] ?? 0), 3);
            if ($qty <= 0) {
                continue;
            }
            if ($qty > $remaining + 1e-9) {
                throw new BusinessRuleException("{$item['product_name']}: only " . qty($remaining) . ' remain to be issued.');
            }
            $lines[] = ['product_id' => (int) $item['product_id'], 'quantity' => $qty, 'unit_id' => null];
        }
        if (!$lines) {
            throw new BusinessRuleException('Enter a quantity to issue for at least one item.');
        }
        return $this->issues->dispatch([
            'source_location_id' => $sourceLocationId,
            'destination_location_id' => (int) $r['destination_location_id'],
            'reason' => 'Request ' . $r['request_number'],
        ], $lines, $userId, 'issue', $id);
    }

    /** Stop issuing what remains (e.g. item unavailable): approved = issued for every line. */
    public function closeRemaining(int $id, string $reason, int $userId): void
    {
        $r = $this->requests->find($id) ?? throw new BusinessRuleException('Request not found.');
        $this->gate->authorize('requests.approve', (int) $r['supplying_department_id']);
        if (!in_array($r['status'], ['approved', 'partially_approved', 'partially_issued'], true)) {
            throw new BusinessRuleException('Nothing remains open on this request.');
        }
        $this->db->transaction(function (Database $db) use ($id, $reason, $r) {
            $db->execute('UPDATE stock_request_items SET approved_qty = issued_qty WHERE stock_request_id = :id', ['id' => $id]);
            $total = (float) $db->fetchValue('SELECT SUM(issued_qty) FROM stock_request_items WHERE stock_request_id = :id', ['id' => $id]);
            $db->update('stock_requests', [
                'status' => $total > 0 ? 'issued' : 'cancelled',
                'review_notes' => mb_substr(trim(($r['review_notes'] ? $r['review_notes'] . ' | ' : '') . 'Closed: ' . $reason), 0, 255),
            ], 'id = :id', ['id' => $id]);
        });
        $this->progress->sync($id);
        cache_bump();
        $this->audit->log('close', 'stock_request', $id, "Closed remaining quantities on {$r['request_number']}", null, ['reason' => $reason]);
    }

    /** @return array<int, float> product_id => base quantity (duplicates merged) */
    private function normaliseLines(array $lines): array
    {
        if (!$lines) {
            throw new BusinessRuleException('Add at least one product.');
        }
        $items = [];
        foreach (array_values($lines) as $i => $line) {
            $n = $i + 1;
            $product = $this->products->find((int) $line['product_id']) ?? throw new BusinessRuleException("Line $n: product not found.");
            if ((int) $product['is_active'] !== 1) {
                throw new BusinessRuleException("Line $n: {$product['name']} is inactive.");
            }
            [$qty] = $this->converter->toBase((int) $product['id'], (float) $line['quantity'], $line['unit_id'] ?? null, "Line $n quantity");
            $items[(int) $product['id']] = round(($items[(int) $product['id']] ?? 0) + $qty, 3);
        }
        return $items;
    }

    private function notifySubmitted(int $id): void
    {
        $r = $this->requests->find($id);
        $this->notifications->notifyPermission('requests.approve', [
            'type' => 'request_pending', 'severity' => $r['priority'] === 'emergency' ? 'critical' : ($r['priority'] === 'urgent' ? 'warning' : 'info'),
            'title' => ucfirst($r['priority']) . " stock request {$r['request_number']}",
            'message' => "{$r['requesting_department']} requested {$r['item_count']} product(s). Review required.",
            'link_url' => '/requests/' . $id, 'entity_type' => 'stock_request', 'entity_id' => $id,
            'dedupe_key' => 'request_submitted:' . $id,
        ], (int) $r['supplying_department_id']);
    }
}
