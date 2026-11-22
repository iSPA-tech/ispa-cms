<?php

declare(strict_types=1);

namespace Cms\Core\Actions\Security;

use Cms\Core\Services\Security\PasskeyService;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use Laravel\Passkeys\Actions\StorePasskey;
use Laravel\Passkeys\Contracts\PasskeyUser;
use Laravel\Passkeys\Events\PasskeyRegistered;
use Laravel\Passkeys\Passkey;
use Laravel\Passkeys\Support\WebAuthn;
use ParagonIE\ConstantTime\Base64UrlSafe;
use RuntimeException;
use Webauthn\CredentialRecord;
use Webauthn\PublicKeyCredential;
use Webauthn\PublicKeyCredentialCreationOptions;

/**
 * Stores a restore credential with its kind present before model/package events.
 *
 * Keep the overridden signatures aligned with Laravel Passkeys StorePasskey
 * when upgrading that package.
 */
class StoreRestorePasskey extends StorePasskey
{
    public function __invoke(
        Authenticatable $user,
        string $name,
        PublicKeyCredential $credential,
        PublicKeyCredentialCreationOptions $options,
    ): Passkey {
        if (! $user instanceof PasskeyUser) {
            throw new RuntimeException('User model must implement the PasskeyUser contract.');
        }

        $response = $this->getResponse($credential);
        $source = $this->validate($response, $options);
        $this->ensureCredentialIsUnique($source);

        $passkey = DB::transaction(
            fn (): Passkey => $this->createPasskey($user, $name, $source),
        );

        PasskeyRegistered::dispatch($user, $passkey);

        return $passkey;
    }

    public function createPasskey(
        PasskeyUser $user,
        string $name,
        CredentialRecord $source,
    ): Passkey {
        /** @var Passkey $passkey */
        $passkey = $user->passkeys()->make();
        $passkey->forceFill([
            'name' => $name,
            'credential_id' => Base64UrlSafe::encodeUnpadded($source->publicKeyCredentialId),
            'credential' => json_decode(WebAuthn::toJson($source), true, flags: JSON_THROW_ON_ERROR),
            'kind' => PasskeyService::KIND_RESTORE,
        ]);
        $passkey->saveOrFail();

        return $passkey;
    }
}
