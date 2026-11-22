<?php
/*
 * Copyright (c) 2026 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

declare(strict_types=1);

/**
 * ApiTokenIssuer stores the audience, the coarse network label and a short device label, takes the lifetime
 * from TokenPolicy, keeps at most the policy cap of live tokens per account and audience (oldest first out), replaces
 * the token of a device that signs in again, and hands the plain token back exactly once.
 */

use App\Models\User;
use Cms\Core\Models\ApiAccessToken;
use Cms\Core\Services\Security\ApiTokenIssuer;
use Cms\Core\Services\Security\TokenAudience;
use Cms\Core\Services\Security\TokenPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Sanctum\NewAccessToken;

uses(Cms\Core\Tests\TestCase::class, RefreshDatabase::class);

const P10_CHROME_MAC_UA = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36';
const P10_FIREFOX_WIN_UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:132.0) Gecko/20100101 Firefox/132.0';

/** Create a plain account for the issuer tests. */
function p10IssuerUser(): User
{
    return User::query()->forceCreate([
        'uuid' => (string) Str::uuid(),
        'name' => 'Issuer Owner',
        'username' => 'issuer-' . Str::lower(Str::random(6)),
        'email' => 'issuer-' . Str::lower(Str::random(6)) . '@example.com',
        'email_verified_at' => now(),
        'password' => Hash::make('secret123'),
        'type' => 0,
        'level' => 0,
        'status' => null,
    ]);
}

/** Build a request from a given address and user agent, independent of the global request. */
function p10IssuerRequest(string $ip, ?string $userAgent = P10_CHROME_MAC_UA): Request
{
    $server = ['REMOTE_ADDR' => $ip];
    if ($userAgent !== null) {
        $server['HTTP_USER_AGENT'] = $userAgent;
    }

    return Request::create('/api/account/auth/login', 'POST', [], [], [], $server);
}

/** The issuer under test. */
function p10Issuer(): ApiTokenIssuer
{
    return app(ApiTokenIssuer::class);
}

afterEach(function () {
    Carbon::setTestNow();
});

test('issue stores the audience, the device record and a hashed token, and returns the plain token once', function () {
    $user = p10IssuerUser();

    $new = p10Issuer()->issue($user, TokenAudience::ACCOUNT, 'Pixel 9', ['*'], p10IssuerRequest('203.0.113.77'), null, 'account.login');

    expect($new)->toBeInstanceOf(NewAccessToken::class)
        ->and($new->accessToken)->toBeInstanceOf(ApiAccessToken::class);
    [$id, $secret] = explode('|', $new->plainTextToken, 2);
    $row = DB::table('personal_access_tokens')->where('id', (int) $id)->first();

    expect($row->token)->toBe(hash('sha256', $secret))
        ->and($row->audience)->toBe('account')
        ->and($row->issued_via)->toBe('account.login')
        ->and($row->name)->toBe('Pixel 9')
        ->and($row->ip_address)->toBe('203.0.113.0/24')
        ->and($row->user_agent)->toBe('Chrome on OS X')
        ->and(Str::isUuid((string) $row->uuid))->toBeTrue()
        ->and(json_decode((string) $row->abilities, true))->toBe(['*'])
        ->and($row->expires_at)->toBeNull();

    // The model never serialises the hash.
    expect($new->accessToken->toArray())->not->toHaveKey('token');
    expect(ApiAccessToken::query()->findOrFail((int) $id)->audience)->toBe('account');
});

test('issue truncates IPv6 to /64 and an IPv4-mapped address to /24', function () {
    $user = p10IssuerUser();

    $v6 = p10Issuer()->issue($user, TokenAudience::ACCOUNT, 'a', ['*'], p10IssuerRequest('2001:db8:85a3:8d3:1319:8a2e:370:7348'));
    $mapped = p10Issuer()->issue($user, TokenAudience::ACCOUNT, 'b', ['*'], p10IssuerRequest('::ffff:198.51.100.23', P10_FIREFOX_WIN_UA));

    expect($v6->accessToken->fresh()->ip_address)->toBe('2001:db8:85a3:8d3::/64')
        ->and($mapped->accessToken->fresh()->ip_address)->toBe('198.51.100.0/24')
        ->and($mapped->accessToken->fresh()->user_agent)->toBe('Firefox on Windows');
});

