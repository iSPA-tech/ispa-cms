<?php
/*
 * Copyright (c) 2026 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */


declare(strict_types=1);

/**
 * token settings and their Webmaster-only API. Defaults reproduce today's behaviour, only the Webmaster may
 * read or write, values outside the option lists are 422, "never" needs an explicit confirmation, the preview changes
 * nothing, an update audits changed keys only, and "apply to existing" spares the token that carries the request.
 */

use App\Constants\UserType;
use App\Models\User;
use Cms\Core\Events\AuditLogEvent;
use Cms\Core\Models\ApiAccessToken;
use Cms\Core\Models\Role;
use Cms\Core\Services\Security\ApiTokenIssuer;
use Cms\Core\Services\Security\StepUpConfirmation;
use Cms\Core\Services\Security\TokenAudience;
use Cms\Core\Services\Security\TokenPolicy;
use Cms\Core\Services\Security\TokenSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

uses(Cms\Core\Tests\TestCase::class, RefreshDatabase::class);

const TTS_BASE = 'https://localhost/api/v2/admin/security/token-settings';

/** Create an admin-type account with a role of the given level (0 = Webmaster). */
function ttsUser(string $name, ?int $roleLevel, int $type = UserType::ADMIN): User
{
    $user = User::query()->forceCreate([
        'uuid' => (string) Str::uuid(),
        'name' => $name,
        'username' => $name . '-' . Str::lower(Str::random(6)),
        'email' => $name . '-' . Str::lower(Str::random(6)) . '@example.com',
        'email_verified_at' => now(),
        'password' => Hash::make('secret123'),
        'type' => $type,
        'level' => 0,
        'status' => null,
    ]);
    if ($roleLevel !== null) {
        $user->roles()->attach(Role::query()->create(['name' => $name . ' ' . Str::random(4), 'custom' => ['level' => $roleLevel]]));
    }

    return $user;
}

/** Issue a token and move its creation time back by the given number of hours. */
function ttsToken(User $user, string $audience, int $hoursOld = 0, bool $expiring = false): ApiAccessToken
{
    $new = app(ApiTokenIssuer::class)->issue($user, $audience, 'dev-' . Str::random(5), ['*'], null, $expiring ? now()->addDays(400) : null);
    $token = ApiAccessToken::query()->findOrFail($new->accessToken->getKey());
    $token->forceFill(['created_at' => now()->subHours($hoursOld)])->save();

    return $token;
}

/** A fresh step-up grant of the account for the token of the request ("apply to existing" consumes one,). */
function ttsGrant(User $user, ApiAccessToken $current): array
{
    return [StepUpConfirmation::HEADER => app(StepUpConfirmation::class)->start($user->fresh(), $current, 'secret123')['grant']];
}

beforeEach(function (): void {
    ttsUser('filler', null); // user id 1 is always a Webmaster
    $this->webmaster = ttsUser('webmaster', 0);
    $this->manager = ttsUser('manager', 50);
    $this->client = ttsUser('client', null, UserType::CLIENT);
});

test('defaults reproduce today behaviour: never expires, cap 50, log mode, no idle, no cleanup', function (): void {
    $settings = new TokenSettings();
    expect($settings->mode())->toBe('log')
        ->and($settings->maxLiveTokens())->toBe(50)
        ->and($settings->unusedDays())->toBe(0);
    foreach (TokenAudience::userAudiences() as $audience) {
        expect($settings->lifetimeMinutes($audience))->toBeNull()->and($settings->idleDays($audience))->toBe(0);
    }

    $policy = new TokenPolicy();
    expect($policy->lifetimeMinutes(TokenAudience::ADMIN))->toBeNull()
        ->and($policy->maxLiveTokens(TokenAudience::ADMIN))->toBe(50)
        ->and($policy->expiresAt(TokenAudience::ADMIN, null))->toBeNull();
});

test('a fresh issue with default settings equals the old createToken (no expiry)', function (): void {
    $user = ttsUser('plain', null, UserType::CLIENT);
    $new = app(ApiTokenIssuer::class)->issue($user, TokenAudience::ACCOUNT, 'phone', ['*']);

    expect($new->accessToken->expires_at)->toBeNull()->and($new->accessToken->abilities)->toBe(['*']);
});

test('saved settings drive the policy', function (): void {
    (new TokenSettings())->update(['lifetime' => ['admin' => '7d'], 'max_live_tokens' => 5, 'idle_days' => ['admin' => 7]]);

    $policy = new TokenPolicy();
    expect($policy->lifetimeMinutes('admin'))->toBe(10080)
        ->and($policy->lifetimeMinutes('account'))->toBeNull()
        ->and($policy->maxLiveTokens('admin'))->toBe(5)
        ->and($policy->idleDays('admin'))->toBe(7)
        ->and($policy->expiresAt('admin', null)?->diffInMinutes(now(), true))->toBeGreaterThan(10079);
});

