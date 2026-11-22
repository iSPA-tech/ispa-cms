<?php

use Cms\Core\Models\Agents\AgentRun;
use Cms\Core\Services\Agentic\AgentRunControlService;
use Cms\Core\Services\Agentic\AgentScheduleRuntimeSyncService;

function buildRunControlService(): AgentRunControlService
{
    $syncService = Mockery::mock(AgentScheduleRuntimeSyncService::class);
    $syncService->shouldReceive('syncFromRun')->andReturn(null)->byDefault();
    return new AgentRunControlService($syncService);
}

test('service can be constructed with mocked sync dependency', function () {
    expect(buildRunControlService())->toBeInstanceOf(AgentRunControlService::class);
});

test('requestStop throws for completed run', function () {
    $run = Mockery::mock(AgentRun::class)->makePartial();
    $run->status = 'completed';
    $run->id = 1;
    $run->shouldReceive('loadMissing')->andReturnSelf();

    expect(fn () => buildRunControlService()->requestStop($run))
        ->toThrow(\InvalidArgumentException::class);
});

test('requestStop throws for cancelled run', function () {
    $run = Mockery::mock(AgentRun::class)->makePartial();
    $run->status = 'cancelled';
    $run->id = 2;
    $run->shouldReceive('loadMissing')->andReturnSelf();

    expect(fn () => buildRunControlService()->requestStop($run))
        ->toThrow(\InvalidArgumentException::class);
});

test('requestStop throws for failed run', function () {
    $run = Mockery::mock(AgentRun::class)->makePartial();
    $run->status = 'failed';
    $run->id = 3;
    $run->shouldReceive('loadMissing')->andReturnSelf();

    expect(fn () => buildRunControlService()->requestStop($run))
        ->toThrow(\InvalidArgumentException::class);
});

test('acknowledgeStop returns early when already acknowledged', function () {
    $freshRun = Mockery::mock(AgentRun::class)->makePartial();
    $freshRun->status = 'cancelled';
    $freshRun->operator_request_state = 'stop_acknowledged';

    $run = Mockery::mock(AgentRun::class)->makePartial();
    $run->status = 'cancelled';
    $run->operator_request_state = 'stop_acknowledged';
    $run->shouldReceive('loadMissing')->andReturnSelf();
    $run->shouldReceive('fresh')->andReturn($freshRun);

    $service = buildRunControlService();
    $result = $service->acknowledgeStop($run);

    expect($result->operator_request_state)->toBe('stop_acknowledged');
});
