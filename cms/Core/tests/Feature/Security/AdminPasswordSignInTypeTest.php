<?php

declare(strict_types=1);

/**
 * The admin password sign-in accepts the same staff types as every other admin gate (ApiAdminVerifyMiddleware, passkey
 * sign-in, tokens): Webmaster and Admin. It used to let only Webmasters in, so Admin accounts, among them the shared demo
 * account and the fund reviewer account of demo.ispa.tech, could never sign in with their password.
 */

use App\Constants\UserType;
use App\Http\Middleware\ApiLocalization;
use App\Http\Middleware\HttpsProtocol;
use App\Http\Middleware\Localization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

uses(Cms\Core\Tests\TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $this->withoutMiddleware([HttpsProtocol::class, Localization::class, ApiLocalization::class, ThrottleRequests::class]);
});

function adminSignInTypeUser(int $type): User
{
    return User::query()->forceCreate([
        'uuid' => (string) Str::uuid(),
        'name' => 'Sign-in type probe',
        'username' => 'probe-' . Str::lower(Str::random(6)),
        'email' => 'probe+' . Str::lower(Str::random(6)) . '@example.com',
        'email_verified_at' => now(),
        'password' => Hash::make('secret123'),
        'type' => $type,
        'level' => $type === UserType::WEBMASTER ? 0 : 1,
        'status' => null,
    ]);
}

test('an Admin account signs in with its password, like a Webmaster', function () {
    foreach ([UserType::WEBMASTER, UserType::ADMIN] as $type) {
        $user = adminSignInTypeUser($type);

        $this->postJson('/api/v2/admin/auth/sign-in', ['username' => $user->email, 'password' => 'secret123'])
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.type', 'auth');
    }
});

test('client and member accounts are still refused at the admin sign-in', function () {
    foreach ([UserType::CLIENT, UserType::USER] as $type) {
        $user = adminSignInTypeUser($type);

        $response = $this->postJson('/api/v2/admin/auth/sign-in', ['username' => $user->email, 'password' => 'secret123']);

        $response->assertOk();
        expect($response->json('status'))->not->toBe('success')
            ->and($response->json('data.token'))->toBeNull();
    }
});
