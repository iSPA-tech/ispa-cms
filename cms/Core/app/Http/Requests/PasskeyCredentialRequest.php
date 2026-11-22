<?php

declare(strict_types=1);

namespace Cms\Core\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Laravel\Passkeys\Exceptions\InvalidPasskeyException;
use Laravel\Passkeys\Support\WebAuthn;
use Throwable;
use Webauthn\PublicKeyCredential;

abstract class PasskeyCredentialRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function publicKeyCredential(): PublicKeyCredential
    {
        try {
            return WebAuthn::fromJson(
                json_encode($this->validated('credential'), JSON_THROW_ON_ERROR),
                PublicKeyCredential::class,
            );
        } catch (Throwable) {
            throw InvalidPasskeyException::make(__('core::text.core.web.passkey.invalid_credential'));
        }
    }

    /**
     * @return array<string, array<int, string>>
     */
    protected function credentialRules(): array
    {
        return [
            'challenge_id' => ['required', 'string', 'alpha_num', 'max:128'],
            'credential' => ['required', 'array'],
            'credential.id' => ['required', 'string', 'max:1024'],
            'credential.rawId' => ['required', 'string', 'max:2048'],
            'credential.type' => ['required', 'string', 'in:public-key'],
            'credential.response' => ['required', 'array'],
            'credential.response.clientDataJSON' => ['required', 'string'],
            'credential.response.attestationObject' => ['nullable', 'string'],
            'credential.response.authenticatorData' => ['nullable', 'string'],
            'credential.response.signature' => ['nullable', 'string'],
            'credential.response.userHandle' => ['nullable', 'string'],
        ];
    }

    public function rules(): array
    {
        return $this->credentialRules();
    }
}
