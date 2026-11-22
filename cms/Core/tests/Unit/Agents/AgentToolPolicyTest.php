<?php

use Cms\Core\Agents\Support\AgentState;
use Cms\Core\Agents\Support\AgentToolBindingData;
use Cms\Core\Agents\Support\AgentToolDefinitionData;
use Cms\Core\Agents\Tools\AgentToolRegistry;
use Cms\Core\Agents\Tools\DefaultToolPolicyResolver;
use Cms\Core\Agents\Tools\DefaultAgentToolBindingResolver;
use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Container\Container;

test('tool policy resolver enforces capability side effect ceilings', function () {
    $resolver = new DefaultToolPolicyResolver(new ConfigRepository([
        'core.agentic.capability_classes.class_0_read_only' => [
            'allowed_side_effect_classes' => ['read_only'],
            'approval_required' => false,
        ],
    ]));

    $state = new AgentState(
        runId: 1,
        workflowInstanceId: null,
        parentWorkflowStepId: null,
        stepIndex: 0,
        environment: 'testing',
        tenantId: null,
        moduleScope: 'content',
        goal: 'Read and suggest',
        availableToolDefinitions: ['content.read', 'content.draft'],
        profileCapabilityClass: 'class_0_read_only',
    );

    $allowed = $resolver->authorize($state, new AgentToolDefinitionData(
        toolName: 'content.read',
        sideEffectClass: 'read_only',
    ));

    $denied = $resolver->authorize($state, new AgentToolDefinitionData(
        toolName: 'content.draft',
        sideEffectClass: 'draft_write',
    ));

    expect($allowed->allowed)->toBeTrue()
        ->and($denied->allowed)->toBeFalse()
        ->and($denied->reason)->toBe('side_effect_not_allowed_for_capability_class');
});

test('tool policy resolver denies profiles without explicit tool grants', function () {
    $resolver = new DefaultToolPolicyResolver(new ConfigRepository([
        'core.agentic.capability_classes.class_0_read_only' => [
            'allowed_side_effect_classes' => ['read_only'],
            'approval_required' => false,
        ],
    ]));

    $state = new AgentState(
        runId: 1,
        workflowInstanceId: null,
        parentWorkflowStepId: null,
        stepIndex: 0,
        environment: 'testing',
        tenantId: null,
        moduleScope: 'content',
        goal: 'Read latest posts',
        availableToolDefinitions: [],
        profileCapabilityClass: 'class_0_read_only',
    );

    $denied = $resolver->authorize($state, new AgentToolDefinitionData(
        toolName: 'content.latest_posts',
        sideEffectClass: 'read_only',
    ));

    expect($denied->allowed)->toBeFalse()
        ->and($denied->reason)->toBe('profile_has_no_tool_grants');
});

test('binding resolver prefers more specific matching scope', function () {
    $registry = new AgentToolRegistry(
        new Container(),
        new ConfigRepository([
            'core.agentic.discovery.allowlisted_namespaces' => [],
        ]),
    );
    $resolver = new DefaultAgentToolBindingResolver($registry);

    $registry->registerBinding(new AgentToolBindingData(
        toolName: 'content.search',
        bindingTarget: 'Demo@fallback',
        priority: 0,
    ));

    $registry->registerBinding(new AgentToolBindingData(
        toolName: 'content.search',
        moduleScope: 'content',
        environment: 'testing',
        bindingTarget: 'Demo@exact',
        priority: 10,
    ));

    $binding = $resolver->resolve(
        toolName: 'content.search',
        environment: 'testing',
        tenantId: null,
        moduleScope: 'content',
        policyScope: null,
    );

    expect($binding)->not->toBeNull()
        ->and($binding->bindingTarget)->toBe('Demo@exact');
});
