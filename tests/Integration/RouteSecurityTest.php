<?php
declare(strict_types=1);

namespace Tests\Integration;

use App\Core\App;
use App\Core\Config;
use App\Core\Route;
use PHPUnit\Framework\TestCase;

/**
 * Static guarantees about the route table, so a new route cannot silently ship without
 * authentication or a permission check.
 */
final class RouteSecurityTest extends TestCase
{
    /** The only routes allowed to skip a `can:` permission check. */
    private const NO_PERMISSION = [
        'GET /login', 'POST /login', 'POST /logout', 'GET /password/change', 'POST /password/change',
    ];

    /** @return Route[] */
    private function routes(): array
    {
        return App::instance()->router->routes();
    }

    private static function key(Route $r): string
    {
        return $r->method . ' ' . $r->path;
    }

    public function testEveryRouteIsAuthenticatedOrExplicitlyPublic(): void
    {
        foreach ($this->routes() as $r) {
            $mw = $r->middleware;
            if (in_array('guest', $mw, true)) {
                $this->assertContains(self::key($r), self::NO_PERMISSION, self::key($r) . ' is public but not whitelisted');
                continue;
            }
            $this->assertContains('auth', $mw, self::key($r) . ' must require authentication');
        }
    }

    public function testEveryAuthenticatedRouteChecksAPermissionAfterAuth(): void
    {
        foreach ($this->routes() as $r) {
            if (in_array(self::key($r), self::NO_PERMISSION, true)) {
                continue;
            }
            $auth = array_search('auth', $r->middleware, true);
            $can = null;
            foreach ($r->middleware as $i => $m) {
                if (str_starts_with($m, 'can:')) {
                    $can = $i;
                    break;
                }
            }
            $this->assertNotNull($can, self::key($r) . ' has no can: permission middleware');
            $this->assertIsInt($auth);
            $this->assertLessThan($can, $auth, self::key($r) . ' must authenticate before checking permissions');
        }
    }

    public function testRoutePermissionsExistInTheCatalogue(): void
    {
        $catalogue = array_keys((array) Config::get('permissions.permissions', []));
        $this->assertNotEmpty($catalogue);
        foreach ($this->routes() as $r) {
            foreach ($r->middleware as $m) {
                if (!str_starts_with($m, 'can:')) {
                    continue;
                }
                foreach (explode('|', substr($m, 4)) as $perm) {
                    $this->assertContains($perm, $catalogue, self::key($r) . " uses unknown permission [$perm]");
                }
            }
        }
    }

    public function testNoDuplicateRoutes(): void
    {
        $keys = array_map([self::class, 'key'], $this->routes());
        $this->assertSame([], array_values(array_unique(array_diff_assoc($keys, array_unique($keys)))));
    }

    public function testStateChangingRoutesArePostOnly(): void
    {
        foreach ($this->routes() as $r) {
            $this->assertContains($r->method, ['GET', 'POST']);
            if ($r->method === 'GET') {
                // GET handlers must not be named like actions that change state.
                $this->assertDoesNotMatchRegularExpression(
                    '/^(store|update|destroy|delete|approve|reject|cancel|post|submit|send|close|toggle|confirm|dispatch|receive|unlock|decide)/',
                    $r->handler[1],
                    self::key($r) . ' looks state-changing but is reachable by GET'
                );
            }
        }
    }
}
