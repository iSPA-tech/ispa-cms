<?php
/*
 * Copyright (c) 2026 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

declare(strict_types=1);

/**
 * the admin sign-in token is issued through ApiTokenIssuer with the admin audience, device record and policy
 * lifetime; the admin account endpoint no longer mints a token per call; the sign-out routes delete exactly the
 * presented token.
 */

use App\Constants\UserType;
use App\Models\User;
use Cms\Core\Models\ApiAccessToken;
use Cms\Core\Services\Security\AdminMfaService;
use Cms\Core\Services\Security\TokenAudience;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

uses(Cms\Core\Tests\TestCase::class, RefreshDatabase::class);

const P10T04_UA = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36';

/** Create a staff account; the first call also fills user id 1 (always a Webmaster). */
function p10t04Staff(): User
{
    if (User::query()->count() === 0) {
        User::query()->forceCreate([
            'uuid' => (string) Str::uuid(), 'name' => 'filler', 'username' => 'filler-' . Str::random(4),
            'email' => 'filler-' . Str::random(4) . '@example.com', 'email_verified_at' => now(),
            'password' => Hash::make('secret123'), 'type' => UserType::WEBMASTER, 'level' => 0, 'status' => null,
        ]);
    }

    return User::query()->forceCreate([
        'uuid' => (string) Str::uuid(), 'name' => 'staff', 'username' => 'staff-' . Str::random(4),
        'email' => 'staff-' . Str::random(4) . '@example.com', 'email_verified_at' => now(),
        'password' => Hash::make('secret123'), 'type' => UserType::WEBMASTER, 'level' => 0, 'status' => null,
    ]);
}

/** Request from a given address as the sign-in would see it. */
function p10t04Request(string $ip = '203.0.113.9'): Request
{
    return Request::create('/api/v2/admin/auth/sign-in', 'POST', [], [], [], ['REMOTE_ADDR' => $ip, 'HTTP_USER_AGENT' => P10T04_UA]);
}

test('admin sign-in stores the admin audience, truncated address, device label and keeps the permission abilities', function (): void {
    $user = p10t04Staff();

    $payload = app(AdminMfaService::class)->buildAuthPayload($user, p10t04Request());

    expect($payload['type'])->toBe('auth')->and($payload['token'])->toContain('|');
    $row = DB::table('personal_access_tokens')->first();
    expect($row->audience)->toBe(TokenAudience::ADMIN)
        ->and($row->ip_address)->toBe('203.0.113.0/24')
        ->and($row->user_agent)->toBe('Chrome on OS X')
        ->and($row->issued_via)->toBe('admin.sign-in')
        ->and($row->name)->toBe('authToken')
        ->and($row->expires_at)->toBeNull();
    expect(json_decode((string) $row->abilities, true))->toBe($user->permissions());
});

test('admin sign-in from the same device again replaces the token instead of adding one', function (): void {
    $user = p10t04Staff();
    $service = app(AdminMfaService::class);

    $service->buildAuthPayload($user, p10t04Request());
    $service->buildAuthPayload($user, p10t04Request());

    expect(DB::table('personal_access_tokens')->count())->toBe(1);
});

test('the issued admin token still opens the admin account endpoint, and repeated calls create no token rows', function (): void {
    $user = p10t04Staff();
    $token = app(AdminMfaService::class)->buildAuthPayload($user, p10t04Request())['token'];
    $before = DB::table('personal_access_tokens')->count();

    foreach (range(1, 3) as $_) {
        $response = $this->withToken($token)->getJson('https://localhost/api/v2/admin/account/info');
        $response->assertOk();
        expect($response->json('data.access_token') ?? $response->json('access_token'))->toBeNull();
    }

    expect(DB::table('personal_access_tokens')->count())->toBe($before)
        ->and(DB::table('personal_access_tokens')->where('name', 'ispa_manager')->count())->toBe(0);
});

test('admin sign-out deletes exactly the presented token', function (): void {
    $user = p10t04Staff();
    $service = app(AdminMfaService::class);
    $presented = $service->buildAuthPayload($user, p10t04Request('203.0.113.9'))['token'];
    $other = $service->buildAuthPayload($user, p10t04Request('198.51.100.4'))['token'];
    expect(DB::table('personal_access_tokens')->count())->toBe(2);
    $otherId = (int) explode('|', $other)[0];

    $this->withToken($presented)->postJson('https://localhost/api/v2/admin/account/sign-out')->assertOk();

    expect(DB::table('personal_access_tokens')->count())->toBe(1)
        ->and(ApiAccessToken::query()->whereKey($otherId)->exists())->toBeTrue();
});

test('admin auth logout route deletes exactly the presented token', function (): void {
    $user = p10t04Staff();
    $service = app(AdminMfaService::class);
    $presented = $service->buildAuthPayload($user, p10t04Request('203.0.113.9'))['token'];
    $service->buildAuthPayload($user, p10t04Request('198.51.100.4'));

    $this->withToken($presented)->postJson('https://localhost/api/v2/admin/auth/logout')->assertOk();

    expect(DB::table('personal_access_tokens')->count())->toBe(1)
        ->and(ApiAccessToken::query()->whereKey((int) explode('|', $presented)[0])->exists())->toBeFalse();
});
