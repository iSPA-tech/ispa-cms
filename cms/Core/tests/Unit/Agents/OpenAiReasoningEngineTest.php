<?php

use Cms\Core\Agents\Runtime\OpenAiReasoningEngine;
use Cms\Core\Agents\Runtime\ToolDefinitionFormatter;
use Cms\Core\Agents\Support\AgentState;
use Cms\Core\Agents\Support\AgentToolDefinitionData;
use Cms\Core\Agents\Contracts\AgenticAiProviderInterface;
use Cms\Core\Agents\Tools\AgentToolRegistry;

// The engine reads `core.agentic.reasoning_model` through config(), which needs a booted application.
uses(\Tests\TestCase::class);

function buildReasoningState(array $overrides = []): AgentState
{
    return new AgentState(
        runId: $overrides['runId'] ?? 1,
        workflowInstanceId: null,
        parentWorkflowStepId: null,
        stepIndex: $overrides['stepIndex'] ?? 0,
        environment: 'testing',
        tenantId: null,
        moduleScope: $overrides['moduleScope'] ?? 'content',
        goal: $overrides['goal'] ?? 'Improve SEO for top products',
        availableToolDefinitions: $overrides['availableToolDefinitions'] ?? ['content.read'],
        profileCapabilityClass: $overrides['profileCapabilityClass'] ?? 'class_0_read_only',
        budgetRemaining: $overrides['budgetRemaining'] ?? ['steps' => 5],
        workingMemory: $overrides['workingMemory'] ?? [],
        contextSnapshot: $overrides['contextSnapshot'] ?? [],
        lastAction: $overrides['lastAction'] ?? null,
    );
}

function buildEngine(
    ?AgenticAiProviderInterface $provider = null,
    ?AgentToolRegistry $registry = null,
): OpenAiReasoningEngine {
    $reg = $registry ?? Mockery::mock(AgentToolRegistry::class);
    if (!$registry) {
        $reg->shouldReceive('definitions')->andReturn([
            'content.read' => new AgentToolDefinitionData(
                toolName: 'content.read',
                description: 'Read latest posts',
                inputSchema: ['type' => 'object', 'properties' => ['limit' => ['type' => 'integer']]],
            ),
        ]);
    }

    return new OpenAiReasoningEngine(
        $provider ?? Mockery::mock(AgenticAiProviderInterface::class),
        $reg,
        new ToolDefinitionFormatter(),
    );
}

// ── tool call response ─────────────────────────────────────────

test('plan returns tool decision when LLM responds with tool_call', function () {
    $provider = Mockery::mock(AgenticAiProviderInterface::class);
    $provider->shouldReceive('createCompletion')
        ->once()
        ->andReturn([
            'choices' => [[
                'message' => [
                    'tool_calls' => [[
                        'id' => 'call_abc123',
                        'type' => 'function',
                        'function' => [
                            'name' => 'content.read',
                            'arguments' => '{"limit":5}',
                        ],
                    ]],
                ],
                'finish_reason' => 'tool_calls',
            ]],
        ]);

    $engine = buildEngine($provider);
    $state = buildReasoningState();

    $decision = $engine->plan($state);

    expect($decision->type)->toBe('tool_call');
    expect($decision->name)->toBe('content.read');
    expect($decision->input)->toBe(['limit' => 5]);
});

// ── terminal completion ────────────────────────────────────────

test('plan returns terminal complete when LLM responds with content', function () {
    $provider = Mockery::mock(AgenticAiProviderInterface::class);
    $provider->shouldReceive('createCompletion')
        ->once()
        ->andReturn([
            'choices' => [[
                'message' => [
                    'content' => 'The goal has been achieved. All SEO fields are up to date.',
                ],
                'finish_reason' => 'stop',
            ]],
        ]);

    $engine = buildEngine($provider);
    $state = buildReasoningState();

    $decision = $engine->plan($state);

    expect($decision->type)->toBe('terminal');
    expect($decision->name)->toBe('complete');
    expect($decision->terminalReason)->toContain('goal has been achieved');
});

// ── error handling ─────────────────────────────────────────────

test('plan returns terminal failed when API throws', function () {
    $provider = Mockery::mock(AgenticAiProviderInterface::class);
    $provider->shouldReceive('createCompletion')
        ->once()
        ->andThrow(new \RuntimeException('API timeout'));

    $engine = buildEngine($provider);
    $state = buildReasoningState();

    $decision = $engine->plan($state);

    expect($decision->type)->toBe('terminal');
    expect($decision->name)->toBe('failed');
    expect($decision->terminalReason)->toContain('ai_provider_error');
});

test('plan returns terminal failed on empty response', function () {
    $provider = Mockery::mock(AgenticAiProviderInterface::class);
    $provider->shouldReceive('createCompletion')
        ->once()
        ->andReturn([]);

    $engine = buildEngine($provider);
    $state = buildReasoningState();

    $decision = $engine->plan($state);

    expect($decision->type)->toBe('terminal');
    expect($decision->name)->toBe('failed');
    expect($decision->terminalReason)->toBe('empty_response');
});

