<?php

use Cms\Core\Models\BackgroundTask;
use Cms\Core\Services\BackgroundTasks\BackgroundTaskRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;

uses(Cms\Core\Tests\TestCase::class, RefreshDatabase::class);

test('a task moves from queued to running to succeeded', function () {
    $recorder = new BackgroundTaskRecorder();

    $task = $recorder->start('export.users', 'Core', 7);
    expect($task->status)->toBe(BackgroundTask::STATUS_QUEUED)->and($task->uuid)->not->toBeEmpty();

    $task = $recorder->running($task);
    expect($task->status)->toBe(BackgroundTask::STATUS_RUNNING)->and($task->started_at)->not->toBeNull();

    $task = $recorder->succeed($task, ['rows' => 5]);
    $fresh = BackgroundTask::query()->findOrFail($task->id);
    expect($fresh->status)->toBe(BackgroundTask::STATUS_SUCCEEDED)
        ->and($fresh->progress)->toBe(100)
        ->and($fresh->finished_at)->not->toBeNull()
        ->and($fresh->result)->toBe(['rows' => 5])
        ->and($fresh->started_by)->toBe(7);
});

test('fail records the message and finish time', function () {
    $recorder = new BackgroundTaskRecorder();
    $task = $recorder->fail($recorder->start('import', 'Core', null), 'Boom');

    $fresh = BackgroundTask::query()->findOrFail($task->id);
    expect($fresh->status)->toBe(BackgroundTask::STATUS_FAILED)
        ->and($fresh->message)->toBe('Boom')
        ->and($fresh->finished_at)->not->toBeNull();
});

test('progress is clamped and promotes a queued task to running', function () {
    $recorder = new BackgroundTaskRecorder();
    $task = $recorder->start('import', 'Core', 1);

    $task = $recorder->progress($task, 150, 'Almost');
    expect($task->progress)->toBe(100)->and($task->status)->toBe(BackgroundTask::STATUS_RUNNING)->and($task->message)->toBe('Almost');

    $task = $recorder->progress($task, -5);
    expect($task->progress)->toBe(0)->and($task->message)->toBe('Almost');
});

test('messages are truncated to 500 characters without breaking multibyte text', function () {
    $recorder = new BackgroundTaskRecorder();
    $task = $recorder->fail($recorder->start('import', 'Core', 1), str_repeat('ế', 700));

    expect(mb_strlen(BackgroundTask::query()->findOrFail($task->id)->message))->toBe(500);
});

test('secret-looking result keys are stripped recursively', function () {
    $recorder = new BackgroundTaskRecorder();
    $task = $recorder->succeed($recorder->start('import', 'Core', 1), [
        'rows' => 3,
        'API_KEY' => 'x',
        'nested' => ['Password' => 'p', 'ok' => 1, 'deep' => [['authToken' => 't', 'keep' => true]]],
        'client_secret' => 's',
        'Authorization' => 'Bearer x',
    ]);

    expect(BackgroundTask::query()->findOrFail($task->id)->result)->toBe([
        'rows' => 3,
        'nested' => ['ok' => 1, 'deep' => [['keep' => true]]],
    ]);
});

test('prune deletes old finished rows and keeps running and recent ones', function () {
    $make = function (string $status, int $daysAgo): BackgroundTask {
        $task = BackgroundTask::query()->create(['type' => 't', 'module' => 'Core', 'status' => $status]);
        $at = now()->subDays($daysAgo);
        BackgroundTask::query()->whereKey($task->id)->update([
            'created_at' => $at,
            'finished_at' => in_array($status, ['succeeded', 'failed'], true) ? $at : null,
        ]);

        return $task;
    };
    $oldDone = $make('succeeded', 40);
    $oldFailed = $make('failed', 40);
    $oldRunning = $make('running', 40);
    $oldQueued = $make('queued', 40);
    $recent = $make('succeeded', 2);

    expect(Artisan::call('cms:background-tasks-prune', ['--days' => 30]))->toBe(0);
    expect(Artisan::output())->toContain('2');

    $left = BackgroundTask::query()->pluck('id')->all();
    expect($left)->toEqualCanonicalizing([$oldRunning->id, $oldQueued->id, $recent->id])
        ->and($left)->not->toContain($oldDone->id, $oldFailed->id);
});

