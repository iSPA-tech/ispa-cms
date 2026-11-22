<?php

declare(strict_types=1);

namespace Cms\Core\Services\Security;

use App\Models\User;
use Cms\Core\Actions\Security\StoreRestorePasskey;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Laravel\Passkeys\Actions\DeletePasskey;
use Laravel\Passkeys\Actions\GenerateRegistrationOptions;
use Laravel\Passkeys\Actions\GenerateVerificationOptions;
use Laravel\Passkeys\Actions\StorePasskey;
use Laravel\Passkeys\Actions\VerifyPasskey;
use Laravel\Passkeys\Exceptions\InvalidPasskeyException;
use Laravel\Passkeys\Passkey;
use Laravel\Passkeys\Passkeys;
use Laravel\Passkeys\Support\WebAuthn;
use ParagonIE\ConstantTime\Base64UrlSafe;
use Webauthn\Exception\AuthenticatorResponseVerificationException;
use Webauthn\PublicKeyCredential;
use Webauthn\PublicKeyCredentialCreationOptions;
use Webauthn\PublicKeyCredentialRequestOptions;

final class PasskeyService
{
    public const KIND_PASSKEY = 'passkey';
    public const KIND_RESTORE = 'restore';

    private const PURPOSE_REGISTRATION = 'registration';
    private const PURPOSE_LOGIN = 'login';
    private const PURPOSE_RESTORE_REGISTRATION = 'restore-registration';
    private const PURPOSE_RESTORE_LOGIN = 'restore-login';

    /**
     * Start a registration ceremony bound to the authenticated user and API surface.
     *
     * @return array{challenge_id: string, options: array<string, mixed>}
     */
    public function registrationOptions(User $user, string $context): array
    {
        $options = app(GenerateRegistrationOptions::class)($user);

        return $this->rememberChallenge(self::PURPOSE_REGISTRATION, $context, $user, $options);
    }

    public function register(
        User $user,
        string $challengeId,
        string $context,
        string $name,
        PublicKeyCredential $credential,
    ): Passkey {
        return $this->storeCredential(
            self::PURPOSE_REGISTRATION,
            $user,
            $challengeId,
            $context,
            $name,
            $credential,
            self::KIND_PASSKEY,
        );
    }

    /**
     * @return array{challenge_id: string, options: array<string, mixed>}
     */
    public function restoreRegistrationOptions(User $user, string $context): array
    {
        $options = app(GenerateRegistrationOptions::class)($user);

        return $this->rememberChallenge(self::PURPOSE_RESTORE_REGISTRATION, $context, $user, $options);
    }

    public function registerRestoreCredential(
        User $user,
        string $challengeId,
        string $context,
        string $name,
        PublicKeyCredential $credential,
    ): Passkey {
        return $this->storeCredential(
            self::PURPOSE_RESTORE_REGISTRATION,
            $user,
            $challengeId,
            $context,
            $name,
            $credential,
            self::KIND_RESTORE,
        );
    }

    private function storeCredential(
        string $purpose,
        User $user,
        string $challengeId,
        string $context,
        string $name,
        PublicKeyCredential $credential,
        string $kind,
    ): Passkey {
        $options = $this->consumeChallenge(
            $purpose,
            $challengeId,
            $context,
            $user,
        );

        /** @var PublicKeyCredentialCreationOptions $registrationOptions */
        $registrationOptions = WebAuthn::fromJson($options, PublicKeyCredentialCreationOptions::class);

        try {
            $passkey = $kind === self::KIND_RESTORE
                ? app(StoreRestorePasskey::class)($user, $name, $credential, $registrationOptions)
                : app(StorePasskey::class)($user, $name, $credential, $registrationOptions);
        } catch (AuthenticatorResponseVerificationException) {
            throw InvalidPasskeyException::make(__('core::text.core.web.passkey.domain_mismatch'));
        }

        return $passkey;
    }

    /**
     * Start a discoverable login ceremony.
     *
     * @return array{challenge_id: string, options: array<string, mixed>}
     */
    public function loginOptions(string $context): array
    {
        $options = app(GenerateVerificationOptions::class)();

        return $this->rememberChallenge(self::PURPOSE_LOGIN, $context, null, $options);
    }

    public function verifyLogin(
        string $challengeId,
        string $context,
        PublicKeyCredential $credential,
        ?User $expectedUser = null,
    ): User {
        return $this->verifyCredential(
            self::PURPOSE_LOGIN,
            $challengeId,
            $context,
            $credential,
            self::KIND_PASSKEY,
            $expectedUser,
        );
    }

    /**
     * @return array{challenge_id: string, options: array<string, mixed>}
     */
    public function restoreLoginOptions(string $context): array
    {
        $options = app(GenerateVerificationOptions::class)();

        return $this->rememberChallenge(self::PURPOSE_RESTORE_LOGIN, $context, null, $options);
    }

