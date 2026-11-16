<?php
declare(strict_types=1);

namespace App\Core;

use App\Exceptions\HttpException;
use RuntimeException;

/**
 * Simple router: method + path patterns, route groups, per-route middleware
 * and named routes.
 *
 *   $router->get('/admin/users/{id}/edit', [UserController::class, 'edit'])
 *          ->name('users.edit')->middleware('can:users.manage');
 *
 * Parameters named `id` or ending in `Id` only match digits and are passed to
 * the controller as integers; other parameters match one path segment.
 */
final class Router
{
    /** @var Route[] */
    private array $routes = [];

    /** @var array<string, Route> */
    private array $named = [];

    private array $groupStack = [];

    /** @var array<string, class-string> middleware alias => class */
    private array $aliases = [];

    /** @var string[] middleware applied to every route */
    private array $global = [];

    public function __construct(private Container $container)
    {
    }

    public function aliasMiddleware(string $alias, string $class): void
    {
        $this->aliases[$alias] = $class;
    }

    public function globalMiddleware(array $middleware): void
    {
        $this->global = $middleware;
    }

    public function get(string $path, array $handler): Route
    {
        return $this->add('GET', $path, $handler);
    }

    public function post(string $path, array $handler): Route
    {
        return $this->add('POST', $path, $handler);
    }

    /** Group routes sharing a prefix and/or middleware. */
    public function group(array $attributes, callable $callback): void
    {
        $this->groupStack[] = $attributes;
        $callback($this);
        array_pop($this->groupStack);
    }

    private function add(string $method, string $path, array $handler): Route
    {
        $prefix = '';
        $middleware = [];
        foreach ($this->groupStack as $group) {
            $prefix .= '/' . trim($group['prefix'] ?? '', '/');
            $middleware = array_merge($middleware, (array) ($group['middleware'] ?? []));
        }
        $full = '/' . trim(preg_replace('#/+#', '/', $prefix . '/' . trim($path, '/')), '/');
        $route = new Route($method, $full, $handler, $middleware, function (Route $r) {
            $this->named[$r->name] = $r;
        });
        $this->routes[] = $route;
        return $route;
    }

    /** Build the path for a named route. */
    public function path(string $name, array $params = []): string
    {
        if (!isset($this->named[$name])) {
            throw new RuntimeException("Route [$name] is not defined.");
        }
        return preg_replace_callback('/\{(\w+)\}/', function ($m) use ($params, $name) {
            if (!array_key_exists($m[1], $params)) {
                throw new RuntimeException("Missing parameter [{$m[1]}] for route [$name].");
            }
            return rawurlencode((string) $params[$m[1]]);
        }, $this->named[$name]->path);
    }

    /** @return Route[] all registered routes (used by the route-security tests) */
    public function routes(): array
    {
        return $this->routes;
    }

    public function hasRoute(string $name): bool
    {
        return isset($this->named[$name]);
    }

    public function dispatch(Request $request): Response
    {
        $allowed = [];
        foreach ($this->routes as $route) {
            $params = $route->match($request->path());
            if ($params === null) {
                continue;
            }
            if ($route->method !== $request->method()) {
                $allowed[] = $route->method;
                continue;
            }
            $request->setParams($params);
            $request->attributes['route'] = $route;
            return $this->runPipeline($request, $route, $params);
        }

        if ($allowed) {
            // Still run global middleware (session etc.) is not needed for an error response.
            throw new HttpException(405, 'Method not allowed.');
        }
        throw new HttpException(404, 'The page you requested was not found.');
    }

    private function runPipeline(Request $request, Route $route, array $params): Response
    {
        $stack = array_merge($this->global, $route->middleware);

        $core = function (Request $request) use ($route, $params): Response {
            [$class, $method] = $route->handler;
            $controller = $this->container->get($class);
            $result = $controller->$method($request, ...array_values($params));
            return $result instanceof Response ? $result : new Response((string) $result);
        };

        $pipeline = array_reduce(
            array_reverse($stack),
            function (callable $next, string $definition) {
                return function (Request $request) use ($next, $definition): Response {
                    [$alias, $args] = array_pad(explode(':', $definition, 2), 2, '');
                    $class = $this->aliases[$alias] ?? $alias;
                    $middleware = $this->container->get($class);
                    $params = $args === '' ? [] : explode(',', $args);
                    return $middleware->handle($request, $next, ...$params);
                };
            },
            $core
        );

        return $pipeline($request);
    }
}
