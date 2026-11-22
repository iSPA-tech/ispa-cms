<?php
/*
 * Copyright (c) 2026 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

declare(strict_types=1);

/**
 * step-up confirmation before revoking tokens, admin routes. For every kind of account (password only;
 * password and authenticator; password and e-mail code; a recovery code; no password, e-mail code only) the right proofs
 * give a grant; wrong proofs share one 422; the grant works only for its user, its token and its purpose, expires on
 * time, is single use where the route consumes it and dies with its token; a failing cache fails closed; no password,
 * code, grant or hash reaches a later response, a log line or an audit row.
 */

use App\Constants\UserType;
use App\Models\User;
use Cms\Core\Events\AuditLogEvent;
use Cms\Core\Events\SendCodeVerify;
use Cms\Core\Models\ApiAccessToken;
use Cms\Core\Models\Role;
use Cms\Core\Services\Security\AdminMfaService;
use Cms\Core\Services\Security\ApiTokenIssuer;
use Cms\Core\Services\Security\StepUpConfirmation;
use Cms\Core\Services\Security\TokenAudience;
use Cms\Core\Services\Security\TokenRevoker;
use Cms\Core\Services\Security\TokenSettings;
use Cms\Core\Services\Security\TotpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;

uses(Cms\Core\Tests\TestCase::class, RefreshDatabase::class);

const SUC_BASE = 'https://localhost/api/v2/admin/security/step-up';
const SUC_APPLY = 'https://localhost/api/v2/admin/security/token-settings/apply-to-existing';
const SUC_PASSWORD = 'Correct-Horse-7';

/** Create an account; user id 1 is always a Webmaster filler. A null password makes a social-first account. */
function sucUser(int $type = UserType::WEBMASTER, ?string $password = SUC_PASSWORD, bool $webmasterRole = true): User
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
    $user = User::query()->forceCreate([
        'uuid' => (string) Str::uuid(), 'name' => 'person', 'username' => 'person-' . Str::random(5),
        'email' => 'person-' . Str::lower(Str::random(6)) . '@example.com', 'email_verified_at' => now(),
        'password' => $password === null ? null : Hash::make($password), 'type' => $type, 'level' => 0, 'status' => null,
    ]);
    if ($webmasterRole && $type === UserType::WEBMASTER) {
        $user->roles()->attach(Role::query()->create(['name' => 'wm ' . Str::random(4), 'custom' => ['level' => 0]]));
    }

    return $user;
}

/** Issue a real token for the account from its own address (so tokens never replace each other). */
function sucToken(User $user, string $audience = TokenAudience::ADMIN, string $ip = '203.0.113.9'): string
{
    $request = Request::create('/', 'POST', [], [], [], ['REMOTE_ADDR' => $ip, 'HTTP_USER_AGENT' => 'Test agent ' . Str::random(6)]);

    return app(ApiTokenIssuer::class)->issue($user, $audience, 'device-' . Str::random(6), ['*'], $request)->plainTextToken;
}

/** Token row of a plain token. */
function sucTokenRow(string $plain): ApiAccessToken
{
    return ApiAccessToken::query()->findOrFail((int) Str::before($plain, '|'));
}

/** Call a route with a bearer token; the guards are reset so every call authenticates its own token. */
function sucCall(string $token, string $method, string $url, array $body = [], array $headers = []): TestResponse
{
    app('auth')->forgetGuards();

    return test()->withToken($token)->json($method, $url, $body, $headers);
}

/** Enable an authenticator for the account and set its sign-in mode; returns the secret and the recovery codes. */
function sucEnableTotp(User $user, string $mode = AdminMfaService::MODE_TOTP_ONLY): array
{
    $mfa = app(AdminMfaService::class);
    $enroll = $mfa->startAuthenticatorEnrollment($user->fresh());
    $result = $mfa->confirmAuthenticatorEnrollment($user->fresh(), app(TotpService::class)->now($enroll['secret']));
    $mfa->persistMfaMode($user->fresh(), $mode);

    return ['secret' => $enroll['secret'], 'recovery' => $result['recovery_codes']];
}

/** Code of the last step-up e-mail. */
function sucLastEmailCode(): string
{
    $events = Event::dispatched(SendCodeVerify::class);
    expect($events)->not->toBeEmpty();

    return (string) $events->last()[0]->code;
}

beforeEach(function (): void {
    Event::fake([SendCodeVerify::class]);
});

