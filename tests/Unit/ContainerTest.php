<?php

use FGDI\Container;
use FGDI\Exceptions\NotFoundException;

test('can set and get a binding', function () {
    $container = new Container();

    $container->set('foo', fn() => 'bar');
    expect($container->get('foo'))->toBe('bar');
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

test('get throws NotFoundException for missing binding', function () {
    $container = new Container();
    $container->get('missing');
})->throws(NotFoundException::class);