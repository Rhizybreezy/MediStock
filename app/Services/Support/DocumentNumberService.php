<?php
declare(strict_types=1);

namespace App\Services\Support;

use App\Core\Database;

/**
 * Gap-safe, concurrency-safe document numbers.
 *
 *   next('GRN')            → GRN-2026-000001   (resets every year)
 *   next('P-MED', 'MED', false) → MED-000001   (never resets)
 *   batchCode()            → B26-000001
 *
 * The sequence row is locked with SELECT ... FOR UPDATE inside the caller's
 * transaction, so two users can never receive the same number. If the business
 * transaction rolls back, the number is not consumed.
 */
final class DocumentNumberService
{
    public function __construct(private Database $db)
    {
    }

    public function next(string $name, ?string $prefix = null, bool $yearly = true, int $pad = 6): string
    {
        $year = $yearly ? (int) date('Y') : 0;
        $value = $this->db->transaction(function (Database $db) use ($name, $year): int {
            $db->execute('INSERT IGNORE INTO document_sequences (name, seq_year, next_value) VALUES (:n, :y, 1)', ['n' => $name, 'y' => $year]);
            $current = (int) $db->fetchValue('SELECT next_value FROM document_sequences WHERE name = :n AND seq_year = :y FOR UPDATE', ['n' => $name, 'y' => $year]);
            $db->execute('UPDATE document_sequences SET next_value = next_value + 1 WHERE name = :n AND seq_year = :y', ['n' => $name, 'y' => $year]);
            return $current;
        });
        $number = str_pad((string) $value, $pad, '0', STR_PAD_LEFT);
        $prefix ??= $name;
        return $yearly ? "$prefix-$year-$number" : "$prefix-$number";
    }

    /** System batch identifier printed on batch labels / QR codes: B26-000001 */
    public function batchCode(): string
    {
        $year = (int) date('Y');
        $value = $this->db->transaction(function (Database $db) use ($year): int {
            $db->execute("INSERT IGNORE INTO document_sequences (name, seq_year, next_value) VALUES ('BATCH', :y, 1)", ['y' => $year]);
            $current = (int) $db->fetchValue("SELECT next_value FROM document_sequences WHERE name = 'BATCH' AND seq_year = :y FOR UPDATE", ['y' => $year]);
            $db->execute("UPDATE document_sequences SET next_value = next_value + 1 WHERE name = 'BATCH' AND seq_year = :y", ['y' => $year]);
            return $current;
        });
        return sprintf('B%s-%06d', date('y'), $value);
    }
}
