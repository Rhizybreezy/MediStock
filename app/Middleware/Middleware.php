<?php
declare(strict_types=1);

namespace App\Middleware;

use App\Core\Request;
use App\Core\Response;

/**
 * Middleware contract: inspect/modify the request, call $next to continue,
 * or return a Response early (redirect, 403 ...).
 */
interface Middleware
{
    public function handle(Request $request, callable $next, string ...$params): Response;
}
