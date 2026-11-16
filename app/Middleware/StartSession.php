<?php
declare(strict_types=1);

namespace App\Middleware;

use App\Core\Request;
use App\Core\Response;
use App\Core\Session;

final class StartSession implements Middleware
{
    public function handle(Request $request, callable $next, string ...$params): Response
    {
        // JSON/AJAX calls must not consume flash messages meant for the next page.
        Session::start($request->isSecure(), !$request->wantsJson());
        return $next($request);
    }
}
