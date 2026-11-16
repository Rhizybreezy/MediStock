<?php
declare(strict_types=1);

namespace App\Console;

use App\Core\Config;
use App\Core\Database;
use Throwable;

/**
 * Pre-flight / health checks for `php bin/console system:check`.
 * Each check returns [status, label, detail] where status is ok | warn | fail.
 * Nothing here changes data.
 */
final class SystemCheck
{
    private const DEMO_PASSWORDS = ['Admin@12345', 'Demo@12345'];

    /** @var list<array{0: string, 1: string, 2: string}> */
    private array $results = [];

    public function __construct(private string $basePath)
    {
    }

    /** @return list<array{0: string, 1: string, 2: string}> */
    public function run(): array
    {
        $this->results = [];
        $production = Config::get('app.env') === 'production';

        $this->php();
        $this->environment($production);
        $this->storage();
        $db = $this->database();
        if ($db) {
            $this->schema($db);
            $this->accounts($db, $production);
        }
        $this->ai();
        $this->scheduler();
        return $this->results;
    }

    private function add(string $status, string $label, string $detail = ''): void
    {
        $this->results[] = [$status, $label, $detail];
    }

    private function php(): void
    {
        $this->add(version_compare(PHP_VERSION, '8.2.0', '>=') ? 'ok' : 'fail', 'PHP version', PHP_VERSION . ' (8.2+ required)');
        $missing = array_values(array_filter(['pdo_mysql', 'mbstring', 'openssl', 'fileinfo', 'json', 'curl', 'gd', 'dom'], fn ($e) => !extension_loaded($e)));
        $this->add($missing ? 'fail' : 'ok', 'PHP extensions', $missing ? 'missing: ' . implode(', ', $missing) : 'pdo_mysql, mbstring, openssl, fileinfo, json, curl, gd, dom');
    }

    private function environment(bool $production): void
    {
        $env = $this->basePath . '/.env';
        if (!is_file($env)) {
            $this->add('fail', '.env file', 'missing: copy .env.example to .env');
        } elseif (PHP_OS_FAMILY === 'Windows') {
            // Unix permission bits are meaningless on Windows (NTFS uses ACLs).
            $this->add('ok', '.env file', 'present (on Windows, restrict access with folder permissions)');
        } else {
            $mode = fileperms($env) & 0777;
            if ($mode & 0002) {
                $this->add('fail', '.env permissions', sprintf('%o is world-writable; use 640 (owner + web-server group)', $mode));
            } elseif (($mode & 0004) && $production) {
                $this->add('warn', '.env permissions', sprintf('%o is world-readable; prefer 640 with the web-server group (chgrp daemon .env on XAMPP)', $mode));
            } else {
                $this->add('ok', '.env permissions', sprintf('%o', $mode));
            }
        }

        $key = (string) Config::get('app.key', '');
        $this->add(strlen($key) >= 32 ? 'ok' : 'fail', 'APP_KEY', strlen($key) >= 32 ? 'set' : 'missing or too short: php bin/console key:generate');

        $debug = (bool) Config::get('app.debug');
        $this->add($production ? 'ok' : 'warn', 'APP_ENV', (string) Config::get('app.env') . ($production ? '' : ' (use production on live servers)'));
        $this->add($debug && $production ? 'fail' : ($debug ? 'warn' : 'ok'), 'APP_DEBUG', $debug ? 'true: detailed errors are shown to users' : 'false');

        $url = (string) Config::get('app.url', '');
        $https = str_starts_with($url, 'https://');
        $this->add($https || !$production ? 'ok' : 'warn', 'APP_URL', ($url ?: '(not set)') . ($https ? '' : ' (serve over HTTPS in production)'));
        $this->add(date_default_timezone_get() === (string) Config::get('app.timezone', 'UTC') ? 'ok' : 'warn', 'Timezone', date_default_timezone_get());
    }

    private function storage(): void
    {
        foreach (['storage/logs', 'storage/cache', 'storage/cache/data', 'storage/sessions', 'storage/uploads', 'storage/reports'] as $dir) {
            $path = $this->basePath . '/' . $dir;
            if (!is_dir($path)) {
                $this->add($dir === 'storage/cache/data' ? 'ok' : 'fail', "$dir/", $dir === 'storage/cache/data' ? 'created on first use' : 'missing');
                continue;
            }
            if (PHP_OS_FAMILY === 'Windows') {
                $this->add(is_writable($path) ? 'ok' : 'fail', "$dir/", is_writable($path) ? 'writable' : 'NOT writable');
                continue;
            }
            $mode = fileperms($path) & 0777;
            // The web server (e.g. "daemon" on XAMPP) and CLI/cron users both write here.
            $shared = ($mode & 0002) || (function_exists('posix_getgrgid') && ($mode & 0020));
            $this->add(is_writable($path) ? ($shared ? 'ok' : 'warn') : 'fail', "$dir/", is_writable($path)
                ? sprintf('writable (%o)%s', $mode, $shared ? '' : ' - make it group-writable for the web-server user')
                : 'NOT writable by ' . get_current_user());
        }
    }

