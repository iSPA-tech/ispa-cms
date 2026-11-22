<?php

use Cms\Core\Agents\Tools\AgentToolRegistry;
use Cms\Core\Tests\Fixtures\Agents\SampleToolService;
use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Container\Container;

test('agent tool registry discovers attribute and suffix based tools', function () {
    $registry = new AgentToolRegistry(
        new Container(),
        new ConfigRepository([
            'core.agentic.discovery.allowlisted_namespaces' => [
                'Cms\\Core\\Tests\\Fixtures\\Agents\\',
            ],
            'core.agentic.discovery.method_suffix' => '_tool',
            'core.agentic.discovery.require_attribute' => false,
            'core.agentic.discovery.default_environment' => 'testing',
        ]),
    );

    $discovered = $registry->discoverService(SampleToolService::class, moduleScope: 'content', environment: 'testing');

    expect($discovered)->toContain('content.read', 'content.search');

    $definition = $registry->definition('content.read');
    expect($definition)->not->toBeNull()
        ->and($definition->contractSteward)->toBe('content')
        ->and($definition->description)->toBe('Read one content entity by id.');

    $fallbackDefinition = $registry->definition('content.search');
    expect($fallbackDefinition)->not->toBeNull()
        ->and($fallbackDefinition->toolFamily)->toBe('content');

    $binding = $registry->bindingsFor('content.read')[0] ?? null;
    expect($binding)->not->toBeNull()
        ->and($binding->bindingTarget)->toBe(SampleToolService::class . '@read_tool')
        ->and($binding->environment)->toBe('testing');
});

/*
 * binding selection identity is toolName + moduleScope + environment + tenantId + policyScope +
 * bindingType. An identical binding registers once; a binding with the same identity but any other field changed
 * throws before the registry changes.
 */
function registryForDuplicates(): AgentToolRegistry
{
    return new AgentToolRegistry(
        new Container(),
        new ConfigRepository([
            'core.agentic.discovery.allowlisted_namespaces' => ['Cms\\Core\\Tests\\Fixtures\\Agents\\'],
            'core.agentic.discovery.method_suffix' => '_tool',
            'core.agentic.discovery.require_attribute' => false,
            'core.agentic.discovery.default_environment' => 'testing',
        ]),
    );
}

function duplicateBinding(array $overrides = []): \Cms\Core\Agents\Support\AgentToolBindingData
{
    return new \Cms\Core\Agents\Support\AgentToolBindingData(...array_replace([
        'toolName' => 'content.read',
        'moduleScope' => 'content',
        'environment' => 'testing',
        'bindingType' => 'service_method',
        'bindingTarget' => SampleToolService::class . '@read_tool',
    ], $overrides));
}

test('an identical binding registers only once, also through repeated discovery', function () {
    $registry = registryForDuplicates();

    $registry->registerBinding(duplicateBinding());
    $registry->registerBinding(duplicateBinding());
    $registry->discoverService(SampleToolService::class, moduleScope: 'content', environment: 'testing');
    $registry->discoverService(SampleToolService::class, moduleScope: 'content', environment: 'testing');

    expect($registry->bindingsFor('content.read'))->toHaveCount(1)
        ->and($registry->bindingsFor('content.search'))->toHaveCount(1);
});

test('a binding with another selection identity is kept beside the first', function () {
    $registry = registryForDuplicates();

    $registry->registerBinding(duplicateBinding());
    $registry->registerBinding(duplicateBinding(['environment' => 'production']));
    $registry->registerBinding(duplicateBinding(['tenantId' => 'tenant-a']));
    $registry->registerBinding(duplicateBinding(['policyScope' => 'strict']));

    expect($registry->bindingsFor('content.read'))->toHaveCount(4);
});

test('a conflicting binding for the same selection identity throws and leaves the registry unchanged', function (array $change) {
    $registry = registryForDuplicates();
    $original = duplicateBinding();
    $registry->registerBinding($original);

    expect(fn () => $registry->registerBinding(duplicateBinding($change)))->toThrow(LogicException::class)
        ->and($registry->bindingsFor('content.read'))->toBe([$original]);
})->with([
    'other target' => [['bindingTarget' => SampleToolService::class . '@search_tool']],
    'other rollout scope' => [['rolloutScope' => ['pilot']]],
    'other config' => [['bindingConfig' => ['x' => 1]]],
    'disabled' => [['enabled' => false]],
    'other priority' => [['priority' => 5]],
]);
