<?php
declare(strict_types=1);

namespace App\Services\Support;

use App\Core\Config;
use App\Core\Database;
use App\Core\Request;
use App\Core\Session;
use App\Exceptions\BusinessRuleException;

/**
 * Login, logout, current user, login rate limiting and account locking.
 *
 * Security notes:
 * - Same generic message for unknown user and wrong password (no user enumeration).
 * - A dummy password_verify() runs for unknown users so timing is similar.
 * - Account status (inactive/locked) is only revealed after a correct password.
 * - session_regenerate_id() on login prevents session fixation.
 */
final class AuthService
{
    private ?array $user = null;
    private bool $loaded = false;

    public function __construct(private Database $db, private AuditService $audit)
    {
    }

    public function id(): ?int
    {
        $id = Session::get('user_id');
        return $id === null ? null : (int) $id;
    }

    public function check(): bool
    {
        return $this->user() !== null;
    }

    /** Current active user (without password hash), or null. */
    public function user(): ?array
    {
        if ($this->loaded) {
            return $this->user;
        }
        $this->loaded = true;
        $id = $this->id();
        if ($id === null) {
            return null;
        }
        $user = $this->db->fetchOne(
            'SELECT id, full_name, username, email, phone, status, must_change_password, last_login_at
               FROM users WHERE id = :id',
            ['id' => $id]
        );
        if ($user === null || $user['status'] !== 'active') {
            $this->logout();
            return null;
        }
        $user['roles'] = $this->db->fetchAll(
            'SELECT r.code, r.name FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE ur.user_id = :id ORDER BY r.name',
            ['id' => $id]
        );
        return $this->user = $user;
    }

    /**
     * @throws BusinessRuleException with a user-safe message on failure
     */
    public function attempt(string $login, string $password, Request $request): void
    {
        $cfg = (array) Config::get('security.login');
        $ip = $request->ip();
        $ipBin = @inet_pton($ip) ?: inet_pton('0.0.0.0');
        $login = mb_strtolower(trim($login));
        $window = (int) $cfg['window_minutes'];

        // ---- Rate limiting (per username and per IP) -------------------------
        $userFailures = (int) $this->db->fetchValue(
            'SELECT COUNT(*) FROM login_attempts
              WHERE username = :u AND succeeded = 0 AND attempted_at > (NOW() - INTERVAL :w MINUTE)',
            ['u' => $login, 'w' => $window]
        );
        $ipFailures = (int) $this->db->fetchValue(
            'SELECT COUNT(*) FROM login_attempts
              WHERE ip_address = :ip AND succeeded = 0 AND attempted_at > (NOW() - INTERVAL :w MINUTE)',
            ['ip' => $ipBin, 'w' => $window]
        );
        if ($userFailures >= (int) $cfg['max_attempts_per_user'] || $ipFailures >= (int) $cfg['max_attempts_per_ip']) {
            throw new BusinessRuleException("Too many failed sign-in attempts. Please wait {$window} minutes and try again.");
        }

        $user = $this->db->fetchOne(
            'SELECT id, username, password_hash, status, locked_until, failed_login_count
               FROM users WHERE LOWER(username) = :l OR LOWER(email) = :l2 LIMIT 1',
            ['l' => $login, 'l2' => $login]
        );

        // ---- Credential check -----------------------------------------------
        $hash = $user['password_hash'] ?? '$2y$12$4n1vgyOVA9/txRjHdzZTL.tq2EqsP/umky1y4TO7YLbiaGywP73cS';
        $valid = password_verify($password, $hash) && $user !== null;

        if (!$valid) {
            $this->recordAttempt($login, $ipBin, false);
            if ($user !== null) {
                $failures = (int) $user['failed_login_count'] + 1;
                $lock = $failures >= (int) $cfg['lock_after_failures'];
                $this->db->update('users', [
                    'failed_login_count' => $failures,
                    'status'             => $lock ? 'locked' : $user['status'],
                ], 'id = :id', ['id' => $user['id']]);
                if ($lock && $user['status'] !== 'locked') {
                    $this->audit->log('account_locked', 'user', (int) $user['id'], 'Locked after repeated failed sign-ins', null, null, (int) $user['id']);
                }
            }
            throw new BusinessRuleException('Invalid username/email or password.');
        }

        if ($user['status'] === 'inactive') {
            $this->recordAttempt($login, $ipBin, false);
            throw new BusinessRuleException('Your account is inactive. Please contact the system administrator.');
        }
        if ($user['status'] === 'locked') {
            $this->recordAttempt($login, $ipBin, false);
            throw new BusinessRuleException('Your account is locked. Please contact the system administrator.');
        }

        // ---- Success ----------------------------------------------------------
        $this->recordAttempt($login, $ipBin, true);
        $update = [
            'failed_login_count' => 0,
            'last_login_at'      => date('Y-m-d H:i:s'),
            'last_login_ip'      => $ipBin,
        ];
        if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
            $update['password_hash'] = password_hash($password, PASSWORD_DEFAULT);
        }
        $this->db->update('users', $update, 'id = :id', ['id' => $user['id']]);

        Session::regenerate();
        Session::set('user_id', (int) $user['id']);
        Session::set('login_at', time());
        Session::set('last_activity', time());
        Session::set('ua_hash', hash('sha256', $request->userAgent()));
        $this->loaded = false;

        $this->audit->log('login', 'user', (int) $user['id'], 'Signed in');
    }

    public function logout(): void
    {
        $id = $this->id();
        if ($id !== null) {
            $this->audit->log('logout', 'user', $id, 'Signed out', null, null, $id);
        }
        Session::destroy();
        $this->user = null;
        $this->loaded = true;
    }

    /** @throws BusinessRuleException */
    public function changePassword(int $userId, string $current, string $new): void
    {
        $hash = (string) $this->db->fetchValue('SELECT password_hash FROM users WHERE id = :id', ['id' => $userId]);
        if (!password_verify($current, $hash)) {
            throw new BusinessRuleException('Your current password is incorrect.');
        }
        if (password_verify($new, $hash)) {
            throw new BusinessRuleException('The new password must be different from the current one.');
        }
        $this->db->update('users', [
            'password_hash'        => password_hash($new, PASSWORD_DEFAULT),
            'must_change_password' => 0,
            'password_changed_at'  => date('Y-m-d H:i:s'),
        ], 'id = :id', ['id' => $userId]);
        Session::regenerate();
        $this->loaded = false;
        $this->audit->log('password_changed', 'user', $userId, 'Changed own password');
    }

    private function recordAttempt(string $login, string $ipBin, bool $ok): void
    {
        $this->db->insert('login_attempts', [
            'username'   => mb_substr($login, 0, 150),
            'ip_address' => $ipBin,
            'succeeded'  => $ok ? 1 : 0,
        ]);
    }
}
