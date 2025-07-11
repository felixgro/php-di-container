<?php

namespace FGDI;

use Psr\Container\ContainerInterface;
use FGDI\Exceptions\NotFoundException;

class Container implements ContainerInterface
{
    private array $bindings = [];

    public function get(string $id)
    {
        if (!$this->has($id)) {
            throw new NotFoundException("No entry found for ID: $id");
        }

        $binding = $this->bindings[$id];

        return $binding($this);
    }

    public function has(string $id): bool
    {
        return array_key_exists($id, $this->bindings) && !empty($this->bindings[$id]);
    }

    public function set(string $id, callable $concrete)
    {
        $this->bindings[$id] = $concrete;
    }
}