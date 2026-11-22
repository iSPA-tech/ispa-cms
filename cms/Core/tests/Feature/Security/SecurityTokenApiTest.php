<?php
/*
 * Copyright (c) 2026 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

declare(strict_types=1);

/**
 * token list and revoke API of the admin side. `mine` shows only the caller's tokens and marks the one in use;
 * every revoke needs a step-up grant; a foreign uuid is a 404; the token in use is never revoked; `revoke-others` and
 * the bulk Webmaster routes consume the grant; the Webmaster-only routes refuse everybody else; nothing secret leaves.
 */

use App\Constants\UserType;
use App\Models\User;
use Cms\Core\Events\AuditLogEvent;
use Cms\Core\Models\ApiAccessToken;
use Cms\Core\Models\Role;
use Cms\Core\Services\Security\ApiTokenIssuer;
use Cms\Core\Services\Security\StepUpConfirmation;
use Cms\Core\Services\Security\TokenAudience;
use Cms\Core\Services\Security\TokenSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

uses(Cms\Core\Tests\TestCase::class, RefreshDatabase::class);

const STK_BASE = 'https://localhost/api/v2/admin/security/tokens';
const STK_PASSWORD = 'Correct-Horse-7';

/** Account of the given type; the first account created is a Webmaster filler (id 1). */
function stkUser(int $type = UserType::ADMIN, ?int $roleLevel = 50): User
{
    if (User::query()->count() === 0) {
        User::query()->forceCreate([
            'uuid' => (string) Str::uuid(), 'name' => 'filler', 'username' => 'filler-' . Str::random(5),
            'email' => 'filler-' . Str::random(5) . '@example.com', 'email_verified_at' => now(),
            'password' => Hash::make('filler-password'), 'type' => UserType::WEBMASTER, 'level' => 0, 'status' => null,
        ]);
    }
    $user = User::query()->forceCreate([
        'uuid' => (string) Str::uuid(), 'name' => 'person ' . Str::random(4), 'username' => 'person-' . Str::random(5),
        'email' => 'person-' . Str::lower(Str::random(6)) . '@example.com', 'email_verified_at' => now(),
        'password' => Hash::make(STK_PASSWORD), 'type' => $type, 'level' => 0, 'status' => null,
    ]);
    if ($roleLevel !== null && $type !== UserType::CLIENT) {
        $user->roles()->attach(Role::query()->create(['name' => 'role ' . Str::random(5), 'custom' => ['level' => $roleLevel]]));
    }

    return $user;
}

/** Issue a real token from its own address and device; returns the plain token. */
function stkToken(User $user, string $audience = TokenAudience::ADMIN, string $ip = '203.0.113.9'): string
{
    $request = Request::create('/', 'POST', [], [], [], ['REMOTE_ADDR' => $ip, 'HTTP_USER_AGENT' => 'Test agent ' . Str::random(6)]);

    return app(ApiTokenIssuer::class)->issue($user, $audience, 'device-' . Str::random(6), ['*'], $request)->plainTextToken;
}

/** Token row of a plain token. */
function stkRow(string $plain): ApiAccessToken
{
    return ApiAccessToken::query()->findOrFail((int) Str::before($plain, '|'));
}

/** Fresh step-up grant of the account for the token of the request. */
function stkGrant(User $user, string $plain): string
{
    return (string) app(StepUpConfirmation::class)->start($user->fresh(), stkRow($plain), STK_PASSWORD)['grant'];
}

/** Call a route with a bearer token; guards are reset so every call authenticates its own token. */
function stkCall(string $plain, string $method, string $url, array $body = [], ?string $grant = null): TestResponse
{
    app('auth')->forgetGuards();

    return test()->withToken($plain)->json($method, $url, $body, $grant === null ? [] : [StepUpConfirmation::HEADER => $grant]);
}

beforeEach(function (): void {
    $this->admin = stkUser();
    $this->adminToken = stkToken($this->admin);
    $this->otherToken = stkToken($this->admin, TokenAudience::ACCOUNT, '198.51.100.7');
});

test('mine lists only the tokens of the caller, marks the one in use and hides every secret', function (): void {
    $stranger = stkUser();
    stkToken($stranger);

    $response = stkCall($this->adminToken, 'GET', STK_BASE . '/mine')->assertOk();
    $rows = collect($response->json('data'));

    expect($rows)->toHaveCount(2)
        ->and($rows->where('current', true))->toHaveCount(1)
        ->and($rows->firstWhere('current', true)['uuid'])->toBe(stkRow($this->adminToken)->uuid)
        ->and($rows->pluck('uuid')->all())->not->toContain(ApiAccessToken::query()->where('tokenable_id', $stranger->id)->value('uuid'));
    $body = (string) $response->getContent();
    expect($body)->not->toContain(Str::after($this->adminToken, '|'))
        ->and($body)->not->toContain(stkRow($this->adminToken)->token)
        ->and($body)->not->toContain('203.0.113.9')
        ->and($rows->first())->not->toHaveKeys(['token', 'abilities', 'ip_address'])
        ->and($rows->firstWhere('current', true)['network'])->toBe('203.0.113.0/24');
});