test('password only: the right password gives a grant at once', function (): void {
    $user = sucUser();
    $token = sucToken($user);

    $status = sucCall($token, 'GET', SUC_BASE . '/status')->assertOk();
    expect($status->json('data'))->toMatchArray([
        'possible' => true, 'password_required' => true, 'second_factor' => [], 'locked' => false, 'grant_minutes' => 5,
    ]);

    $response = sucCall($token, 'POST', SUC_BASE, ['password' => SUC_PASSWORD])->assertOk();
    expect($response->json('data.status'))->toBe('confirmed')
        ->and(strlen((string) $response->json('data.grant')))->toBe(40)
        ->and($response->json('data.expires_in'))->toBe(300)
        ->and($response->json('data.purpose'))->toBe('revoke_tokens')
        ->and($response->json('data.header'))->toBe('X-Step-Up-Grant');
});

test('password and authenticator: the password opens the second step, the authenticator code gives the grant', function (): void {
    $user = sucUser();
    $totp = sucEnableTotp($user);
    $token = sucToken($user);

    expect(sucCall($token, 'GET', SUC_BASE . '/status')->json('data.second_factor'))->toBe(['totp']);

    $step = sucCall($token, 'POST', SUC_BASE, ['password' => SUC_PASSWORD])->assertOk();
    expect($step->json('data'))->toMatchArray(['status' => 'second_factor_required', 'method' => 'totp', 'can_use_recovery_code' => true])
        ->and($step->json('data.grant'))->toBeNull();

    $done = sucCall($token, 'POST', SUC_BASE . '/second-factor', [
        'challenge_id' => $step->json('data.challenge_id'),
        'code' => app(TotpService::class)->now($totp['secret']),
    ])->assertOk();
    expect($done->json('data.status'))->toBe('confirmed')->and(strlen((string) $done->json('data.grant')))->toBe(40);
});

test('an enabled authenticator is required even when the sign-in mode does not ask for it', function (): void {
    $user = sucUser();
    sucEnableTotp($user, AdminMfaService::MODE_OFF);
    $token = sucToken($user);

    $step = sucCall($token, 'POST', SUC_BASE, ['password' => SUC_PASSWORD])->assertOk();
    expect($step->json('data.method'))->toBe('totp');
});

test('password and e-mail code: a code is mailed, the right code gives the grant, a resend replaces the old code', function (): void {
    $user = sucUser();
    app(AdminMfaService::class)->persistMfaMode($user, AdminMfaService::MODE_EMAIL_ONLY);
    $token = sucToken($user);

    $step = sucCall($token, 'POST', SUC_BASE, ['password' => SUC_PASSWORD])->assertOk();
    expect($step->json('data.method'))->toBe('email_code')->and($step->json('data.email_code_expires_at'))->not->toBeNull();
    Event::assertDispatched(SendCodeVerify::class, fn (SendCodeVerify $e): bool => $e->type === 'two_fa' && $e->user->is($user));
    $first = sucLastEmailCode();

    $resent = sucCall($token, 'POST', SUC_BASE . '/email-code', ['challenge_id' => $step->json('data.challenge_id')])->assertOk();
    $second = sucLastEmailCode();
    expect($resent->json('data.challenge_id'))->toBe($step->json('data.challenge_id'));
    Event::assertDispatchedTimes(SendCodeVerify::class, 2);

    if ($first !== $second) {
        sucCall($token, 'POST', SUC_BASE . '/second-factor', ['challenge_id' => $step->json('data.challenge_id'), 'code' => $first])
            ->assertStatus(422)->assertJsonPath('error_code', 'step_up_failed');
    }
    $done = sucCall($token, 'POST', SUC_BASE . '/second-factor', ['challenge_id' => $step->json('data.challenge_id'), 'code' => $second])->assertOk();
    expect($done->json('data.status'))->toBe('confirmed');
});

test('e-mail plus authenticator: both steps in order', function (): void {
    $user = sucUser();
    $totp = sucEnableTotp($user, AdminMfaService::MODE_EMAIL_PLUS_TOTP);
    $token = sucToken($user);

    $step = sucCall($token, 'POST', SUC_BASE, ['password' => SUC_PASSWORD])->assertOk();
    expect($step->json('data.methods'))->toBe(['email_code', 'totp'])->and($step->json('data.method'))->toBe('email_code');
    $next = sucCall($token, 'POST', SUC_BASE . '/second-factor', ['challenge_id' => $step->json('data.challenge_id'), 'code' => sucLastEmailCode()])->assertOk();
    expect($next->json('data.method'))->toBe('totp');
    $done = sucCall($token, 'POST', SUC_BASE . '/second-factor', [
        'challenge_id' => $step->json('data.challenge_id'), 'code' => app(TotpService::class)->now($totp['secret']),
    ])->assertOk();
    expect($done->json('data.status'))->toBe('confirmed');
});

