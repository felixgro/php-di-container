<?php

namespace FGDI;

use ReflectionClass;
use Psr\Container\ContainerInterface;
use FGDI\Exceptions\ContainerException;
use FGDI\Exceptions\NotFoundException;

/**
 * A lightweight DI Container with generic-aware docblocks.
 */
class Container implements ContainerInterface
{
    /** @var array<string, callable(self):mixed> */
    private array $bindings = [];

    /** @var array<string, mixed> */
    private array $singletons = [];

    /** @var array<string,string> */
    private array $aliases = [];

    /**
     * Resolve an entry by id or class name.
     *
     * @template T of object
     * @param class-string<T>|string $id
     * @return T
     * @psalm-suppress MoreSpecificReturnType
     * @phpstan-return T
     */
    public function get(string $id)
    {
        $id = $this->canonicalId($id);

        if ($this->hasBinding($id)) {
            $binding = $this->bindings[$id];
            return $binding($this);
        }

        if (class_exists($id)) {
            return $this->resolve($id);
        }

        throw new NotFoundException("Binding for {$id} not found and not instantiable in the container.");
    }

    /**
     * Check if an entry exists by id or class name.
     * Also returns true for instantiable classes even if not bound.
     * 
     * @param class-string|non-empty-string $id
     */
    public function has(string $id): bool
    {
        $id = $this->canonicalId($id);
        return array_key_exists($id, $this->bindings)
            || (class_exists($id) && (new ReflectionClass($id))->isInstantiable());
    }

    /**
     * Check if a binding exists for the given id.
     * Strictly checks only the bindings, not instantiable classes.
     *
     * @param class-string|non-empty-string $id
     */
    public function hasBinding(string $id): bool
    {
        return array_key_exists($this->canonicalId($id), $this->bindings);
    }

    /**
     * Register a binding.
     *
     * @template T
     * @param class-string<T>|string $id
     * @param (callable(self):T)|T|null $factory
     * @return void
     */
    public function set(string $id, mixed $factory = null): void
    {
        $this->bindings[$id] = $this->generateFactory($id, $factory);
    }

    /**
     * @param non-empty-string $alias
     * @param class-string|non-empty-string $id
     */
    public function setAlias(string $alias, string $id): void
    {
        if ($alias === $id) {
            throw new ContainerException("Alias cannot reference itself: {$alias}");
        }
        // Allow aliasing even if $id isn’t bound yet (it could be a class).
        $this->aliases[$alias] = $id;
    }

    /**
     * Register a singleton binding.
     *
     * @template T
     * @param class-string<T>|string $id
     * @param (callable(self):T)|T|null $factory
     * @return void
     */
    public function singleton(string $id, mixed $factory = null): void
    {
        $factory = $this->generateFactory($id, $factory);
        $this->bindings[$id] = function (self $container) use ($id, $factory) {
            if (!array_key_exists($id, $this->singletons)) {
                /** @var T */
                $this->singletons[$id] = $factory($container);
            }
            /** @var T */
            return $this->singletons[$id];
        };
    }

    /**
     * Execute a method and have parameters auto-resolved.
     *
     * Return type is unknown to the container, so `mixed`.
     *
     * @template T of object
     * @param class-string<T> $class
     * @param non-empty-string $method
     * @return mixed
     */
    public function executeMethod(string $class, string $method): mixed
    {
        if (!class_exists($class)) {
            throw new ContainerException("Class {$class} does not exist.");
        }

        $reflection = new ReflectionClass($class);
        if (!$reflection->hasMethod($method)) {
            throw new ContainerException("Method {$method} does not exist in class {$class}.");
        }

        $methodReflection = $reflection->getMethod($method);
        $parameters = $methodReflection->getParameters();
        $dependencies = [];

        foreach ($parameters as $parameter) {
            $type = $parameter->getType();
            if ($type === null) {
                throw new ContainerException("Parameter {$parameter->getName()} in method {$method} has no type hint.");
            }
            // For simplicity, we assume a named type here; see note below for unions/intersections.
            /** @var class-string|non-empty-string $typeName */
            $typeName = $type->getName();
            $dependencies[] = $this->get($typeName);
        }

        return $methodReflection->invokeArgs(new $class(), $dependencies);
    }

    /**
     * Normalize any $factory to a closure returning T.
     *
     * @template T
     * @param class-string<T>|string $id
     * @param (callable(self):T)|T|null $factory
     * @return callable(self):T
     */
    private function generateFactory(string $id, mixed $factory): callable
    {
        if (is_callable($factory)) {
            /** @var callable(self):T $factory */
            return $factory;
        }

        if ($factory === null) {
            /** @var callable(self):T */
            return $this->generateFactoryFromClass($id);
        }

        // Wrap raw values/objects in a closure.
        $isBuiltinType = in_array(gettype($factory), [
            'boolean',
            'integer',
            'double',
            'string',
            'array',
            'object',
        ], true);

        if ($isBuiltinType /* && !empty($factory) not required for typing */) {
            return function (self $container) use ($factory) {
                /** @var T */
                return $factory;
            };
        }

        throw new ContainerException("Invalid factory provided for binding {$id}: " . gettype($factory));
    }

    /**
     * @template T of object
     * @param class-string<T> $id
     * @return callable(self):T
     */
    private function generateFactoryFromClass(string $id): callable
    {
        if (!class_exists($id)) {
            throw new ContainerException("Class {$id} does not exist.");
        }
        return function (self $container) use ($id) {
            /** @var T */
            return $container->resolve($id);
        };
    }

    /**
     * Instantiate a class, resolving its constructor dependencies.
     *
     * @template T of object
     * @param class-string<T> $id
     * @return T
     */
    private function resolve(string $id): mixed
    {
        $reflection = new ReflectionClass($id);

        if (!$reflection->isInstantiable()) {
            throw new ContainerException("Class {$id} is not instantiable.");
        }

        $constructor = $reflection->getConstructor();
        if ($constructor === null) {
            /** @var T */
            return new $id();
        }

        $parameters = $constructor->getParameters();
        $dependencies = [];

        foreach ($parameters as $parameter) {
            $name = $parameter->getName();
            $type = $parameter->getType();

            if ($type === null) {
                throw new ContainerException("Cannot resolve parameter {$name} in class {$id} because its type is not defined.");
            }

            // NOTE: This assumes a NamedType. If you plan to support union/intersection,
            // add handling here (see note below).
            /** @var class-string|non-empty-string $concrete */
            $concrete = $type->getName();

            if ($type->isBuiltin() && $this->hasBinding($name)) {
                $dependencies[] = $this->get($name);
                continue;
            }

            if ($this->hasBinding($concrete)) {
                $dependencies[] = $this->get($concrete);
                continue;
            }

            if (class_exists($concrete)) {
                $dependencies[] = $this->resolve($concrete);
                continue;
            }

            throw new ContainerException("Cannot resolve parameter {$name} in class {$id} because it is not registered in the container.");
        }

        try {
            /** @var T */
            return $reflection->newInstanceArgs($dependencies);
        } catch (\Throwable $e) {
            throw new ContainerException("Failed to create an instance of {$id}: " . $e->getMessage(), 0, $e);
        }
    }

    // Canonicalize an id via aliases with cycle detection
    private function canonicalId(string $id): string
    {
        $seen = [];
        while (isset($this->aliases[$id])) {
            if (isset($seen[$id])) {
                throw new ContainerException("Alias cycle detected at {$id}");
            }
            $seen[$id] = true;
            $id = $this->aliases[$id];
        }
        return $id;
    }
}
