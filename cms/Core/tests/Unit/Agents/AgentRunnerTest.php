<?php

use Cms\Core\Agents\Runtime\AgentRunner;
use Cms\Core\Agents\Support\AgentState;
use Cms\Core\Agents\Support\AgentDecision;
use Cms\Core\Agents\Support\BudgetEvaluation;
use Cms\Core\Agents\Support\ToolAuthorization;
use Cms\Core\Agents\Support\AgentToolDefinitionData;
use Cms\Core\Agents\Support\AgentToolBindingData;
use Cms\Core\Agents\Contracts\ReasoningEngineInterface;
use Cms\Core\Agents\Contracts\ContextAssemblerInterface;
use Cms\Core\Agents\Contracts\RunBudgetGuardInterface;
use Cms\Core\Agents\Contracts\ToolPolicyResolverInterface;
use Cms\Core\Agents\Contracts\AgentToolBindingResolverInterface;
use Cms\Core\Agents\Tools\AgentToolRegistry;

function buildTestState(array $overrides = []): AgentState
{
    return new AgentState(
        runId: $overrides['runId'] ?? 1,
        workflowInstanceId: $overrides['workflowInstanceId'] ?? null,
        parentWorkflowStepId: $overrides['parentWorkflowStepId'] ?? null,
        stepIndex: $overrides['stepIndex'] ?? 0,
        environment: $overrides['environment'] ?? 'testing',
        tenantId: $overrides['tenantId'] ?? null,
        moduleScope: $overrides['moduleScope'] ?? 'content',
        goal: $overrides['goal'] ?? 'Test goal',
        availableToolDefinitions: $overrides['availableToolDefinitions'] ?? ['content.read'],
        profileCapabilityClass: $overrides['profileCapabilityClass'] ?? 'class_0_read_only',
        budgetConfig: $overrides['budgetConfig'] ?? ['max_steps' => 10],
        budgetUsage: $overrides['budgetUsage'] ?? ['steps' => 0],
        budgetRemaining: $overrides['budgetRemaining'] ?? ['steps' => 10],
    );
}

function buildRunner(array $mocks = []): AgentRunner
{
    return new AgentRunner(
        reasoningEngine: $mocks['reasoningEngine'] ?? Mockery::mock(ReasoningEngineInterface::class),
        contextAssembler: $mocks['contextAssembler'] ?? Mockery::mock(ContextAssemblerInterface::class),
        budgetGuard: $mocks['budgetGuard'] ?? Mockery::mock(RunBudgetGuardInterface::class),
        toolPolicyResolver: $mocks['toolPolicyResolver'] ?? Mockery::mock(ToolPolicyResolverInterface::class),
        bindingResolver: $mocks['bindingResolver'] ?? Mockery::mock(AgentToolBindingResolverInterface::class),
        toolRegistry: $mocks['toolRegistry'] ?? Mockery::mock(AgentToolRegistry::class),
    );
}

// ── authorizeTool ──────────────────────────────────────────────

test('authorizeTool denies when tool definition is not found in registry', function () {
    $registry = Mockery::mock(AgentToolRegistry::class);
    $registry->shouldReceive('definition')->with('unknown.tool')->andReturn(null);

    $runner = buildRunner(['toolRegistry' => $registry]);
    $state = buildTestState();

    $result = $runner->authorizeTool($state, 'unknown.tool');

    expect($result->allowed)->toBeFalse();
    expect($result->reason)->toBe('tool_definition_not_found');
});

