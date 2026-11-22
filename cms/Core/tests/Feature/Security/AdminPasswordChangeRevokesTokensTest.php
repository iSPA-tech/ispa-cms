<?php
/*
 * Copyright (c) 2026 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

declare(strict_types=1);

/**
 * security review: the three admin password writers end the API tokens of the account, as the Account password
 * services already do. A password change in the admin keeps the token in use and ends the others; a Webmaster
 * who sets another account's password, and the forgot-password reset, end every token of that account. Without this a
 * stolen admin token survived a password change, which is the owner's way out when the thief keeps the step-up locked.
 */

use App\Constants\UserType;
use App\Models\PasswordReset;
use App\Models\User;
use Cms\Core\Models\ApiAccessToken;
use Cms\Core\Models\Permission;
use Cms\Core\Models\Role;
use Cms\Core\Services\Security\ApiTokenIssuer;
use Cms\Core\Services\Security\TokenAudience;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

uses(Cms\Core\Tests\TestCase::class, RefreshDatabase::class);

const APR_PASSWORD = 'Correct-Horse-7';
const APR_NEW_PASSWORD = 'New-Stable-Pass-9!';

/** Create an account; the first call also creates a Webmaster filler as user id 1. */
function aprUser(int $type = UserType::ADMIN, int $level = 5): User
{
    if (User::query()->count() === 0) {
        User::query()->forceCreate([
            'uuid' => (string) Str::uuid(), 'name' => 'filler', 'username' => 'filler-' . Str::random(5),
            'email' => 'filler-' . Str::random(5) . '@example.com', 'email_verified_at' => now(),
            'password' => Hash::make('filler-password'), 'type' => UserType::WEBMASTER, 'level' => 0, 'status' => null,
        ]);
    }
    $user = User::query()->forceCreate([
        'uuid' => (string) Str::uuid(), 'name' => 'person', 'username' => 'person-' . Str::random(5),
        'email' => 'person-' . Str::lower(Str::random(6)) . '@example.com', 'email_verified_at' => now(),
        'password' => Hash::make(APR_PASSWORD), 'type' => $type, 'level' => $level, 'status' => null,
    ]);
    if ($type === UserType::WEBMASTER) {
        $user->roles()->attach(Role::query()->create(['name' => 'wm ' . Str::random(4), 'custom' => ['level' => 0]]));
    }

    return $user;
}

/** Issue a token of the account from its own device. */
function aprToken(User $user, string $audience = TokenAudience::ADMIN): string
{
    $request = Request::create('/', 'POST', [], [], [], ['REMOTE_ADDR' => '203.0.113.' . random_int(1, 200), 'HTTP_USER_AGENT' => 'Agent ' . Str::random(6)]);

    return app(ApiTokenIssuer::class)->issue($user, $audience, 'device-' . Str::random(6), ['*'], $request)->plainTextToken;
}

/** Whether the token row of a plain token still exists. */
function aprAlive(string $plain): bool
{
    return ApiAccessToken::query()->whereKey((int) Str::before($plain, '|'))->exists();
}

/** Call a route with a bearer token (guards reset so the call authenticates its own token). */
function aprCall(?string $token, string $method, string $url, array $body = []): TestResponse
{
    app('auth')->forgetGuards();
    $test = test();

    return ($token !== null ? $test->withToken($token) : $test)->json($method, $url, $body);
}

beforeEach(function (): void {
    Event::fake();
});

test('an admin password change keeps the token in use and ends every other token of the account', function (): void {
    $user = aprUser();
    $current = aprToken($user);
    $other = aprToken($user);
    $account = aprToken($user, TokenAudience::ACCOUNT);
    $stranger = aprToken(aprUser());

    aprCall($current, 'POST', 'https://localhost/api/v2/admin/account/change-password', [
        'current_password' => APR_PASSWORD, 'password' => APR_NEW_PASSWORD, 'password_confirmation' => APR_NEW_PASSWORD,
    ])->assertOk();

    expect(Hash::check(APR_NEW_PASSWORD, (string) $user->fresh()->password))->toBeTrue()
        ->and(aprAlive($current))->toBeTrue()
        ->and(aprAlive($other))->toBeFalse()
        ->and(aprAlive($account))->toBeFalse()
        ->and(aprAlive($stranger))->toBeTrue();
});

test('a wrong current password changes nothing and ends no token', function (): void {
    $user = aprUser();
    $current = aprToken($user);
    $other = aprToken($user);

    aprCall($current, 'POST', 'https://localhost/api/v2/admin/account/change-password', [
        'current_password' => 'Wrong-Pass-1!', 'password' => APR_NEW_PASSWORD, 'password_confirmation' => APR_NEW_PASSWORD,
    ]);

    expect(aprAlive($other))->toBeTrue();
});

test('a Webmaster who sets the password of another account ends every token of that account', function (): void {
    $webmaster = aprUser(UserType::WEBMASTER, 0);
    $role = Role::query()->create(['name' => 'password setter ' . Str::random(4)]);
    $role->permissions()->attach(Permission::query()->create(['group' => 'user', 'name' => 'password', 'title' => 'Set passwords']));
    $webmaster->roles()->attach($role);
    $own = aprToken($webmaster);
    $target = aprUser(UserType::ADMIN, 5);
    $first = aprToken($target);
    $second = aprToken($target, TokenAudience::ACCOUNT);

    aprCall($own, 'PUT', 'https://localhost/api/v2/admin/user/' . $target->uuid . '/password', [
        'auto_password' => false, 'password' => APR_NEW_PASSWORD, 'password_confirmation' => APR_NEW_PASSWORD,
    ])->assertOk();

    expect(Hash::check(APR_NEW_PASSWORD, (string) $target->fresh()->password))->toBeTrue()
        ->and(aprAlive($first))->toBeFalse()
        ->and(aprAlive($second))->toBeFalse()
        ->and(aprAlive($own))->toBeTrue();
});

test('the forgot-password reset ends every token of the account', function (): void {
    $user = aprUser();
    $first = aprToken($user);
    $second = aprToken($user, TokenAudience::ACCOUNT);
    $stranger = aprToken(aprUser());
    $code = PasswordReset::query()->forceCreate([
        'user_id' => $user->getKey(), 'code' => '123456', 'token' => Str::random(40),
        'valid_to' => now()->addMinutes(10), 'checked_at' => now(),
    ]);

    aprCall(null, 'POST', 'https://localhost/api/v2/admin/auth/set-password', [
        'token' => $code->token, 'password' => APR_NEW_PASSWORD, 'password_confirmation' => APR_NEW_PASSWORD,
    ])->assertOk();

    expect(Hash::check(APR_NEW_PASSWORD, (string) $user->fresh()->password))->toBeTrue()
        ->and(aprAlive($first))->toBeFalse()
        ->and(aprAlive($second))->toBeFalse()
        ->and(aprAlive($stranger))->toBeTrue();
});
