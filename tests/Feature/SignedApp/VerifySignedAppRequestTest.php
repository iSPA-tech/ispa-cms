<?php

declare(strict_types=1);

namespace Tests\Feature\SignedApp;

use App\Contracts\SignedApp\SignedAppClientProvider;
use App\Contracts\SignedApp\SignedAppCredential;
use App\Contracts\SignedApp\SignedAppPrincipal;
use App\Enums\SignedAppAudience;
use App\Http\Middleware\VerifySignedAppRequest;
use App\Http\Middleware\HttpsProtocol;
use App\Http\Middleware\Localization;
use App\Support\SignedApp\NullSignedAppClientProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

final class VerifySignedAppRequestTest extends TestCase
{
    private const PATH = '/__test/signed-app';
    public const APP_ID = 'appb-test';
    public const SECRET = 'signed-app-test-secret';

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware([HttpsProtocol::class, Localization::class]);

        Route::middleware(VerifySignedAppRequest::class)
            ->post(self::PATH, static function (Request $request) {
                $principal = $request->attributes->get('signed_app_principal');

                return response()->json([
                    'client_key' => $principal instanceof SignedAppPrincipal ? $principal->clientKey : null,
                    'audience' => $principal instanceof SignedAppPrincipal ? $principal->audience->value : null,
                ]);
            });
    }

    public function test_null_provider_denies_requests_by_default(): void
    {
        $this->app->singleton(SignedAppClientProvider::class, NullSignedAppClientProvider::class);

        $this->postJson(self::PATH, [], $this->signedHeaders())
            ->assertUnauthorized()
            ->assertExactJson([
                'status' => 'error',
                'message' => 'Signed app verification failed.',
            ]);
    }

    public function test_valid_signature_exposes_a_secret_free_trusted_principal(): void
    {
        $provider = $this->fakeProvider();
        $this->app->instance(SignedAppClientProvider::class, $provider);

        $this->postJson(self::PATH, [], $this->signedHeaders(platform: 'ios'))
            ->assertOk()
            ->assertExactJson([
                'client_key' => 'client-test-uuid',
                'audience' => 'beta',
            ]);

        $this->assertSame(1, $provider->acceptedCount);
        $this->assertSame('ios', $provider->acceptedPrincipal?->platform);
    }

    public function test_invalid_signature_does_not_consume_the_nonce(): void
    {
        $provider = $this->fakeProvider();
        $this->app->instance(SignedAppClientProvider::class, $provider);
        $nonce = 'nonce-' . Str::uuid()->toString();

        $invalid = $this->signedHeaders(nonce: $nonce);
        $invalid['X-App-Signature'] = str_repeat('0', 64);

        $this->postJson(self::PATH, [], $invalid)->assertUnauthorized();
        $this->postJson(self::PATH, [], $this->signedHeaders(nonce: $nonce))->assertOk();

        $this->assertSame(1, $provider->acceptedCount);
    }

    public function test_oversized_headers_are_rejected_before_provider_lookup(): void
    {
        $provider = $this->fakeProvider();
        $this->app->instance(SignedAppClientProvider::class, $provider);
        $headers = $this->signedHeaders();
        $headers['X-App-Id'] = str_repeat('a', 129);

        $this->postJson(self::PATH, [], $headers)->assertUnauthorized();

        $this->assertSame(0, $provider->lookupCount);
        $this->assertSame(0, $provider->acceptedCount);
    }

    public function test_replayed_valid_nonce_is_rejected(): void
    {
        $provider = $this->fakeProvider();
        $this->app->instance(SignedAppClientProvider::class, $provider);
        $nonce = 'nonce-' . Str::uuid()->toString();
        $headers = $this->signedHeaders(nonce: $nonce);

        $this->postJson(self::PATH, [], $headers)->assertOk();
        $this->postJson(self::PATH, [], $headers)->assertUnauthorized();

        $this->assertSame(1, $provider->acceptedCount);
    }

    private function fakeProvider(): SignedAppClientProvider
    {
        return new class implements SignedAppClientProvider {
            public int $lookupCount = 0;

            public int $acceptedCount = 0;

            public ?SignedAppPrincipal $acceptedPrincipal = null;

            public function findActive(string $appId): ?SignedAppCredential
            {
                $this->lookupCount++;

                if ($appId !== VerifySignedAppRequestTest::APP_ID) {
                    return null;
                }

                return new SignedAppCredential(
                    clientKey: 'client-test-uuid',
                    appId: $appId,
                    encryptedSecret: 'opaque-test-secret',
                    allowedPlatforms: ['android', 'ios'],
                    audience: SignedAppAudience::BETA,
                );
            }

            public function decryptSecret(SignedAppCredential $credential): string
            {
                return VerifySignedAppRequestTest::SECRET;
            }

            public function accepted(
                Request $request,
                SignedAppCredential $credential,
                SignedAppPrincipal $principal,
            ): void {
                $this->acceptedCount++;
                $this->acceptedPrincipal = $principal;
            }
        };
    }

    /**
     * @return array<string, string>
     */
    private function signedHeaders(?string $nonce = null, string $platform = 'android'): array
    {
        $timestamp = (string) now()->timestamp;
        $nonce ??= 'nonce-' . Str::uuid()->toString();
        $payload = implode("\n", [
            'POST',
            self::PATH,
            hash('sha256', '[]'),
            $timestamp,
            $nonce,
        ]);

        return [
            'X-App-Id' => self::APP_ID,
            'X-App-Platform' => $platform,
            'X-App-Timestamp' => $timestamp,
            'X-App-Nonce' => $nonce,
            'X-App-Signature' => hash_hmac('sha256', $payload, self::SECRET),
        ];
    }
}