test('a recovery code replaces the authenticator code once', function (): void {
    $user = sucUser();
    $totp = sucEnableTotp($user);
    $token = sucToken($user);
    $code = $totp['recovery'][0];

    $step = sucCall($token, 'POST', SUC_BASE, ['password' => SUC_PASSWORD])->assertOk();
    $done = sucCall($token, 'POST', SUC_BASE . '/second-factor', ['challenge_id' => $step->json('data.challenge_id'), 'code' => strtolower($code), 'method' => 'recovery_code'])->assertOk();
    expect($done->json('data.status'))->toBe('confirmed');

    $again = sucCall($token, 'POST', SUC_BASE, ['password' => SUC_PASSWORD])->assertOk();
    sucCall($token, 'POST', SUC_BASE . '/second-factor', ['challenge_id' => $again->json('data.challenge_id'), 'code' => $code, 'method' => 'recovery_code'])
        ->assertStatus(422)->assertJsonPath('error_code', 'step_up_failed');
});

test('no password: the account confirms with an e-mail code only', function (): void {
    $user = sucUser(UserType::WEBMASTER, null);
    $token = sucToken($user);

    $status = sucCall($token, 'GET', SUC_BASE . '/status')->assertOk();
    expect($status->json('data'))->toMatchArray(['password_required' => false, 'second_factor' => ['email_code']]);

    $step = sucCall($token, 'POST', SUC_BASE . '/email-code')->assertOk();
    expect($step->json('data.method'))->toBe('email_code');
    $done = sucCall($token, 'POST', SUC_BASE . '/second-factor', ['challenge_id' => $step->json('data.challenge_id'), 'code' => sucLastEmailCode()])->assertOk();
    expect($done->json('data.status'))->toBe('confirmed');
});

test('an account with a password must send it, and cannot skip it through the e-mail route', function (): void {
    $user = sucUser();
    $token = sucToken($user);

    sucCall($token, 'POST', SUC_BASE)->assertStatus(422)->assertJsonPath('error_code', 'step_up_password_required');
    sucCall($token, 'POST', SUC_BASE . '/email-code')->assertStatus(422)->assertJsonPath('error_code', 'step_up_password_required');
    Event::assertNotDispatched(SendCodeVerify::class);
});

test('a wrong password and a wrong code answer the same 422 wording', function (): void {
    $user = sucUser();
    sucEnableTotp($user);
    $token = sucToken($user);

    $wrongPassword = sucCall($token, 'POST', SUC_BASE, ['password' => 'nope'])->assertStatus(422);
    $step = sucCall($token, 'POST', SUC_BASE, ['password' => SUC_PASSWORD])->assertOk();
    $wrongCode = sucCall($token, 'POST', SUC_BASE . '/second-factor', ['challenge_id' => $step->json('data.challenge_id'), 'code' => '12345'])->assertStatus(422);
    $unknown = sucCall($token, 'POST', SUC_BASE . '/second-factor', ['challenge_id' => (string) Str::uuid(), 'code' => '123456'])->assertStatus(422);

    expect($wrongPassword->json())->toBe($wrongCode->json())->and($wrongCode->json())->toBe($unknown->json())
        ->and($wrongPassword->json('error_code'))->toBe('step_up_failed');
});

test('a confirmation opened by one token cannot be finished by another token of the same account', function (): void {
    $user = sucUser();
    $totp = sucEnableTotp($user);
    $first = sucToken($user, TokenAudience::ADMIN, '203.0.113.9');
    $second = sucToken($user, TokenAudience::ADMIN, '198.51.100.4');

    $step = sucCall($first, 'POST', SUC_BASE, ['password' => SUC_PASSWORD])->assertOk();
    sucCall($second, 'POST', SUC_BASE . '/second-factor', [
        'challenge_id' => $step->json('data.challenge_id'), 'code' => app(TotpService::class)->now($totp['secret']),
    ])->assertStatus(422)->assertJsonPath('error_code', 'step_up_failed');
});

test('the second-factor route needs a confirmation id and a code', function (): void {
    $token = sucToken(sucUser());

    sucCall($token, 'POST', SUC_BASE . '/second-factor', [])->assertStatus(422)->assertJsonValidationErrors(['challenge_id', 'code']);
});