test('every route requires authentication and rejects a non-Webmaster', function (): void {
    $routes = [['GET', TTS_BASE], ['PUT', TTS_BASE], ['POST', TTS_BASE . '/preview'], ['POST', TTS_BASE . '/apply-to-existing']];
    foreach ($routes as [$method, $url]) {
        $this->json($method, $url, ['mode' => 'off'])->assertStatus(401);
    }
    foreach ([$this->manager, $this->client] as $user) {
        Sanctum::actingAs($user->fresh(), ['*']);
        foreach ($routes as [$method, $url]) {
            // An Admin gets 403; a client-type account is refused by the admin gate (401 or 403).
            $status = $this->json($method, $url, ['mode' => 'off'])->getStatusCode();
            expect($status)->toBeIn($user->is($this->manager) ? [403] : [401, 403]);
        }
    }
    expect(Cms\Core\Models\Setting::query()->where('setting_key', TokenSettings::SETTING_KEY)->exists())->toBeFalse();
});

test('show returns the defaults, the option lists and a warning for every audience on never', function (): void {
    Sanctum::actingAs($this->webmaster->fresh(), ['*']);

    $response = $this->getJson(TTS_BASE)->assertOk();
    expect($response->json('data.settings.mode'))->toBe('log')
        ->and($response->json('data.settings.lifetime.admin'))->toBe('never')
        ->and($response->json('data.options.lifetime'))->toContain('7d', 'never')
        ->and($response->json('data.recommended.admin'))->toBe('7d')
        ->and(collect($response->json('data.warnings'))->pluck('audience')->all())->toBe(TokenAudience::userAudiences());
});

test('the new-device notice switch is shown off, saves, reloads and is audited as a key only', function (): void {
    Event::fake([AuditLogEvent::class]);
    Sanctum::actingAs($this->webmaster->fresh(), ['*']);

    expect($this->getJson(TTS_BASE)->assertOk()->json('data.settings.notify_new_device'))->toBeFalse();

    $response = $this->putJson(TTS_BASE, ['notify_new_device' => true])->assertOk();
    expect($response->json('data.changed'))->toBe(['notify_new_device'])
        ->and($response->json('data.settings.notify_new_device'))->toBeTrue()
        ->and((new TokenSettings())->notifyNewDevice())->toBeTrue()
        ->and($this->getJson(TTS_BASE)->json('data.settings.notify_new_device'))->toBeTrue();

    $this->putJson(TTS_BASE, ['notify_new_device' => true])->assertOk()->assertJsonPath('data.changed', []);
    $this->putJson(TTS_BASE, ['notify_new_device' => 'maybe'])->assertStatus(422);
    $this->putJson(TTS_BASE, ['notify_new_device' => false])->assertOk();
    expect((new TokenSettings())->notifyNewDevice())->toBeFalse();
});

test('a value outside the option lists is a 422', function (): void {
    Sanctum::actingAs($this->webmaster->fresh(), ['*']);
    foreach ([
        ['lifetime' => ['admin' => '5m']],
        ['lifetime' => ['game' => '7d']],
        ['idle_days' => ['admin' => 3]],
        ['max_live_tokens' => 0],
        ['max_live_tokens' => 101],
        ['mode' => 'panic'],
        ['unused_days' => 45],
    ] as $body) {
        $this->putJson(TTS_BASE, $body)->assertStatus(422);
    }
    expect((new TokenSettings())->mode())->toBe('log');
});

test('never is refused without confirmation and accepted with it, with warnings', function (): void {
    Sanctum::actingAs($this->webmaster->fresh(), ['*']);
    (new TokenSettings())->update(['lifetime' => ['admin' => '7d']]);

    $this->putJson(TTS_BASE, ['lifetime' => ['admin' => 'never']])->assertStatus(422)->assertJsonPath('error_code', 'NEVER_EXPIRE_CONFIRMATION_REQUIRED');
    expect((new TokenSettings())->lifetimeKey('admin'))->toBe('7d');

    $ok = $this->putJson(TTS_BASE, ['lifetime' => ['admin' => 'never'], 'confirm_never_expire' => true])->assertOk();
    expect(collect($ok->json('data.warnings'))->pluck('audience'))->toContain('admin')
        ->and((new TokenSettings())->lifetimeKey('admin'))->toBe('never');
});

test('update saves a partial change and audits the changed keys without values', function (): void {
    Event::fake([AuditLogEvent::class]);
    Sanctum::actingAs($this->webmaster->fresh(), ['*']);

    $response = $this->putJson(TTS_BASE, ['lifetime' => ['admin' => '7d'], 'mode' => 'enforce', 'max_live_tokens' => 20])->assertOk();

    expect($response->json('data.changed'))->toBe(['lifetime.admin', 'max_live_tokens', 'mode'])
        ->and((new TokenSettings())->mode())->toBe('enforce')
        ->and((new TokenSettings())->lifetimeKey('account'))->toBe('never');
    Event::assertDispatched(AuditLogEvent::class, function (AuditLogEvent $event): bool {
        return $event->action === 'security.token_settings.updated'
            && $event->changed === ['changed' => ['lifetime.admin', 'max_live_tokens', 'mode']]
            && ! str_contains(json_encode($event->changed), '7d') && ! str_contains(json_encode($event->changed), 'enforce');
    });
});