test('authorizeTool delegates to policy resolver when definition exists', function () {
    $definition = new AgentToolDefinitionData(
        toolName: 'content.read',
        toolFamily: 'content',
        contractSteward: 'content',
        sideEffectClass: 'read_only',
        approvalRequired: false,
        capabilityClass: 'class_0_read_only',
    );

    $registry = Mockery::mock(AgentToolRegistry::class);
    $registry->shouldReceive('definition')->with('content.read')->andReturn($definition);

    $policyResolver = Mockery::mock(ToolPolicyResolverInterface::class);
    $policyResolver->shouldReceive('authorize')
        ->once()
        ->andReturn(ToolAuthorization::allow(false));

    $runner = buildRunner([
        'toolRegistry' => $registry,
        'toolPolicyResolver' => $policyResolver,
    ]);

    $state = buildTestState();
    $result = $runner->authorizeTool($state, 'content.read');

    expect($result->allowed)->toBeTrue();
    expect($result->requiresApproval)->toBeFalse();
});

test('authorizeTool propagates approval requirement from policy resolver', function () {
    $definition = new AgentToolDefinitionData(
        toolName: 'content.propose',
        toolFamily: 'content',
        contractSteward: 'content',
        sideEffectClass: 'staged_write',
        approvalRequired: true,
        capabilityClass: 'class_2_staged_mutation',
    );

    $registry = Mockery::mock(AgentToolRegistry::class);
    $registry->shouldReceive('definition')->with('content.propose')->andReturn($definition);

    $policyResolver = Mockery::mock(ToolPolicyResolverInterface::class);
    $policyResolver->shouldReceive('authorize')
        ->once()
        ->andReturn(ToolAuthorization::allow(true));

    $runner = buildRunner([
        'toolRegistry' => $registry,
        'toolPolicyResolver' => $policyResolver,
    ]);

    $state = buildTestState(['availableToolDefinitions' => ['content.propose']]);
    $result = $runner->authorizeTool($state, 'content.propose');

    expect($result->allowed)->toBeTrue();
    expect($result->requiresApproval)->toBeTrue();
});

// ── resolveBinding ─────────────────────────────────────────────

test('resolveBinding delegates to binding resolver with state scope', function () {
    $expectedBinding = new AgentToolBindingData(
        toolName: 'content.read',
        moduleScope: 'content',
        environment: 'testing',
        tenantId: null,
        policyScope: null,
        bindingType: 'service_method',
        bindingTarget: 'Cms\\Content\\Services\\Agentic\\PostReadToolService@latest_posts_tool',
    );

    $bindingResolver = Mockery::mock(AgentToolBindingResolverInterface::class);
    $bindingResolver->shouldReceive('resolve')
        ->with('content.read', 'testing', null, 'content', null)
        ->once()
        ->andReturn($expectedBinding);

    $runner = buildRunner(['bindingResolver' => $bindingResolver]);
    $state = buildTestState();

    $result = $runner->resolveBinding($state, 'content.read');

    expect($result)->not->toBeNull();
    expect($result->bindingTarget)->toContain('PostReadToolService');
});

test('resolveBinding returns null when no binding found', function () {
    $bindingResolver = Mockery::mock(AgentToolBindingResolverInterface::class);
    $bindingResolver->shouldReceive('resolve')->andReturn(null);

    $runner = buildRunner(['bindingResolver' => $bindingResolver]);
    $state = buildTestState();

    $result = $runner->resolveBinding($state, 'nonexistent.tool');

    expect($result)->toBeNull();
});

// ── plan ───────────────────────────────────────────────────────

test('plan returns terminal decision when budget is exhausted', function () {
    $run = Mockery::mock(\Cms\Core\Models\Agents\AgentRun::class)->makePartial();
    $run->id = 1;
    $run->working_memory = [];
    $run->budget_config = ['max_steps' => 5];
    $run->budget_usage = ['steps' => 6];
    $run->environment = 'testing';
    $run->tenant_id = null;
    $run->module_scope = 'content';
    $run->input_goal = 'Test';

    $profile = Mockery::mock();
    $profile->capability_class = 'class_0_read_only';
    $profile->toolDefinitions = collect();
    $run->shouldReceive('profile->with->first')->andReturn($profile);
    $run->shouldReceive('steps->latest->value')->andReturn(null);

    $contextAssembler = Mockery::mock(ContextAssemblerInterface::class);
    $contextAssembler->shouldReceive('assemble')->andReturn([]);

    $budgetGuard = Mockery::mock(RunBudgetGuardInterface::class);
    $budgetGuard->shouldReceive('remaining')->andReturn(['steps' => -1]);
    $budgetGuard->shouldReceive('evaluate')->andReturn(
        new BudgetEvaluation(allowed: false, violations: ['budget_steps_exhausted'], terminalState: 'failed')
    );

    $runner = buildRunner([
        'contextAssembler' => $contextAssembler,
        'budgetGuard' => $budgetGuard,
    ]);

    $decision = $runner->plan($run, 0);

    expect($decision->type)->toBe('terminal');
    expect($decision->name)->toBe('failed');
    expect($decision->terminalReason)->toBe('budget_steps_exhausted');
});

