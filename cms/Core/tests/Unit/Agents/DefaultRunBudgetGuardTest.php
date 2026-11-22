<?php

use Cms\Core\Agents\Runtime\DefaultRunBudgetGuard;
use Cms\Core\Agents\Support\AgentState;
use Cms\Core\Agents\Support\BudgetEvaluation;

test('remaining returns null for unconfigured limits', function () {
    $guard = new DefaultRunBudgetGuard();

    $remaining = $guard->remaining(
        budgetConfig: [],
        budgetUsage: ['steps' => 5, 'tokens' => 100],
    );

    expect($remaining['steps'])->toBeNull()
        ->and($remaining['tokens'])->toBeNull()
        ->and($remaining['tool_failures'])->toBeNull();
});

test('remaining calculates correctly when limits are set', function () {
    $guard = new DefaultRunBudgetGuard();

    $remaining = $guard->remaining(
        budgetConfig: ['max_steps' => 10, 'max_tokens' => 500, 'max_tool_failures' => 3],
        budgetUsage: ['steps' => 3, 'tokens' => 200, 'tool_failures' => 1],
    );

    expect($remaining['steps'])->toBe(7)
        ->and($remaining['tokens'])->toBe(300)
        ->and($remaining['tool_failures'])->toBe(2);
});

test('evaluate allows when budget has remaining', function () {
    $guard = new DefaultRunBudgetGuard();

    $state = new AgentState(
        runId: 1,
        workflowInstanceId: null,
        parentWorkflowStepId: null,
        stepIndex: 3,
        environment: 'testing',
        tenantId: null,
        moduleScope: 'core',
        goal: 'Test budget guard',
        budgetConfig: ['max_steps' => 10, 'max_tokens' => 500],
        budgetUsage: ['steps' => 3, 'tokens' => 100],
    );

    $evaluation = $guard->evaluate($state);

    expect($evaluation)->toBeInstanceOf(BudgetEvaluation::class)
        ->and($evaluation->allowed)->toBeTrue()
        ->and($evaluation->violations)->toBe([])
        ->and($evaluation->terminalState)->toBeNull()
        ->and($evaluation->remaining['steps'])->toBe(7)
        ->and($evaluation->remaining['tokens'])->toBe(400);
});

test('evaluate denies when budget is exhausted', function () {
    $guard = new DefaultRunBudgetGuard();

    $state = new AgentState(
        runId: 1,
        workflowInstanceId: null,
        parentWorkflowStepId: null,
        stepIndex: 6,
        environment: 'testing',
        tenantId: null,
        moduleScope: 'core',
        goal: 'Test budget exhaustion',
        budgetConfig: ['max_steps' => 5, 'max_tokens' => 1000],
        budgetUsage: ['steps' => 6, 'tokens' => 500],
    );

    $evaluation = $guard->evaluate($state);

    expect($evaluation->allowed)->toBeFalse()
        ->and($evaluation->violations)->toContain('budget_steps_exhausted')
        ->and($evaluation->terminalState)->toBe('failed')
        ->and($evaluation->remaining['steps'])->toBe(-1);
});

test('evaluate allows when remaining is exactly zero', function () {
    $guard = new DefaultRunBudgetGuard();

    $state = new AgentState(
        runId: 1,
        workflowInstanceId: null,
        parentWorkflowStepId: null,
        stepIndex: 5,
        environment: 'testing',
        tenantId: null,
        moduleScope: 'core',
        goal: 'Test exact boundary',
        budgetConfig: ['max_steps' => 5, 'max_tokens' => 1000],
        budgetUsage: ['steps' => 5, 'tokens' => 1000],
    );

    $evaluation = $guard->evaluate($state);

    // remaining = 0 is NOT < 0, so the agent is still allowed
    expect($evaluation->allowed)->toBeTrue()
        ->and($evaluation->violations)->toBe([])
        ->and($evaluation->terminalState)->toBeNull()
        ->and($evaluation->remaining['steps'])->toBe(0)
        ->and($evaluation->remaining['tokens'])->toBe(0);
});
