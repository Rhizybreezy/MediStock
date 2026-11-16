<?php
declare(strict_types=1);

namespace Tests\Integration;

use App\Services\Reporting\CsvExporter;
use App\Services\Reporting\ReportService;

/** Report figures must agree with the ledger and balances they summarise. */
final class ReportTest extends DatabaseTestCase
{
    private function filters(array $o = []): array
    {
        return $o + ['from' => date('Y-m-d', strtotime('-89 days')), 'to' => date('Y-m-d'), 'department_id' => null, 'category_id' => null, 'group' => '', 'days' => 90, 'type' => ''];
    }

    public function testValuationTotalEqualsBalances(): void
    {
        $this->actingAs('manager');
        $report = app(ReportService::class)->run('valuation', $this->filters());
        $expected = (float) $this->db->fetchValue('SELECT COALESCE(SUM(sb.quantity * b.unit_cost), 0) FROM stock_balances sb JOIN product_batches b ON b.id = sb.batch_id WHERE sb.quantity > 0');
        $this->assertEqualsWithDelta($expected, $report['totals']['value'], 0.01);
    }

    public function testConsumptionTotalEqualsLedger(): void
    {
        $this->actingAs('manager');
        $f = $this->filters(['group' => 'department']);
        $report = app(ReportService::class)->run('consumption', $f);
        $expected = (float) $this->db->fetchValue(
            "SELECT COALESCE(SUM(quantity * unit_cost), 0) FROM stock_movements WHERE movement_type = 'CONSUMED' AND created_at BETWEEN :f AND :t",
            ['f' => $f['from'] . ' 00:00:00', 't' => $f['to'] . ' 23:59:59']
        );
        $this->assertEqualsWithDelta($expected, $report['totals']['value'], 0.01);
    }

    public function testDepartmentUserSeesOnlyOwnDepartmentAndNoMoney(): void
    {
        $this->actingAs('maternity');
        $report = app(ReportService::class)->run('consumption', $this->filters(['group' => 'department']));
        $this->assertLessThanOrEqual(1, count($report['rows']));
        foreach ($report['rows'] as $r) {
            $this->assertSame('Maternity', $r['name']);
        }
        $this->assertArrayNotHasKey('value', $report['columns'], 'Money columns require valuation.view');
        // Asking for another department returns nothing.
        $ed = (int) $this->db->fetchValue("SELECT id FROM departments WHERE code = 'ED'");
        $this->assertSame([], app(ReportService::class)->run('consumption', $this->filters(['department_id' => $ed, 'group' => 'department']))['rows']);
    }

    public function testCsvFormulaInjectionIsNeutralised(): void
    {
        $this->assertSame("'=1+1", CsvExporter::safe('=1+1'));
        $this->assertSame("'@cmd", CsvExporter::safe('@cmd'));
        $this->assertSame('-12.5', CsvExporter::safe('-12.5'));
        $this->assertSame('Paracetamol', CsvExporter::safe('Paracetamol'));
    }
}
