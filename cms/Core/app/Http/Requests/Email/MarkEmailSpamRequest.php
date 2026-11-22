<?php

namespace Cms\Core\Http\Requests\Email;

use Illuminate\Foundation\Http\FormRequest;

class MarkEmailSpamRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'uuids' => ['required', 'array', 'min:1', 'max:100'],
            'uuids.*' => ['required', 'string', 'uuid', 'distinct'],
            'block_domain' => ['sometimes', 'boolean'],
        ];
    }
}
