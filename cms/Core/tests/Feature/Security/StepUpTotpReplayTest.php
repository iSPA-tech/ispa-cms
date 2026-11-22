<?php
/*
 * Copyright (c) 2026 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

declare(strict_types=1);

/**
 * security review: an authenticator code is accepted once by the step-up confirmation. Replaying the code that
 * already gave a grant (or the code the person just typed at sign-in) inside its 30-second window is refused, while a
 * code of a later time step still works.
 */

use App\Constants\UserType;
use App\Models\User;
use Cms\Core\Events\SendCodeVerify;
use Cms\Core\Models\Role;
use Cms\Core\Models\UserMfaFactor;
use Cms\Core\Services\Security\AdminMfaService;
use Cms\Core\Services\Security\ApiTokenIssuer;
use Cms\Core\Services\Security\TokenAudience;
use Cms\Core\Services\Security\TotpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

uses(Cms\Core\Tests\TestCase::class, RefreshDatabase::class);

const STR_BASE = 'https://localhost/api/v2/admin/security/step-up';
const STR_PASSWORD = 'Correct-Horse-7';

/** A Webmaster with an enabled authenticator; returns the account, a token and the authenticator secret. */
function strAccount(): array
{
    User::query()->forceCreate([
        'uuid' => (string) Str::uuid(), 'name' => 'filler', 'username' => 'filler-' . Str::random(5),
        'email' => 'filler-' . Str::random(5) . '@example.com', 'email_verified_at' => now(),
        'password' => Hash::make('filler-password'), 'type' => UserType::WEBMASTER, 'level' => 0, 'status' => null,
    ]);
    $user = User::query()->forceCreate([
        'uuid' => (string) Str::uuid(), 'name' => 'person', 'username' => 'person-' . Str::random(5),
        'email' => 'person-' . Str::lower(Str::random(6)) . '@example.com', 'email_verified_at' => now(),
        'password' => Hash::make(STR_PASSWORD), 'type' => UserType::WEBMASTER, 'level' => 0, 'status' => null,
    ]);
    $user->roles()->attach(Role::query()->create(['name' => 'wm ' . Str::random(4), 'custom' => ['level' => 0]]));

    $mfa = app(AdminMfaService::class);
    $enroll = $mfa->startAuthenticatorEnrollment($user->fresh());
    $mfa->confirmAuthenticatorEnrollment($user->fresh(), app(TotpService::class)->now($enroll['secret']));
    $mfa->persistMfaMode($user->fresh(), AdminMfaService::MODE_TOTP_ONLY);
    // Enrollment is not a use of a code; start from a factor that was last used long ago.
    UserMfaFactor::query()->where('user_id', $user->getKey())->update(['last_used_at' => now()->subHour()]);

    $request = Request::create('/', 'POST', [], [], [], ['REMOTE_ADDR' => '203.0.113.9', 'HTTP_USER_AGENT' => 'Test agent ' . Str::random(6)]);
    $token = app(ApiTokenIssuer::class)->issue($user, TokenAudience::ADMIN, 'device-' . Str::random(6), ['*'], $request)->plainTextToken;

    return [$user, $token, $enroll['secret']];
}

/** Call a route with a bearer token; the guards are reset so every call authenticates its own token. */
function strCall(string $token, string $method, string $url, array $body = []): TestResponse
{
    app('auth')->forgetGuards();

    return test()->withToken($token)->json($method, $url, $body);
}

/** Password step then the authenticator code; returns the second-factor response. */
function strConfirm(string $token, string $code): TestResponse
{
    $step = strCall($token, 'POST', STR_BASE, ['password' => STR_PASSWORD])->assertOk();
    expect($step->json('data.method'))->toBe('totp');

    return strCall($token, 'POST', STR_BASE . '/second-factor', ['challenge_id' => $step->json('data.challenge_id'), 'code' => $code]);
}

beforeEach(function (): void {
    Event::fake([SendCodeVerify::class]);
});

test('an authenticator code that already gave a grant cannot be replayed for a second grant', function (): void {
    [, $token, $secret] = strAccount();
    $code = app(TotpService::class)->now($secret);

    $first = strConfirm($token, $code)->assertOk();
    expect($first->json('data.status'))->toBe('confirmed');

    $replay = strConfirm($token, $code);
    $replay->assertStatus(422);
    expect($replay->json('data.grant'))->toBeNull();
});

test('the code the person just used at sign-in cannot be reused for the step-up', function (): void {
    [$user, $token, $secret] = strAccount();
    // The admin sign-in marks the factor as used when it accepts a code.
    UserMfaFactor::query()->where('user_id', $user->getKey())->update(['last_used_at' => now()]);

    strConfirm($token, app(TotpService::class)->now($secret))->assertStatus(422);
});

test('a code of a time step after the last use is accepted', function (): void {
    [$user, $token, $secret] = strAccount();
    UserMfaFactor::query()->where('user_id', $user->getKey())->update(['last_used_at' => now()->subSeconds(95)]);

    $done = strConfirm($token, app(TotpService::class)->now($secret))->assertOk();
    expect($done->json('data.status'))->toBe('confirmed')
        ->and(UserMfaFactor::query()->where('user_id', $user->getKey())->value('last_used_at'))->not->toBeNull();
});
