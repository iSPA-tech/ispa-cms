<?php
/*
 * Copyright (c) 2026 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

declare(strict_types=1);

/**
 * throttling and lockout of the step-up confirmation. Five failed attempts in fifteen minutes lock the
 * confirmation of the account (the sixth answers 429 with Retry-After even with the right password), across tokens,
 * addresses and restarted confirmations, without touching another account; the lock ends on time; the third lockout
 * within 24 hours lasts an hour; a success clears the counter; e-mail codes are capped at three per hour, live ten
 * minutes and allow five attempts; the lock writes one audit event and one in-app notice; a failing cache fails closed.
 */

use App\Constants\UserType;
use App\Models\User;
use Cms\Core\Events\SendCodeVerify;
use Cms\Core\Services\Security\AdminMfaService;
use Cms\Core\Services\Security\ApiTokenIssuer;
use Cms\Core\Services\Security\StepUpThrottle;
use Cms\Core\Services\Security\TokenAudience;
use Cms\Core\Services\Security\TotpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

uses(Cms\Core\Tests\TestCase::class, RefreshDatabase::class);

const SUT_BASE = 'https://localhost/api/v2/admin/security/step-up';
const SUT_PASSWORD = 'Battery-Staple-9';

/** Create a Webmaster-type account (user id 1 is a filler). A null password makes a social-first account. */
function sutUser(?string $password = SUT_PASSWORD): User
{
    if ($password === null && ! (collect(\Illuminate\Support\Facades\Schema::getColumns('users'))->firstWhere('name', 'password')['nullable'] ?? false)) {
        test()->markTestSkipped('Accounts without a password need the Account module, which makes users.password nullable.');
    }
    if (User::query()->count() === 0) {
        User::query()->forceCreate([
            'uuid' => (string) Str::uuid(), 'name' => 'filler', 'username' => 'filler-' . Str::random(5),
            'email' => 'filler-' . Str::random(5) . '@example.com', 'email_verified_at' => now(),
            'password' => Hash::make('filler-password'), 'type' => UserType::WEBMASTER, 'level' => 0, 'status' => null,
        ]);
    }

    return User::query()->forceCreate([
        'uuid' => (string) Str::uuid(), 'name' => 'person', 'username' => 'person-' . Str::random(5),
        'email' => 'person-' . Str::lower(Str::random(6)) . '@example.com', 'email_verified_at' => now(),
        'password' => $password === null ? null : Hash::make($password), 'type' => UserType::WEBMASTER, 'level' => 0, 'status' => null,
    ]);
}

/** Issue a real admin token for the account from the given address. */
function sutToken(User $user, string $ip = '203.0.113.9'): string
{
    $request = Request::create('/', 'POST', [], [], [], ['REMOTE_ADDR' => $ip, 'HTTP_USER_AGENT' => 'Test agent ' . Str::random(6)]);

    return app(ApiTokenIssuer::class)->issue($user, TokenAudience::ADMIN, 'device-' . Str::random(6), ['*'], $request)->plainTextToken;
}

/** Call a step-up route with a bearer token from an address; the guards are reset for every call. */
function sutCall(string $token, string $path, array $body = [], string $ip = '203.0.113.9', string $method = 'POST'): TestResponse
{
    app('auth')->forgetGuards();

    return test()->withToken($token)->withServerVariables(['REMOTE_ADDR' => $ip])->json($method, SUT_BASE . $path, $body);
}

/** Code of the last step-up e-mail. */
function sutLastEmailCode(): string
{
    return (string) Event::dispatched(SendCodeVerify::class)->last()[0]->code;
}

beforeEach(function (): void {
    Event::fake([SendCodeVerify::class]);
});

