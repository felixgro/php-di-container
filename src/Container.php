<?php

namespace FGDI;

use ReflectionClass;
use Psr\Container\ContainerInterface;
use FGDI\Exceptions\AliasException;
use FGDI\Exceptions\BindingException;
use FGDI\Exceptions\ContainerException;
use FGDI\Exceptions\NotFoundException;
use FGDI\Exceptions\NotInstantiableException;
use FGDI\Exceptions\ParameterResolutionException;
use FGDI\Exceptions\ResolutionException;

/**
 * Lightweight DI Container with rich errors and generic-aware docblocks (Psalm/PHPStan).
 */
final class Container implements ContainerInterface
{
    /**
     * Map of canonical id => factory closure.
     *
     * @var array<string, callable(self):mixed>
     * @phpstan-var array<string, callable(self):mixed>
     */
    private array $bindings = [];

    /**
     * Map of canonical id => singleton instance.
     *
     * @var array<string, mixed>
     */
    private array $singletons = [];

    /**
     * Map of alias => target id (can be class-string or another alias).
     *
     * @var array<string,string>
     */
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

        try {
            if ($this->hasBinding($id)) {
                $factory = $this->bindings[$id];
                /** @var T */
                return $factory($this);
            }

            if (\class_exists($id) || \interface_exists($id)) {
                /** @var T */
                return $this->resolve($id, new ResolutionContext()); // let resolve() push/pop
            }
        } catch (ContainerException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new ResolutionException("Failed to resolve '{$id}'.", 0, $e);
        }

        throw new NotFoundException("No binding found for '{$id}' and class is not instantiable.");
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

