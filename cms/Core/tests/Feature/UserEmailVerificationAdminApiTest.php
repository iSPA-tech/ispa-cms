<?php

use App\Http\Middleware\ApiLocalization;
use App\Http\Middleware\HttpsProtocol;
use App\Http\Middleware\Localization;
use App\Models\User;
use Cms\Core\Models\Permission;
use Cms\Core\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
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

function makeEmailVerificationAdminApiUser(array $overrides = []): User
{
    return User::query()->forceCreate(array_merge([
        'uuid' => (string) Str::uuid(),
        'name' => 'Verification Admin',
        'username' => 'verification-admin-' . Str::lower(Str::random(6)),
        'email' => 'verification-admin-' . Str::lower(Str::random(6)) . '@example.com',
        'email_verified_at' => now(),
        'password' => Hash::make('secret123'),
        'type' => 0,
        'level' => 0,
        'status' => null,
    ], $overrides));
}

function grantEmailVerificationUserUpdatePermission(User $user): void
{
    $permission = Permission::query()->create([
        'group' => 'user',
        'name' => 'update',
        'title' => 'Update users',
    ]);
    $role = Role::query()->create(['name' => 'Verification editor']);
    $role->permissions()->attach($permission);
    $user->roles()->attach($role);
}

test('admin queues an email verification notification for an unverified user', function () {
    Queue::fake();

    $admin = makeEmailVerificationAdminApiUser();
    grantEmailVerificationUserUpdatePermission($admin);
    $target = makeEmailVerificationAdminApiUser([
        'name' => 'Unverified User',
        'username' => 'unverified-' . Str::lower(Str::random(6)),
        'email' => 'unverified-' . Str::lower(Str::random(6)) . '@example.com',
        'email_verified_at' => null,
        'level' => 99,
    ]);

    $this->actingAs($admin, 'sanctum')
        ->postJson("/api/v2/admin/user/{$target->uuid}/email-verification/resend")
        ->assertOk()
        ->assertJsonPath('status', 'success');

    Queue::assertPushed(SendQueuedNotifications::class, function (SendQueuedNotifications $job) use ($target): bool {
        return $job->notification::class === 'Cms\\Core\\Notifications\\QueuedVerifyEmail'
            && $job->notifiables->contains(fn(User $user): bool => $user->is($target));
    });
});

test('shared user queues the verification email after commit', function () {
    Queue::fake();

    $target = makeEmailVerificationAdminApiUser([
        'name' => 'New Website User',
        'username' => 'website-user-' . Str::lower(Str::random(6)),
        'email' => 'website-user-' . Str::lower(Str::random(6)) . '@example.com',
        'email_verified_at' => null,
    ]);

    $target->sendEmailVerificationNotification();

    Queue::assertPushed(SendQueuedNotifications::class, function (SendQueuedNotifications $job) use ($target): bool {
        return $job->notification::class === 'Cms\\Core\\Notifications\\QueuedVerifyEmail'
            && $job->afterCommit === true
            && $job->notifiables->contains(fn(User $user): bool => $user->is($target));
    });
});

test('admin cannot queue email verification for an already verified user', function () {
    Queue::fake();

    $admin = makeEmailVerificationAdminApiUser();
    grantEmailVerificationUserUpdatePermission($admin);
    $target = makeEmailVerificationAdminApiUser([
        'name' => 'Verified User',
        'username' => 'verified-' . Str::lower(Str::random(6)),
        'email' => 'verified-' . Str::lower(Str::random(6)) . '@example.com',
        'level' => 99,
    ]);

    $this->actingAs($admin, 'sanctum')
        ->postJson("/api/v2/admin/user/{$target->uuid}/email-verification/resend")
        ->assertStatus(400)
        ->assertJsonPath('status', 'error');

    Queue::assertNothingPushed();
});

test('email verification resend requires user update permission', function () {
    Queue::fake();

    $admin = makeEmailVerificationAdminApiUser();
    $target = makeEmailVerificationAdminApiUser([
        'name' => 'Unverified User',
        'username' => 'permission-target-' . Str::lower(Str::random(6)),
        'email' => 'permission-target-' . Str::lower(Str::random(6)) . '@example.com',
        'email_verified_at' => null,
        'level' => 99,
    ]);

    $this->actingAs($admin, 'sanctum')
        ->postJson("/api/v2/admin/user/{$target->uuid}/email-verification/resend")
        ->assertStatus(400)
        ->assertJsonPath('message', __('mgs.no.permission'));

    Queue::assertNothingPushed();
});

test('email verification resend cannot target a higher privilege account', function () {
    Queue::fake();

    $admin = makeEmailVerificationAdminApiUser(['level' => 99]);
    grantEmailVerificationUserUpdatePermission($admin);
    $target = makeEmailVerificationAdminApiUser([
        'name' => 'Higher Privilege User',
        'username' => 'higher-privilege-' . Str::lower(Str::random(6)),
        'email' => 'higher-privilege-' . Str::lower(Str::random(6)) . '@example.com',
        'email_verified_at' => null,
        'level' => 0,
    ]);

    $this->actingAs($admin, 'sanctum')
        ->postJson("/api/v2/admin/user/{$target->uuid}/email-verification/resend")
        ->assertStatus(400)
        ->assertJsonPath('message', __('mgs.no.permission'));

    Queue::assertNothingPushed();
});

test('email verification resend requires authentication', function () {
    Queue::fake();

    $target = makeEmailVerificationAdminApiUser([
        'name' => 'Unverified User',
        'username' => 'guest-target-' . Str::lower(Str::random(6)),
        'email' => 'guest-target-' . Str::lower(Str::random(6)) . '@example.com',
        'email_verified_at' => null,
        'level' => 99,
    ]);

    $this->postJson("/api/v2/admin/user/{$target->uuid}/email-verification/resend")
        ->assertUnauthorized();

    Queue::assertNothingPushed();
});
