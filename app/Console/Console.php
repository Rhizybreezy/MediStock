<?php
declare(strict_types=1);

namespace App\Console;

use App\Core\Config;
use App\Core\Database;
use PDO;
use Throwable;

/**
 * Command-line tool:  php bin/console <command> [options]
 *
 *   migrate            Create the database (if missing) and run pending migrations
 *   migrate:fresh      DROP all tables and views, then migrate (asks for confirmation; --force skips)
 *   migrate:status     List migrations and whether they have run
 *   db:seed            Insert reference data (roles, permissions, departments ...). Safe to re-run.
 *                      --demo-users  also create one demo user per role (development only)
 *                      --demo-data   also register a sample product catalogue (development only)
 *   user:create        Interactively create a user and assign a role
 *   key:generate       Print a new random APP_KEY
 *
 *   stock:reconcile    Verify balances against the ledger; notifies users with stock.reconcile on mismatch
 *                      (schedule nightly, e.g. cron: 15 2 * * * /opt/lampp/bin/php /path/bin/console stock:reconcile)
 *
 *   alerts:run         Create notifications (schedule every 15 minutes with cron)
 *   system:check       Health / deployment check; exits 1 if anything is FAIL
 *   demo:generate      DEVELOPMENT ONLY: realistic back-dated history for forecasts/charts (--days=365, --force)
 */
final class Console
{
    /** @var array<string, array{0: callable, 1: string}> */
    private array $commands = [];

    public function __construct(private array $argv)
    {
        $this->commands = [
            'migrate'        => [fn () => $this->migrate(), 'Run pending database migrations'],
            'migrate:fresh'  => [fn () => $this->fresh(), 'Drop all tables and re-run every migration'],
            'migrate:status' => [fn () => $this->status(), 'Show migration status'],
            'db:seed'        => [fn () => $this->seed(), 'Seed reference data (idempotent)'],
            'user:create'    => [fn () => $this->createUser(), 'Create a user interactively'],
            'key:generate'   => [fn () => $this->line(bin2hex(random_bytes(32))), 'Generate an APP_KEY value'],
            'stock:reconcile' => [fn () => $this->reconcile(), 'Verify stock balances against the movement ledger'],
            'alerts:run'      => [fn () => $this->alerts(), 'Scan stock, expiry, deliveries, approvals and anomalies; create notifications'],
            'demo:generate'   => [fn () => $this->demo(), 'DEVELOPMENT ONLY: generate back-dated demo history (--days=365)'],
            'system:check'    => [fn () => $this->systemCheck(), 'Pre-flight health check: config, permissions, database, schema, accounts, cron'],
        ];
    }

    /** Allow later phases to register more commands. */
    public function register(string $name, callable $handler, string $description): void
    {
        $this->commands[$name] = [$handler, $description];
    }

    public function run(): int
    {
        $name = $this->argv[1] ?? 'help';
        if (!isset($this->commands[$name])) {
            $this->help();
            return $name === 'help' ? 0 : 1;
        }
        try {
            ($this->commands[$name][0])();
            return 0;
        } catch (Throwable $e) {
            $this->line("\033[31mError:\033[0m " . $e->getMessage());
            if (Config::get('app.debug')) {
                $this->line($e->getFile() . ':' . $e->getLine());
            }
            return 1;
        }
    }

    public function hasOption(string $option): bool
    {
        return in_array($option, $this->argv, true);
    }

    public function line(string $text = ''): void
    {
        fwrite(STDOUT, $text . PHP_EOL);
    }

    public function ask(string $question, bool $hidden = false): string
    {
        fwrite(STDOUT, $question . ' ');
        if ($hidden && DIRECTORY_SEPARATOR === '/') {
            system('stty -echo');
            $answer = trim((string) fgets(STDIN));
            system('stty echo');
            fwrite(STDOUT, PHP_EOL);
            return $answer;
        }
        return trim((string) fgets(STDIN));
    }

    private function help(): void
    {
        $this->line('MediStock Intelligence console');
        $this->line('Usage: php bin/console <command> [options]');
        $this->line();
        foreach ($this->commands as $name => [, $description]) {
            $this->line(sprintf('  %-18s %s', $name, $description));
        }
    }

    // ------------------------------------------------------------------ migrations