test('every revoke route answers 403 step_up_required and deletes nothing without a grant', function (): void {
    $wm = stkUser(UserType::WEBMASTER, 0);
    $wmToken = stkToken($wm);
    $uuid = stkRow($this->otherToken)->uuid;
    $before = ApiAccessToken::query()->count();

    foreach ([
        [$this->adminToken, 'DELETE', STK_BASE . '/mine/' . $uuid, []],
        [$this->adminToken, 'POST', STK_BASE . '/mine/revoke-others', []],
        [$wmToken, 'DELETE', STK_BASE . '/' . $uuid, []],
        [$wmToken, 'POST', STK_BASE . '/revoke-user', ['user' => $this->admin->uuid, 'confirm' => 'REVOKE']],
        [$wmToken, 'POST', STK_BASE . '/revoke-audience', ['audience' => 'admin', 'confirm' => 'REVOKE']],
        [$wmToken, 'POST', STK_BASE . '/revoke-all', ['confirm' => 'REVOKE']],
    ] as [$token, $method, $url, $body]) {
        stkCall($token, $method, $url, $body)->assertStatus(403)->assertJsonPath('error_code', 'step_up_required');
    }
    expect(ApiAccessToken::query()->count())->toBe($before);
});

test('an operator with a grant ends another token of their account, never the one in use, and a foreign uuid is a 404', function (): void {
    $stranger = stkUser();
    $foreign = stkRow(stkToken($stranger))->uuid;
    $grant = stkGrant($this->admin, $this->adminToken);

    stkCall($this->adminToken, 'DELETE', STK_BASE . '/mine/' . $foreign, [], $grant)->assertStatus(404);
    expect(ApiAccessToken::query()->where('uuid', $foreign)->exists())->toBeTrue();

    $self = stkRow($this->adminToken)->uuid;
    stkCall($this->adminToken, 'DELETE', STK_BASE . '/mine/' . $self, [], $grant)
        ->assertStatus(422)->assertJsonPath('error_code', 'CURRENT_TOKEN_NOT_REVOCABLE');
    expect(ApiAccessToken::query()->where('uuid', $self)->exists())->toBeTrue();

    // A single revoke may reuse the grant.
    stkCall($this->adminToken, 'DELETE', STK_BASE . '/mine/' . stkRow($this->otherToken)->uuid, [], $grant)
        ->assertOk()->assertJsonPath('data.revoked', 1);
    expect(ApiAccessToken::query()->whereKey(stkRow($this->adminToken)->getKey())->exists())->toBeTrue();
});

test('revoke-others consumes the grant, keeps the token in use and answers with a count', function (): void {
    stkToken($this->admin, TokenAudience::CLIENT, '192.0.2.5');
    $stranger = stkUser();
    $strangerToken = stkToken($stranger);
    Event::fake([AuditLogEvent::class]);
    $grant = stkGrant($this->admin, $this->adminToken);

    stkCall($this->adminToken, 'POST', STK_BASE . '/mine/revoke-others', [], $grant)
        ->assertOk()->assertJsonPath('data.revoked', 2)->assertJsonPath('data.current_kept', true);
    expect(ApiAccessToken::query()->where('tokenable_id', $this->admin->id)->count())->toBe(1)
        ->and(ApiAccessToken::query()->whereKey(stkRow($strangerToken)->getKey())->exists())->toBeTrue();

    stkCall($this->adminToken, 'POST', STK_BASE . '/mine/revoke-others', [], $grant)->assertStatus(403);
    Event::assertDispatched(AuditLogEvent::class, fn (AuditLogEvent $e): bool => $e->action === 'security.tokens.revoked'
        && $e->changed['revoked'] === 2 && ! str_contains(json_encode($e->changed), '|'));
});

test('the Webmaster routes refuse an Admin, a client and tokens of other audiences', function (): void {
    $client = stkUser(UserType::CLIENT, null);
    $clientToken = stkToken($client, TokenAudience::ACCOUNT);
    $uuid = stkRow($this->otherToken)->uuid;
    $grant = stkGrant($this->admin, $this->adminToken);

    foreach ([
        ['GET', STK_BASE, []],
        ['DELETE', STK_BASE . '/' . $uuid, []],
        ['POST', STK_BASE . '/revoke-user', ['user' => $this->admin->uuid, 'confirm' => 'REVOKE']],
        ['POST', STK_BASE . '/revoke-audience', ['audience' => 'admin', 'confirm' => 'REVOKE']],
        ['POST', STK_BASE . '/revoke-all', ['confirm' => 'REVOKE']],
    ] as [$method, $url, $body]) {
        stkCall($this->adminToken, $method, $url, $body, $grant)->assertStatus(403);
        expect(stkCall($clientToken, $method, $url, $body)->getStatusCode())->toBeIn([401, 403]);
    }
    (new TokenSettings())->update(['mode' => 'enforce']);
    $wm = stkUser(UserType::WEBMASTER, 0);
    expect(stkCall(stkToken($wm, TokenAudience::ACCOUNT), 'GET', STK_BASE)->getStatusCode())->toBeIn([401, 403]);
    expect(ApiAccessToken::query()->count())->toBeGreaterThan(3);
});

