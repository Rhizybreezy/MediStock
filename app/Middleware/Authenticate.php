<?php
declare(strict_types=1);

namespace App\Middleware;

use App\Core\Config;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Services\Support\AuthService;

/**
 * Requires a signed-in, active user. Enforces idle and absolute session
 * timeouts, a user-agent binding, and the forced password change.
 */
final class Authenticate implements Middleware
{
    /** Routes reachable while a password change is pending. */
    private const PASSWORD_CHANGE_ALLOWED = ['/password/change', '/logout'];

    public function __construct(private AuthService $auth)
    {
    }

    public function handle(Request $request, callable $next, string ...$params): Response
    {
        $user = $this->auth->user();
        if ($user === null) {
            return $this->deny($request, 'Please sign in to continue.');
        }

        $cfg = (array) Config::get('security.session');
        $now = time();
        $idle = $now - (int) Session::get('last_activity', $now);
        $age = $now - (int) Session::get('login_at', $now);
        $uaChanged = !hash_equals((string) Session::get('ua_hash', ''), hash('sha256', $request->userAgent()));

        if ($idle > (int) $cfg['idle_minutes'] * 60 || $age > (int) $cfg['absolute_hours'] * 3600 || $uaChanged) {
            $this->auth->logout();
            Session::start($request->isSecure());
            return $this->deny($request, 'Your session has expired. Please sign in again.');
        }

        // Background polling (notifications) should not keep an idle session alive forever.
        if (!$request->wantsJson() || $request->header('X-Activity') === '1') {
            Session::set('last_activity', $now);
        }

        if ((int) $user['must_change_password'] === 1 && !in_array($request->path(), self::PASSWORD_CHANGE_ALLOWED, true)) {
            if ($request->wantsJson()) {
                return Response::json(['ok' => false, 'error' => ['code' => 'password_change_required', 'message' => 'You must change your password first.']], 403);
            }
            return Response::redirect(url('/password/change'));
        }

        return $next($request);
    }

    private function deny(Request $request, string $message): Response
    {
        if ($request->wantsJson()) {
            return Response::json(['ok' => false, 'error' => ['code' => 'unauthenticated', 'message' => $message]], 401);
        }
        flash('warning', $message);
        if ($request->isMethod('GET') && $request->path() !== '/') {
            Session::set('intended_url', url($request->path(), $request->queryAll()));
        }
        return Response::redirect(url('/login'));
    }
}
