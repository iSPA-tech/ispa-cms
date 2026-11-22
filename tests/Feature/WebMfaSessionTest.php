<?php

use App\Constants\UserType;
use App\Enums\UserStatusEnum;
use App\Models\User;
use Cms\Core\Events\SendCodeVerify;
use Cms\Core\Models\AuthChallenge;
use Cms\Core\Models\UserSetting;
use Cms\Core\Models\UserMfaFactor;
use Cms\Core\Services\Security\AdminMfaService;
use Cms\Core\Services\Security\TotpService;
use Illuminate\Auth\Events\Verified;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ViewErrorBag;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function (): void {
    $this->withoutMiddleware([
        \App\Http\Middleware\HttpsProtocol::class,
        \App\Http\Middleware\Localization::class,
        ThrottleRequests::class,
    ]);
});

function makeWebMfaUser(array $attributes = []): User
{
    DB::table('enumerates')->insertOrIgnore([
        'enum_key' => UserStatusEnum::USER_ACTIVE->value,
        'name' => UserStatusEnum::USER_ACTIVE->value,
    ]);

    return User::query()->forceCreate(array_merge([
        'uuid' => (string) Str::uuid(),
        'name' => 'Web MFA User',
        'username' => 'web-mfa-' . Str::lower(Str::random(8)),
        'email' => 'web-mfa-' . Str::lower(Str::random(8)) . '@example.test',
        'email_verified_at' => now(),
        'password' => bcrypt('secret123'),
        'type' => UserType::CLIENT,
        'level' => 0,
        'status' => UserStatusEnum::USER_ACTIVE->value,
    ], $attributes));
}

function enableWebEmailMfa(User $user, string $mode = 'email_only'): void
{
    UserSetting::query()->create([
        'user_id' => $user->id,
        'setting_group' => 'security',
        'setting_key' => 'mfa_mode',
        'setting_value' => [
            'value' => $mode,
            'type' => 'select',
        ],
    ]);
}

test('an authenticated website session is gated by MFA and completion does not mint an API token', function (): void {
    Event::fake([SendCodeVerify::class]);
    $user = makeWebMfaUser();
    enableWebEmailMfa($user);

    $this->actingAs($user)
        ->get('/')
        ->assertRedirect(route('core.web-mfa.show'));

    $challengeEvent = Event::dispatched(SendCodeVerify::class)[0][0] ?? null;

    expect($challengeEvent)->not->toBeNull();
    $this->assertDatabaseCount('personal_access_tokens', 0);

    $this->post(route('core.web-mfa.verify'), [
        'code' => $challengeEvent->code,
    ])->assertRedirect('/');

    $this->assertAuthenticatedAs($user);
    $this->assertDatabaseCount('personal_access_tokens', 0);
});

test('Acme password login completes email MFA and enters the partner portal', function (): void {
    // POST /login belongs to the Acme project; run with VITE_TEMPLATE_FOLDER=Acme to cover it.
    if (! Route::has('acme.web.login.store')) {
        $this->markTestSkipped('The Acme project routes are not loaded (VITE_TEMPLATE_FOLDER is not Acme).');
    }

    $this->withoutVite();
    $this->withMiddleware(\App\Http\Middleware\Localization::class);
    Event::fake([SendCodeVerify::class]);
    $user = makeWebMfaUser([
        'name' => 'Acme Partner',
        'email' => 'acme-mfa@example.test',
        'username' => 'acme-mfa-partner',
        'type' => 3,
        'level' => 99,
    ]);
    enableWebEmailMfa($user);

    $this->post('/login', [
        'email' => $user->email,
        'password' => 'secret123',
        'intended' => '/quan-ly',
    ])->assertRedirect('/quan-ly');

    $this->assertAuthenticatedAs($user, 'web');

    $this->get('/quan-ly')
        ->assertRedirect(route('core.web-mfa.show'));

    $this->get(route('core.web-mfa.show'))
        ->assertOk()
        ->assertSee('Mã xác thực');

    $event = Event::dispatched(SendCodeVerify::class)[0][0] ?? null;
    expect($event)->not->toBeNull();
    $challenge = AuthChallenge::query()->where('user_id', $user->id)->latest('id')->firstOrFail();
    expect($challenge->expires_at->greaterThan(now()->addMinutes(9)))->toBeTrue();

    $this->post(route('core.web-mfa.verify'), [
        'code' => $event->code,
    ])->assertRedirect('/quan-ly');

    $this->get('/quan-ly')->assertOk();
});

