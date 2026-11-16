<?php
declare(strict_types=1);

namespace App\Services\Stock;

use App\Core\Database;
use App\Exceptions\BusinessRuleException;
use App\Exceptions\InsufficientStockException;
use App\Services\Support\DocumentNumberService;

/**
 * THE ONLY CLASS THAT CHANGES STOCK.
 *
 * post() writes one stock_transactions header plus one stock_movements row per
 * line, and updates stock_balances in the SAME database transaction:
 *
 *   1. lock every source balance row (SELECT ... FOR UPDATE, fixed order → no deadlocks)
 *   2. check there is enough stock at each source
 *   3. insert the ledger rows (append-only)
 *   4. decrement sources / increment destinations
 *   5. refresh batch status (depleted ↔ active)
 *
 * Any failure rolls everything back. The CHECK (quantity >= 0) constraint on
 * stock_balances is a second safety net. Quantities are always in base units.
 */
final class StockService
{
    public const TRANSACTION_TYPES = [
        'receipt', 'issue_dispatch', 'issue_receipt', 'transfer_dispatch', 'transfer_receipt', 'return',
        'consumption', 'adjustment', 'write_off', 'expiry_disposal', 'recall_quarantine', 'count_correction',
        'opening_balance', 'reversal',
    ];

    public const MOVEMENT_TYPES = [
        'RECEIVED', 'ISSUED', 'TRANSFERRED', 'RETURNED', 'CONSUMED', 'ADJUSTED_IN', 'ADJUSTED_OUT',
        'DAMAGED', 'EXPIRED', 'WRITTEN_OFF', 'QUARANTINED',
    ];

    public function __construct(private Database $db, private DocumentNumberService $numbers)
    {
    }

