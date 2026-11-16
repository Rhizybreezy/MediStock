<?php
declare(strict_types=1);

namespace App\Middleware;

use App\Core\Gate;
use App\Core\Request;
use App\Core\Response;

/**
 * Route-level permission check: ->middleware('can:users.manage').
 * Several permissions separated by '|' mean "any of": can:requests.create|requests.approve
 * Department-level checks happen inside services, where the department is known.
 */
final class RequirePermission implements Middleware
{
    public function __construct(private Gate $gate)
    {
    }

    public function handle(Request $request, callable $next, string ...$params): Response
    {
        foreach ($params as $param) {
            $any = explode('|', $param);
            if (!$this->gate->canAny($any)) {
                $this->gate->authorize($any[0]); // throws AuthorizationException (logged)
            }
        }
        return $next($request);
    }
}