test('five failures lock the account across tokens and addresses; the sixth answers 429 even with the right password', function (): void {
    $user = sutUser();
    $tokens = [];
    foreach (range(1, 6) as $i) {
        $tokens[] = sutToken($user, '198.51.' . $i . '.7');
    }

    foreach (range(0, 3) as $i) {
        sutCall($tokens[$i], '', ['password' => 'wrong-' . $i], '198.51.' . ($i + 1) . '.7')->assertStatus(422)->assertJsonPath('error_code', 'step_up_failed');
    }
    $fifth = sutCall($tokens[4], '', ['password' => 'wrong-4'], '198.51.5.7')->assertStatus(429)->assertJsonPath('error_code', 'step_up_locked');
    expect((int) $fifth->headers->get('Retry-After'))->toBeGreaterThan(890)->toBeLessThanOrEqual(900);

    $sixth = sutCall($tokens[5], '', ['password' => SUT_PASSWORD], '198.51.6.7')->assertStatus(429)->assertJsonPath('error_code', 'step_up_locked');
    expect($sixth->headers->get('Retry-After'))->not->toBeNull()
        ->and($sixth->json('data'))->toBeNull();

    $status = sutCall($tokens[0], '/status', [], '198.51.1.7', 'GET')->assertOk();
    expect($status->json('data.locked'))->toBeTrue()->and($status->json('data.retry_after'))->toBeGreaterThan(0);
});

test('the lock of one account does not touch another account', function (): void {
    $victim = sutUser();
    $other = sutUser();
    $victimToken = sutToken($victim);
    foreach (range(1, 5) as $i) {
        sutCall($victimToken, '', ['password' => 'wrong']);
    }
    sutCall($victimToken, '', ['password' => SUT_PASSWORD])->assertStatus(429);

    sutCall(sutToken($other), '', ['password' => SUT_PASSWORD])->assertOk()->assertJsonPath('data.status', 'confirmed');
});

test('failures count across restarted confirmations, so a new challenge does not reset the counter', function (): void {
    $user = sutUser();
    $mfa = app(AdminMfaService::class);
    $enroll = $mfa->startAuthenticatorEnrollment($user->fresh());
    $mfa->confirmAuthenticatorEnrollment($user->fresh(), app(TotpService::class)->now($enroll['secret']));
    $mfa->persistMfaMode($user->fresh(), AdminMfaService::MODE_TOTP_ONLY);
    $token = sutToken($user);

    $first = sutCall($token, '', ['password' => SUT_PASSWORD])->assertOk();
    foreach (range(1, 3) as $_) {
        sutCall($token, '/second-factor', ['challenge_id' => $first->json('data.challenge_id'), 'code' => '12345'])->assertStatus(422);
    }
    $second = sutCall($token, '', ['password' => SUT_PASSWORD])->assertOk();
    sutCall($token, '/second-factor', ['challenge_id' => $second->json('data.challenge_id'), 'code' => '12345'])->assertStatus(422);
    sutCall($token, '/second-factor', ['challenge_id' => $second->json('data.challenge_id'), 'code' => '12345'])->assertStatus(429);
    sutCall($token, '/second-factor', [
        'challenge_id' => $second->json('data.challenge_id'), 'code' => app(TotpService::class)->now($enroll['secret']),
    ])->assertStatus(429);
});

test('the lock ends on time and a third lockout within 24 hours lasts an hour', function (): void {
    $user = sutUser();
    $token = sutToken($user);
    $lockOnce = function () use ($token): TestResponse {
        foreach (range(1, 4) as $_) {
            sutCall($token, '', ['password' => 'wrong'])->assertStatus(422);
        }

        return sutCall($token, '', ['password' => 'wrong'])->assertStatus(429);
    };

    $lockOnce();
    $this->travel(14)->minutes();
    sutCall($token, '', ['password' => SUT_PASSWORD])->assertStatus(429);
    $this->travel(61)->seconds();
    sutCall($token, '', ['password' => SUT_PASSWORD])->assertOk();

    $this->travel(1)->minutes();
    $lockOnce();
    $this->travel(16)->minutes();

    $third = $lockOnce();
    expect((int) $third->headers->get('Retry-After'))->toBeGreaterThan(3500);
    $this->travel(16)->minutes();
    sutCall($token, '', ['password' => SUT_PASSWORD])->assertStatus(429);
    $this->travel(45)->minutes();
    sutCall($token, '', ['password' => SUT_PASSWORD])->assertOk();
});

test('a success clears the failure counter', function (): void {
    $user = sutUser();
    $token = sutToken($user);

    foreach (range(1, 4) as $_) {
        sutCall($token, '', ['password' => 'wrong'])->assertStatus(422);
    }
    sutCall($token, '', ['password' => SUT_PASSWORD])->assertOk();
    foreach (range(1, 4) as $_) {
        sutCall($token, '', ['password' => 'wrong'])->assertStatus(422);
    }
});