    /**
     * @param array $header ['type', 'department_id', 'performed_by', 'reference_type'?, 'reference_id'?,
     *                       'approved_by'?, 'approval_reference'?, 'reason'?, 'notes'?]
     * @param array $lines  [['product_id', 'batch_id', 'movement_type', 'from_location_id'|null,
     *                        'to_location_id'|null, 'quantity', 'reason'?], ...]
     * @return int stock_transactions.id
     */
    public function post(array $header, array $lines): int
    {
        if (!in_array($header['type'] ?? '', self::TRANSACTION_TYPES, true)) {
            throw new BusinessRuleException('Invalid stock transaction type.');
        }
        if (!$lines) {
            throw new BusinessRuleException('A stock transaction needs at least one line.');
        }
        foreach ($lines as $line) {
            $this->assertLine($line);
        }

        $txnId = $this->db->transaction(function (Database $db) use ($header, $lines): int {
            // 1-2. Lock source balances in a fixed order and check quantities (aggregated per balance row).
            $needed = [];
            foreach ($lines as $line) {
                if ($line['from_location_id'] !== null) {
                    $key = $line['from_location_id'] . ':' . $line['batch_id'];
                    $needed[$key] = ($needed[$key] ?? 0) + (float) $line['quantity'];
                }
            }
            ksort($needed, SORT_NATURAL);
            foreach ($needed as $key => $qty) {
                [$loc, $batch] = array_map('intval', explode(':', $key));
                $have = $db->fetchValue(
                    'SELECT quantity FROM stock_balances WHERE location_id = :l AND batch_id = :b FOR UPDATE',
                    ['l' => $loc, 'b' => $batch]
                );
                if ($have === null || (float) $have + 1e-9 < $qty) {
                    $code = $db->fetchValue('SELECT batch_code FROM product_batches WHERE id = :b', ['b' => $batch]);
                    $locName = $db->fetchValue('SELECT name FROM inventory_locations WHERE id = :l', ['l' => $loc]);
                    throw new InsufficientStockException(sprintf(
                        'Insufficient stock of batch %s at %s: %s needed, %s available.',
                        $code, $locName, self::fmt($qty), self::fmt((float) ($have ?? 0))
                    ));
                }
            }

            // 3. Header
            $txnId = $db->insert('stock_transactions', [
                'txn_number'         => $this->numbers->next('TXN'),
                'type'               => $header['type'],
                'reference_type'     => $header['reference_type'] ?? null,
                'reference_id'       => $header['reference_id'] ?? null,
                'department_id'      => (int) $header['department_id'],
                'performed_by'       => (int) $header['performed_by'],
                'approved_by'        => $header['approved_by'] ?? null,
                'approval_reference' => $header['approval_reference'] ?? null,
                'reason'             => isset($header['reason']) ? mb_substr((string) $header['reason'], 0, 255) : null,
                'notes'              => $header['notes'] ?? null,
            ]);

            // 3-4. Ledger rows + balance updates
            $touchedBatches = [];
            foreach ($lines as $line) {
                $batch = $db->fetchOne('SELECT product_id, unit_cost FROM product_batches WHERE id = :b', ['b' => $line['batch_id']]);
                if ($batch === null || (int) $batch['product_id'] !== (int) $line['product_id']) {
                    throw new BusinessRuleException('Batch does not belong to the product.');
                }
                $qty = round((float) $line['quantity'], 3);
                $movementId = $db->insert('stock_movements', [
                    'transaction_id'   => $txnId,
                    'product_id'       => (int) $line['product_id'],
                    'batch_id'         => (int) $line['batch_id'],
                    'movement_type'    => $line['movement_type'],
                    'from_location_id' => $line['from_location_id'],
                    'to_location_id'   => $line['to_location_id'],
                    'quantity'         => $qty,
                    'unit_cost'        => $batch['unit_cost'],
                    'performed_by'     => (int) $header['performed_by'],
                    'reason'           => isset($line['reason']) ? mb_substr((string) $line['reason'], 0, 255) : null,
                ]);

                if ($line['from_location_id'] !== null) {
                    $affected = $db->execute(
                        'UPDATE stock_balances SET quantity = quantity - :q, last_movement_id = :m
                          WHERE location_id = :l AND batch_id = :b AND quantity >= :q2',
                        ['q' => $qty, 'q2' => $qty, 'm' => $movementId, 'l' => $line['from_location_id'], 'b' => $line['batch_id']]
                    );
                    if ($affected !== 1) {
                        throw new InsufficientStockException('Stock changed while saving. Please try again.');
                    }
                }
                if ($line['to_location_id'] !== null) {
                    $db->execute(
                        'INSERT INTO stock_balances (location_id, batch_id, product_id, quantity, last_movement_id)
                         VALUES (:l, :b, :p, :q, :m)
                         ON DUPLICATE KEY UPDATE quantity = quantity + VALUES(quantity), last_movement_id = VALUES(last_movement_id)',
                        ['l' => $line['to_location_id'], 'b' => $line['batch_id'], 'p' => $line['product_id'], 'q' => $qty, 'm' => $movementId]
                    );
                }
                $touchedBatches[(int) $line['batch_id']] = true;
            }

            // 5. Batch status: depleted when nothing left anywhere; back to active when restocked.
            foreach (array_keys($touchedBatches) as $batchId) {
                $total = (float) $db->fetchValue('SELECT COALESCE(SUM(quantity), 0) FROM stock_balances WHERE batch_id = :b', ['b' => $batchId]);
                if ($total <= 0) {
                    $db->execute("UPDATE product_batches SET status = 'depleted' WHERE id = :b AND status = 'active'", ['b' => $batchId]);
                } else {
                    $db->execute("UPDATE product_batches SET status = 'active' WHERE id = :b AND status = 'depleted'", ['b' => $batchId]);
                }
            }

            return $txnId;
        });
        cache_bump();
        return $txnId;
    }

    private function assertLine(array $line): void
    {
        foreach (['product_id', 'batch_id', 'movement_type', 'quantity'] as $key) {
            if (!isset($line[$key])) {
                throw new BusinessRuleException("Stock line is missing '$key'.");
            }
        }
        if (!array_key_exists('from_location_id', $line) || !array_key_exists('to_location_id', $line)) {
            throw new BusinessRuleException('Stock line must define from_location_id and to_location_id (null allowed).');
        }
        if ($line['from_location_id'] === null && $line['to_location_id'] === null) {
            throw new BusinessRuleException('A movement needs a source or a destination.');
        }
        if ($line['from_location_id'] !== null && $line['from_location_id'] === $line['to_location_id']) {
            throw new BusinessRuleException('Source and destination must be different.');
        }
        if (!in_array($line['movement_type'], self::MOVEMENT_TYPES, true)) {
            throw new BusinessRuleException('Invalid movement type.');
        }
        if ((float) $line['quantity'] <= 0) {
            throw new BusinessRuleException('Movement quantity must be greater than zero.');
        }
    }

    private static function fmt(float $n): string
    {
        return rtrim(rtrim(number_format($n, 3, '.', ''), '0'), '.') ?: '0';
    }
}
