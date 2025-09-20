<?php

use FGDI\Container;
use \FGDI\Exceptions\ContainerException;

test('can autoresolve a class with one dependency', function () {
    class EmailService {}
    class NotificationService {
        public function __construct(EmailService $emailService) {}
    }

    $container = new Container();

    $container->set(NotificationService::class, fn($c) => new NotificationService($c->get(EmailService::class)));

    $notificationService = $container->get(NotificationService::class);

    expect($notificationService)->toBeInstanceOf(NotificationService::class);
});

test('can autoresolve a class with multiple dependencies', function () {
    class Logger {}
    class Database {}
    class UserService
    {
        public function __construct(Logger $logger, Database $database) {}
    }
    $container = new Container();
    $container->set(UserService::class, fn($c) => new UserService($c->get(Logger::class), $c->get(Database::class)));
    $userService = $container->get(UserService::class);
    expect($userService)->toBeInstanceOf(UserService::class);
});

test('can autoresolve a class with nested dependencies', function () {
    class Cache {}
    class Config {}
    class SettingsService
    {
        public function __construct(Cache $cache, Config $config) {}
    }
    class AppService
    {
        public function __construct(SettingsService $settingsService) {}
    }

    $container = new Container();
    $container->set(AppService::class);

    $appService = $container->get(AppService::class);
    expect($appService)->toBeInstanceOf(AppService::class);
});

test('can autoresolve a class with built-in types', function () {
    class AuthService
    {
        public function __construct(public string $authType) {}

        public function test(): string
        {
            return $this->authType;
        }
    }

    $container = new Container();
    $container->set('authType', fn() => 'JWT');
    $auth = $container->get(AuthService::class);
    expect($auth)->toBeInstanceOf(AuthService::class);
});