<?php

use App\Http\Middleware\ApiLocalization;
use App\Http\Middleware\HttpsProtocol;
use App\Http\Middleware\Localization;
use App\Models\User;
use Cms\Core\Notifications\AdminNotification;
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

function makeNotificationAdmin(): User
{
    return User::query()->forceCreate([
        'uuid' => (string) Str::uuid(),
        'name' => 'Notification Admin',
        'username' => 'notif-admin-' . Str::lower(Str::random(6)),
        'email' => 'notif-admin-' . Str::lower(Str::random(6)) . '@example.com',
        'email_verified_at' => now(),
        'password' => Hash::make('secret123'),
        'type' => 0,
        'level' => 0,
        'status' => null,
    ]);
}

function makeNotificationRow(User $user, string $type = 'test.event', bool $read = false, int $minutesAgo = 0): string
{
    $id = (string) Str::uuid();
    DB::table('notifications')->insert([
        'id' => $id,
        'type' => AdminNotification::class,
        'notifiable_type' => $user->getMorphClass(),
        'notifiable_id' => $user->getKey(),
        'data' => json_encode(['type' => $type, 'payload' => ['k' => 'v']]),
        'read_at' => $read ? now() : null,
        'created_at' => now()->subMinutes($minutesAgo),
        'updated_at' => now()->subMinutes($minutesAgo),
    ]);

    return $id;
}

test('a user lists only own notifications newest first with unread count in meta', function () {
    $me = makeNotificationAdmin();
    $other = makeNotificationAdmin();
    $old = makeNotificationRow($me, 'a', false, 10);
    $new = makeNotificationRow($me, 'b', true, 1);
    $foreign = makeNotificationRow($other);

    $response = $this->actingAs($me, 'sanctum')->getJson('/api/v2/admin/notifications')->assertOk();

    $ids = collect($response->json('data.data'))->pluck('id')->all();
    expect($ids)->toEqual([$new, $old])->and($ids)->not->toContain($foreign);
    expect(array_keys($response->json('data.data.0')))->toEqual(['id', 'type', 'payload', 'read_at', 'created_at']);
    expect($response->json('data.data.0.type'))->toBe('b')
        ->and($response->json('data.data.0.payload'))->toBe(['k' => 'v'])
        ->and($response->json('data.meta.unread_count'))->toBe(1)
        ->and($response->json('data.meta.total'))->toBe(2);
});

test('unread filter keeps unread rows only', function () {
    $me = makeNotificationAdmin();
    $unread = makeNotificationRow($me);
    makeNotificationRow($me, 'x', true);

    $ids = collect($this->actingAs($me, 'sanctum')->getJson('/api/v2/admin/notifications?unread=1')->assertOk()->json('data.data'))->pluck('id')->all();

    expect($ids)->toEqual([$unread]);
});

test('per_page is clamped between 1 and 50', function () {
    $me = makeNotificationAdmin();
    foreach (range(1, 3) as $i) {
        makeNotificationRow($me, 't' . $i, false, $i);
    }

    $this->actingAs($me, 'sanctum');
    expect($this->getJson('/api/v2/admin/notifications?per_page=0')->json('data.meta.per_page'))->toBe(1)
        ->and($this->getJson('/api/v2/admin/notifications?per_page=999')->json('data.meta.per_page'))->toBe(50)
        ->and($this->getJson('/api/v2/admin/notifications')->json('data.meta.per_page'))->toBe(20);
});

test('unread count counts only own unread rows', function () {
    $me = makeNotificationAdmin();
    $other = makeNotificationAdmin();
    makeNotificationRow($me);
    makeNotificationRow($me);
    makeNotificationRow($me, 'x', true);
    makeNotificationRow($other);

    $this->actingAs($me, 'sanctum')->getJson('/api/v2/admin/notifications/unread-count')
        ->assertOk()->assertJsonPath('data.unread_count', 2);
});

test('read marks an own notification and another user id is a 404', function () {
    $me = makeNotificationAdmin();
    $other = makeNotificationAdmin();
    $mine = makeNotificationRow($me);
    $foreign = makeNotificationRow($other);

    $this->actingAs($me, 'sanctum');
    $this->postJson("/api/v2/admin/notifications/{$mine}/read")->assertOk()->assertJsonPath('data.id', $mine);
    expect(DB::table('notifications')->where('id', $mine)->value('read_at'))->not->toBeNull();

    $this->postJson("/api/v2/admin/notifications/{$foreign}/read")->assertNotFound();
    expect(DB::table('notifications')->where('id', $foreign)->value('read_at'))->toBeNull();
});

test('read-all marks only the caller unread rows', function () {
    $me = makeNotificationAdmin();
    $other = makeNotificationAdmin();
    makeNotificationRow($me);
    makeNotificationRow($me);
    makeNotificationRow($me, 'x', true);
    $foreign = makeNotificationRow($other);

    $this->actingAs($me, 'sanctum')->postJson('/api/v2/admin/notifications/read-all')
        ->assertOk()->assertJsonPath('data.updated', 2);

    expect(DB::table('notifications')->where('notifiable_id', $me->id)->whereNull('read_at')->count())->toBe(0)
        ->and(DB::table('notifications')->where('id', $foreign)->value('read_at'))->toBeNull();
});

test('unauthenticated requests are rejected with 401', function () {
    $this->getJson('/api/v2/admin/notifications')->assertUnauthorized();
    $this->getJson('/api/v2/admin/notifications/unread-count')->assertUnauthorized();
    $this->postJson('/api/v2/admin/notifications/read-all')->assertUnauthorized();
});
