<?php

namespace Tests\ExceptionFixtures;

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