test('plan delegates to reasoning engine when budget allows', function () {
    $run = Mockery::mock(\Cms\Core\Models\Agents\AgentRun::class)->makePartial();
    $run->id = 1;
    $run->working_memory = [];
    $run->budget_config = ['max_steps' => 10];
    $run->budget_usage = ['steps' => 1];
    $run->environment = 'testing';
    $run->tenant_id = null;
    $run->module_scope = 'content';
    $run->input_goal = 'Improve SEO';

    $profile = Mockery::mock();
    $profile->capability_class = 'class_0_read_only';
    $profile->toolDefinitions = collect();
    $run->shouldReceive('profile->with->first')->andReturn($profile);
    $run->shouldReceive('steps->latest->value')->andReturn(null);

    $contextAssembler = Mockery::mock(ContextAssemblerInterface::class);
    $contextAssembler->shouldReceive('assemble')->andReturn([]);

    $budgetGuard = Mockery::mock(RunBudgetGuardInterface::class);
    $budgetGuard->shouldReceive('remaining')->andReturn(['steps' => 9]);
    $budgetGuard->shouldReceive('evaluate')->andReturn(
        new BudgetEvaluation(allowed: true)
    );

    $expectedDecision = AgentDecision::tool('content.read', ['limit' => 5]);

    $reasoningEngine = Mockery::mock(ReasoningEngineInterface::class);
    $reasoningEngine->shouldReceive('plan')
        ->once()
        ->andReturn($expectedDecision);

    $runner = buildRunner([
        'contextAssembler' => $contextAssembler,
        'budgetGuard' => $budgetGuard,
        'reasoningEngine' => $reasoningEngine,
    ]);

    $decision = $runner->plan($run, 1);

    expect($decision->type)->toBe('tool_call');
    expect($decision->name)->toBe('content.read');
    expect($decision->input)->toBe(['limit' => 5]);
});

// ── hydrateState ───────────────────────────────────────────────

test('hydrateState carries the delegating user id from the run', function () {
    $run = Mockery::mock(\Cms\Core\Models\Agents\AgentRun::class)->makePartial();
    $run->id = 1;
    $run->working_memory = [];
    $run->budget_config = ['max_steps' => 10];
    $run->budget_usage = ['steps' => 0];
    $run->environment = 'testing';
    $run->tenant_id = null;
    $run->module_scope = 'content';
    $run->input_goal = 'Test';
    $run->delegated_by_user_id = 7;

    $run->shouldReceive('profile->with->first')->andReturn(null);
    $run->shouldReceive('steps->latest->value')->andReturn(null);

    $contextAssembler = Mockery::mock(ContextAssemblerInterface::class);
    $contextAssembler->shouldReceive('assemble')->andReturn([]);

    $budgetGuard = Mockery::mock(RunBudgetGuardInterface::class);
    $budgetGuard->shouldReceive('remaining')->andReturn(['steps' => 10]);

    $runner = buildRunner([
        'contextAssembler' => $contextAssembler,
        'budgetGuard' => $budgetGuard,
    ]);

    $state = $runner->hydrateState($run, 0);

    expect($state->delegatedByUserId)->toBe(7);
});
