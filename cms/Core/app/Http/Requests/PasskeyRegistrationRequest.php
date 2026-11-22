<?php

declare(strict_types=1);

namespace Cms\Core\Http\Requests;

final class PasskeyRegistrationRequest extends PasskeyCredentialRequest
{
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'name' => ['required', 'string', 'min:1', 'max:80'],
            'credential.response.attestationObject' => ['required', 'string'],
        ];
    }
}