test('the admin routes need a signed-in operator and a personal access token', function (): void {
    $this->postJson(SUC_BASE, ['password' => SUC_PASSWORD])->assertStatus(401);

    $client = sucUser(UserType::CLIENT);
    $status = sucCall(sucToken($client, TokenAudience::ACCOUNT), 'POST', SUC_BASE, ['password' => SUC_PASSWORD])->getStatusCode();
    expect($status)->toBeIn([401, 403]);

    // A transient token (no personal access token) cannot hold a grant.
    $user = sucUser();
    app('auth')->forgetGuards();
    $this->withoutToken();
    Sanctum::actingAs($user->fresh(), ['*']);
    $this->getJson(SUC_BASE . '/status')->assertOk()->assertJsonPath('data.possible', false);
    $this->postJson(SUC_BASE, ['password' => SUC_PASSWORD])->assertStatus(422)->assertJsonPath('error_code', 'step_up_not_possible');
});

test('a grant works only for its user, its token and its purpose, and expires on time', function (): void {
    $user = sucUser();
    $other = sucUser();
    $plain = sucToken($user, TokenAudience::ADMIN, '203.0.113.9');
    $sibling = sucTokenRow(sucToken($user, TokenAudience::ADMIN, '198.51.100.4'));
    $foreign = sucTokenRow(sucToken($other));
    $token = sucTokenRow($plain);
    $grant = (string) sucCall($plain, 'POST', SUC_BASE, ['password' => SUC_PASSWORD])->json('data.grant');
    $service = app(StepUpConfirmation::class);

    expect($service->checkGrant($user, $token, $grant, 'revoke_tokens', false))->toBeTrue()
        ->and($service->checkGrant($user, $token, $grant, 'revoke_tokens', false))->toBeTrue()
        ->and($service->checkGrant($user, $sibling, $grant, 'revoke_tokens', false))->toBeFalse()
        ->and($service->checkGrant($other, $foreign, $grant, 'revoke_tokens', false))->toBeFalse()
        ->and($service->checkGrant($user, $token, $grant, 'something_else', false))->toBeFalse()
        ->and($service->checkGrant($user, $token, Str::random(40), 'revoke_tokens', false))->toBeFalse()
        ->and($service->checkGrant($user, $token, null, 'revoke_tokens', false))->toBeFalse();

    $this->travel(5)->minutes();
    $this->travel(1)->seconds();
    expect($service->checkGrant($user, $token, $grant, 'revoke_tokens', false))->toBeFalse();
});

test('a consumed grant is single use', function (): void {
    $user = sucUser();
    $plain = sucToken($user);
    $token = sucTokenRow($plain);
    $grant = (string) sucCall($plain, 'POST', SUC_BASE, ['password' => SUC_PASSWORD])->json('data.grant');
    $service = app(StepUpConfirmation::class);

    expect($service->checkGrant($user, $token, $grant, 'revoke_tokens', true))->toBeTrue()
        ->and($service->checkGrant($user, $token, $grant, 'revoke_tokens', true))->toBeFalse()
        ->and($service->checkGrant($user, $token, $grant, 'revoke_tokens', false))->toBeFalse();
});

test('apply-to-existing answers 403 step_up_required without a grant, consumes the grant, and a grant dies with its token', function (): void {
    $user = sucUser();
    $plain = sucToken($user, TokenAudience::ADMIN, '203.0.113.9');
    $sibling = sucToken($user, TokenAudience::ADMIN, '198.51.100.4');
    (new TokenSettings())->update(['lifetime' => ['account' => '30d']]);
    $client = sucUser(UserType::CLIENT);
    sucToken($client, TokenAudience::ACCOUNT);
    $before = ApiAccessToken::query()->whereNotNull('expires_at')->count();

    sucCall($plain, 'POST', SUC_APPLY, ['audience' => 'account'])->assertStatus(403)->assertJsonPath('error_code', 'step_up_required');
    sucCall($plain, 'POST', SUC_APPLY, ['audience' => 'account'], ['X-Step-Up-Grant' => Str::random(40)])->assertStatus(403);
    expect(ApiAccessToken::query()->whereNotNull('expires_at')->count())->toBe($before);

    $grant = (string) sucCall($plain, 'POST', SUC_BASE, ['password' => SUC_PASSWORD])->json('data.grant');
    sucCall($sibling, 'POST', SUC_APPLY, ['audience' => 'account'], ['X-Step-Up-Grant' => $grant])->assertStatus(403);
    sucCall($plain, 'POST', SUC_APPLY, ['audience' => 'account'], ['X-Step-Up-Grant' => $grant])->assertOk()->assertJsonPath('data.set', 1);
    sucCall($plain, 'POST', SUC_APPLY, ['audience' => 'account'], ['X-Step-Up-Grant' => $grant])->assertStatus(403)->assertJsonPath('error_code', 'step_up_required');

    // A fresh grant of a token that is then revoked is useless: the token no longer signs in, and no other token may use it.
    $grant = (string) sucCall($plain, 'POST', SUC_BASE, ['password' => SUC_PASSWORD])->json('data.grant');
    app(TokenRevoker::class)->revokeToken(sucTokenRow($plain), null);
    sucCall($plain, 'POST', SUC_APPLY, ['audience' => 'account'], ['X-Step-Up-Grant' => $grant])->assertStatus(401);
    sucCall($sibling, 'POST', SUC_APPLY, ['audience' => 'account'], ['X-Step-Up-Grant' => $grant])->assertStatus(403);
});

