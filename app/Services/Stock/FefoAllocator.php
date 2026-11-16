<?php
declare(strict_types=1);

namespace App\Services\Stock;

use App\Core\Database;
use App\Exceptions\InsufficientStockException;

/**
 * FEFO (First Expiry, First Out) batch allocation.
 *
 * Given a product, a location and a quantity, returns which batches to take
 * stock from: earliest expiry first (batches without expiry last, then oldest
 * received). It NEVER allocates:
 *   - expired batches (expiry_date < today)
 *   - batches that are recalled, quarantined, expired or depleted (status <> active)
 *   - stock held by active emergency reservations
 *
 * With $lock = true the balance rows are locked (SELECT ... FOR UPDATE), so the
 * allocation stays valid until the surrounding transaction commits.
 */
final class FefoAllocator
{
    public function __construct(private Database $db)
    {
    }

    /**
     * Usable batches at a location in FEFO order, with available quantity after reservations.
     * @return array<int, array{batch_id:int, batch_code:string, lot_number:string, expiry_date:?string, unit_cost:float, on_hand:float, reserved:float, available:float, days_to_expiry:?int}>
     */
    public function candidates(int $productId, int $locationId, bool $lock = false): array
    {
        $rows = $this->db->fetchAll(
            "SELECT sb.batch_id, sb.quantity, b.batch_code, b.lot_number, b.expiry_date, b.unit_cost,
                    DATEDIFF(b.expiry_date, CURDATE()) AS days_to_expiry
               FROM stock_balances sb
               JOIN product_batches b ON b.id = sb.batch_id
              WHERE sb.location_id = :loc AND sb.product_id = :pid AND sb.quantity > 0
                AND b.status = 'active'
                AND (b.expiry_date IS NULL OR b.expiry_date >= CURDATE())
              ORDER BY b.expiry_date IS NULL, b.expiry_date, b.received_at, b.id" . ($lock ? ' FOR UPDATE' : ''),
            ['loc' => $locationId, 'pid' => $productId]
        );

        // Active reservations: batch-specific ones reduce that batch; product-level ones
        // are kept back from the LATEST-expiring stock so FEFO still issues oldest first.
        $reservations = $this->db->fetchAll(
            "SELECT batch_id, SUM(quantity) AS qty FROM stock_reservations
              WHERE location_id = :loc AND product_id = :pid AND status = 'active'
                AND (valid_until IS NULL OR valid_until > NOW())
              GROUP BY batch_id",
            ['loc' => $locationId, 'pid' => $productId]
        );
        $byBatch = [];
        $productLevel = 0.0;
        foreach ($reservations as $r) {
            if ($r['batch_id'] === null) {
                $productLevel += (float) $r['qty'];
            } else {
                $byBatch[(int) $r['batch_id']] = (float) $r['qty'];
            }
        }

        $out = [];
        foreach ($rows as $r) {
            $id = (int) $r['batch_id'];
            $onHand = (float) $r['quantity'];
            $reserved = min($onHand, $byBatch[$id] ?? 0.0);
            $out[] = [
                'batch_id'       => $id,
                'batch_code'     => $r['batch_code'],
                'lot_number'     => $r['lot_number'],
                'expiry_date'    => $r['expiry_date'],
                'unit_cost'      => (float) $r['unit_cost'],
                'on_hand'        => $onHand,
                'reserved'       => $reserved,
                'available'      => $onHand - $reserved,
                'days_to_expiry' => $r['days_to_expiry'] === null ? null : (int) $r['days_to_expiry'],
            ];
        }
        // Apply product-level reservations from the end of the FEFO list.
        for ($i = count($out) - 1; $i >= 0 && $productLevel > 0; $i--) {
            $take = min($out[$i]['available'], $productLevel);
            $out[$i]['available'] -= $take;
            $out[$i]['reserved'] += $take;
            $productLevel -= $take;
        }
        return $out;
    }

    /**
     * Allocate a quantity. If $batchId is given, only that batch is used (manual override).
     * @return array<int, array{batch_id:int, batch_code:string, expiry_date:?string, unit_cost:float, quantity:float}>
     * @throws InsufficientStockException
     */
    public function allocate(int $productId, int $locationId, float $quantity, ?int $batchId = null, bool $lock = true): array
    {
        $candidates = $this->candidates($productId, $locationId, $lock);
        if ($batchId !== null) {
            $candidates = array_values(array_filter($candidates, fn ($c) => $c['batch_id'] === $batchId));
            if (!$candidates) {
                throw new InsufficientStockException('The selected batch is not available at this location (it may be expired, recalled, reserved or out of stock).');
            }
        }
        $available = array_sum(array_column($candidates, 'available'));
        if ($available + 1e-9 < $quantity) {
            throw new InsufficientStockException(sprintf(
                'Not enough usable stock: %s requested, %s available%s.',
                self::fmt($quantity),
                self::fmt($available),
                $batchId !== null ? ' in the selected batch' : ' (expired, recalled and reserved stock excluded)'
            ));
        }

        $allocation = [];
        $remaining = $quantity;
        foreach ($candidates as $c) {
            if ($remaining <= 1e-9) {
                break;
            }
            $take = min($c['available'], $remaining);
            if ($take <= 0) {
                continue;
            }
            $allocation[] = [
                'batch_id'    => $c['batch_id'],
                'batch_code'  => $c['batch_code'],
                'lot_number'  => $c['lot_number'],
                'expiry_date' => $c['expiry_date'],
                'unit_cost'   => $c['unit_cost'],
                'quantity'    => round($take, 3),
            ];
            $remaining = round($remaining - $take, 3);
        }
        return $allocation;
    }

    private static function fmt(float $n): string
    {
        return rtrim(rtrim(number_format($n, 3, '.', ''), '0'), '.');
    }
}
