<?php
declare(strict_types=1);

namespace App\Services\Stock;

use App\Core\Database;

/**
 * Verifies that stock_balances (current state) equals what the stock_movements
 * ledger says, for every (location, batch) pair:
 *
 *   ledger = Σ quantity moved INTO the location − Σ quantity moved OUT of it
 *
 * Differences are REPORTED, never auto-corrected: the fix must be an approved
 * data-correction adjustment so the ledger stays the single source of truth.
 */
final class ReconciliationService
{
    public function __construct(private Database $db)
    {
    }

    /** @return array{checked:int, mismatches:array, ran_at:string} */
    public function run(): array
    {
        $rows = $this->db->fetchAll(
            "SELECT k.location_id, k.batch_id,
                    COALESCE(lg.ledger_qty, 0) AS ledger_qty,
                    COALESCE(sb.quantity, 0)   AS balance_qty,
                    l.name AS location_name, b.batch_code, p.name AS product_name
               FROM (
                    SELECT location_id, batch_id FROM stock_balances
                    UNION
                    SELECT to_location_id, batch_id FROM stock_movements WHERE to_location_id IS NOT NULL
                    UNION
                    SELECT from_location_id, batch_id FROM stock_movements WHERE from_location_id IS NOT NULL
               ) k
               LEFT JOIN (
                    SELECT location_id, batch_id, SUM(qty) AS ledger_qty FROM (
                        SELECT to_location_id AS location_id, batch_id, quantity AS qty FROM stock_movements WHERE to_location_id IS NOT NULL
                        UNION ALL
                        SELECT from_location_id, batch_id, -quantity FROM stock_movements WHERE from_location_id IS NOT NULL
                    ) x GROUP BY location_id, batch_id
               ) lg ON lg.location_id = k.location_id AND lg.batch_id = k.batch_id
               LEFT JOIN stock_balances sb ON sb.location_id = k.location_id AND sb.batch_id = k.batch_id
               JOIN inventory_locations l ON l.id = k.location_id
               JOIN product_batches b ON b.id = k.batch_id
               JOIN products p ON p.id = b.product_id"
        );
        $mismatches = [];
        foreach ($rows as $r) {
            $diff = round((float) $r['balance_qty'] - (float) $r['ledger_qty'], 3);
            if (abs($diff) > 0.0005) {
                $r['difference'] = $diff;
                $mismatches[] = $r;
            }
        }
        return ['checked' => count($rows), 'mismatches' => $mismatches, 'ran_at' => date('Y-m-d H:i:s')];
    }
}
