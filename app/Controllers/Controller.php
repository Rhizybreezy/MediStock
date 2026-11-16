<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Gate;
use App\Core\Request;
use App\Exceptions\HttpException;

/**
 * Base controller with small shared helpers. Controllers stay thin:
 * validate input → call a service/repository → return a view, redirect or JSON.
 */
abstract class Controller
{
    protected function gate(): Gate
    {
        return app(Gate::class);
    }

    protected function userId(): int
    {
        return (int) auth()->id();
    }

    /** Standard list filters from the query string. */
    protected function filters(Request $request, array $keys): array
    {
        $out = [];
        foreach (array_merge($keys, ['sort', 'dir']) as $key) {
            $value = $request->query($key);
            $out[$key] = is_string($value) ? mb_substr($value, 0, 100) : null;
        }
        return $out;
    }

    protected function page(Request $request): int
    {
        return max(1, (int) $request->query('page', 1));
    }

    /** @throws HttpException 404 */
    protected function findOr404(?array $row, string $what = 'Record'): array
    {
        if ($row === null) {
            throw new HttpException(404, "$what not found.");
        }
        return $row;
    }

    /**
     * Read repeating form rows: lines[0][product_id], lines[0][quantity] ...
     * Blank rows are skipped. Values are trimmed strings or null.
     */
    protected function lines(Request $request, array $fields, string $key = 'lines'): array
    {
        $out = [];
        foreach ((array) $request->input($key, []) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $clean = [];
            foreach ($fields as $field) {
                $v = $row[$field] ?? null;
                $clean[$field] = is_string($v) ? (trim($v) === '' ? null : trim($v)) : (is_scalar($v) ? $v : null);
            }
            if (array_filter($clean, fn ($v) => $v !== null) === []) {
                continue;
            }
            $out[] = $clean;
        }
        return $out;
    }

    /** Valid YYYY-MM-DD date or null. */
    protected static function dateOrNull(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $d = \DateTime::createFromFormat('Y-m-d', $value);
        if (!$d || $d->format('Y-m-d') !== $value) {
            throw new \App\Exceptions\BusinessRuleException("Invalid date: $value (use YYYY-MM-DD).");
        }
        return $value;
    }
}
