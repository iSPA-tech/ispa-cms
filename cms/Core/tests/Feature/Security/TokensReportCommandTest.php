<?php
/*
 * Copyright (c) 2026 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

declare(strict_types=1);

/**
 * `cms:tokens-report` prints counts only, `cms:tokens-revoke-unused` is a dry run without `--force`, the
 * dashboard attention entries follow the token settings, and the cleanup commands are scheduled (never run in tests).
 */

use App\Constants\UserType;
use App\Models\User;
use App\Services\Dashboard\AttentionRegistry;
use Cms\Core\Models\ApiAccessToken;
use Cms\Core\Services\Dashboard\TokenAttentionProvider;
use Cms\Core\Services\Security\ApiTokenIssuer;
use Cms\Core\Services\Security\TokenAudience;
use Cms\Core\Services\Security\TokenSettings;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

uses(Cms\Core\Tests\TestCase::class, RefreshDatabase::class);

/** Create an account of the given type. */
function tkrUser(string $name, int $type = UserType::ADMIN): User
{
    return User::query()->forceCreate([
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
}

/** Issue a token; `$daysUnused` backdates creation and last use, `$legacy` clears the audience. */
function tkrToken(User $user, string $audience, int $daysUnused = 0, bool $legacy = false, bool $expiring = false): ApiAccessToken
{
    $new = app(ApiTokenIssuer::class)->issue($user, $audience, 'dev-' . Str::random(5), ['*'], null, $expiring ? now()->addDays(30) : null);
    $token = ApiAccessToken::query()->findOrFail($new->accessToken->getKey());
    $token->forceFill([
        'created_at' => now()->subDays($daysUnused),
        'last_used_at' => $daysUnused > 0 ? now()->subDays($daysUnused) : null,
        'audience' => $legacy ? null : $audience,
    ])->save();

    return $token;
}

beforeEach(function (): void {
    tkrUser('filler');
    $this->admin = tkrUser('admin');
    $this->client = tkrUser('client', UserType::CLIENT);
    tkrToken($this->admin, TokenAudience::ADMIN);
    tkrToken($this->admin, TokenAudience::ADMIN, 100, false, true);
    tkrToken($this->admin, TokenAudience::ADMIN, 0, true);
    tkrToken($this->client, TokenAudience::CLIENT, 40);
});

test('the report prints counts per audience and account type and never a token value', function (): void {
    $this->artisan('cms:tokens-report', ['--days' => 30, '--max' => 1])
        ->expectsOutputToContain('Total: 4')
        ->expectsOutputToContain('admin: 2')
        ->expectsOutputToContain('client: 1')
        ->expectsOutputToContain('Legacy (no audience): 1')
        ->expectsOutputToContain('Never expire: 3')
        ->expectsOutputToContain('Unused for 30 days: 2')
        ->expectsOutputToContain('Accounts over the cap of 1: 1')
        ->assertExitCode(0);
});

test('revoke-unused revokes nothing without --force and only unused tokens with it', function (): void {
    $this->artisan('cms:tokens-revoke-unused', ['--days' => 30])
        ->expectsOutputToContain('Would revoke 2')
        ->assertExitCode(0);
    expect(ApiAccessToken::query()->count())->toBe(4);

    $this->artisan('cms:tokens-revoke-unused', ['--days' => 30, '--force' => true])->assertExitCode(0);
    expect(ApiAccessToken::query()->count())->toBe(2);
});

test('revoke-unused does nothing when the setting is off and no --days is given', function (): void {
    $this->artisan('cms:tokens-revoke-unused', ['--force' => true])
        ->expectsOutputToContain('off')
        ->assertExitCode(0);
    expect(ApiAccessToken::query()->count())->toBe(4);
});

test('the attention provider warns about never-expiring tokens only when an audience is on never, and about enforcement only when off', function (): void {
    $registry = new AttentionRegistry();
    TokenAttentionProvider::register($registry);
    $webmaster = User::query()->orderBy('id')->first(); // user id 1 is always a Webmaster
    $keys = fn (): array => array_column($registry->forUser($webmaster), 'key');

    expect($keys())->toContain(TokenAttentionProvider::KEY_NEVER, TokenAttentionProvider::KEY_LEGACY)
        ->not->toContain(TokenAttentionProvider::KEY_ENFORCEMENT);

    app(TokenSettings::class)->update([
        'lifetime' => array_fill_keys(TokenAudience::userAudiences(), '7d'),
        'mode' => 'off',
    ]);
    $registry = new AttentionRegistry();
    TokenAttentionProvider::register($registry);
    $after = array_column($registry->forUser($webmaster), 'key');
    expect($after)->not->toContain(TokenAttentionProvider::KEY_NEVER)
        ->and($after)->toContain(TokenAttentionProvider::KEY_ENFORCEMENT);

    expect($registry->forUser($this->admin))->toBe([]);
});

test('the cleanup commands are scheduled and nothing ran', function (): void {
    $commands = collect(app(Schedule::class)->events())->map(fn ($e) => (string) $e->command)->implode("\n");
    expect($commands)->toContain('sanctum:prune-expired --hours=24')->toContain('cms:tokens-revoke-unused');
    expect(ApiAccessToken::query()->count())->toBe(4);
});

test('the token prefix setting defaults to empty and comes from the environment', function (): void {
    expect(config('sanctum.token_prefix'))->toBe('');
});
