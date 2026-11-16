<?php
declare(strict_types=1);

namespace Tests\Integration;

use PDOException;

/**
 * Database-level guarantees that hold even if application code is bypassed.
 */
final class DataIntegrityTest extends DatabaseTestCase
{
    private function lastMovementId(): int
    {
        $id = (int) $this->db->fetchValue('SELECT MAX(id) FROM stock_movements');
        if ($id === 0) {
            $this->markTestSkipped('No stock movements in the database (run demo:generate or receive stock first).');
        }
        return $id;
    }

    private function assertRefused(string $sql, array $params, string $needle): void
    {
        try {
            $this->db->execute($sql, $params);
            $this->fail("Statement should have been refused: $sql");
        } catch (PDOException $e) {
            $this->assertStringContainsString($needle, $e->getMessage());
        }
    }

    public function testLedgerRowsCannotBeDeleted(): void
    {
        $this->assertRefused('DELETE FROM stock_movements WHERE id = :id', ['id' => $this->lastMovementId()], 'append-only');
    }

    public function testLedgerQuantitiesCannotBeEditedEvenWithBackdateFlag(): void
    {
        // DatabaseTestCase sets @medistock_allow_backdate = 1; quantity changes must still fail.
        $this->assertRefused('UPDATE stock_movements SET quantity = quantity + 1 WHERE id = :id', ['id' => $this->lastMovementId()], 'append-only');
    }

    public function testLedgerTimestampsNeedTheBackdateFlag(): void
    {
        $id = $this->lastMovementId();
        $this->db->execute('SET @medistock_allow_backdate = 0');
        $this->assertRefused('UPDATE stock_movements SET created_at = created_at - INTERVAL 1 DAY WHERE id = :id', ['id' => $id], 'append-only');
        $this->db->execute('SET @medistock_allow_backdate = 1');
        $this->assertSame(1, $this->db->execute('UPDATE stock_movements SET created_at = created_at - INTERVAL 1 DAY WHERE id = :id', ['id' => $id]));
    }

    public function testAuditLogIsAppendOnly(): void
    {
        $uid = $this->actingAs('admin');
        app(\App\Services\Support\AuditService::class)->log('test.integrity', 'user', $uid, null, ['x' => 1]);
        $id = (int) $this->db->fetchValue('SELECT MAX(id) FROM audit_logs');
        $this->assertGreaterThan(0, $id);
        $this->assertRefused('UPDATE audit_logs SET action = :a WHERE id = :id', ['a' => 'tampered', 'id' => $id], 'append-only');
        $this->assertRefused('DELETE FROM audit_logs WHERE id = :id', ['id' => $id], 'append-only');
    }

    public function testBalancesCannotGoNegative(): void
    {
        $row = $this->db->fetchOne('SELECT id FROM stock_balances ORDER BY id LIMIT 1');
        if (!$row) {
            $this->markTestSkipped('No balances to test against.');
        }
        $this->assertRefused('UPDATE stock_balances SET quantity = -1 WHERE id = :id', ['id' => (int) $row['id']], 'ck_bal_quantity');
    }

    public function testMovementsNeedAPositiveQuantity(): void
    {
        $m = $this->db->fetchOne('SELECT transaction_id, product_id, batch_id, from_location_id, to_location_id, performed_by FROM stock_movements WHERE id = :id', ['id' => $this->lastMovementId()]);
        $this->assertRefused(
            'INSERT INTO stock_movements (transaction_id, product_id, batch_id, movement_type, from_location_id, to_location_id, quantity, performed_by)
             VALUES (:t, :p, :b, :mt, :f, :to, 0, :u)',
            ['t' => $m['transaction_id'], 'p' => $m['product_id'], 'b' => $m['batch_id'], 'mt' => 'ADJUSTED_IN', 'f' => $m['from_location_id'], 'to' => $m['to_location_id'] ?? $m['from_location_id'], 'u' => $m['performed_by']],
            'ck_mov'
        );
    }

    public function testCriticalTablesAreTransactionalWithForeignKeys(): void
    {
        $nonInno = $this->db->fetchColumn("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE' AND ENGINE <> 'InnoDB'");
        $this->assertSame([], $nonInno, 'Every table must use InnoDB (transactions + foreign keys)');
        foreach (['stock_movements' => ['product_id', 'batch_id', 'transaction_id', 'performed_by'], 'stock_balances' => ['product_id', 'batch_id', 'location_id'],
                  'product_batches' => ['product_id'], 'purchase_order_items' => ['purchase_order_id', 'product_id'], 'user_roles' => ['user_id', 'role_id']] as $table => $cols) {
            $fkCols = $this->db->fetchColumn('SELECT COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND REFERENCED_TABLE_NAME IS NOT NULL', ['t' => $table]);
            foreach ($cols as $c) {
                $this->assertContains($c, $fkCols, "$table.$c must have a foreign key");
            }
        }
    }

    public function testOneBalanceRowPerBatchAndLocation(): void
    {
        $dupes = (int) $this->db->fetchValue('SELECT COUNT(*) FROM (SELECT batch_id, location_id FROM stock_balances GROUP BY batch_id, location_id HAVING COUNT(*) > 1) d');
        $this->assertSame(0, $dupes);
    }

    public function testBalancesMatchTheLedger(): void
    {
        $this->assertSame([], app(\App\Services\Stock\ReconciliationService::class)->run()['mismatches']);
    }
}
