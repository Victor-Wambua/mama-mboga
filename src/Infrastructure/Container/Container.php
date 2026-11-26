<?php

declare(strict_types=1);

namespace MamaMboga\Infrastructure\Container;

/**
 * A tiny dependency-injection container. Concrete classes are autowired from
 * their constructor's type hints; interfaces and scalars need a binding.
 * Everything is shared (built once) unless made with make().
 */
final class Container
{
    /** @var array<string, \Closure(self): object> */
    private array $factories = [];
    /** @var array<string, object> */
    private array $instances = [];
    /** @var array<string, true> */
    private array $resolving = [];

    /** @param \Closure(self): object|class-string $concrete */
    public function bind(string $id, \Closure|string $concrete): void
    {
        unset($this->instances[$id]);
        $this->factories[$id] = is_string($concrete) ? static fn (self $c): object => $c->make($concrete) : $concrete;
    }

    public function instance(string $id, object $object): void
    {
        $this->instances[$id] = $object;
    }

    public function has(string $id): bool
    {
        return isset($this->instances[$id]) || isset($this->factories[$id]) || class_exists($id);
    }

    /**
     * @template T of object
     * @param class-string<T> $id
     * @return T
     */
    public function get(string $id): object
    {
        if (isset($this->instances[$id])) {
            /** @var T */
            return $this->instances[$id];
        }
        if (isset($this->resolving[$id])) {
            throw new ContainerException("Circular dependency while resolving {$id}.");
        }

        $this->resolving[$id] = true;
        try {
            $object = isset($this->factories[$id]) ? $this->factories[$id]($this) : $this->make($id);
        } finally {
            unset($this->resolving[$id]);
        }

        $this->instances[$id] = $object;

        /** @var T */
        return $object;
    }

    /**
     * Build a fresh instance, autowiring class-typed parameters. Scalar
     * parameters are taken in order from $args, then from their defaults.
     *
     * @template T of object
     * @param class-string<T>   $class
     * @param list<scalar|null> $args
     * @return T
     */
    public function make(string $class, array $args = []): object
    {
        if (interface_exists($class)) {
            throw new ContainerException("{$class} is an interface; bind it to a concrete class.");
        }
        if (!class_exists($class)) {
            throw new ContainerException("Cannot resolve {$class}: no binding and no such class.");
        }
        $reflection = new \ReflectionClass($class);
        if (!$reflection->isInstantiable()) {
            throw new ContainerException("{$class} is not instantiable; bind it to a concrete class.");
        }

        $constructor = $reflection->getConstructor();
        if ($constructor === null) {
            return new $class();
        }

        $values = [];
        $scalars = $args;
        foreach ($constructor->getParameters() as $parameter) {
            $values[] = $this->resolveParameter($class, $parameter, $scalars);
        }

        return $reflection->newInstanceArgs($values);
    }

    /** @param list<scalar|null> $scalars */
    private function resolveParameter(string $class, \ReflectionParameter $parameter, array &$scalars): mixed
    {
        $type = $parameter->getType();
        if ($type instanceof \ReflectionNamedType && !$type->isBuiltin()) {
            /** @var class-string $dependency */
            $dependency = $type->getName();
            if ($this->has($dependency)) {
                return $this->get($dependency);
            }
        } elseif ($scalars !== []) {
            return array_shift($scalars);
        }

        if ($parameter->isDefaultValueAvailable()) {
            return $parameter->getDefaultValue();
        }

        throw new ContainerException("Cannot resolve parameter \${$parameter->getName()} of {$class}.");
    }
}
