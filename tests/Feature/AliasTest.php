<?php

use FGDI\Container;

test('can register an alias for a existing binding', function () {
    class ConfigService {}

    $container = new Container();
    $container->singleton(ConfigService::class);
    $container->setAlias('config', ConfigService::class);

    $configService = $container->get('config');
    $configServiceFromContainer = $container->get(ConfigService::class);
    expect($configService)->toBeInstanceOf(ConfigService::class);
    expect($configService)->toBe($configServiceFromContainer);
});