    public function verifyRestoreLogin(
        string $challengeId,
        string $context,
        PublicKeyCredential $credential,
    ): User {
        return $this->verifyCredential(
            self::PURPOSE_RESTORE_LOGIN,
            $challengeId,
            $context,
            $credential,
            self::KIND_RESTORE,
        );
    }

    private function verifyCredential(
        string $purpose,
        string $challengeId,
        string $context,
        PublicKeyCredential $credential,
        string $kind,
        ?User $expectedUser = null,
    ): User {
        $options = $this->consumeChallenge($purpose, $challengeId, $context, $expectedUser);

        /** @var PublicKeyCredentialRequestOptions $verificationOptions */
        $verificationOptions = WebAuthn::fromJson($options, PublicKeyCredentialRequestOptions::class);
        $this->assertCredentialKind($credential, $kind);

        try {
            $passkey = app(VerifyPasskey::class)($credential, $verificationOptions, $expectedUser);
        } catch (AuthenticatorResponseVerificationException) {
            throw InvalidPasskeyException::make(__('core::text.core.web.passkey.domain_mismatch'));
        }

        if ((string) $passkey->getAttribute('kind') !== $kind) {
            throw InvalidPasskeyException::make(__('core::text.core.web.passkey.wrong_kind'));
        }

        $user = $passkey->user;

        if (! $user instanceof User) {
            throw InvalidPasskeyException::make(__('core::text.core.web.passkey.unknown_account'));
        }

        return $user;
    }

    private function assertCredentialKind(PublicKeyCredential $credential, string $expectedKind): void
    {
        $model = Passkeys::passkeyModel();
        $credentialId = Base64UrlSafe::encodeUnpadded($credential->rawId);

        /** @var Passkey|null $passkey */
        $passkey = $model::query()
            ->where('credential_id', $credentialId)
            ->first();

        if (! $passkey || (string) $passkey->getAttribute('kind') !== $expectedKind) {
            throw InvalidPasskeyException::make(__('core::text.core.web.passkey.wrong_kind'));
        }
    }

    /**
     * @return list<array{id: string, name: string, authenticator: string|null, last_used_at: string|null, created_at: string|null}>
     */
    public function forUser(User $user): array
    {
        return $user->passkeys()
            ->where('kind', self::KIND_PASSKEY)
            ->latest('created_at')
            ->get(['id', 'name', 'credential', 'last_used_at', 'created_at'])
            ->map(static fn (Passkey $passkey): array => [
                'id' => (string) $passkey->getKey(),
                'name' => $passkey->name,
                'authenticator' => $passkey->authenticator,
                'last_used_at' => $passkey->last_used_at?->toISOString(),
                'created_at' => $passkey->created_at?->toISOString(),
            ])
            ->values()
            ->all();
    }

    public function delete(User $user, string|int $passkeyId): void
    {
        $model = Passkeys::passkeyModel();

        /** @var Passkey $passkey */
        $passkey = $model::query()
            ->whereKey($passkeyId)
            ->where('user_id', $user->getKey())
            ->where('kind', self::KIND_PASSKEY)
            ->firstOrFail();

        app(DeletePasskey::class)($user, $passkey);
    }

    /**
     * @param object $options
     * @return array{challenge_id: string, options: array<string, mixed>}
     */
    private function rememberChallenge(string $purpose, string $context, ?User $user, object $options): array
    {
        $challengeId = Str::random(64);

        Cache::put($this->cacheKey($challengeId), [
            'purpose' => $purpose,
            'context' => $context,
            'user_id' => $user ? (string) $user->getKey() : null,
            'options' => WebAuthn::toJson($options),
        ], now()->addSeconds(max(1, (int) config('passkeys.challenge_ttl', 300))));

        return [
            'challenge_id' => $challengeId,
            'options' => WebAuthn::toBrowserArray($options),
        ];
    }

    private function consumeChallenge(
        string $purpose,
        string $challengeId,
        string $context,
        ?User $user,
    ): string {
        $challengeKey = $this->cacheKey($challengeId);
        $lock = Cache::lock($challengeKey . ':consume', 10);

        if (! $lock->get()) {
            throw InvalidPasskeyException::make(__('core::text.core.web.passkey.challenge_busy'));
        }

        try {
            $challenge = Cache::get($challengeKey);

            if (! is_array($challenge)
                || ($challenge['purpose'] ?? null) !== $purpose
                || ($challenge['context'] ?? null) !== $context
                || ($user && (string) ($challenge['user_id'] ?? '') !== (string) $user->getKey())
                || ! is_string($challenge['options'] ?? null)
            ) {
                throw InvalidPasskeyException::make(__('core::text.core.web.passkey.challenge_invalid'));
            }

            Cache::forget($challengeKey);

            return $challenge['options'];
        } finally {
            $lock->release();
        }
    }

    private function cacheKey(string $challengeId): string
    {
        return 'passkey:challenge:' . $challengeId;
    }
}
