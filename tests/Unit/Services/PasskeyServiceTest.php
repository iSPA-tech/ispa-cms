<?php

use App\Constants\UserType;
use App\Models\User;
use Cms\Core\Actions\Security\StoreRestorePasskey;
use Cms\Core\Services\Security\PasskeyService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Passkeys\Actions\StorePasskey;
use Laravel\Passkeys\Actions\VerifyPasskey;
use Laravel\Passkeys\Contracts\PasskeyUser;
use Laravel\Passkeys\Events\PasskeyRegistered;
use Laravel\Passkeys\Exceptions\InvalidPasskeyException;
use Laravel\Passkeys\Passkey;
use ParagonIE\ConstantTime\Base64UrlSafe;
use Symfony\Component\Uid\Uuid;
use Tests\TestCase;
use Webauthn\AuthenticatorAttestationResponse;
use Webauthn\AuthenticatorAssertionResponse;
use Webauthn\CredentialRecord;
use Webauthn\Exception\AuthenticatorResponseVerificationException;
use Webauthn\PublicKeyCredential;
use Webauthn\PublicKeyCredentialCreationOptions;
use Webauthn\TrustPath\EmptyTrustPath;

uses(TestCase::class, RefreshDatabase::class);

function makePasskeyServiceUser(): User
{
    return User::query()->forceCreate([
        'uuid' => (string) Str::uuid(),
        'name' => 'Passkey Service User',
        'username' => 'passkey-service-' . Str::lower(Str::random(8)),
        'email' => Str::lower(Str::random(8)) . '@example.test',
        'password' => bcrypt(Str::random(32)), // any value: users.password is NOT NULL without the Account module
        'type' => UserType::CLIENT,
        'level' => 0,
        'status' => null,
        'email_verified_at' => now(),
    ]);
}

function passkeyServiceCredential(): PublicKeyCredential
{
    return (new ReflectionClass(PublicKeyCredential::class))->newInstanceWithoutConstructor();
}

function restoreCredentialRecord(string $credentialId): CredentialRecord
{
    return CredentialRecord::create(
        publicKeyCredentialId: $credentialId,
        type: 'public-key',
        transports: [],
        attestationType: 'none',
        trustPath: EmptyTrustPath::create(),
        aaguid: Uuid::fromString('00000000-0000-0000-0000-000000000000'),
        credentialPublicKey: 'synthetic-public-key',
        userHandle: 'synthetic-user-handle',
        counter: 0,
    );
}

function restoreAttestationResponse(): AuthenticatorAttestationResponse
{
    return (new ReflectionClass(AuthenticatorAttestationResponse::class))
        ->newInstanceWithoutConstructor();
}

function passkeyServiceAssertionCredential(string $rawId): PublicKeyCredential
{
    $response = (new ReflectionClass(AuthenticatorAssertionResponse::class))
        ->newInstanceWithoutConstructor();

    return new PublicKeyCredential('public-key', $rawId, $response);
}

test('maps registration origin verification failures to a validation error', function (): void {
    $this->app->instance(StorePasskey::class, new class
    {
        public function __invoke(mixed ...$arguments): never
        {
            throw AuthenticatorResponseVerificationException::create('Invalid origin.');
        }
    });

    $service = app(PasskeyService::class);
    $user = makePasskeyServiceUser();
    $challenge = $service->registrationOptions($user, 'test');

    try {
        $service->register(
            $user,
            $challenge['challenge_id'],
            'test',
            'Test device',
            passkeyServiceCredential(),
        );

        $this->fail('The WebAuthn origin verification error was not converted.');
    } catch (InvalidPasskeyException $exception) {
        expect($exception->errors()['credential'])->toContain(
            'Passkey không thể xác thực trên tên miền này. Vui lòng thử lại hoặc liên hệ quản trị viên.',
        );
    }
});

