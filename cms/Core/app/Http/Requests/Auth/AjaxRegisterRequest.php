<?php

namespace Cms\Core\Http\Requests\Auth;

use App\Constants\Tables;
use Illuminate\Validation\Rule;

final class AjaxRegisterRequest extends RegisterRequest
{
    public function rules(): array
    {
        $rules = parent::rules();

        $rules['email'] = [
            'required',
            'email:rfc',
            'max:255',
            Rule::unique(Tables::USERS, 'email'),
        ];
        $rules['username'] = [
            'required',
            'string',
            'regex:/^[A-Za-z][A-Za-z0-9_-]{3,63}$/',
            Rule::unique(Tables::USERS, 'username'),
        ];

        return $rules;
    }
}
