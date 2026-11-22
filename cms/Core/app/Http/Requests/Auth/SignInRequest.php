<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Http\Requests\Auth;

use Cms\Core\Http\RedirectFailedTrait;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Form request for the admin panel sign-in action.
 *
 * Validates: username (required string, 1–255 characters), password (required string,
 * 1–255 characters), and an optional remember-me flag (accepts: on, off, true, false, 0, 1).
 * Returns a localised error message for invalid remember-me values.
 *
 * @package Cms\Core\Http\Requests\Auth
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
class SignInRequest extends FormRequest
{
    use RedirectFailedTrait;

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
    public function rules(): array
    {
        return [
            'username' => 'required|string|min:1|max:255',
            'password' => 'required|string|min:1|max:255',
            'remember-me' => 'nullable|in:on,off,true,false,0,1',
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
            'remember-me.boolean' => __('validation.boolean', ['attribute' => __('text.auth.remember')]),
        ];
    }
}