// ── no tools available ─────────────────────────────────────────

test('plan returns terminal failed when no tools match the profile', function () {
    $registry = Mockery::mock(AgentToolRegistry::class);
    $registry->shouldReceive('definitions')->andReturn([]);

    $engine = buildEngine(registry: $registry);
    $state = buildReasoningState(['availableToolDefinitions' => ['nonexistent.tool']]);

    $decision = $engine->plan($state);

    expect($decision->type)->toBe('terminal');
    expect($decision->name)->toBe('failed');
    expect($decision->terminalReason)->toBe('no_tools_available');
});

// ── malformed tool call ────────────────────────────────────────

test('plan handles tool call with invalid JSON arguments', function () {
    $provider = Mockery::mock(AgenticAiProviderInterface::class);
    $provider->shouldReceive('createCompletion')
        ->once()
        ->andReturn([
            'choices' => [[
                'message' => [
                    'tool_calls' => [[
                        'function' => [
                            'name' => 'content.read',
                            'arguments' => '{invalid json',
                        ],
                    ]],
                ],
                'finish_reason' => 'tool_calls',
            ]],
        ]);

    $engine = buildEngine($provider);
    $state = buildReasoningState();

    $decision = $engine->plan($state);

    expect($decision->type)->toBe('tool_call');
    expect($decision->name)->toBe('content.read');
    expect($decision->input)->toBe([]);
});

// ── prompt assembly ────────────────────────────────────────────

test('plan sends goal and budget constraints in the system prompt', function () {
    $capturedPayload = null;
    $provider = Mockery::mock(AgenticAiProviderInterface::class);
    $provider->shouldReceive('createCompletion')
        ->once()
        ->withArgs(function (array $payload) use (&$capturedPayload) {
            $capturedPayload = $payload;
            return true;
        })
        ->andReturn([
            'choices' => [[
                'message' => ['content' => 'Done'],
                'finish_reason' => 'stop',
            ]],
        ]);

    $engine = buildEngine($provider);
    $state = buildReasoningState([
        'goal' => 'Fix broken SEO',
        'budgetRemaining' => ['steps' => 3, 'tokens' => null],
    ]);

    $engine->plan($state);

    $systemMessage = $capturedPayload['messages'][0]['content'] ?? '';
    expect($systemMessage)->toContain('Fix broken SEO');
    expect($systemMessage)->toContain('steps: 3 remaining');
    expect($capturedPayload['tools'])->toHaveCount(1);
    expect($capturedPayload['tool_choice'])->toBe('auto');
});

test('plan sends the model configured in core.agentic.reasoning_model', function () {
    config()->set('core.agentic.reasoning_model', 'configured-reasoning-model');

    $capturedPayload = null;
    $provider = Mockery::mock(AgenticAiProviderInterface::class);
    $provider->shouldReceive('createCompletion')
        ->once()
        ->withArgs(function (array $payload) use (&$capturedPayload) {
            $capturedPayload = $payload;
            return true;
        })
        ->andReturn([
            'choices' => [[
                'message' => ['content' => 'Done'],
                'finish_reason' => 'stop',
            ]],
        ]);

    buildEngine($provider)->plan(buildReasoningState());

    expect($capturedPayload['model'])->toBe('configured-reasoning-model');
});

test('plan routes through AiTaskRuntime and meters the call as agentic_reasoning', function () {
    foreach (['2026_10_21_100000_create_core_ai_usage_daily_table', '2026_10_21_110000_add_via_to_core_ai_usage_daily_table'] as $__m) {
        (require base_path('cms/Core/database/migrations/' . $__m . '.php'))->up();
    }
    \Illuminate\Support\Facades\DB::table('core_ai_usage_daily')->delete();
    $provider = Mockery::mock(AgenticAiProviderInterface::class);
    $provider->shouldReceive('createCompletion')->once()->andReturn([
        'choices' => [['message' => ['content' => 'done'], 'finish_reason' => 'stop']],
        'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5],
    ]);
    $provider->shouldReceive('getContent')->andReturn('done');
    $registry = Mockery::mock(AgentToolRegistry::class);
    $registry->shouldReceive('definitions')->andReturn([
        'content.read' => new AgentToolDefinitionData(toolName: 'content.read', description: 'Read', inputSchema: ['type' => 'object']),
    ]);

    $engine = new OpenAiReasoningEngine($provider, $registry, new ToolDefinitionFormatter(), app(\Cms\Core\Services\Ai\AiTaskRuntime::class));
    $decision = $engine->plan(buildReasoningState());

    expect($decision->name)->toBe('complete');
    $row = \Illuminate\Support\Facades\DB::table('core_ai_usage_daily')->where('task_key', 'agentic_reasoning')->first();
    expect($row)->not->toBeNull()->and((int) $row->input_tokens)->toBe(10)->and($row->via)->toBe('runtime');
});
