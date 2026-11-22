<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Http\Requests\Auth;

use App\Constants\Tables;
use Cms\Core\Http\RedirectFailedTrait;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates input for user registration.
 *
 * Rules: Requires unique email and username, confirmed password (min 6), first/last name; optional mobile, organization.
 *
 * @package Cms\Core\Http\Requests\Auth
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
class RegisterRequest extends FormRequest
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
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'email' => 'email|required|unique:' . Tables::USERS . ',email',
            'password' => 'required|confirmed|min:6',
            'password_confirmation' => 'required',
            'username' => 'required|unique:' . Tables::USERS . ',username',
            'first_name' => 'required|string',
            'last_name' => 'required|string',
            'mobile' => 'nullable|string',
            'organization' => 'nullable|string',
        ];
    }
}