test('the Webmaster lists everybody with filters and ends one token of another account', function (): void {
    $wm = stkUser(UserType::WEBMASTER, 0);
    $wmToken = stkToken($wm);
    $legacy = stkRow(stkToken($this->admin, TokenAudience::CLIENT, '192.0.2.6'));
    $legacy->forceFill(['audience' => null])->save();

    $all = stkCall($wmToken, 'GET', STK_BASE)->assertOk();
    expect($all->json('data.meta.total'))->toBe(4)
        ->and($all->json('data.items.0.user'))->toHaveKeys(['uuid', 'name'])
        ->and((string) $all->getContent())->not->toContain(Str::after($wmToken, '|'));
    expect(stkCall($wmToken, 'GET', STK_BASE . '?audience=account')->json('data.meta.total'))->toBe(1)
        ->and(stkCall($wmToken, 'GET', STK_BASE . '?legacy=1')->json('data.items.0.uuid'))->toBe($legacy->uuid)
        ->and(stkCall($wmToken, 'GET', STK_BASE . '?user=' . $wm->uuid)->json('data.meta.total'))->toBe(1)
        ->and(stkCall($wmToken, 'GET', STK_BASE . '?never_expiring=1')->json('data.meta.total'))->toBe(4)
        ->and(stkCall($wmToken, 'GET', STK_BASE . '?audience=nope')->getStatusCode())->toBe(422);

    $target = stkRow($this->otherToken)->uuid;
    stkCall($wmToken, 'DELETE', STK_BASE . '/' . $target, [], stkGrant($wm, $wmToken))->assertOk()->assertJsonPath('data.revoked', 1);
    stkCall($wmToken, 'DELETE', STK_BASE . '/' . $target, [], stkGrant($wm, $wmToken))->assertStatus(404);
    stkCall($wmToken, 'DELETE', STK_BASE . '/' . stkRow($wmToken)->uuid, [], stkGrant($wm, $wmToken))
        ->assertStatus(422)->assertJsonPath('error_code', 'CURRENT_TOKEN_NOT_REVOCABLE');
});

test('bulk Webmaster revokes need the typed word, consume the grant and spare the Webmaster token', function (): void {
    $wm = stkUser(UserType::WEBMASTER, 0);
    $wmToken = stkToken($wm);
    $wmOther = stkToken($wm, TokenAudience::ACCOUNT, '192.0.2.9');
    $grant = stkGrant($wm, $wmToken);

    // A wrong or missing word is refused (the grant is spent either way: fail closed).
    stkCall($wmToken, 'POST', STK_BASE . '/revoke-all', ['confirm' => 'revoke'], $grant)
        ->assertStatus(422)->assertJsonPath('error_code', 'CONFIRMATION_WORD_REQUIRED');
    expect(ApiAccessToken::query()->count())->toBe(4);

    stkCall($wmToken, 'POST', STK_BASE . '/revoke-user', ['user' => $wm->uuid, 'confirm' => 'REVOKE'], stkGrant($wm, $wmToken))
        ->assertOk()->assertJsonPath('data.revoked', 1)->assertJsonPath('data.current_kept', true);
    expect(ApiAccessToken::query()->whereKey(stkRow($wmToken)->getKey())->exists())->toBeTrue();

    stkCall($wmToken, 'POST', STK_BASE . '/revoke-audience', ['audience' => 'account', 'confirm' => 'REVOKE'], stkGrant($wm, $wmToken))
        ->assertOk()->assertJsonPath('data.revoked', 1);
    stkCall($wmToken, 'POST', STK_BASE . '/revoke-audience', ['audience' => 'game', 'confirm' => 'REVOKE'], stkGrant($wm, $wmToken))
        ->assertStatus(422);

    $grant = stkGrant($wm, $wmToken);
    stkCall($wmToken, 'POST', STK_BASE . '/revoke-all', ['confirm' => 'REVOKE'], $grant)
        ->assertOk()->assertJsonPath('data.revoked', 1)->assertJsonPath('data.current_kept', true);
    expect(ApiAccessToken::query()->pluck('id')->all())->toBe([stkRow($wmToken)->getKey()]);
    stkCall($wmToken, 'POST', STK_BASE . '/revoke-all', ['confirm' => 'REVOKE'], $grant)->assertStatus(403);
});

test('the revoke routes are throttled', function (): void {
    $wm = stkUser(UserType::WEBMASTER, 0);
    $wmToken = stkToken($wm);
    $last = 0;
    for ($i = 0; $i < 7; $i++) {
        $last = stkCall($wmToken, 'POST', STK_BASE . '/revoke-all', ['confirm' => 'REVOKE'])->getStatusCode();
    }
    expect($last)->toBe(429);
});
