<?php

namespace FGDI;

use FGDI\Exceptions\CircularDependencyException;

/**
 * Tracks the current resolution stack for clearer errors and cycle detection.
 *
 * @internal
 */
final class ResolutionContext
{
    /**
     * @var list<class-string> Resolution stack (breadcrumbs).
     */
    public array $stack = [];

    /**
     * @param class-string $id
     */
    public function push(string $id): void
    {
        if (\in_array($id, $this->stack, true)) {
            $cycle = \implode(' -> ', \array_merge($this->stack, [$id]));
            throw new CircularDependencyException("Circular dependency detected: {$cycle}");
        }
        $this->stack[] = $id;
    }

    public function pop(): void
    {
        \array_pop($this->stack);
    }

    public function breadcrumb(): string
    {
        return $this->stack ? \implode(' -> ', $this->stack) : '(root)';
    }
}
