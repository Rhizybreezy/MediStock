<?php
declare(strict_types=1);

namespace App\Core;

use App\Middleware\Authenticate;
use App\Middleware\RedirectIfAuthenticated;
use App\Middleware\RequirePermission;
use App\Middleware\SecureHeaders;
use App\Middleware\StartSession;
use App\Middleware\VerifyCsrfToken;
use Dotenv\Dotenv;
use Throwable;

/**
 * Application bootstrap: loads .env + config, sets up error handling,
 * the container and the router, then handles the HTTP request.
 */
final class App
{
    private static ?self $instance = null;

    public Container $container;
    public Router $router;
    public ?Request $request = null;

    private function __construct(public readonly string $basePath)
    {
    }

    public static function boot(string $basePath): self
    {
        if (!defined('BASE_PATH')) {
            define('BASE_PATH', $basePath);
        }
        $app = self::$instance = new self($basePath);

        if (is_file($basePath . '/.env')) {
            Dotenv::createImmutable($basePath)->safeLoad();
        }
        Config::load($basePath . '/config');
        date_default_timezone_set((string) Config::get('app.timezone', 'UTC'));

        error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);
        ini_set('display_errors', Config::get('app.debug') && PHP_SAPI === 'cli' ? '1' : '0');
        ini_set('log_errors', '1');
        ini_set('serialize_precision', '-1'); // shortest exact float representation in JSON
        // Convert PHP warnings/notices into exceptions so they are never silently ignored.
        set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
            if (!(error_reporting() & $severity)) {
                return false;
            }
            throw new \ErrorException($message, 0, $severity, $file, $line);
        });

        $app->container = new Container();
        $app->container->set(Container::class, $app->container);
        $app->container->set(Database::class, new Database());
        $app->router = new Router($app->container);
        $app->container->set(Router::class, $app->router);

        $app->router->globalMiddleware([SecureHeaders::class, StartSession::class, VerifyCsrfToken::class]);
        $app->router->aliasMiddleware('auth', Authenticate::class);
        $app->router->aliasMiddleware('guest', RedirectIfAuthenticated::class);
        $app->router->aliasMiddleware('can', RequirePermission::class);

        $router = $app->router;
        require $basePath . '/routes/web.php';
        require $basePath . '/routes/api.php';

        return $app;
    }

    public static function instance(): self
    {
        if (self::$instance === null) {
            throw new \RuntimeException('Application has not been booted.');
        }
        return self::$instance;
    }

    public function handle(Request $request): Response
    {
        $this->request = $request;
        $this->container->set(Request::class, $request);
        try {
            return $this->router->dispatch($request);
        } catch (Throwable $e) {
            return $this->container->get(ExceptionHandler::class)->render($e, $request);
        }
    }

    public function run(): void
    {
        $response = $this->handle(Request::capture());
        if (!$response->hasHeader('X-Content-Type-Options')) {
            SecureHeaders::apply($response, $this->request);
        }
        $response->send();
    }
}