    private function serverPdo(): PDO
    {
        $c = (array) Config::get('database');
        return new PDO(sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $c['host'], $c['port']), $c['username'], $c['password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
    }

    private function ensureDatabase(): void
    {
        $name = (string) Config::get('database.database');
        if (!preg_match('/^[A-Za-z0-9_]+$/', $name)) {
            throw new \RuntimeException('Invalid database name in .env');
        }
        $this->serverPdo()->exec("CREATE DATABASE IF NOT EXISTS `$name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    }

    private function migrationFiles(): array
    {
        $files = glob(BASE_PATH . '/database/migrations/*.sql') ?: [];
        sort($files);
        return $files;
    }

    private function migrate(): void
    {
        $this->ensureDatabase();
        $db = app(Database::class);
        $db->pdo()->exec('CREATE TABLE IF NOT EXISTS migrations (
            migration VARCHAR(190) NOT NULL PRIMARY KEY,
            ran_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB');
        $ran = $db->fetchColumn('SELECT migration FROM migrations');
        $count = 0;
        foreach ($this->migrationFiles() as $file) {
            $name = basename($file);
            if (in_array($name, $ran, true)) {
                continue;
            }
            $this->line("Migrating: $name");
            // DDL statements auto-commit in MySQL, so each file is applied statement by statement.
            foreach ($this->splitSql((string) file_get_contents($file)) as $statement) {
                $db->pdo()->exec($statement);
            }
            $db->insert('migrations', ['migration' => $name]);
            $count++;
        }
        $this->line($count ? "\033[32mDone: $count migration(s) applied.\033[0m" : 'Nothing to migrate.');
    }

    private function fresh(): void
    {
        $name = (string) Config::get('database.database');
        if (!$this->hasOption('--force')) {
            $answer = $this->ask("This will DELETE ALL DATA in database '$name'. Type the database name to confirm:");
            if ($answer !== $name) {
                $this->line('Aborted.');
                return;
            }
        }
        $this->ensureDatabase();
        $db = app(Database::class);
        $pdo = $db->pdo();
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach ($db->fetchAll('SELECT TABLE_NAME, TABLE_TYPE FROM information_schema.TABLES WHERE TABLE_SCHEMA = :s', ['s' => $name]) as $t) {
            $kind = $t['TABLE_TYPE'] === 'VIEW' ? 'VIEW' : 'TABLE';
            $pdo->exec("DROP $kind IF EXISTS `{$t['TABLE_NAME']}`");
        }
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
        $this->line('Dropped all tables and views.');
        $this->migrate();
    }

    private function status(): void
    {
        $db = app(Database::class);
        $ran = [];
        try {
            $ran = $db->fetchColumn('SELECT migration FROM migrations');
        } catch (Throwable) {
        }
        foreach ($this->migrationFiles() as $file) {
            $name = basename($file);
            $this->line(sprintf('  [%s] %s', in_array($name, $ran, true) ? "\033[32mran\033[0m " : "\033[33mpending\033[0m", $name));
        }
    }

    /** Split an SQL file into statements (statements end with ';' at end of line). */
    private function splitSql(string $sql): array
    {
        $sql = preg_replace('/^\s*--.*$/m', '', $sql);
        $parts = preg_split('/;\s*$/m', (string) $sql) ?: [];
        return array_values(array_filter(array_map('trim', $parts), fn ($s) => $s !== ''));
    }

    // ------------------------------------------------------------------ stock

    private function reconcile(): void
    {
        $result = app(\App\Services\Stock\ReconciliationService::class)->run();
        $count = count($result['mismatches']);
        $this->line(sprintf('Checked %d location/batch balance(s).', $result['checked']));
        app(\App\Services\Support\AuditService::class)->log('reconcile', 'stock', null, sprintf('Scheduled reconciliation: %d checked, %d mismatch(es)', $result['checked'], $count));
        if ($count === 0) {
            $this->line("\033[32mAll balances reconcile with the ledger.\033[0m");
            return;
        }
        foreach ($result['mismatches'] as $m) {
            $this->line(sprintf('  MISMATCH %s / %s (%s): ledger %s, balance %s', $m['location_name'], $m['product_name'], $m['batch_code'], $m['ledger_qty'], $m['balance_qty']));
        }
        app(\App\Core\Logger::class)->warning("Stock reconciliation found $count mismatch(es)");
        app(\App\Services\Support\NotificationService::class)->notifyPermission('stock.reconcile', [
            'type' => 'reconciliation', 'severity' => 'critical',
            'title' => 'Stock reconciliation mismatch',
            'message' => "$count stock balance(s) do not match the movement ledger. Review and correct with an approved adjustment.",
            'link_url' => '/inventory/reconciliation', 'dedupe_key' => 'reconcile:' . date('Y-m-d'),
        ]);
        $this->line("\033[31m$count mismatch(es) found. Users with stock.reconcile were notified.\033[0m");
        throw new \RuntimeException('Reconciliation failed.');
    }

    private function systemCheck(): void
    {
        $results = (new SystemCheck(BASE_PATH))->run();
        $colour = ['ok' => "\033[32m OK \033[0m", 'warn' => "\033[33mWARN\033[0m", 'fail' => "\033[31mFAIL\033[0m"];
        $counts = ['ok' => 0, 'warn' => 0, 'fail' => 0];
        foreach ($results as [$status, $label, $detail]) {
            $counts[$status]++;
            $this->line(sprintf('[%s] %-28s %s', $colour[$status], $label, $detail));
        }
        $this->line();
        $this->line(sprintf('%d ok, %d warning(s), %d failure(s)', $counts['ok'], $counts['warn'], $counts['fail']));
        if ($counts['fail'] > 0) {
            throw new \RuntimeException('System check failed.');
        }
    }

    private function alerts(): void
    {
        $result = app(\App\Services\Intelligence\AlertService::class)->run();
        foreach ($result as $k => $n) {
            $this->line(sprintf('  %-12s %d new notification(s)', $k, $n));
        }
        $this->line("\033[32mAlert scan complete.\033[0m");
    }

    private function demo(): void
    {
        $days = 365;
        foreach ($this->argv as $arg) {
            if (preg_match('/^--days=(\d+)$/', $arg, $m)) {
                $days = max(30, min(730, (int) $m[1]));
            }
        }
        if (!$this->hasOption('--force')) {
            $answer = $this->ask("Generate $days days of DEMO inventory history in '" . Config::get('database.database') . "'? This adds receipts, issues and consumption. Type 'yes':");
            if ($answer !== 'yes') {
                $this->line('Aborted.');
                return;
            }
        }
        $started = microtime(true);
        $log = app(\App\Services\Demo\DemoHistoryGenerator::class)->run($days, function (string $date, int $d, int $total) {
            $this->line(sprintf('  … %s (%d%%)', $date, (int) round(100 * $d / max(1, $total))));
        });
        $this->line(sprintf("\033[32mDone in %ds:\033[0m %d receipts, %d issues, %d consumption records.", microtime(true) - $started, $log['receipts'] ?? 0, $log['issues'] ?? 0, $log['consumption'] ?? 0));
        $this->line('Run php bin/console stock:reconcile to verify the ledger.');
    }

    // ------------------------------------------------------------------ seeding

    private function seed(): void
    {
        $db = app(Database::class);
        $files = glob(BASE_PATH . '/database/seeds/*.php') ?: [];
        sort($files);
        $context = [
            'demo_users' => $this->hasOption('--demo-users'),
            'demo_data'  => $this->hasOption('--demo-data'),
            'console'    => $this,
        ];
        foreach ($files as $file) {
            $this->line('Seeding: ' . basename($file));
            $seeder = require $file;
            $db->transaction(fn () => $seeder($db, $context));
        }
        $this->line("\033[32mSeeding complete.\033[0m");
    }

    private function createUser(): void
    {
        $db = app(Database::class);
        $roles = $db->fetchAll('SELECT id, code, name, scope FROM roles ORDER BY id');
        if (!$roles) {
            throw new \RuntimeException('No roles found. Run db:seed first.');
        }
        $fullName = $this->ask('Full name:');
        $username = $this->ask('Username:');
        $email = $this->ask('Email:');
        foreach ($roles as $i => $r) {
            $this->line(sprintf('  %d) %s (%s)', $i + 1, $r['name'], $r['scope']));
        }
        $choice = (int) $this->ask('Role number:');
        $role = $roles[$choice - 1] ?? null;
        $password = $this->ask('Password (min 8, letters and numbers):', true);

        if ($fullName === '' || !preg_match('/^[A-Za-z0-9._-]{3,50}$/', $username) || !filter_var($email, FILTER_VALIDATE_EMAIL) || !$role) {
            throw new \RuntimeException('Invalid input.');
        }
        if (strlen($password) < 8 || !preg_match('/[A-Za-z]/', $password) || !preg_match('/\d/', $password)) {
            throw new \RuntimeException('Password does not meet the policy.');
        }
        $db->transaction(function (Database $db) use ($fullName, $username, $email, $password, $role) {
            $id = $db->insert('users', [
                'full_name' => $fullName, 'username' => $username, 'email' => strtolower($email),
                'password_hash' => password_hash($password, PASSWORD_DEFAULT), 'status' => 'active',
                'must_change_password' => 0,
            ]);
            $db->insert('user_roles', ['user_id' => $id, 'role_id' => $role['id']]);
            if ($role['scope'] === 'department') {
                $this->line('Note: assign departments to this user in Admin → Users.');
            }
        });
        $this->line("\033[32mUser created.\033[0m");
    }
}
