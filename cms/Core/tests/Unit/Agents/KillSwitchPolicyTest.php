<?php

use Cms\Core\Agents\Support\AgentState;
use Cms\Core\Agents\Support\AgentToolDefinitionData;
use Cms\Core\Agents\Tools\DefaultToolPolicyResolver;
use Illuminate\Config\Repository as ConfigRepository;

test('kill switch denies all tool execution when active', function () {
    $resolver = new DefaultToolPolicyResolver(new ConfigRepository([
        'core.agentic.kill_switch' => true,
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
        goal: 'Read posts',
        availableToolDefinitions: ['content.read'],
        profileCapabilityClass: 'class_0_read_only',
    );

    $definition = new AgentToolDefinitionData(
        toolName: 'content.read',
        sideEffectClass: 'read_only',
    );

    $result = $resolver->authorize($state, $definition);

    expect($result->allowed)->toBeFalse();
    expect($result->reason)->toBe('kill_switch_active');
});

test('kill switch allows when not active', function () {
    $resolver = new DefaultToolPolicyResolver(new ConfigRepository([
        'core.agentic.kill_switch' => false,
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
        goal: 'Read posts',
        availableToolDefinitions: ['content.read'],
        profileCapabilityClass: 'class_0_read_only',
    );

    $definition = new AgentToolDefinitionData(
        toolName: 'content.read',
        sideEffectClass: 'read_only',
    );

    $result = $resolver->authorize($state, $definition);

    expect($result->allowed)->toBeTrue();
});

test('kill switch defaults to inactive when not configured', function () {
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
        goal: 'Read posts',
        availableToolDefinitions: ['content.read'],
        profileCapabilityClass: 'class_0_read_only',
    );

    $definition = new AgentToolDefinitionData(
        toolName: 'content.read',
        sideEffectClass: 'read_only',
    );

    $result = $resolver->authorize($state, $definition);

    expect($result->allowed)->toBeTrue();
});
