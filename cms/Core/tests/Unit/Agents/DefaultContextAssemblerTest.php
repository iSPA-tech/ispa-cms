<?php

use Cms\Core\Agents\Runtime\DefaultContextAssembler;
use Cms\Core\Models\Agents\AgentRun;

test('assemble returns structured context with run metadata', function () {
    $run = Mockery::mock(AgentRun::class)->makePartial();
    $run->id = 1;
    $run->status = 'running';
    $run->input_goal = 'Test context assembly';
    $run->environment = 'testing';
    $run->tenant_id = 42;
    $run->module_scope = 'core';
    $run->target_type = 'App\\Models\\User';
    $run->target_id = 99;
    $run->working_memory = ['last_action' => 'read'];
    $run->last_checkpoint_at = null;
    $run->terminal_reason = null;
    $run->post_approval_outcome = null;

    // Partial-mock the assembler so loadRelevantMemories does not hit the DB
    $assembler = Mockery::mock(DefaultContextAssembler::class)->makePartial();
    $assembler->shouldAllowMockingProtectedMethods();
    $assembler->shouldReceive('loadRelevantMemories')
        ->once()
        ->with($run)
        ->andReturn([]);

    $result = $assembler->assemble($run);

    expect($result)->toHaveKeys(['run', 'target', 'working_memory', 'checkpoint', 'agent_memories'])
        ->and($result['run']['id'])->toBe(1)
        ->and($result['run']['status'])->toBe('running')
        ->and($result['run']['goal'])->toBe('Test context assembly')
        ->and($result['run']['environment'])->toBe('testing')
        ->and($result['run']['tenant_id'])->toBe(42)
        ->and($result['run']['module_scope'])->toBe('core')
        ->and($result['target']['type'])->toBe('App\\Models\\User')
        ->and($result['target']['id'])->toBe(99)
        ->and($result['agent_memories'])->toBe([]);
});

test('assemble includes working memory from run', function () {
    $run = Mockery::mock(AgentRun::class)->makePartial();
    $run->id = 2;
    $run->status = 'running';
    $run->input_goal = 'Check working memory';
    $run->environment = 'testing';
    $run->tenant_id = null;
    $run->module_scope = 'education';
    $run->target_type = null;
    $run->target_id = null;
    $run->working_memory = ['last_action' => 'test', 'step_count' => 3];
    $run->last_checkpoint_at = null;
    $run->terminal_reason = null;
    $run->post_approval_outcome = null;

    $assembler = Mockery::mock(DefaultContextAssembler::class)->makePartial();
    $assembler->shouldAllowMockingProtectedMethods();
    $assembler->shouldReceive('loadRelevantMemories')
        ->once()
        ->with($run)
        ->andReturn([]);

    $result = $assembler->assemble($run);

    expect($result['working_memory'])->toBe(['last_action' => 'test', 'step_count' => 3]);
});

test('assemble returns empty agent_memories when no DB connection', function () {
    $run = Mockery::mock(AgentRun::class)->makePartial();
    $run->id = 3;
    $run->status = 'pending';
    $run->input_goal = 'Memory fallback test';
    $run->environment = 'testing';
    $run->tenant_id = null;
    $run->module_scope = 'core';
    $run->target_type = 'App\\Models\\User';
    $run->target_id = 10;
    $run->working_memory = null;
    $run->last_checkpoint_at = null;
    $run->terminal_reason = null;
    $run->post_approval_outcome = null;

    // Simulate the protected method throwing (no DB) and returning empty
    $assembler = Mockery::mock(DefaultContextAssembler::class)->makePartial();
    $assembler->shouldAllowMockingProtectedMethods();
    $assembler->shouldReceive('loadRelevantMemories')
        ->once()
        ->with($run)
        ->andReturn([]);

    $result = $assembler->assemble($run);

    expect($result['agent_memories'])->toBe([])
        ->and($result['working_memory'])->toBe([]);
});
