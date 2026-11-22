<?php

use App\Http\Middleware\ApiLocalization;
use App\Http\Middleware\HttpsProtocol;
use App\Http\Middleware\Localization;
use App\Models\PasswordReset;
use App\Models\User;
use Cms\Core\Events\SendCodeVerify;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $this->withoutMiddleware([
        HttpsProtocol::class,
        Localization::class,
        ApiLocalization::class,
        ThrottleRequests::class,
    ]);
});

function makeAdminPasswordResetUser(array $attributes = []): User
{
    return User::query()->forceCreate(array_merge([
        'uuid' => (string) Str::uuid(),
        'name' => 'Reset Admin',
        'username' => 'reset-admin-' . Str::lower(Str::random(6)),
        'email' => 'reset-admin+' . Str::lower(Str::random(6)) . '@example.com',
        'email_verified_at' => now(),
        'password' => Hash::make('old-secret'),
        'type' => 0,
        'level' => 0,
        'status' => null,
    ], $attributes));
}

test('admin password reset routes have dedicated throttles', function () {
    expect(Route::getRoutes()->match(Request::create('/api/v2/admin/auth/request-code', 'POST'))->gatherMiddleware())
        ->toContain('throttle.route:5,10')
        ->and(Route::getRoutes()->match(Request::create('/api/v2/admin/auth/check-code', 'POST'))->gatherMiddleware())
        ->toContain('throttle.route:10,1')
        ->and(Route::getRoutes()->match(Request::create('/api/v2/admin/auth/set-password', 'POST'))->gatherMiddleware())
        ->toContain('throttle.route:5,10');
});

test('admin password reset request is neutral for unknown accounts and uses random tokens', function () {
    Event::fake([SendCodeVerify::class]);
    $user = makeAdminPasswordResetUser([
        'email' => 'known-reset@example.com',
    ]);

    $this->postJson('/api/v2/admin/auth/request-code', [
        'email' => 'missing-reset@example.com',
        'type' => 'reset-password',
    ])
        ->assertOk()
        ->assertJsonPath('data.email', 'missing-reset@example.com')
        ->assertJsonPath('data.type', 'check-code');

    expect(PasswordReset::query()->count())->toBe(0);
    Event::assertNotDispatched(SendCodeVerify::class);

    $this->postJson('/api/v2/admin/auth/request-code', [
        'email' => $user->email,
        'type' => 'reset-password',
    ])
        ->assertOk()
        ->assertJsonPath('data.email', $user->email)
        ->assertJsonPath('data.type', 'check-code');

    $reset = PasswordReset::query()->firstOrFail();

    expect($reset->token)
        ->toHaveLength(64)
        ->not->toBe(md5($reset->code . $user->id));
    Event::assertDispatched(SendCodeVerify::class);
});

test('admin password reset code verification is scoped to the requested email', function () {
    $expectedUser = makeAdminPasswordResetUser([
        'email' => 'expected-reset@example.com',
    ]);
    $otherUser = makeAdminPasswordResetUser([
        'email' => 'other-reset@example.com',
    ]);

    $otherReset = PasswordReset::query()->create([
        'user_id' => $otherUser->id,
        'code' => '87654321',
        'token' => Str::random(64),
        'valid_to' => now()->addMinutes(5),
    ]);

    $this->postJson('/api/v2/admin/auth/check-code', [
        'email' => $expectedUser->email,
        'code' => '87654321',
    ])
        ->assertStatus(422)
        ->assertJsonPath('status', 'error');

    expect($otherReset->fresh()->checked_at)->toBeNull();

    $this->postJson('/api/v2/admin/auth/check-code', [
        'email' => $otherUser->email,
        'code' => '87654321',
    ])
        ->assertOk()
        ->assertJsonPath('data.email', $otherUser->email)
        ->assertJsonPath('data.token', $otherReset->token);

    expect($otherReset->fresh()->checked_at)->not->toBeNull();
});

test('admin password reset set password rejects expired checked tokens', function () {
    $user = makeAdminPasswordResetUser();
    $reset = PasswordReset::query()->create([
        'user_id' => $user->id,
        'code' => '11223344',
        'token' => Str::random(64),
        'valid_to' => now()->subMinute(),
    ]);
    $reset->checked_at = now();
    $reset->save();

    $this->postJson('/api/v2/admin/auth/set-password', [
        'token' => $reset->token,
        'password' => 'new-secret',
        'password_confirmation' => 'new-secret',
    ])
        ->assertStatus(406)
        ->assertJsonPath('status', 'error');

    expect(Hash::check('old-secret', $user->fresh()->password))->toBeTrue();
});
