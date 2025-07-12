<?php

namespace FGDI;

use ReflectionClass;
use Psr\Container\ContainerInterface;
use FGDI\Exceptions\ContainerException;
use FGDI\Exceptions\NotFoundException;

class Container implements ContainerInterface
{
    private array $bindings = [];

    private array $singletons = [];

    public function get(string $id)
    {
        if ($this->hasBinding($id)) {
            $binding = $this->bindings[$id];
            return $binding($this);
        }

        if (class_exists($id)) {
            // If the class exists, we can resolve it using reflection
            return $this->resolve($id);
        }

        throw new NotFoundException("Binding for {$id} not found and not instantiable in the container.");
    }

    public function has(string $id): bool
    {
        return array_key_exists($id, $this->bindings)
            || (class_exists($id) && (new \ReflectionClass($id))->isInstantiable());
    }

    public function hasBinding(string $id): bool
    {
        return array_key_exists($id, $this->bindings);
    }

    public function set(string $id, mixed $factory = null)
    {
        $this->bindings[$id] = $this->generateFactory($id, $factory);
    }

    public function setAlias(string $alias, string $id)
    {
        if (!$this->has($id)) {
            throw new ContainerException("Cannot set alias for {$alias} because binding for {$id} does not exist.");
        }
        $this->bindings[$alias] = $this->bindings[$id];
    }

    public function singleton(string $id, mixed $factory = null)
    {
        $factory = $this->generateFactory($id, $factory);
        $this->bindings[$id] = function ($container) use ($id, $factory) {
            if (!isset($this->singletons[$id])) {
                $this->singletons[$id] = $factory($container);
            }
            return $this->singletons[$id];
        };
    }

    public function executeMethod(string $class, string $method)
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
            if (is_null($type)) {
                throw new ContainerException("Parameter {$parameter->getName()} in method {$method} has no type hint.");
            }
            $dependencies[] = $this->get($type->getName());
        }

        return $methodReflection->invokeArgs(new $class(), $dependencies);
    }

    private function generateFactory(string $id, mixed $factory): callable
    {
        if (is_callable($factory)) {
            return $factory;
        }

        if (is_null($factory)) {
            return $this->generateFactoryFromClass($id);
        }

        // If $factory is a built-in type we can return it inside a closure
        $isBuiltinType = in_array(gettype($factory), [
            'boolean',
            'integer',
            'double',
            'string',
            'array',
            'object',
        ]);

        if ($isBuiltinType && !empty($factory)) {
            return function (Container $container) use ($factory) {
                return $factory;
            };
        }

        throw new ContainerException("Invalid factory provided for binding {$id}: " . gettype($factory));
    }

    private function generateFactoryFromClass(string $id): callable
    {
        if (!class_exists($id)) {
            throw new ContainerException("Class {$id} does not exist.");
        }
        return function (Container $container) use ($id) {
            return $container->resolve($id);
        };
    }

    private function resolve(string $id): mixed
    {
        $reflection = new ReflectionClass($id);

        if (!$reflection->isInstantiable()) {
            throw new ContainerException("Class {$id} is not instantiable.");
        }

        // If the class has no constructor, we can instantiate it directly
        $constructor = $reflection->getConstructor();
        if (is_null($constructor)) {
            return new $id();
        }

        // Ok, there is a constructor method, we need to resolve its dependencies
        $parameters = $constructor->getParameters();
        $dependencies = [];

        foreach ($parameters as $parameter) {
            $name = $parameter->getName();
            $type = $parameter->getType();
            $concrete = $type->getName();

            if (is_null($type)) {
                // If the type is not defined, we cannot resolve it
                throw new ContainerException("Cannot resolve parameter {$parameter->getName()} in class {$id} because its type is not defined.");
            }

            // If the parameter is a build-int type (like int, string, etc.) and its name is registered in the container
            if ($type->isBuiltin() && $this->hasBinding($name)) {
                $dependencies[] = $this->get($name);
                continue;
            }

            // If the parameter is a class type and is registered in the container 
            if ($this->hasBinding($concrete)) {
                $dependencies[] = $this->get($concrete);
                continue;
            }

            // If the parameter is a class type but not registered in the container, we need to resolve it recursively
            if (class_exists($concrete)) {
                $dependencies[] = $this->resolve($concrete);
                continue;
            }

            // If the type is not registered in the container, we need to resolve it using reflection
            throw new ContainerException("Cannot resolve parameter {$name} in class {$id} because it is not registered in the container.");
        }

        try {
            // Create an instance of the class with the resolved dependencies
            return $reflection->newInstanceArgs($dependencies);
        } catch (\Throwable $e) {
            throw new ContainerException("Failed to create an instance of {$id}: " . $e->getMessage(), 0, $e);
        }
    }
}
