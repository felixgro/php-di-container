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