<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Http\Requests\User;

use Cms\Core\Http\RedirectFailedTrait;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates input for setting a user password.
 *
 * Rules: Requires password (min 6, confirmed) unless auto_password is true.
 *
 * @package Cms\Core\Http\Requests\User
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
class SetPasswordRequest extends FormRequest
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
        $password = '';
        if ((bool)request('auto_password', false) === false) {
            $password = '|string|min:6|confirmed';
        }

        return [
            'auto_password' => 'boolean',
            'password' => 'required_if:auto_password,false' . $password,
        ];
    }
}