    private function database(): ?Database
    {
        try {
            $db = app(Database::class);
            $version = (string) $db->fetchValue('SELECT VERSION()');
            $this->add('ok', 'Database connection', $version . ' / ' . Config::get('database.database'));
            $user = (string) $db->fetchValue('SELECT CURRENT_USER()');
            $this->add(str_starts_with($user, 'root@') && Config::get('app.env') === 'production' ? 'warn' : 'ok', 'Database account',
                $user . (str_starts_with($user, 'root@') ? ' (use a restricted account in production: database/production_grants.sql)' : ''));
            return $db;
        } catch (Throwable $e) {
            $this->add('fail', 'Database connection', $e->getMessage());
            return null;
        }
    }

    private function schema(Database $db): void
    {
        $files = array_map('basename', glob($this->basePath . '/database/migrations/*.sql') ?: []);
        try {
            $ran = $db->fetchColumn('SELECT migration FROM migrations');
        } catch (Throwable) {
            $ran = [];
        }
        $pending = array_values(array_diff($files, $ran));
        $this->add($pending ? 'fail' : 'ok', 'Migrations', $pending ? count($pending) . ' pending: php bin/console migrate' : count($files) . ' applied');

        $triggers = (int) $db->fetchValue("SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() AND TRIGGER_NAME LIKE 'trg\\_%'");
        // information_schema.TRIGGERS only lists triggers to accounts holding the TRIGGER privilege.
        $canSeeTriggers = (int) $db->fetchValue("SELECT COUNT(*) FROM information_schema.SCHEMA_PRIVILEGES WHERE PRIVILEGE_TYPE = 'TRIGGER' AND TABLE_SCHEMA IN (DATABASE(), REPLACE(DATABASE(), '_', '\\\\_'))") > 0
            || (int) $db->fetchValue("SELECT COUNT(*) FROM information_schema.USER_PRIVILEGES WHERE PRIVILEGE_TYPE = 'TRIGGER'") > 0;
        if ($triggers >= 4) {
            $this->add('ok', 'Ledger / audit protection', 'append-only triggers present');
        } elseif (!$canSeeTriggers) {
            $this->add('ok', 'Ledger / audit protection', 'restricted account (cannot list triggers; ledger/audit are INSERT-only by grant)');
        } else {
            $this->add('fail', 'Ledger / audit protection', 'triggers missing (migration 0013)');
        }

        $perms = (int) $db->fetchValue('SELECT COUNT(*) FROM permissions');
        $expected = count((array) Config::get('permissions.permissions', []));
        $this->add($perms >= $expected ? 'ok' : 'fail', 'Permissions seeded', "$perms of $expected" . ($perms >= $expected ? '' : ': php bin/console db:seed'));

        try {
            $result = app(\App\Services\Stock\ReconciliationService::class)->run();
            $n = count($result['mismatches']);
            $this->add($n === 0 ? 'ok' : 'fail', 'Stock reconciliation', $n === 0 ? $result['checked'] . ' balances match the ledger' : "$n mismatch(es) - see Inventory > Reconciliation");
        } catch (Throwable $e) {
            $this->add('fail', 'Stock reconciliation', $e->getMessage());
        }
    }

    private function accounts(Database $db, bool $production): void
    {
        $admins = (int) $db->fetchValue("SELECT COUNT(DISTINCT u.id) FROM users u JOIN user_roles ur ON ur.user_id = u.id JOIN role_permissions rp ON rp.role_id = ur.role_id
            JOIN permissions p ON p.id = rp.permission_id WHERE p.code = 'users.manage' AND u.status = 'active'");
        $this->add($admins > 0 ? 'ok' : 'fail', 'Active administrators', (string) $admins);

        $weak = [];
        foreach ($db->fetchAll("SELECT username, password_hash, must_change_password FROM users WHERE status = 'active'") as $u) {
            foreach (self::DEMO_PASSWORDS as $pw) {
                if (password_verify($pw, (string) $u['password_hash'])) {
                    $weak[] = $u['username'] . ((int) $u['must_change_password'] === 1 ? '' : ' (no forced change)');
                    break;
                }
            }
        }
        $this->add($weak ? ($production ? 'fail' : 'warn') : 'ok', 'Default passwords', $weak ? 'still in use: ' . implode(', ', $weak) : 'none');
    }

    private function ai(): void
    {
        $key = (string) Config::get('ai.api_key', '');
        $this->add($key !== '' ? 'ok' : 'warn', 'Gemini API key', $key !== '' ? 'configured (model ' . Config::get('ai.model') . ')' : 'not set: the AI assistant is disabled, everything else works');
    }

    private function scheduler(): void
    {
        $file = $this->basePath . '/storage/cache/alerts_last_run';
        if (!is_file($file)) {
            $this->add('warn', 'Alert scan (cron)', 'has never run: schedule  */15 * * * * php bin/console alerts:run');
            return;
        }
        $age = time() - (int) filemtime($file);
        $this->add($age <= 3600 ? 'ok' : 'warn', 'Alert scan (cron)', 'last run ' . $this->ago($age) . ($age <= 3600 ? '' : ' - check the cron entry'));
    }

    private function ago(int $s): string
    {
        return match (true) {
            $s < 120   => "$s seconds ago",
            $s < 7200  => intdiv($s, 60) . ' minutes ago',
            $s < 172800 => intdiv($s, 3600) . ' hours ago',
            default    => intdiv($s, 86400) . ' days ago',
        };
    }
}
