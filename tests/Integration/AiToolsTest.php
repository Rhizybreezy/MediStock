<?php
declare(strict_types=1);

namespace Tests\Integration;

use App\Services\Ai\ToolRegistry;

/** Tool layer without calling Gemini: permissions, scope, validation, proposals. */
final class AiToolsTest extends DatabaseTestCase
{
    private function names(): array
    {
        return array_column(app(ToolRegistry::class)->declarations(), 'name');
    }

    public function testDeclarationsFollowPermissions(): void
    {
        $this->actingAs('maternity');
        $names = $this->names();
        $this->assertContains('get_low_stock_items', $names);
        $this->assertNotContains('get_inventory_value', $names, 'storekeeper has no valuation access');
        $this->assertNotContains('prepare_purchase_request', $names, 'storekeeper has no ai.propose');
        $this->assertNotContains('get_supplier_performance', $names);
        $this->actingAs('inventory');
        $this->assertContains('prepare_purchase_request', $this->names());
    }

    public function testDeniedToolCallIsRefused(): void
    {
        $this->actingAs('maternity');
        $r = app(ToolRegistry::class)->execute('get_inventory_value', ['group_by' => 'category']);
        $this->assertSame('denied', $r['status']);
    }

    public function testDepartmentScopeCannotBeEscapedByArguments(): void
    {
        $this->actingAs('maternity');
        $r = app(ToolRegistry::class)->execute('get_department_stock', ['department' => 'Emergency Department']);
        $this->assertSame('denied', $r['status']);
        $ok = app(ToolRegistry::class)->execute('get_department_stock', ['department' => 'Maternity']);
        $this->assertSame('ok', $ok['status']);
        $this->assertSame('actual', $ok['response']['data_kind']);
    }

    public function testInvalidArgumentsAreRejected(): void
    {
        $this->actingAs('inventory');
        $this->assertSame('invalid', app(ToolRegistry::class)->execute('get_expiring_batches', ['within_days' => 5000])['status']);
        $this->assertSame('invalid', app(ToolRegistry::class)->execute('get_expiring_batches', ['query' => 'SELECT * FROM users'])['status']);
        $this->assertSame('invalid', app(ToolRegistry::class)->execute('drop_database', [])['status']);
    }

    public function testEstimatesAreLabelled(): void
    {
        $this->actingAs('inventory');
        $r = app(ToolRegistry::class)->execute('get_stockout_predictions', []);
        $this->assertSame('ok', $r['status']);
        $this->assertSame('estimate', $r['response']['data_kind']);
    }

    public function testPreparePurchaseRequestCreatesDraftOnly(): void
    {
        $this->actingAs('inventory');
        $code = (string) $this->db->fetchValue("SELECT product_code FROM products WHERE is_active = 1 ORDER BY id LIMIT 1");
        $r = app(ToolRegistry::class)->execute('prepare_purchase_request', ['items' => [['product' => $code, 'quantity' => 100]], 'justification' => 'Test']);
        $this->assertSame('ok', $r['status'], json_encode($r));
        $pr = $this->db->fetchOne('SELECT status, source FROM purchase_requests ORDER BY id DESC LIMIT 1');
        $this->assertSame(['status' => 'draft', 'source' => 'ai_proposal'], $pr);
        $this->assertSame('proposal', $r['response']['data_kind']);
    }

    public function testAmbiguousProductAsksForClarification(): void
    {
        $this->actingAs('inventory');
        $r = app(ToolRegistry::class)->execute('prepare_purchase_request', ['items' => [['product' => 'mg', 'quantity' => 1]], 'justification' => 'Test']);
        $this->assertSame('invalid', $r['status']);
        $this->assertNotEmpty($r['response']['candidates'] ?? []);
    }
}