        return \array_key_exists($id, $this->bindings)
            || ((\class_exists($id) || \interface_exists($id))
                && (new \ReflectionClass($id))->isInstantiable());
    }

    /**
     * Check if a binding exists for the given id.
     * Strictly checks only the bindings, not instantiable classes.
     *
     * @param class-string|non-empty-string $id
     */
    public function hasBinding(string $id): bool
    {
        return \array_key_exists($this->canonicalId($id), $this->bindings);
    }

    /**
     * Register a binding (factory or raw value).
     *
     * @template T
     * @param class-string<T>|string $id
     * @param (callable(ContainerInterface):T)|T|null $factory
     * @return void
     */
    public function set(string $id, mixed $factory = null): void
    {
        $id = $this->canonicalId($id);
        $this->bindings[$id] = $this->generateFactory($id, $factory);
        unset($this->singletons[$id]); // reset stale singleton
    }

    /**
     * Register a singleton binding.
     *
     * @template T
     * @param class-string<T>|string $id
     * @param (callable(ContainerInterface):T)|T|null $factory
     * @return void
     */
    public function singleton(string $id, mixed $factory = null): void
    {
        $id = $this->canonicalId($id);
        $factory = $this->generateFactory($id, $factory);

        /**
         * @return T
         * @phpstan-return T
         */
        $this->bindings[$id] = function (self $container) use ($id, $factory) {
            if (!\array_key_exists($id, $this->singletons)) {
                /** @var T $instance */
                $instance = $factory($container);
                $this->singletons[$id] = $instance;
            }
            /** @var T */
            return $this->singletons[$id];
        };
    }

    /**
     * Create an alias. Target may be bound later.
     *
     * @param non-empty-string $alias
     * @param class-string|non-empty-string $id
     * @return void
     */
    public function setAlias(string $alias, string $id): void
    {
        if ($alias === $id) {
            throw new AliasException("Alias cannot reference itself: '{$alias}'");
        }
        $this->aliases[$alias] = $id;
    }

    /**
     * Execute a method with auto-resolved parameters.
     * You may pass primitive parameters as an associative array.
     * These parameters are matched by name and not registered as bindings.
     *
     * @template T of object
     * @param class-string<T>|T $target class concrete or existing instance
     * @param non-empty-string $method
     * @param array<string,mixed> $params
     * @return mixed
     */
    public function executeMethod(string|object $target, string $method, array $additionalParams = []): mixed
    {
        $instance = null;
        if (is_object($target)) {
            // Use the provided instance
            $instance = $target;
            $class = $target::class;
            $refl = new \ReflectionObject($instance);
        } else {
            // Original behavior: accept a class name
            $class = $target;

            if (!\class_exists($class)) {
                throw new ContainerException("Class {$class} does not exist.");
            }

            $refl = new \ReflectionClass($class);
        }

        if (!$refl->hasMethod($method)) {
            throw new ContainerException("Method {$method} does not exist in class {$class}.");
        }

        $methodRefl = $refl->getMethod($method);
        $params = $methodRefl->getParameters();
        $deps = [];

        foreach ($params as $p) {
            $paramName = $p->getName();

            if (array_key_exists($paramName, $additionalParams)) {
                $deps[] = $additionalParams[$paramName];
                continue;
            }

            $type = $p->getType();
            if ($type === null) {
                if ($p->isDefaultValueAvailable()) {
                    $deps[] = $p->getDefaultValue();
                    continue;
                }
                throw new ParameterResolutionException("Parameter \${$paramName} in {$class}::{$method}() has no type and no default.");
            }

            if ($type instanceof \ReflectionUnionType || $type instanceof \ReflectionIntersectionType) {
                throw new ParameterResolutionException(
                    "Parameter \${$paramName} in {$class}::{$method}() uses unsupported type '{$type}'."
                );
            }

            /** @var \ReflectionNamedType $type */
            $name = $type->getName();
            $name = $type->isBuiltin() ? $paramName : $this->canonicalId($name);
            $deps[] = $this->get($name);
        }

        // If an instance was provided, use it.
        if ($instance !== null) {
            return $methodRefl->invokeArgs($instance, $deps);
        }

        // Handle static methods on class strings without instantiation.
        if ($methodRefl->isStatic()) {
            return $methodRefl->invokeArgs(null, $deps);
        }

        // newInstanceWithoutConstructor + invokeArgs for methods that don't rely on ctor (your original behavior)
        return $methodRefl->invokeArgs($refl->newInstanceWithoutConstructor(), $deps);
    }

    /**
     * Execute a function with auto-resolved parameters.
     * You may pass primitive parameters as an associative array.
     * These parameters are matched by name and not registered as bindings.
     *
     * @param callable $function
     * @param array<string,mixed> $additionalParams
     * @return mixed
     */
    public function executeFunction(callable $function, array $additionalParams = []): mixed
    {
        $refl = new \ReflectionFunction($function);
        $params = $refl->getParameters();
        $deps = [];

        foreach ($params as $p) {
            $paramName = $p->getName();

            if (array_key_exists($paramName, $additionalParams)) {
                $deps[] = $additionalParams[$paramName];
                continue;
            }

            $type = $p->getType();
            if ($type === null) {
                if ($p->isDefaultValueAvailable()) {
                    $deps[] = $p->getDefaultValue();
                    continue;
                }
                throw new ParameterResolutionException("Parameter \${$paramName} in function has no type and no default.");
            }

            if ($type instanceof \ReflectionUnionType || $type instanceof \ReflectionIntersectionType) {
                throw new ParameterResolutionException(
                    "Parameter \${$paramName} in function uses unsupported type '{$type}'."
                );
            }

            /** @var \ReflectionNamedType $type */
            $name = $type->getName();
            $name = $type->isBuiltin() ? $paramName : $this->canonicalId($name);
            $deps[] = $this->get($name);
        }

        return $refl->invokeArgs($deps);
    }

    /**
     * Normalize any $factory to a closure and preserve cause if it throws.
     *
     * @template T
     * @param class-string<T>|string $id
     * @param (callable(ContainerInterface):T)|T|null $factory
     * @return callable(self):T
     * @phpstan-return callable(self):T
     */
    private function generateFactory(string $id, mixed $factory): callable
    {
        if (\is_callable($factory)) {
            /**
             * @return T
             * @phpstan-return T
             */
            return function (self $c) use ($id, $factory) {
                try {
                    /** @var callable(ContainerInterface):T $factory */
                    return $factory($c);
                } catch (\Throwable $e) {
                    throw new BindingException("Factory for '{$id}' threw: {$e->getMessage()}", 0, $e);
                }
            };
        }

        if ($factory === null) {
            if (!\class_exists($id)) {
                throw new BindingException("Cannot infer factory for '{$id}': class does not exist and no factory provided.");
            }
            /**
             * @return T
             * @phpstan-return T
             */
            return fn(self $c) => $c->resolve($id, new ResolutionContext());
        }

        // Accept any raw value (including falsy); T will be that value's type at usage site.
        /**
         * @return T
         * @phpstan-return T
         */
        return fn() => $factory;
    }

    /**
     * Resolve alias chain to canonical id; detect cycles.
     *
     * @param class-string|non-empty-string $id
     * @return class-string|non-empty-string
     */
    private function canonicalId(string $id): string
    {
        $seen = [];
        while (isset($this->aliases[$id])) {
            if (isset($seen[$id])) {
                $chain = \implode(' -> ', \array_keys($seen)) . " -> {$id}";
                throw new AliasException("Alias cycle detected: {$chain}");
            }
            $seen[$id] = true;
            $id = $this->aliases[$id];
        }
        return $id;
    }

    /**
     * Instantiate a class, resolving constructor dependencies with rich errors.
     *
     * @template T of object
     * @param class-string<T> $id
     * @param ResolutionContext|null $ctx
     * @return T
     * @phpstan-return T
     */
    private function resolve(string $id, ?ResolutionContext $ctx = null): mixed
    {
        $ctx ??= new ResolutionContext();
        $ctx->push($id);

        try {
            $refl = new ReflectionClass($id);

            if (!$refl->isInstantiable()) {
                $kind = $refl->isInterface() ? 'interface' : ($refl->isAbstract() ? 'abstract class' : 'class');
                throw new NotInstantiableException("'{$id}' is a {$kind} and cannot be instantiated. Stack: {$ctx->breadcrumb()}");
            }

            $ctor = $refl->getConstructor();
            if ($ctor === null) {
                /** @var T */
                return new $id();
            }

            $args = [];
            foreach ($ctor->getParameters() as $p) {
                $type = $p->getType();

                if ($type === null) {
                    if ($p->isDefaultValueAvailable()) {
                        $args[] = $p->getDefaultValue();
                        continue;
                    }
                    throw new ParameterResolutionException(
                        "Parameter \${$p->getName()} in {$id}::__construct() has no type and no default. Stack: {$ctx->breadcrumb()}"
                    );
                }

                if ($type instanceof \ReflectionUnionType || $type instanceof \ReflectionIntersectionType) {
                    throw new ParameterResolutionException(
                        "Parameter \${$p->getName()} in {$id}::__construct() uses unsupported type '{$type}'. Stack: {$ctx->breadcrumb()}"
                    );
                }

                /** @var \ReflectionNamedType $type */
                $typeName = $type->getName();

                if ($type->isBuiltin()) {
                    // scalar by parameter-name binding; otherwise default, otherwise error
                    if ($this->hasBinding($p->getName())) {
                        $args[] = $this->get($p->getName());
                        continue;
                    }
                    if ($p->isDefaultValueAvailable()) {
                        $args[] = $p->getDefaultValue();
                        continue;
                    }
                    throw new ParameterResolutionException(
                        "Cannot resolve scalar \${$p->getName()} (type {$typeName}) for {$id}::__construct(): no binding for '{$p->getName()}' and no default. Stack: {$ctx->breadcrumb()}"
                    );
                }

                $target = $this->canonicalId($typeName);

                if ($this->hasBinding($target)) {
                    $args[] = $this->get($target);
                    continue;
                }

                if (\class_exists($target)) {
                    $args[] = $this->resolve($target, $ctx);
                    continue;
                }

                if ($type->allowsNull() && $p->isDefaultValueAvailable() && $p->getDefaultValue() === null) {
                    $args[] = null;
                    continue;
                }

                throw new ParameterResolutionException(
                    "Cannot resolve parameter \${$p->getName()} (type {$typeName}) for {$id}::__construct(): not bound and not instantiable. Stack: {$ctx->breadcrumb()}"
                );
            }

            try {
                /** @var T */
                return $refl->newInstanceArgs($args);
            } catch (\Throwable $e) {
                throw new ResolutionException("Constructor for '{$id}' threw: {$e->getMessage()}. Stack: {$ctx->breadcrumb()}", 0, $e);
            }
        } finally {
            $ctx->pop();
        }
    }

    /**
     * Forget a singleton instance for this id.
     *
     * @param class-string|non-empty-string $id
     * @return void
     */
    public function forget(string $id): void
    {
        $id = $this->canonicalId($id);
        unset($this->singletons[$id]);
    }

    /**
     * Clear all bindings, singletons, and aliases.
     *
     * @return void
     */
    public function clear(): void
    {
        $this->bindings = [];
        $this->singletons = [];
        $this->aliases = [];
    }

    /**
     * Dev helper: dump the exception causal chain as text.
     */
    public function debugWhatFailed(\Throwable $e): string
    {
        $lines = [\get_class($e) . ': ' . $e->getMessage()];
        $prev = $e->getPrevious();
        while ($prev) {
            $lines[] = 'Caused by ' . \get_class($prev) . ': ' . $prev->getMessage();
            $prev = $prev->getPrevious();
        }
        return \implode("\n", $lines);
    }
}