test('the lock writes one locked audit event and queues one in-app notice to the owner', function (): void {
    $user = sutUser();
    $token = sutToken($user);
    foreach (range(1, 5) as $_) {
        sutCall($token, '', ['password' => 'wrong']);
    }
    sutCall($token, '', ['password' => 'wrong'])->assertStatus(429);

    expect(DB::table('audit_logs')->where('action', 'action.security.stepup.locked')->count())->toBe(1)
        ->and(DB::table('audit_logs')->where('action', 'action.security.stepup.failed')->count())->toBe(5);
    $notices = $user->fresh()->notifications()->get();
    expect($notices)->toHaveCount(1)
        ->and($notices->first()->data['type'])->toBe('security.stepup.locked')
        ->and($notices->first()->data['payload'])->toBe(['minutes' => 15]);
});

test('e-mail codes are capped at three sends per hour', function (): void {
    $user = sutUser(null);
    $token = sutToken($user);

    $step = sutCall($token, '/email-code')->assertOk();
    sutCall($token, '/email-code', ['challenge_id' => $step->json('data.challenge_id')])->assertOk();
    sutCall($token, '/email-code', ['challenge_id' => $step->json('data.challenge_id')])->assertOk();
    $limited = sutCall($token, '/email-code', ['challenge_id' => $step->json('data.challenge_id')])
        ->assertStatus(429)->assertJsonPath('error_code', 'step_up_email_limit');
    expect((int) $limited->headers->get('Retry-After'))->toBeGreaterThan(3500);
    Event::assertDispatchedTimes(SendCodeVerify::class, 3);
});

test('an e-mail code lives ten minutes', function (): void {
    config(['core.token_security.stepup.challenge_minutes' => 30]);
    $user = sutUser(null);
    $token = sutToken($user);
    $step = sutCall($token, '/email-code')->assertOk();
    $code = sutLastEmailCode();

    $this->travel(10)->minutes();
    $this->travel(1)->seconds();
    sutCall($token, '/second-factor', ['challenge_id' => $step->json('data.challenge_id'), 'code' => $code])
        ->assertStatus(422)->assertJsonPath('error_code', 'step_up_failed');
});

test('an e-mail code allows five attempts, then even the right code fails', function (): void {
    config(['core.token_security.stepup.max_failures' => 20]);
    $user = sutUser(null);
    $token = sutToken($user);
    $step = sutCall($token, '/email-code')->assertOk();
    $code = sutLastEmailCode();
    $wrong = $code === '000000' ? '111111' : '000000';

    foreach (range(1, 5) as $_) {
        sutCall($token, '/second-factor', ['challenge_id' => $step->json('data.challenge_id'), 'code' => $wrong])->assertStatus(422);
    }
    sutCall($token, '/second-factor', ['challenge_id' => $step->json('data.challenge_id'), 'code' => $code])->assertStatus(422);

    // A new code restores the attempts.
    sutCall($token, '/email-code', ['challenge_id' => $step->json('data.challenge_id')])->assertOk();
    sutCall($token, '/second-factor', ['challenge_id' => $step->json('data.challenge_id'), 'code' => sutLastEmailCode()])
        ->assertOk()->assertJsonPath('data.status', 'confirmed');
});

test('counters are keyed by a hash of the user id and a failing store fails closed', function (): void {
    $user = sutUser();
    $throttle = app(StepUpThrottle::class);
    $throttle->begin($user);
    $throttle->failed($user);

    $store = StepUpThrottle::store();
    $key = 'security:stepup:failures:' . hash_hmac('sha256', 'stepup-user|' . $user->getKey(), (string) config('app.key'));
    expect((int) $store->get($key))->toBe(1)
        ->and($store->get('security:stepup:failures:' . $user->getKey()))->toBeNull();

    config(['cache.stores.stepup-broken' => ['driver' => 'stepup-missing-driver'], 'core.token_security.stepup.cache_store' => 'stepup-broken']);
    sutCall(sutToken($user), '', ['password' => SUT_PASSWORD])->assertStatus(503)->assertJsonPath('error_code', 'step_up_unavailable');
    expect(fn () => $throttle->begin($user))->toThrow(\Cms\Core\Services\Security\StepUpException::class);
});