test('preview returns counts only and changes nothing', function (): void {
    Sanctum::actingAs($this->webmaster->fresh(), ['*']);
    $old = ttsToken($this->client, TokenAudience::ACCOUNT, 24 * 40);
    ttsToken($this->client, TokenAudience::ACCOUNT, 1);
    ttsToken($this->client, TokenAudience::ADMIN, 1, true);
    ApiAccessToken::query()->whereKey(ttsToken($this->client, TokenAudience::CLIENT)->getKey())->update(['audience' => null]);

    $response = $this->postJson(TTS_BASE . '/preview', ['lifetime' => ['account' => '30d']])->assertOk();

    expect($response->json('data.audiences.account'))->toBe(['live' => 2, 'never_expiring' => 2, 'would_expire_now' => 1])
        ->and($response->json('data.audiences.admin.never_expiring'))->toBe(0)
        ->and($response->json('data.without_audience'))->toBe(1)
        ->and($response->getContent())->not->toContain('token')->not->toContain(Str::before((string) $old->uuid, '-'))
        ->and(ApiAccessToken::query()->whereNull('expires_at')->count())->toBe(3)
        ->and((new TokenSettings())->lifetimeKey('account'))->toBe('never');
});

test('apply-to-existing sets expiry from created_at, spares the current token and revokes past ones only on request', function (): void {
    $current = ttsToken($this->webmaster, TokenAudience::ADMIN);
    Sanctum::actingAs($this->webmaster->fresh(), ['*']);
    $this->app['auth']->guard('sanctum')->setUser($this->webmaster->fresh()->withAccessToken($current));
    (new TokenSettings())->update(['lifetime' => ['account' => '30d']]);
    $recent = ttsToken($this->client, TokenAudience::ACCOUNT, 24);
    $past = ttsToken($this->client, TokenAudience::ACCOUNT, 24 * 60);
    $other = ttsToken($this->client, TokenAudience::CLIENT, 24 * 60);

    $this->postJson(TTS_BASE . '/apply-to-existing', ['audience' => 'account'])->assertStatus(403)->assertJsonPath('error_code', 'step_up_required');
    $first = $this->postJson(TTS_BASE . '/apply-to-existing', ['audience' => 'account'], ttsGrant($this->webmaster, $current))->assertOk();
    expect($first->json('data'))->toMatchArray(['set' => 1, 'already_past' => 1, 'revoked' => 0])
        ->and($recent->fresh()->expires_at->equalTo($recent->created_at->copy()->addDays(30)))->toBeTrue()
        ->and($past->fresh())->not->toBeNull()
        ->and($other->fresh()->expires_at)->toBeNull();

    $second = $this->postJson(TTS_BASE . '/apply-to-existing', ['audience' => 'account', 'revoke_past' => true], ttsGrant($this->webmaster, $current))->assertOk();
    expect($second->json('data.revoked'))->toBe(1)
        ->and(ApiAccessToken::query()->whereKey($past->getKey())->exists())->toBeFalse()
        ->and(ApiAccessToken::query()->whereKey($recent->getKey())->exists())->toBeTrue();
});

test('apply-to-existing under never has nothing to apply and never removes an expiry', function (): void {
    $current = ttsToken($this->webmaster, TokenAudience::ADMIN, 0, true);
    $other = ttsToken($this->webmaster, TokenAudience::ADMIN, 0, true);
    $expiryBefore = $other->fresh()->expires_at;
    Sanctum::actingAs($this->webmaster->fresh(), ['*']);
    $this->app['auth']->guard('sanctum')->setUser($this->webmaster->fresh()->withAccessToken($current));

    $this->postJson(TTS_BASE . '/apply-to-existing', ['audience' => 'admin'], ttsGrant($this->webmaster, $current))->assertStatus(422)->assertJsonPath('error_code', 'NOTHING_TO_APPLY');
    $this->postJson(TTS_BASE . '/apply-to-existing', ['audience' => 'admin', 'confirm_never_expire' => true], ttsGrant($this->webmaster, $current))->assertStatus(422);
    $this->postJson(TTS_BASE . '/apply-to-existing', ['audience' => 'game'], ttsGrant($this->webmaster, $current))->assertStatus(422);

    expect($other->fresh()->expires_at?->toIso8601String())->toBe($expiryBefore?->toIso8601String());
});

test('the token settings row is never exported by configuration packages or shown as a plain setting', function (): void {
    (new TokenSettings())->update(['mode' => 'enforce']);
    $row = \Cms\Core\Models\Setting::query()->where('setting_key', TokenSettings::SETTING_KEY)->firstOrFail();

    // Configuration packages skip every secret or non-public setting (SettingsSection::excludeReason).
    expect(app(\Cms\Core\Services\ConfigPackage\SecretGuard::class)->isSecretSetting(['type' => $row->type, 'input_setting' => $row->input_setting]))->toBeTrue();
});
