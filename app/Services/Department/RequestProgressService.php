<?php
declare(strict_types=1);

namespace App\Services\Department;

use App\Core\Database;

/**
 * Recomputes a stock request's issued/received quantities and status from the
 * issue transfers linked to it. Recomputing (rather than incrementing) keeps the
 * request consistent even after partial receipts and returns.
 */
final class RequestProgressService
{
    public function __construct(private Database $db)
    {
    }

    public function sync(int $requestId): void
    {
        $this->db->transaction(function (Database $db) use ($requestId) {
            $request = $db->fetchOne('SELECT * FROM stock_requests WHERE id = :id FOR UPDATE', ['id' => $requestId]);
            if (!$request) {
                return;
            }
            $totals = $db->fetchAll(
                "SELECT i.product_id,
                        SUM(i.dispatched_qty - i.returned_qty) AS issued,
                        SUM(i.received_qty) AS received
                   FROM stock_transfers t JOIN stock_transfer_items i ON i.stock_transfer_id = t.id
                  WHERE t.stock_request_id = :r AND t.status <> 'cancelled' AND i.dispatched_qty > 0
                  GROUP BY i.product_id",
                ['r' => $requestId]
            );
            $byProduct = [];
            foreach ($totals as $t) {
                $byProduct[(int) $t['product_id']] = $t;
            }
            $items = $db->fetchAll('SELECT * FROM stock_request_items WHERE stock_request_id = :r', ['r' => $requestId]);
            $approved = $issued = $received = 0.0;
            $partialApproval = false;
            foreach ($items as $item) {
                $t = $byProduct[(int) $item['product_id']] ?? ['issued' => 0, 'received' => 0];
                $db->update('stock_request_items', [
                    'issued_qty'   => round((float) $t['issued'], 3),
                    'received_qty' => round((float) $t['received'], 3),
                ], 'id = :id', ['id' => $item['id']]);
                $approved += (float) $item['approved_qty'];
                $issued += (float) $t['issued'];
                $received += (float) $t['received'];
                if ((float) $item['approved_qty'] < (float) $item['requested_qty']) {
                    $partialApproval = true;
                }
            }
            if (in_array($request['status'], ['draft', 'submitted', 'rejected', 'cancelled'], true)) {
                return;
            }
            $open = (int) $db->fetchValue(
                "SELECT COUNT(*) FROM stock_transfers WHERE stock_request_id = :r AND status IN ('dispatched','partially_received')",
                ['r' => $requestId]
            );
            $status = match (true) {
                $issued <= 1e-9                                 => $partialApproval ? 'partially_approved' : 'approved',
                $issued + 1e-9 < $approved                      => 'partially_issued',
                $open === 0 && $received + 1e-9 >= $issued      => 'received',
                default                                         => 'issued',
            };
            if ($status !== $request['status']) {
                $db->update('stock_requests', ['status' => $status], 'id = :id', ['id' => $requestId]);
            }
        });
    }
}
