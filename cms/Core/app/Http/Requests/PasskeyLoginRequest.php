<?php

declare(strict_types=1);

namespace Cms\Core\Http\Requests;

final class PasskeyLoginRequest extends PasskeyCredentialRequest
{
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'device_name' => ['nullable', 'string', 'max:120'],
            'remember' => ['nullable', 'boolean'],
            'credential.response.authenticatorData' => ['required', 'string'],
            'credential.response.signature' => ['required', 'string'],
        ];
    }
}
