<?php

uses(\Cms\Core\Tests\TestCase::class);

use Cms\Core\Services\BlueprintDiscoveryService;

test('blueprint discovery service resolves categories', function () {
    $service = new BlueprintDiscoveryService();
    $method = new \ReflectionMethod($service, 'resolveCategory');
    $method->setAccessible(true);

    expect($method->invoke($service, 'App\\Services\\Example'))->toBe('service');
    expect($method->invoke($service, 'App\\Jobs\\ExampleJob'))->toBe('job');
    expect($method->invoke($service, 'App\\Console\\Commands\\ExampleCommand'))->toBe('command');
    expect($method->invoke($service, 'App\\Events\\ExampleEvent'))->toBe('event');
    expect($method->invoke($service, 'App\\Foo\\Bar'))->toBe('unknown');
});