test('issue never stores the raw user agent and keeps the label within 255 characters', function () {
    $user = p10IssuerUser();
    $longAgent = 'SuperClient/9.9 ' . str_repeat('x', 1200);

    $new = p10Issuer()->issue($user, TokenAudience::CLIENT, 'device', ['*'], p10IssuerRequest('10.1.2.3', $longAgent));
    $stored = (string) $new->accessToken->fresh()->user_agent;

    expect(mb_strlen($stored))->toBeLessThanOrEqual(255)
        ->and($stored)->not->toBe($longAgent)
        ->and($stored)->not->toContain(str_repeat('x', 50))
        ->and($stored)->toBe('SuperClient');

    $plainBrowser = p10Issuer()->issue($user, TokenAudience::CLIENT, 'device-2', ['*'], p10IssuerRequest('10.1.2.3'));
    expect($plainBrowser->accessToken->fresh()->user_agent)->not->toBe(P10_CHROME_MAC_UA);
});

test('issue without a request leaves the device record empty and reads no request global', function () {
    $user = p10IssuerUser();
    app()->instance('request', p10IssuerRequest('192.0.2.200'));

    $new = p10Issuer()->issue($user, TokenAudience::SERVICE, 'nightly-job', ['*'], null, null, 'console');
    $row = $new->accessToken->fresh();

    expect($row->ip_address)->toBeNull()->and($row->user_agent)->toBeNull()->and($row->issued_via)->toBe('console');
});

test('issue refuses an audience outside the closed list', function () {
    $user = p10IssuerUser();

    expect(fn () => p10Issuer()->issue($user, 'mobile', 'x', ['*'], null))->toThrow(InvalidArgumentException::class);
    expect(DB::table('personal_access_tokens')->count())->toBe(0);
});

test('with the default policy the expiry is exactly what the caller asked for (today\'s behaviour)', function () {
    Carbon::setTestNow('2026-11-13 10:00:00');
    $user = p10IssuerUser();

    $never = p10Issuer()->issue($user, TokenAudience::ADMIN, 'a', ['*'], null);
    $asked = p10Issuer()->issue($user, TokenAudience::SERVICE, 'b', ['*'], null, now()->addMonth());

    expect($never->accessToken->fresh()->expires_at)->toBeNull()
        ->and($asked->accessToken->fresh()->expires_at->toDateTimeString())->toBe('2026-12-13 10:00:00');
});

test('the policy lifetime sets expires_at and caps a longer expiry the caller asked for', function () {
    Carbon::setTestNow('2026-11-13 10:00:00');
    app()->instance(TokenPolicy::class, new TokenPolicy([TokenAudience::ADMIN => 60 * 24 * 7]));
    $user = p10IssuerUser();

    $default = p10Issuer()->issue($user, TokenAudience::ADMIN, 'a', ['*'], null);
    $longer = p10Issuer()->issue($user, TokenAudience::ADMIN, 'b', ['*'], null, now()->addYears(10));
    $shorter = p10Issuer()->issue($user, TokenAudience::ADMIN, 'c', ['*'], null, now()->addHour());
    $otherAudience = p10Issuer()->issue($user, TokenAudience::ACCOUNT, 'd', ['*'], null);

    expect($default->accessToken->fresh()->expires_at->toDateTimeString())->toBe('2026-11-20 10:00:00')
        ->and($longer->accessToken->fresh()->expires_at->toDateTimeString())->toBe('2026-11-20 10:00:00')
        ->and($shorter->accessToken->fresh()->expires_at->toDateTimeString())->toBe('2026-11-13 11:00:00')
        ->and($otherAudience->accessToken->fresh()->expires_at)->toBeNull();
});

