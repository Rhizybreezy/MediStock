<?php
declare(strict_types=1);

namespace App\Core;

use ReflectionClass;
use ReflectionNamedType;
use RuntimeException;

/**
 * Minimal dependency-injection container.
 *
 * - `set()` registers a ready object or a factory closure.
 * - `get()` returns a shared instance; unknown classes are built automatically by
 *   reading their constructor type hints (autowiring).
 *
 * One instance per class per request is enough for this application.
 */
final class Container
{
    /** @var array<string, object> */
    private array $instances = [];

    /** @var array<string, callable> */
    private array $factories = [];

    public function set(string $id, object|callable $concrete): void
    {
        if ($concrete instanceof \Closure) {
            $this->factories[$id] = $concrete;
            unset($this->instances[$id]);
            return;
        }
        $this->instances[$id] = $concrete;
    }

    public function has(string $id): bool
    {
        return isset($this->instances[$id]) || isset($this->factories[$id]) || class_exists($id);
    }

    public function get(string $id): object
    {
        if (isset($this->instances[$id])) {
            return $this->instances[$id];
        }
        if (isset($this->factories[$id])) {
            return $this->instances[$id] = ($this->factories[$id])($this);
        }
        return $this->instances[$id] = $this->build($id);
    }

    /** Create a NEW (non-shared) instance with autowired dependencies. */
    public function make(string $class): object
    {
        return $this->build($class);
    }

    private function build(string $class): object
    {
        if (!class_exists($class)) {
            throw new RuntimeException("Cannot resolve [$class]: class not found.");
        }
        $reflection = new ReflectionClass($class);
        if (!$reflection->isInstantiable()) {
            throw new RuntimeException("Cannot instantiate [$class].");
        }
        $constructor = $reflection->getConstructor();
        if ($constructor === null) {
            return new $class();
        }
        $args = [];
        foreach ($constructor->getParameters() as $param) {
            $type = $param->getType();
            if ($type instanceof ReflectionNamedType && !$type->isBuiltin()) {
                $args[] = $this->get($type->getName());
            } elseif ($param->isDefaultValueAvailable()) {
                $args[] = $param->getDefaultValue();
            } else {
                throw new RuntimeException("Cannot autowire parameter \${$param->getName()} of [$class].");
            }
        }
        return $reflection->newInstanceArgs($args);
    }
}
