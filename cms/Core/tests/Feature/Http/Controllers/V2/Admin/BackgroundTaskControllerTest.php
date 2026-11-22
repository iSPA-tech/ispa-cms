<?php

use App\Http\Middleware\ApiLocalization;
use App\Http\Middleware\HttpsProtocol;
use App\Http\Middleware\Localization;
use App\Models\User;
use Cms\Core\Models\BackgroundTask;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

uses(Cms\Core\Tests\TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $this->withoutMiddleware([
        HttpsProtocol::class,
        Localization::class,
        ApiLocalization::class,
        ThrottleRequests::class,
    ]);
});

function makeBackgroundTaskAdmin(): User
{
    return User::query()->forceCreate([
        'uuid' => (string) Str::uuid(),
        'name' => 'Task Admin',
        'username' => 'task-admin-' . Str::lower(Str::random(6)),
        'email' => 'task-admin-' . Str::lower(Str::random(6)) . '@example.com',
        'email_verified_at' => now(),
        'password' => Hash::make('secret123'),
        'type' => 0,
        'level' => 0,
        'status' => null,
    ]);
}

function makeBackgroundTaskRow(User $user, string $status = 'succeeded', int $hoursAgo = 0, string $type = 'export'): BackgroundTask
{
    $task = BackgroundTask::query()->create([
        'type' => $type, 'module' => 'Core', 'status' => $status, 'started_by' => $user->id,
    ]);
    BackgroundTask::query()->whereKey($task->id)->update(['created_at' => now()->subHours($hoursAgo)]);

    return $task->refresh();
}

test('index returns only own recent tasks plus own old running tasks', function () {
    $me = makeBackgroundTaskAdmin();
    $other = makeBackgroundTaskAdmin();
    $recent = makeBackgroundTaskRow($me, 'succeeded', 1);
    $oldDone = makeBackgroundTaskRow($me, 'succeeded', 48);
    $oldRunning = makeBackgroundTaskRow($me, 'running', 72);
    $foreign = makeBackgroundTaskRow($other, 'running', 1);

    $response = $this->actingAs($me, 'sanctum')->getJson('/api/v2/admin/background-tasks')->assertOk();

    $uuids = collect($response->json('data'))->pluck('uuid')->all();
    expect($uuids)->toEqual([$recent->uuid, $oldRunning->uuid])
        ->and($uuids)->not->toContain($oldDone->uuid, $foreign->uuid);

    $row = $response->json('data.0');
    expect(array_keys($row))->toEqual(['uuid', 'type', 'module', 'status', 'progress', 'message', 'message_params', 'result', 'started_at', 'finished_at', 'created_at']);
});

test('index and show return the message params of a task', function () {
    $me = makeBackgroundTaskAdmin();
    $task = makeBackgroundTaskRow($me, 'running', 1);
    $task->forceFill(['message' => 'text.x.progress', 'message_params' => ['done' => 3, 'total' => 9]])->save();

    $row = collect($this->actingAs($me, 'sanctum')->getJson('/api/v2/admin/background-tasks')->assertOk()->json('data'))->firstWhere('uuid', $task->uuid);
    expect($row['message'])->toBe('text.x.progress')->and($row['message_params'])->toBe(['done' => 3, 'total' => 9]);

    $this->actingAs($me, 'sanctum')->getJson('/api/v2/admin/background-tasks/' . $task->uuid)
        ->assertOk()->assertJsonPath('data.message_params.done', 3);
});

test('a stale running task is presented as failed without changing the row', function () {
    $me = makeBackgroundTaskAdmin();
    $stale = makeBackgroundTaskRow($me, 'running', 48);
    $fresh = makeBackgroundTaskRow($me, 'running', 1);

    $data = collect($this->actingAs($me, 'sanctum')->getJson('/api/v2/admin/background-tasks')->assertOk()->json('data'))->keyBy('uuid');

    expect($data[$stale->uuid]['status'])->toBe('failed')
        ->and($data[$stale->uuid]['message'])->toBe('text.background_tasks.stale')
        ->and($data[$stale->uuid]['message_params'])->toBeNull()
        ->and($data[$fresh->uuid]['status'])->toBe('running')
        ->and(BackgroundTask::query()->find($stale->id)->status)->toBe('running');

    $this->actingAs($me, 'sanctum')->getJson('/api/v2/admin/background-tasks/' . $stale->uuid)
        ->assertOk()->assertJsonPath('data.status', 'failed');
});

test('index returns at most 20 tasks newest first', function () {
    $me = makeBackgroundTaskAdmin();
    foreach (range(1, 25) as $i) {
        makeBackgroundTaskRow($me, 'succeeded', 0, "t{$i}");
        BackgroundTask::query()->where('type', "t{$i}")->update(['created_at' => now()->subMinutes(30 - $i)]);
    }

    $data = $this->actingAs($me, 'sanctum')->getJson('/api/v2/admin/background-tasks')->assertOk()->json('data');

    expect($data)->toHaveCount(20)->and($data[0]['type'])->toBe('t25')->and($data[19]['type'])->toBe('t6');
});

test('show returns own task and 404 for another users task', function () {
    $me = makeBackgroundTaskAdmin();
    $other = makeBackgroundTaskAdmin();
    $mine = makeBackgroundTaskRow($me);
    $theirs = makeBackgroundTaskRow($other);

    $this->actingAs($me, 'sanctum')->getJson("/api/v2/admin/background-tasks/{$mine->uuid}")
        ->assertOk()->assertJsonPath('data.uuid', $mine->uuid);
    $this->actingAs($me, 'sanctum')->getJson("/api/v2/admin/background-tasks/{$theirs->uuid}")->assertNotFound();
});

test('show returns 404 for a non-uuid id', function () {
    $me = makeBackgroundTaskAdmin();

    // pgsql throws on a non-uuid string in a uuid column, so the lookup must never reach the table.
    $taskQueries = 0;
    DB::listen(function ($query) use (&$taskQueries): void {
        if (str_contains($query->sql, 'background_tasks')) {
            $taskQueries++;
        }
    });

    $this->actingAs($me, 'sanctum')->getJson('/api/v2/admin/background-tasks/not-a-uuid')->assertNotFound();

    expect($taskQueries)->toBe(0);
});

test('unauthenticated requests are rejected', function () {
    $this->getJson('/api/v2/admin/background-tasks')->assertUnauthorized();
});