test('a pending website MFA challenge can be cancelled safely', function (): void {
    Event::fake([SendCodeVerify::class]);
    $user = makeWebMfaUser();
    enableWebEmailMfa($user);

    $this->actingAs($user)
        ->get('/')
        ->assertRedirect(route('core.web-mfa.show'));

    $challenge = AuthChallenge::query()->where('user_id', $user->id)->latest('id')->firstOrFail();

    $response = $this->post(route('core.web-mfa.cancel'));
    $response->assertRedirect('/')
        ->assertSessionMissing('core.web_mfa.pending_challenge')
        ->assertSessionMissing('core.web_mfa.verified_user_id')
        ->assertSessionMissing('core.web_mfa.intended_path');

    $this->assertGuest('web');
    expect($challenge->fresh()->status)->toBe('cancelled');
});

test('website MFA cancellation copy matches the active method', function (
    string $method,
    bool $isRecovery,
    string $expected,
): void {
    request()->query->replace($isRecovery ? ['method' => 'recovery_code'] : []);

    $html = view('core::auth.mfa', [
        'errors' => new ViewErrorBag(),
        'challenge' => [
            'current_method' => $method,
            'email' => 'user@example.test',
            'can_resend' => false,
            'can_use_recovery_code' => false,
        ],
    ])->render();

    expect($html)
        ->toContain($expected)
        ->toContain('Bạn sẽ được đăng xuất và quá trình xác thực hiện tại sẽ bị hủy.');
})->with([
    'email code' => [AdminMfaService::METHOD_EMAIL_CODE, false, 'Tôi không thể truy cập email lúc này'],
    'authenticator app' => [AdminMfaService::METHOD_TOTP, false, 'Tôi không thể dùng ứng dụng xác thực lúc này'],
    'recovery code' => [AdminMfaService::METHOD_TOTP, true, 'Tôi không có mã khôi phục lúc này'],
]);

test('a website session bypasses MFA when account mode is missing or off', function (?string $mode): void {
    Event::fake([SendCodeVerify::class]);
    $user = makeWebMfaUser();

    if ($mode !== null) {
        enableWebEmailMfa($user, $mode);
    }

    Route::get('/__test/web-mfa-optional', static fn () => response()->json(['ok' => true]))
        ->middleware('web');

    $this->actingAs($user)
        ->getJson('/__test/web-mfa-optional')
        ->assertOk()
        ->assertExactJson(['ok' => true])
        ->assertSessionHas('core.web_mfa.verified_user_id', $user->id);

    Event::assertNotDispatched(SendCodeVerify::class);
    $this->assertDatabaseMissing('auth_challenges', [
        'user_id' => $user->id,
    ]);
})->with([
    'missing setting' => null,
    'explicit off' => AdminMfaService::MODE_OFF,
]);

test('the web login event starts an MFA challenge before the browser can reach another route', function (): void {
    Event::fake([SendCodeVerify::class]);
    $user = makeWebMfaUser();
    enableWebEmailMfa($user);

    Route::post('/__test/web-mfa-login', static function (Request $request) use ($user) {
        Auth::login($user);

        return response()->json([
            'has_pending_challenge' => $request->session()->has('core.web_mfa.pending_challenge'),
        ]);
    })->middleware('web');

    $this->postJson('/__test/web-mfa-login')
        ->assertOk()
        ->assertJsonPath('has_pending_challenge', true);

    expect(Event::dispatched(SendCodeVerify::class))->not->toBeEmpty();
});

test('the web login event redacts unexpected MFA startup failures', function (): void {
    Exceptions::fake();
    $mfa = new class(app(TotpService::class)) extends \Cms\Core\Services\Security\AdminMfaService {
        public function resolveRequiredMethods(User $user): array
        {
            return [self::METHOD_EMAIL_CODE];
        }

        public function createChallenge(
            User $user,
            string $ipAddress,
            ?string $userAgent = null,
            bool $remember = false,
            array $context = [],
            ?array $requiredMethods = null,
        ): ?\Cms\Core\Models\AuthChallenge {
            throw new RuntimeException('smtp-password=listener-secret');
        }
    };
    app()->instance(\Cms\Core\Services\Security\AdminMfaService::class, $mfa);
    app()->forgetInstance(\Cms\Core\Services\Security\WebMfaSessionService::class);
    $user = makeWebMfaUser();

    Route::post('/__test/web-mfa-login-failure', static function () use ($user) {
        Auth::login($user);

        return response()->json(['authenticated' => true]);
    })->middleware('web');

    $response = $this->postJson('/__test/web-mfa-login-failure')
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['email', 'username']);

    $this->assertSame(
        'Không thể bắt đầu xác thực đăng nhập. Vui lòng thử lại sau.',
        $response->json('errors.email.0'),
    );
    $this->assertStringNotContainsString('listener-secret', $response->getContent());
    Exceptions::assertReported(static fn (RuntimeException $exception): bool => $exception->getMessage() === 'smtp-password=listener-secret');
    $this->assertGuest();
});

