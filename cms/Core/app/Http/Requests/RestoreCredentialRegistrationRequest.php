<?php

declare(strict_types=1);

namespace Cms\Core\Http\Requests;

final class RestoreCredentialRegistrationRequest extends PasskeyCredentialRequest
{
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'credential.response.attestationObject' => ['required', 'string'],
        ];
    }
}
