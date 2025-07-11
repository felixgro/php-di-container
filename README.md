# Modern Dependency Injection Container for PHP

A simple and modern Dependency Injection Container for PHP, compliant with PSR-11.

## Features
- **PSR-11 Compliance**: Fully compliant with the PSR-11 Container Interface.
- **Type Safety**: Strong type hints for better code quality and maintainability.
- **Automatic Resolution**: Automatically resolves dependencies based on type hints.
- **Singleton Support**: Easily register and retrieve singleton services.
- **Fully Tested**: Using [PestPHP](https://pestphp.com/).

## Getting Started

```php
use FGDI\Container;

// Create a container instance. This should be done once in your application.
$container = new Container();

// Register a value of any type directly in the container
$container->set('foo', 'bar');
$value = $container->get('foo'); // returns 'bar'

$container->set('baz', 42);
$value = $container->get('baz'); // returns 42

$container->set('nestedStuff', [
    'complex' => ['nested' => 'stuff'],
]);
$value = $container->get('nestedStuff'); // you guess it!

// Register classes automatically and resolve all nested dependencies needed
$container->set(AuthService::class);
$authService = $container->get(AuthService::class);

// If you want more control over the instantiation, you can pass a factory callback which returns an instanciated service
$container->set(CustomService::class, function (Container $container) {
    return new CustomService($container->get(NestedService::class));
});

// Conveniently register a singleton service
$container->singleton(DatabaseConnection::class);
$db1 = $container->get(DatabaseConnection::class);
$db2 = $container->get(DatabaseConnection::class);
// $db1 and $db2 are the exact same instance

// Check if a service is registered in the container.
$hasDC = $container->has(DatabaseConnection::class);
// This even returns true if the service is not yet registered but instantiable by the container
```

## Development

### Requirements
- PHP 8.0 or higher
- Composer

### Installation
Run the following command to install the dependencies:
```bash
composer install
```

### Running Tests
Run the following command to execute the tests:
```bash
composer test
```