test('a failing cache fails closed: 503 and no grant, on the confirmation and on a protected route', function (): void {
    $user = sucUser();
    $plain = sucToken($user);
    $grant = (string) sucCall($plain, 'POST', SUC_BASE, ['password' => SUC_PASSWORD])->json('data.grant');

    config(['cache.stores.stepup-broken' => ['driver' => 'stepup-missing-driver'], 'core.token_security.stepup.cache_store' => 'stepup-broken']);

    $response = sucCall($plain, 'POST', SUC_BASE, ['password' => SUC_PASSWORD])->assertStatus(503)->assertJsonPath('error_code', 'step_up_unavailable');
    expect($response->json('data'))->toBeNull();
    sucCall($plain, 'GET', SUC_BASE . '/status')->assertStatus(503);
    sucCall($plain, 'POST', SUC_APPLY, ['audience' => 'account'], ['X-Step-Up-Grant' => $grant])->assertStatus(503);
});

test('no password, code, grant or hash appears in later responses, logs or audit rows; audit events carry the user uuid', function (): void {
    $logged = [];
    Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$logged): void {
        $logged[] = $event->message . ' ' . json_encode($event->context);
    });
    $user = sucUser();
    app(AdminMfaService::class)->persistMfaMode($user, AdminMfaService::MODE_EMAIL_ONLY);
    $plain = sucToken($user);
    $bodies = [];

    $bodies[] = sucCall($plain, 'POST', SUC_BASE, ['password' => 'wrong-' . SUC_PASSWORD])->getContent();
    $step = sucCall($plain, 'POST', SUC_BASE, ['password' => SUC_PASSWORD]);
    $bodies[] = $step->getContent();
    $code = sucLastEmailCode();
    $bodies[] = sucCall($plain, 'POST', SUC_BASE . '/second-factor', ['challenge_id' => $step->json('data.challenge_id'), 'code' => '000000' === $code ? '111111' : '000000'])->getContent();
    $done = sucCall($plain, 'POST', SUC_BASE . '/second-factor', ['challenge_id' => $step->json('data.challenge_id'), 'code' => $code]);
    $grant = (string) $done->json('data.grant');
    $bodies[] = sucCall($plain, 'GET', SUC_BASE . '/status')->getContent();
    $bodies[] = sucCall($plain, 'POST', SUC_APPLY, ['audience' => 'admin'], ['X-Step-Up-Grant' => $grant])->getContent();

    $audit = DB::table('audit_logs')->where('action', 'like', 'action.security.stepup.%')->get();
    expect($audit->pluck('action')->unique()->sort()->values()->all())->toBe(['action.security.stepup.failed', 'action.security.stepup.succeeded']);
    foreach ($audit as $row) {
        expect(json_decode((string) $row->changed, true)['user'] ?? null)->toBe((string) $user->uuid);
    }

    $haystack = implode("\n", $bodies) . "\n" . implode("\n", $logged) . "\n" . $audit->toJson()
        . "\n" . DB::table('audit_logs')->get()->toJson();
    foreach ([SUC_PASSWORD, $grant, hash('sha256', $grant), (string) $user->getAttributes()['password']] as $secret) {
        expect($haystack)->not->toContain($secret);
    }
    // A six-digit code is matched as a whole token, so digits inside uuids or times do not count.
    $codePattern = '/(?<![0-9A-Za-z])' . $code . '(?![0-9A-Za-z])/';
    expect(preg_match($codePattern, $haystack . "\n" . $done->getContent()))->toBe(0);
});
