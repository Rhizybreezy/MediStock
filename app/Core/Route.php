<?php
declare(strict_types=1);

namespace App\Core;

/**
 * A single route definition.
 */
final class Route
{
    public ?string $name = null;
    private string $regex;
    private array $paramNames = [];

    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $handler,
        public array $middleware,
        private \Closure $onName,
    ) {
        $this->regex = '#^' . preg_replace_callback('/\{(\w+)\}/', function ($m) {
            $this->paramNames[] = $m[1];
            $isId = $m[1] === 'id' || str_ends_with($m[1], 'Id');
            return $isId ? '(\d+)' : '([^/]+)';
        }, $this->path) . '$#';
    }

    public function name(string $name): self
    {
        $this->name = $name;
        ($this->onName)($this);
        return $this;
    }

    public function middleware(string ...$middleware): self
    {
        $this->middleware = array_merge($this->middleware, $middleware);
        return $this;
    }

    /** @return array<string, int|string>|null */
    public function match(string $path): ?array
    {
        if (!preg_match($this->regex, $path, $matches)) {
            return null;
        }
        $params = [];
        foreach ($this->paramNames as $i => $name) {
            $value = $matches[$i + 1];
            $params[$name] = ctype_digit($value) ? (int) $value : $value;
        }
        return $params;
    }
}
