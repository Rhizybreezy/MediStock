<?php
declare(strict_types=1);

namespace App\Services\Reporting;

use App\Core\Response;

/**
 * Spreadsheet-compatible CSV (UTF-8 with BOM so Excel shows accents correctly).
 * Cells beginning with = + - @ (or tab/CR) are prefixed with an apostrophe to
 * prevent CSV/formula injection when the file is opened in a spreadsheet.
 */
final class CsvExporter
{
    /** @param array<string, array{label:string}> $columns */
    public function render(array $columns, array $rows, string $filename, array $meta = []): Response
    {
        $fh = fopen('php://temp', 'w+');
        fwrite($fh, "\xEF\xBB\xBF");
        foreach ($meta as $line) {
            fputcsv($fh, [self::safe((string) $line)]);
        }
        if ($meta) {
            fputcsv($fh, []);
        }
        fputcsv($fh, array_map(fn ($c) => self::safe($c['label']), $columns));
        foreach ($rows as $row) {
            $out = [];
            foreach ($columns as $key => $c) {
                $v = $row[$key] ?? '';
                $out[] = is_float($v) ? round($v, 4) : self::safe((string) $v);
            }
            fputcsv($fh, $out);
        }
        rewind($fh);
        $csv = (string) stream_get_contents($fh);
        fclose($fh);
        return Response::download($csv, $filename, 'text/csv; charset=utf-8');
    }

    public static function safe(string $value): string
    {
        if ($value !== '' && preg_match('/^[=+\-@\t\r]/', $value) && !is_numeric($value)) {
            return "'" . $value;
        }
        return $value;
    }
}
