<?php
declare(strict_types=1);

namespace App\Services\Department;

use App\Core\Database;
use App\Core\Gate;
use App\Exceptions\BusinessRuleException;
use App\Repositories\LocationRepository;
use App\Repositories\ProductRepository;
use App\Services\Stock\FefoAllocator;
use App\Services\Stock\QuantityConverter;
use App\Services\Stock\StockService;
use App\Services\Support\AuditService;

/**
 * Records stock used/dispensed in a department (CONSUMED movements, FEFO batches).
 * No patient-identifiable information is stored, only an optional purpose.
 */
final class ConsumptionService
{
    public const PURPOSES = [
        'patient_care' => 'Patient care / ward use', 'dispensing' => 'Dispensed to patients', 'procedure' => 'Procedure / theatre',
        'laboratory' => 'Laboratory testing', 'emergency' => 'Emergency use', 'cleaning' => 'Cleaning & sanitation', 'other' => 'Other',
    ];

    public function __construct(
        private Database $db,
        private Gate $gate,
        private StockService $stock,
        private FefoAllocator $fefo,
        private QuantityConverter $converter,
        private ProductRepository $products,
        private LocationRepository $locations,
        private AuditService $audit,
    ) {
    }

    public function record(array $header, array $lines, int $userId): int
    {
        $loc = $this->locations->find((int) $header['location_id']) ?? throw new BusinessRuleException('Location not found.');
        $this->gate->authorize('consumption.record', (int) $loc['department_id']);
        if ((int) $loc['is_active'] !== 1 || in_array($loc['type'], ['transit', 'quarantine'], true)) {
            throw new BusinessRuleException('Consumption cannot be recorded from this location.');
        }
        if (!isset(self::PURPOSES[$header['purpose'] ?? ''])) {
            throw new BusinessRuleException('Choose a purpose.');
        }
        if (!$lines) {
            throw new BusinessRuleException('Add at least one product.');
        }
        $wanted = [];
        foreach (array_values($lines) as $i => $line) {
            $n = $i + 1;
            $product = $this->products->find((int) $line['product_id']) ?? throw new BusinessRuleException("Line $n: product not found.");
            [$qty] = $this->converter->toBase((int) $product['id'], (float) $line['quantity'], $line['unit_id'] ?? null, "Line $n quantity");
            $wanted[] = ['product_id' => (int) $product['id'], 'batch_id' => !empty($line['batch_id']) ? (int) $line['batch_id'] : null, 'qty' => $qty];
        }

        $txnId = $this->db->transaction(function () use ($header, $wanted, $loc, $userId) {
            $movements = [];
            foreach ($wanted as $w) {
                foreach ($this->fefo->allocate($w['product_id'], (int) $loc['id'], $w['qty'], $w['batch_id']) as $a) {
                    $movements[] = [
                        'product_id' => $w['product_id'], 'batch_id' => $a['batch_id'], 'movement_type' => 'CONSUMED',
                        'from_location_id' => (int) $loc['id'], 'to_location_id' => null, 'quantity' => $a['quantity'],
                    ];
                }
            }
            $txnId = $this->stock->post([
                'type' => 'consumption', 'department_id' => (int) $loc['department_id'], 'performed_by' => $userId,
                'reason' => self::PURPOSES[$header['purpose']], 'notes' => $header['notes'] ?? null,
            ], $movements);
            $this->db->update('stock_transactions', ['purpose' => $header['purpose']], 'id = :id', ['id' => $txnId]);
            return $txnId;
        });
        $this->audit->log('consume', 'stock_transaction', $txnId, "Recorded consumption at {$loc['name']}", null, ['lines' => count($wanted)]);
        return $txnId;
    }
}
