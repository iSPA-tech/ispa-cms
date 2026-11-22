<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Http\Requests\Account;

use Cms\Core\Http\RedirectFailedTrait;
use JetBrains\PhpStorm\ArrayShape;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Form request for the admin/account password change action.
 *
 * Validates the current_password (required string) and the new password which must meet
 * complexity requirements: minimum 8 characters, at least one lowercase letter, one
 * uppercase letter, one digit, and one special character (!@#$%^&*()_-). The new password
 * must not match the list of commonly-used passwords and must be confirmed by a second field.
 *
 * @package Cms\Core\Http\Requests\Account
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
class PostChangePasswordRequest extends FormRequest
{
    use RedirectFailedTrait;

    protected array $commonPassword = [
        '12345678', '1234567890', '123456789', 'password', 'qwerty123',
        '00000000',
    ];

    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    #[ArrayShape(['current_password' => 'string', 'password' => 'string'])]
    public function rules(): array
    {
        return [
            'current_password' => 'string|required',
            'password' => [
                'string',
                'required',
                'not_in:' . implode(',', $this->commonPassword),
                'confirmed',
                'min:8',
                'regex:/[a-z]/',
                'regex:/[A-Z]/',
                'regex:/[0-9]/',
                'regex:/[!@#$%^&*()_-]/',
            ],
        ];
    }

    /**
     * Message return
     *
     * @return array
     * @author malayvuong
     * @since 6.62.6 - 2022-08-20, 15:42 ICT
     */
    public function messages(): array
    {
        return [
            'password.regex' => __('validation.validate-password', ['n' => 8]),
            'password.not_in' => __('validation.validate-password-common'),
        ];
    }
}
