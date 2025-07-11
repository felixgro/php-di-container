<?php

use FGDI\Container;

test('can set and get a binding', function () {
    $container = new Container();
    $container->set('foo', fn() => 'bar');
    expect($container->get('foo'))->toBe('bar');
});

test('can set and get a mixed value', function () {
    $container = new Container();
    $container->set('baz', 42);
    expect($container->get('baz'))->toBe(42);
});

test('has returns true for existing binding', function () {
    $container = new Container();
    $container->set('foo', fn() => 'bar');
    expect($container->has('foo'))->toBeTrue();
});

test('has returns false for missing binding', function () {
    $container = new Container();
    expect($container->has('missing'))->toBeFalse();
});