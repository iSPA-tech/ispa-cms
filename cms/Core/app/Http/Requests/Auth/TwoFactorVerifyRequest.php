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
 * Validates input for two-factor authentication verification.
 *
 * Rules: Requires a 6-digit code; either challenge_id (UUID) or email must be provided.
 *
 * @package Cms\Core\Http\Requests\Auth
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
class TwoFactorVerifyRequest extends FormRequest
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
            'challenge_id' => 'nullable|uuid|required_without:email',
            'email' => 'nullable|email|required_without:challenge_id',
            'code' => 'required|string|digits:6',
        ];
    }
}
