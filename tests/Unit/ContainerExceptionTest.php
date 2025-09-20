<?php

use FGDI\Container;
use FGDI\Exceptions\AliasException;
use FGDI\Exceptions\ContainerException;
use FGDI\Exceptions\NotFoundException;
use FGDI\Exceptions\NotInstantiableException;
use FGDI\Exceptions\ParameterResolutionException;
use FGDI\Exceptions\ResolutionException;
use FGDI\Exceptions\CircularDependencyException;

interface IExample {}
abstract class AbstractExample {}
final class Simple {}

final class NeedsScalarNoDefault
{
    public function __construct(int $port) {}
}

final class NeedsUnion
{
    public function __construct(Simple|AbstractExample $x) {}
}

final class NeedsUntypedNoDefault
{
    /** @psalm-suppress MissingParamType */
    public function __construct($something) {}
}

final class BoomCtor
{
    public function __construct()
    {
        throw new \RuntimeException('boom');
    }
}

final class A
{
    public function __construct(public B $b) {}
}
final class B
{
    public function __construct(public A $a) {}
}

beforeEach(function () {
    $this->c = new Container();
});

it('throws NotFoundException when id is unknown and not a class', function () {
    $this->c->get('__definitely_not_registered__');
})->throws(NotFoundException::class);

it('throws BindingException when a factory throws, preserving previous', function () {
    $this->c->set('boom', fn() => throw new \RuntimeException('kaboom'));
    $this->c->set(Simple::class, fn(\Psr\Container\ContainerInterface $c) => $c->get('boom'));

    try {
        $this->c->get(Simple::class);
        expect()->fail('Expected BindingException');
    } catch (\FGDI\Exceptions\BindingException $e) {
        // Top-level BindingException (for Simple::class)
        expect($e->getMessage())->toContain("Factory for 'Simple' threw");

        // Its previous is the BindingException for 'boom'
        $prev = $e->getPrevious();
        expect($prev)->toBeInstanceOf(\FGDI\Exceptions\BindingException::class)
            ->and($prev->getMessage())->toContain("Factory for 'boom' threw");

        // And the original cause is the RuntimeException('kaboom')
        $root = $prev->getPrevious();
        expect($root)->toBeInstanceOf(\RuntimeException::class)
            ->and($root->getMessage())->toBe('kaboom');
    }
});

it('throws ResolutionException when a constructor throws (with previous cause)', function () {
    $this->c->get(BoomCtor::class);
})->throws(ResolutionException::class);

it('throws CircularDependencyException on circular autowiring (A -> B -> A)', function () {
    $this->c->get(A::class);
})->throws(CircularDependencyException::class);

it('throws AliasException for alias cycles', function () {
    $this->c->setAlias('a', 'b');
    $this->c->setAlias('b', 'a');
    $this->c->get('a');
})->throws(AliasException::class);

it('throws AliasException for self-aliasing', function () {
    $this->c->setAlias('self', 'self');
})->throws(AliasException::class);

// Use built-ins to avoid autoload / ordering issues
it('throws NotInstantiableException for interfaces', function () {
    $this->c->get(\Iterator::class);
})->throws(NotInstantiableException::class);

it('throws NotInstantiableException for abstract classes', function () {
    $this->c->get(\FilterIterator::class);
})->throws(NotInstantiableException::class);

it('throws ParameterResolutionException when scalar param has no binding and no default', function () {
    $this->c->get(NeedsScalarNoDefault::class);
})->throws(ParameterResolutionException::class);

it('throws ParameterResolutionException for union constructor types (unsupported)', function () {
    $this->c->get(NeedsUnion::class);
})->throws(ParameterResolutionException::class);

it('throws ParameterResolutionException for untyped param with no default', function () {
    $this->c->get(NeedsUntypedNoDefault::class);
})->throws(ParameterResolutionException::class);

it('throws ContainerException from executeMethod when class does not exist', function () {
    $this->c->executeMethod('__Nope__', 'any');
})->throws(ContainerException::class);

it('throws ContainerException from executeMethod when method does not exist', function () {
    $this->c->executeMethod(Simple::class, 'nope');
})->throws(ContainerException::class);