test('website MFA completes email and authenticator steps without issuing a Sanctum token', function (): void {
    Event::fake([SendCodeVerify::class]);
    $secret = 'JBSWY3DPEHPK3PXP';
    $user = makeWebMfaUser();
    enableWebEmailMfa($user, 'email_plus_totp');

    UserMfaFactor::query()->create([
        'uuid' => (string) Str::uuid(),
        'user_id' => $user->id,
        'provider' => UserMfaFactor::PROVIDER_TOTP,
        'label' => $user->email,
        'issuer' => 'iSPA Test',
        'secret' => $secret,
        'verified_at' => now(),
        'enabled_at' => now(),
        'is_primary' => true,
    ]);

    $this->actingAs($user)->get('/')->assertRedirect(route('core.web-mfa.show'));
    $emailEvent = Event::dispatched(SendCodeVerify::class)[0][0] ?? null;

    expect($emailEvent)->not->toBeNull();

    $this->post(route('core.web-mfa.verify'), [
        'code' => $emailEvent->code,
    ])->assertRedirect(route('core.web-mfa.show'));

    $this->post(route('core.web-mfa.verify'), [
        'code' => app(TotpService::class)->now($secret),
    ])->assertRedirect('/');

    $this->assertDatabaseCount('personal_access_tokens', 0);
});

test('unverified users cannot retain a website session', function (): void {
    $user = makeWebMfaUser(['email_verified_at' => null]);

    $this->actingAs($user)
        ->get('/')
        ->assertRedirect(url('/auth/sign-in'));

    $this->assertGuest();
});

test('unexpected MFA startup failures are redacted for html and json sessions', function (): void {
    $mfa = new class(app(TotpService::class)) extends \Cms\Core\Services\Security\AdminMfaService {
        public function resolveRequiredMethods(User $user): array
        {
            return [self::METHOD_EMAIL_CODE];
        }

        public function createChallenge(
            User $user,
            string $ipAddress,
            ?string $userAgent = null,
            bool $remember = false,
            array $context = [],
            ?array $requiredMethods = null,
        ): ?\Cms\Core\Models\AuthChallenge {
            throw new RuntimeException('smtp-password=internal-secret');
        }
    };
    app()->instance(\Cms\Core\Services\Security\AdminMfaService::class, $mfa);
    app()->forgetInstance(\Cms\Core\Services\Security\WebMfaSessionService::class);
    app()->forgetInstance(\Cms\Core\Http\Middleware\EnforceWebMfa::class);
    $user = makeWebMfaUser();

    $html = $this->actingAs($user)
        ->get('/')
        ->assertRedirect(url('/auth/sign-in'))
        ->assertSessionHasErrors('email');

    expect($html->getSession()->get('errors')->first('email'))
        ->toBe('Không thể bắt đầu xác thực đăng nhập. Vui lòng thử lại sau.')
        ->not->toContain('internal-secret');
    $this->assertGuest();

    $this->actingAs($user)
        ->getJson('/')
        ->assertUnauthorized()
        ->assertExactJson(['message' => 'Không thể bắt đầu xác thực đăng nhập. Vui lòng thử lại sau.']);
    $this->assertGuest();
});

test('the signed verification link marks an account as verified without a prior login', function (): void {
    Event::fake([Verified::class]);
    $user = makeWebMfaUser(['email_verified_at' => null]);
    $url = URL::temporarySignedRoute('verification.verify', now()->addMinutes(30), [
        'id' => $user->getKey(),
        'hash' => sha1($user->getEmailForVerification()),
    ]);

    $this->get($url)
        ->assertRedirect(url('/auth/sign-in'));

    expect($user->fresh()->email_verified_at)->not->toBeNull();
    Event::assertDispatched(Verified::class);
});