test('the cap evicts the oldest live token of the same account and audience only', function () {
    app()->instance(TokenPolicy::class, new TokenPolicy([], 3));
    $user = p10IssuerUser();
    $other = p10IssuerUser();
    $legacy = $user->createToken('legacy');
    $otherAudience = p10Issuer()->issue($user, TokenAudience::CLIENT, 'client-app', ['*'], null);
    $otherUser = p10Issuer()->issue($other, TokenAudience::ACCOUNT, 'phone', ['*'], null);

    $ids = [];
    foreach (['10.0.0.1', '10.0.1.1', '10.0.2.1'] as $i => $ip) {
        Carbon::setTestNow(now()->addMinute());
        $ids[] = p10Issuer()->issue($user, TokenAudience::ACCOUNT, "device-{$i}", ['*'], p10IssuerRequest($ip))->accessToken->getKey();
    }
    Carbon::setTestNow(now()->addMinute());
    $fourth = p10Issuer()->issue($user, TokenAudience::ACCOUNT, 'device-3', ['*'], p10IssuerRequest('10.0.3.1'));

    $live = ApiAccessToken::query()->where('tokenable_id', $user->id)->where('audience', 'account')->pluck('id')->all();
    expect($live)->toEqualCanonicalizing([$ids[1], $ids[2], $fourth->accessToken->getKey()]);
    foreach ([$legacy, $otherAudience, $otherUser] as $untouched) {
        expect(ApiAccessToken::query()->whereKey($untouched->accessToken->getKey())->exists())->toBeTrue();
    }
});

test('expired tokens do not count towards the cap', function () {
    app()->instance(TokenPolicy::class, new TokenPolicy([], 2));
    $user = p10IssuerUser();
    $expired = p10Issuer()->issue($user, TokenAudience::ACCOUNT, 'old', ['*'], null, now()->subDay());
    $a = p10Issuer()->issue($user, TokenAudience::ACCOUNT, 'a', ['*'], null);
    $b = p10Issuer()->issue($user, TokenAudience::ACCOUNT, 'b', ['*'], null);

    foreach ([$expired, $a, $b] as $token) {
        expect(ApiAccessToken::query()->whereKey($token->accessToken->getKey())->exists())->toBeTrue();
    }
});

test('a repeated sign-in from the same device replaces its token', function () {
    $user = p10IssuerUser();
    $first = p10Issuer()->issue($user, TokenAudience::ACCOUNT, 'Pixel 9', ['*'], p10IssuerRequest('203.0.113.10'));
    $otherDevice = p10Issuer()->issue($user, TokenAudience::ACCOUNT, 'Pixel 9', ['*'], p10IssuerRequest('203.0.113.10', P10_FIREFOX_WIN_UA));
    $otherName = p10Issuer()->issue($user, TokenAudience::ACCOUNT, 'iPad', ['*'], p10IssuerRequest('203.0.113.10'));
    $otherAudience = p10Issuer()->issue($user, TokenAudience::CLIENT, 'Pixel 9', ['*'], p10IssuerRequest('203.0.113.10'));

    $again = p10Issuer()->issue($user, TokenAudience::ACCOUNT, 'Pixel 9', ['*'], p10IssuerRequest('203.0.113.99'));

    expect(ApiAccessToken::query()->whereKey($first->accessToken->getKey())->exists())->toBeFalse()
        ->and(ApiAccessToken::query()->whereKey($again->accessToken->getKey())->exists())->toBeTrue();
    foreach ([$otherDevice, $otherName, $otherAudience] as $kept) {
        expect(ApiAccessToken::query()->whereKey($kept->accessToken->getKey())->exists())->toBeTrue();
    }
});

test('tokens issued without a device label never replace each other', function () {
    $user = p10IssuerUser();
    $a = p10Issuer()->issue($user, TokenAudience::SERVICE, 'job', ['*'], null);
    $b = p10Issuer()->issue($user, TokenAudience::SERVICE, 'job', ['*'], null);

    expect(ApiAccessToken::query()->whereKey([$a->accessToken->getKey(), $b->accessToken->getKey()])->count())->toBe(2);
});

test('no log line and no audit row carries the plain token', function () {
    $messages = [];
    Event::listen(MessageLogged::class, static function (MessageLogged $event) use (&$messages): void {
        $messages[] = $event->message . ' ' . json_encode($event->context);
    });
    $user = p10IssuerUser();
    app()->instance(TokenPolicy::class, new TokenPolicy([], 1));

    $first = p10Issuer()->issue($user, TokenAudience::ACCOUNT, 'one', ['*'], p10IssuerRequest('203.0.113.1'));
    $second = p10Issuer()->issue($user, TokenAudience::ACCOUNT, 'two', ['*'], p10IssuerRequest('203.0.113.2'));

    $haystack = implode("\n", $messages) . json_encode(DB::table('audit_logs')->get());
    foreach ([$first, $second] as $new) {
        [, $secret] = explode('|', $new->plainTextToken, 2);
        expect($haystack)->not->toContain($secret)->and($haystack)->not->toContain(hash('sha256', $secret));
    }
});