test('prune marks stale queued and running rows failed and keeps recent ones', function () {
    $make = function (string $status, int $hoursAgo, bool $started = true): BackgroundTask {
        $task = BackgroundTask::query()->create(['type' => 't', 'module' => 'Core', 'status' => $status]);
        BackgroundTask::query()->whereKey($task->id)->update([
            'created_at' => now()->subHours($hoursAgo),
            'started_at' => $started ? now()->subHours($hoursAgo) : null,
        ]);

        return $task;
    };
    $stale = $make('running', 48);
    $staleQueued = $make('queued', 48, false);
    $recent = $make('running', 1);

    expect(Artisan::call('cms:background-tasks-prune'))->toBe(0);

    foreach ([$stale, $staleQueued] as $row) {
        $fresh = BackgroundTask::query()->findOrFail($row->id);
        expect($fresh->status)->toBe('failed')
            ->and($fresh->message)->toBe(BackgroundTask::STALE_MESSAGE_KEY)
            ->and($fresh->finished_at)->not->toBeNull();
    }
    expect(BackgroundTask::query()->findOrFail($recent->id)->status)->toBe('running');
});

test('prune respects the stale-hours option', function () {
    $task = BackgroundTask::query()->create(['type' => 't', 'module' => 'Core', 'status' => 'running']);
    BackgroundTask::query()->whereKey($task->id)->update(['started_at' => now()->subHours(2), 'created_at' => now()->subHours(2)]);

    Artisan::call('cms:background-tasks-prune');
    expect(BackgroundTask::query()->findOrFail($task->id)->status)->toBe('running');

    Artisan::call('cms:background-tasks-prune', ['--stale-hours' => 1]);
    expect(BackgroundTask::query()->findOrFail($task->id)->status)->toBe('failed');
});

test('progress and fail store a message key with sanitized params', function () {
    $recorder = new BackgroundTaskRecorder();
    $task = $recorder->progress($recorder->start('import', 'Core', 1), 40, 'text.x.progress', [
        'done' => 2,
        'total' => 5,
        'api_key' => 'nope',
        'Secret_Token' => 'nope',
        'nested' => ['a' => 1],
        'long' => str_repeat('ế', 300),
    ]);

    $fresh = BackgroundTask::query()->findOrFail($task->id);
    expect($fresh->message)->toBe('text.x.progress')
        ->and(array_keys($fresh->message_params))->toBe(['done', 'total', 'long'])
        ->and($fresh->message_params['done'])->toBe(2)
        ->and(mb_strlen($fresh->message_params['long']))->toBe(200);

    $failed = $recorder->fail($recorder->start('import', 'Core', 1), 'text.x.failed', ['error' => 'Boom']);
    expect(BackgroundTask::query()->findOrFail($failed->id)->message_params)->toBe(['error' => 'Boom']);
});

test('a plain message clears old params and a null message keeps them', function () {
    $recorder = new BackgroundTaskRecorder();
    $task = $recorder->progress($recorder->start('import', 'Core', 1), 10, 'text.x.progress', ['done' => 1]);

    $task = $recorder->progress($task, 20);
    expect($task->fresh()->message_params)->toBe(['done' => 1]);

    $task = $recorder->progress($task, 30, 'Plain text');
    $fresh = $task->fresh();
    expect($fresh->message)->toBe('Plain text')->and($fresh->message_params)->toBeNull();
});

test('succeed keeps the message and params', function () {
    $recorder = new BackgroundTaskRecorder();
    $task = $recorder->progress($recorder->start('import', 'Core', 1), 10, 'text.x.progress', ['done' => 1]);

    $fresh = $recorder->succeed($task, ['rows' => 1])->fresh();
    expect($fresh->message)->toBe('text.x.progress')->and($fresh->message_params)->toBe(['done' => 1]);
});
