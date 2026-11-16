<?php
declare(strict_types=1);

namespace App\Middleware;

use App\Core\Request;
use App\Core\Response;
use App\Services\Support\AuthService;

/** Guest-only pages (login): signed-in users go to the dashboard. */
final class RedirectIfAuthenticated implements Middleware
{
    public function __construct(private AuthService $auth)
    {
    }

    public function handle(Request $request, callable $next, string ...$params): Response
    {
        if ($this->auth->check()) {
            return Response::redirect(url('/'));
        }
        return $next($request);
    }
}
