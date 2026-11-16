<?php
declare(strict_types=1);

namespace App\Controllers\Analytics;

use App\Controllers\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Exceptions\AuthorizationException;
use App\Repositories\CategoryRepository;
use App\Repositories\DepartmentRepository;
use App\Services\Reporting\CsvExporter;
use App\Services\Reporting\PdfExporter;
use App\Services\Reporting\ReportService;
use App\Services\Support\AuditService;

final class AnalyticsController extends Controller
{
    /** Reports about procurement need supplier access in addition to analytics.view. */
    private const NEEDS = ['purchasing' => 'suppliers.view', 'suppliers' => 'suppliers.view'];

    public function __construct(
        private ReportService $reports,
        private DepartmentRepository $departments,
        private CategoryRepository $categories,
        private CsvExporter $csv,
        private PdfExporter $pdf,
        private AuditService $audit,
    ) {
    }

    private function reportFilters(Request $request): array
    {
        $date = fn ($v, $d) => (is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) && strtotime($v)) ? $v : $d;
        $to = $date($request->query('to'), date('Y-m-d'));
        $from = $date($request->query('from'), date('Y-m-d', strtotime($to . ' -89 days')));
        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }
        if ((strtotime($to) - strtotime($from)) / 86400 > 731) {
            $from = date('Y-m-d', strtotime($to . ' -731 days'));
        }
        return [
            'from' => $from, 'to' => $to,
            'department_id' => ctype_digit((string) $request->query('department_id')) ? (int) $request->query('department_id') : null,
            'category_id' => ctype_digit((string) $request->query('category_id')) ? (int) $request->query('category_id') : null,
            'group' => (string) $request->query('group', ''), 'days' => (int) $request->query('days', 90),
            'type' => (string) $request->query('type', ''),
        ];
    }

    private function available(): array
    {
        $out = [];
        foreach (ReportService::REPORTS as $key => [$label, $icon]) {
            if (!isset(self::NEEDS[$key]) || $this->gate()->can(self::NEEDS[$key])) {
                $out[$key] = [$label, $icon];
            }
        }
        return $out;
    }

    public function index(Request $request): Response
    {
        $f = $this->reportFilters($request);
        return view('analytics/index', [
            'title'       => 'Analytics',
            'f'           => $f,
            'kpi'         => $this->reports->overview($f),
            'reports'     => $this->available(),
            'departments' => $this->departments->options($this->gate()->departmentScope('stock.view')),
            'showValues'  => $this->gate()->can('valuation.view'),
            'scripts'     => ['vendor/chartjs/chart.umd.min.js', 'js/pages/analytics.js'],
        ]);
    }

    public function report(Request $request, string $key): Response
    {
        if (!isset($this->available()[$key])) {
            throw new AuthorizationException('You do not have access to this report.');
        }
        $f = $this->reportFilters($request);
        $report = $this->reports->run($key, $f);
        $format = (string) $request->query('format', 'html');
        $meta = [
            setting('hospital_name', 'Hospital') . ' — ' . $report['title'],
            !empty($report['dated']) ? "Period: {$f['from']} to {$f['to']}" : 'As of ' . date('Y-m-d H:i'),
            'Department: ' . ($f['department_id'] ? ($this->departments->find($f['department_id'])['name'] ?? '') : ($this->gate()->departmentScope('stock.view') === null ? 'All' : 'My departments')),
            'Generated ' . date('Y-m-d H:i') . ' by ' . (user()['full_name'] ?? ''),
        ];
        if (in_array($format, ['csv', 'pdf'], true)) {
            $this->gate()->authorize('reports.export');
            $this->audit->log('export', 'report', null, strtoupper($format) . " export: {$report['title']}", null, ['filters' => $f, 'rows' => count($report['rows'])]);
            $name = 'medistock-' . $key . '-' . date('Ymd');
            if ($format === 'csv') {
                $rows = array_map(fn ($r) => $this->formatRow($report['columns'], $r, false), $report['rows']);
                return $this->csv->render($report['columns'], $rows, "$name.csv", $meta);
            }
            return $this->pdf->render('analytics/report_pdf', ['title' => $report['title'], 'report' => $report, 'meta' => $meta,
                'fmt' => fn ($c, $r) => $this->formatRow($c, $r, true)], "$name.pdf", count($report['columns']) > 7 ? 'landscape' : 'portrait');
        }
        return view('analytics/report', [
            'title'       => $report['title'],
            'key'         => $key,
            'report'      => $report,
            'f'           => $f,
            'reports'     => $this->available(),
            'departments' => $this->departments->options($this->gate()->departmentScope('stock.view')),
            'categories'  => $this->categories->options(false),
            'canExport'   => $this->gate()->can('reports.export'),
            'fmt'         => fn ($c, $r) => $this->formatRow($c, $r, true),
            'scripts'     => ['vendor/chartjs/chart.umd.min.js', 'js/pages/analytics.js'],
        ]);
    }

    /** Format values for display (or plain values for CSV). */
    private function formatRow(array $columns, array $row, bool $display): array
    {
        $out = [];
        foreach ($columns as $k => $c) {
            $v = $row[$k] ?? null;
            $type = $c['type'] ?? 'text';
            if ($v === null || $v === '') {
                $out[$k] = $display ? '—' : '';
                continue;
            }
            $out[$k] = match ($type) {
                'money'      => $display ? number_format((float) $v, 2) : round((float) $v, 2),
                'qty'        => $display ? qty($v) : round((float) $v, 3),
                'int'        => $display ? number_format((float) $v) : (int) $v,
                'pct'        => $display ? number_format((float) $v * 100, 1) . '%' : round((float) $v * 100, 2),
                'pct_change' => $display ? (((float) $v > 0 ? '+' : '') . number_format((float) $v * 100, 1) . '%') : round((float) $v * 100, 2),
                'date'       => $display ? format_date((string) $v) : (string) $v,
                default      => (string) $v,
            };
        }
        return $out;
    }
}
