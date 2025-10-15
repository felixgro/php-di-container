<?php

use FGDI\Container;

test('can inject dependencies into a method', function () {
    class Request {}
    class PostsController {
        public function index(Request $requestService) {
            return $requestService;
        }
    }

    $container = new Container();

    $requestService = $container->executeMethod(PostsController::class, 'index');
    expect($requestService)->toBeInstanceOf(Request::class);
});

test('can inject singleton dependencies into a method', function () {
    class RequestSingleton {}
    class PageController {
        public function index(RequestSingleton $requestService) {
            return $requestService;
        }
    }

    $container = new Container();

    $container->singleton(RequestSingleton::class);
    $requestFromContainer = $container->get(RequestSingleton::class);
    $requestFromMethod = $container->executeMethod(PageController::class, 'index');
    expect($requestFromContainer)->toBeInstanceOf(RequestSingleton::class);
    expect($requestFromMethod)->toBeInstanceOf(RequestSingleton::class);
    expect($requestFromContainer)->toBe($requestFromMethod);
});

test('can inject primitive dependencies into a method', function () {
    class AnyControllerClass {
        public function index(string $env) {
            return $env;
        }
    }

    $container = new Container();

    $returnValue = $container->executeMethod(AnyControllerClass::class, 'index', [
        'env' => 'production',
    ]);
    expect($returnValue)->toBe('production');
});

test('can inject primitive dependencies into a method mixed with resolvable dependencies.', function () {
    class RequestService2 {
        public $id = 42;
    }
    class AnyControllerClass2 {
        public RequestService2 $requestService;
        public function index(RequestService2 $requestService, string $env) {
            return [
                'env' => $env,
                'requestServiceId' => $requestService->id,
            ];
        }
    }

    $container = new Container();

    $returnValue = $container->executeMethod(AnyControllerClass2::class, 'index', [
        'env' => 'production',
    ]);
    expect($returnValue)->toBe([
        'env' => 'production',
        'requestServiceId' => 42,
    ]);
});

test('can inject primitive dependencies into a function with default values', function () {
    $container = new Container();

    $returnValue = $container->executeFunction(function (int $count = 10, string $name = 'foo') {
        return [
            'count' => $count,
            'name' => $name,
        ];
    }, [
        'name' => 'bar',
        'count' => 10,
    ]);

    expect($returnValue)->toBe([
        'count' => 10,
        'name' => 'bar',
    ]);
});