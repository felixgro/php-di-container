<?php

use FGDI\Container;


test('can register and resolve singletons', function () {
    class AuthenticationService
    {
        public function __construct() {}
    }

    $container = new Container();
    $container->singleton(AuthenticationService::class, fn() => new AuthenticationService());
    $authService1 = $container->get(AuthenticationService::class);
    $authService2 = $container->get(AuthenticationService::class);
    expect($authService1)->toBe($authService2); // Should be the same instance
});

test('can autoresolve singletons with dependencies', function () {
    class FileLogger {}
    class LoggerService
    {
        public function __construct(private FileLogger $logger) {}
    }

    $container = new Container();
    $container->singleton(LoggerService::class);
    $logger1 = $container->get(LoggerService::class);
    $logger2 = $container->get(LoggerService::class);
    expect($logger1)->toBe($logger2); // Should be the same instance
});