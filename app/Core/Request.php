<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Wraps the incoming HTTP request. Controllers read input only through this
 * class so values are consistently trimmed and typed.
 */
final class Request
{
    private static string $basePath = '';

    /** @var array<string, mixed> route parameters */
    private array $params = [];

    /** Attributes set by middleware (e.g. the current route). */
    public array $attributes = [];

    private function __construct(
        private string $method,
        private string $path,
        private array $query,
        private array $body,
        private array $files,
        private array $server,
    ) {
    }

    public static function capture(): self
    {
        // Base path: '/MediStock' when served from htdocs/MediStock (via the root .htaccess),
        // '' when a VirtualHost points directly at /public.
        $scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/index.php'));
        $base = preg_replace('#/public$#', '', rtrim($scriptDir, '/'));
        self::$basePath = $base === '/' ? '' : (string) $base;

        $uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        $uri = rawurldecode($uri);
        if (self::$basePath !== '' && str_starts_with($uri, self::$basePath)) {
            $uri = substr($uri, strlen(self::$basePath));
        }
        if (str_starts_with($uri, '/public/')) {
            $uri = substr($uri, 7);
        }
        $path = '/' . trim($uri, '/');

        $body = $_POST;
        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
        if (str_contains($contentType, 'application/json')) {
            $raw = file_get_contents('php://input', false, null, 0, 1_048_576) ?: '';
            $decoded = json_decode($raw, true);
            $body = is_array($decoded) ? $decoded : [];
        }

        return new self(
            strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET'),
            $path,
            $_GET,
            $body,
            $_FILES,
            $_SERVER
        );
    }

    /** Build a request manually (used by tests and the console). */
    public static function create(string $method, string $path, array $query = [], array $body = [], array $server = []): self
    {
        return new self(strtoupper($method), $path, $query, $body, [], $server);
    }

    public static function basePath(): string
    {
        return self::$basePath;
    }

    public function method(): string
    {
        return $this->method;
    }

    public function path(): string
    {
        return $this->path;
    }

    public function isMethod(string $method): bool
    {
        return $this->method === strtoupper($method);
    }

    /** Body input (POST form or JSON), falling back to query string. */
    public function input(string $key, mixed $default = null): mixed
    {
        $value = $this->body[$key] ?? $this->query[$key] ?? $default;
        return is_string($value) ? trim($value) : $value;
    }

    public function all(): array
    {
        return array_map(fn ($v) => is_string($v) ? trim($v) : $v, array_merge($this->query, $this->body));
    }

    public function body(): array
    {
        return array_map(fn ($v) => is_string($v) ? trim($v) : $v, $this->body);
    }

    public function only(array $keys): array
    {
        $all = $this->all();
        $out = [];
        foreach ($keys as $key) {
            $out[$key] = $all[$key] ?? null;
        }
        return $out;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->body) || array_key_exists($key, $this->query);
    }

    public function query(string $key, mixed $default = null): mixed
    {
        $value = $this->query[$key] ?? $default;
        return is_string($value) ? trim($value) : $value;
    }

    public function queryAll(): array
    {
        return $this->query;
    }

    public function int(string $key, int $default = 0): int
    {
        $value = $this->input($key);
        return is_numeric($value) ? (int) $value : $default;
    }

    public function bool(string $key): bool
    {
        return in_array($this->input($key), [1, '1', 'on', 'true', true, 'yes'], true);
    }

    public function file(string $key): ?array
    {
        $file = $this->files[$key] ?? null;
        return (is_array($file) && ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) ? $file : null;
    }

    public function header(string $name): ?string
    {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        return $this->server[$key] ?? null;
    }

    public function server(string $key): mixed
    {
        return $this->server[$key] ?? null;
    }

    /** True for fetch()/AJAX calls and anything under /api. */
    public function wantsJson(): bool
    {
        return str_starts_with($this->path, '/api/')
            || str_contains((string) $this->header('Accept'), 'application/json')
            || $this->header('X-Requested-With') === 'XMLHttpRequest';
    }

    public function isSecure(): bool
    {
        return (!empty($this->server['HTTPS']) && $this->server['HTTPS'] !== 'off')
            || ($this->server['SERVER_PORT'] ?? null) == 443;
    }

    public function ip(): string
    {
        return (string) ($this->server['REMOTE_ADDR'] ?? '0.0.0.0');
    }

    public function userAgent(): string
    {
        return mb_substr((string) ($this->server['HTTP_USER_AGENT'] ?? ''), 0, 255);
    }

    public function setParams(array $params): void
    {
        $this->params = $params;
    }

    public function param(string $key, mixed $default = null): mixed
    {
        return $this->params[$key] ?? $default;
    }

    /** Safe "back" URL: the Referer only if it belongs to this application. */
    public function previousUrl(string $fallback = '/'): string
    {
        $referer = (string) $this->header('Referer');
        $host = (string) ($this->server['HTTP_HOST'] ?? '');
        if ($referer !== '' && $host !== '') {
            $parts = parse_url($referer);
            if (($parts['host'] ?? '') . (isset($parts['port']) ? ':' . $parts['port'] : '') === $host) {
                $path = $parts['path'] ?? '/';
                return $path . (isset($parts['query']) ? '?' . $parts['query'] : '');
            }
        }
        return url($fallback);
    }
}
