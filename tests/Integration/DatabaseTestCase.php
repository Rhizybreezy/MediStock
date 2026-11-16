<?php
declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Database;
use PHPUnit\Framework\TestCase;

abstract class DatabaseTestCase extends TestCase
{
    protected Database $db;

    protected function setUp(): void
    {
        $this->db = app(Database::class);
        $this->db->pdo()->beginTransaction();
        // Tests may back-date ledger rows to build history (allowed by the 0013 trigger only with this flag).
        $this->db->execute('SET @medistock_allow_backdate = 1');
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        if ($this->db->pdo()->inTransaction()) {
            $this->db->pdo()->rollBack();
        }
        $this->db->execute('SET @medistock_allow_backdate = 0');
        $_SESSION = [];
        app(\App\Core\Gate::class)->flush();
    }

    /** Act as a seeded user (by username) for Gate/Auth checks. */
    protected function actingAs(string $username): int
    {
        $id = (int) $this->db->fetchValue('SELECT id FROM users WHERE username = :u', ['u' => $username]);
        $this->assertGreaterThan(0, $id, "Seeded user '$username' not found (run db:seed --demo-users).");
        $_SESSION['user_id'] = $id;
        app(\App\Core\Gate::class)->flush();
        return $id;
    }
}