test('maps login origin verification failures to a validation error', function (): void {
    $this->app->instance(VerifyPasskey::class, new class
    {
        public function __invoke(mixed ...$arguments): never
        {
            throw AuthenticatorResponseVerificationException::create('Invalid origin.');
        }
    });

    $service = app(PasskeyService::class);
    $user = makePasskeyServiceUser();
    $rawId = 'login-origin-verification';
    $user->passkeys()->create([
        'name' => 'Origin verification credential',
        'credential_id' => Base64UrlSafe::encodeUnpadded($rawId),
        'credential' => [],
    ]);
    $challenge = $service->loginOptions('test');

    try {
        $service->verifyLogin(
            $challenge['challenge_id'],
            'test',
            passkeyServiceAssertionCredential($rawId),
        );

        $this->fail('The WebAuthn origin verification error was not converted.');
    } catch (InvalidPasskeyException $exception) {
        expect($exception->errors()['credential'])->toContain(
            'Passkey không thể xác thực trên tên miền này. Vui lòng thử lại hoặc liên hệ quản trị viên.',
        );
    }
});

test('restore registration publishes only an already typed credential', function (): void {
    $baselineTransactionLevel = DB::transactionLevel();
    $published = [];
    Event::listen(
        PasskeyRegistered::class,
        static function (PasskeyRegistered $event) use (&$published): void {
            $published = [
                'kind' => $event->passkey->kind,
                'transaction_level' => DB::transactionLevel(),
            ];
        },
    );

    $user = makePasskeyServiceUser();
    $source = restoreCredentialRecord('restore-registration-event');
    $action = new class($source) extends StoreRestorePasskey
    {
        public function __construct(private readonly CredentialRecord $source) {}

        protected function getResponse(PublicKeyCredential $credential): AuthenticatorAttestationResponse
        {
            return restoreAttestationResponse();
        }

        protected function validate(
            AuthenticatorAttestationResponse $response,
            PublicKeyCredentialCreationOptions $options,
        ): CredentialRecord {
            return $this->source;
        }
    };

    $passkey = $action(
        $user,
        'Android device restore',
        passkeyServiceCredential(),
        (new ReflectionClass(PublicKeyCredentialCreationOptions::class))->newInstanceWithoutConstructor(),
    );

    expect($passkey->kind)->toBe(PasskeyService::KIND_RESTORE)
        ->and($published)->toBe([
            'kind' => PasskeyService::KIND_RESTORE,
            'transaction_level' => $baselineTransactionLevel,
        ]);
});

test('restore registration rolls back a partial ordinary row before publication', function (): void {
    Event::fake([PasskeyRegistered::class]);

    $user = makePasskeyServiceUser();
    $source = restoreCredentialRecord('restore-registration-rollback');
    $action = new class($source) extends StoreRestorePasskey
    {
        public function __construct(private readonly CredentialRecord $source) {}

        protected function getResponse(PublicKeyCredential $credential): AuthenticatorAttestationResponse
        {
            return restoreAttestationResponse();
        }

        protected function validate(
            AuthenticatorAttestationResponse $response,
            PublicKeyCredentialCreationOptions $options,
        ): CredentialRecord {
            return $this->source;
        }

        public function createPasskey(
            PasskeyUser $user,
            string $name,
            CredentialRecord $source,
        ): Passkey {
            $passkey = parent::createPasskey($user, $name, $source);
            $passkey->forceFill(['kind' => PasskeyService::KIND_PASSKEY])->saveOrFail();

            throw new RuntimeException('Synthetic typed-store failure.');
        }
    };

    expect(fn () => $action(
        $user,
        'Android device restore',
        passkeyServiceCredential(),
        (new ReflectionClass(PublicKeyCredentialCreationOptions::class))->newInstanceWithoutConstructor(),
    ))->toThrow(RuntimeException::class, 'Synthetic typed-store failure.');

    expect($user->passkeys()->count())->toBe(0);
    Event::assertNotDispatched(PasskeyRegistered::class);
});
