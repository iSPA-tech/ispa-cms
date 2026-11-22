<?php

use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Http\Middleware\ApiLocalization;
use App\Http\Middleware\HttpsProtocol;
use App\Http\Middleware\Localization;
use Cms\Core\Events\SendCodeVerify;
use Cms\Core\Http\Requests\User\PutSaveSettingRequest;
use Cms\Core\Models\UserSetting;
use Cms\Core\Models\UserMfaFactor;
use Cms\Core\Services\Security\AdminMfaService;
use Cms\Core\Services\Security\TotpService;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $this->withoutMiddleware([
        HttpsProtocol::class,
        Localization::class,
        ApiLocalization::class,
        ThrottleRequests::class,
    ]);
});

function makeAdminUser(array $attributes = []): User
{
    return User::query()->forceCreate(array_merge([
        'uuid' => (string)Str::uuid(),
        'name' => 'Admin MFA User',
        'username' => 'admin-' . Str::lower(Str::random(6)),
        'email' => 'admin+' . Str::lower(Str::random(6)) . '@example.com',
        'email_verified_at' => now(),
        'password' => Hash::make('secret123'),
        'type' => 0,
        'level' => 0,
        'status' => null,
    ], $attributes));
}

function putUserSecuritySetting(User $user, string $key, mixed $value, string $type = 'boolean'): void
{
    UserSetting::query()->create([
        'user_id' => $user->id,
        'setting_group' => 'security',
        'setting_key' => $key,
        'setting_value' => [
            'value' => $value,
            'type' => $type,
        ],
    ]);
}

test('user settings request rejects unknown admin mfa modes', function () {
    $request = new PutSaveSettingRequest();

    $validator = Validator::make([
        'security' => [
            'mfa_mode' => [
                'value' => 'email_only;bad',
            ],
        ],
    ], $request->rules());

    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->has('security.mfa_mode.value'))->toBeTrue();
});

test('admin mfa service rejects invalid persisted mode', function () {
    $user = makeAdminUser();

    expect(fn () => app(AdminMfaService::class)->persistMfaMode($user, 'email_only;bad'))
        ->toThrow(InvalidArgumentException::class);

    expect(UserSetting::query()
        ->where('user_id', $user->id)
        ->where('setting_group', 'security')
        ->where('setting_key', 'mfa_mode')
        ->exists())->toBeFalse();
});

test('admin mfa mode cannot require an authenticator before one is enrolled', function () {
    $user = makeAdminUser();

    expect(fn () => app(AdminMfaService::class)->persistMfaMode($user, AdminMfaService::MODE_TOTP_ONLY))
        ->toThrow(RuntimeException::class);
});

test('admin sign-in returns an MFA challenge when email verification is required', function () {
    Event::fake([SendCodeVerify::class]);

    $user = makeAdminUser();
    putUserSecuritySetting($user, 'mfa_mode', 'email_only', 'select');
    putUserSecuritySetting($user, 'two_fa', true);

    $response = $this->postJson('/api/v2/admin/auth/sign-in', [
        'username' => $user->username,
        'password' => 'secret123',
    ]);

    $response->assertOk()
        ->assertJsonPath('status', 'success')
        ->assertJsonPath('data.type', 'mfa_challenge')
        ->assertJsonPath('data.current_method', 'email_code')
        ->assertJsonPath('data.email', $user->email);

    Event::assertDispatched(SendCodeVerify::class);
});

test('admin sign-in can complete email and authenticator verification before issuing a token', function () {
    Event::fake([SendCodeVerify::class]);

    $secret = 'JBSWY3DPEHPK3PXP';
    $user = makeAdminUser();
    putUserSecuritySetting($user, 'mfa_mode', 'email_plus_totp', 'select');
    putUserSecuritySetting($user, 'two_fa', true);

    UserMfaFactor::query()->create([
        'uuid' => (string)Str::uuid(),
        'user_id' => $user->id,
        'provider' => UserMfaFactor::PROVIDER_TOTP,
        'label' => $user->email,
        'issuer' => 'iSPA Test',
        'secret' => $secret,
        'verified_at' => now(),
        'enabled_at' => now(),
        'is_primary' => true,
    ]);

    $signIn = $this->postJson('/api/v2/admin/auth/sign-in', [
        'username' => $user->username,
        'password' => 'secret123',
    ]);

    $signIn->assertOk()
        ->assertJsonPath('status', 'success')
        ->assertJsonPath('data.type', 'mfa_challenge')
        ->assertJsonPath('data.current_method', 'email_code');

    $challengeId = $signIn->json('data.challenge_id');
    $emailEvent = Event::dispatched(SendCodeVerify::class)[0][0] ?? null;

    expect($emailEvent)->not->toBeNull();

    $emailVerify = $this->postJson('/api/v2/admin/auth/challenge', [
        'challenge_id' => $challengeId,
        'code' => $emailEvent->code,
    ]);

    $emailVerify->assertOk()
        ->assertJsonPath('status', 'success')
        ->assertJsonPath('data.type', 'mfa_challenge')
        ->assertJsonPath('data.current_method', 'totp')
        ->assertJsonPath('data.completed_methods.0', 'email_code');

    $totpCode = app(TotpService::class)->now($secret);

    $totpVerify = $this->postJson('/api/v2/admin/auth/challenge', [
        'challenge_id' => $challengeId,
        'code' => $totpCode,
    ]);

    $totpVerify->assertOk()
        ->assertJsonPath('status', 'success')
        ->assertJsonPath('data.type', 'auth');

    expect($totpVerify->json('data.token'))->not->toBeEmpty();
});

test('authenticator enrollment returns an OTP URI without a QR URL', function () {
    $user = makeAdminUser();

    $response = $this->actingAs($user, 'sanctum')
        ->postJson('/api/v2/admin/account/mfa/authenticator/start');

    $response->assertOk();

    expect($response->json('data'))
        ->toHaveKey('otpauth_uri')
        ->not->toHaveKey('qr_code_url');
});
