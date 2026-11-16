<?php
declare(strict_types=1);

namespace App\Middleware;

use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Exceptions\HttpException;

/**
 * Every state-changing request (POST/PUT/PATCH/DELETE) must carry the session's
 * CSRF token, either as the `_csrf` form field or the `X-CSRF-Token` header (fetch).
 */
final class VerifyCsrfToken implements Middleware
{
    public function handle(Request $request, callable $next, string ...$params): Response
    {
        if (!in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true)) {
            $token = $request->input('_csrf') ?? $request->header('X-CSRF-Token');
            if (!Session::validCsrf(is_string($token) ? $token : null)) {
                // 403 (not the non-standard 419, which Apache rewrites to 500)
                throw new HttpException(403, 'Your session has expired or the form is out of date. Please reload the page and try again.', [], 'Session expired');
            }
        }
        return $next($request);
    }
}
