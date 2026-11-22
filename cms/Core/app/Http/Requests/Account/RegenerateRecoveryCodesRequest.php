<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Http\Requests\Account;

use Cms\Core\Http\RedirectFailedTrait;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates input for regenerating two-factor recovery codes.
 *
 * Rules: Requires current_password as a string with minimum 8 characters.
 *
 * @package Cms\Core\Http\Requests\Account
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
class RegenerateRecoveryCodesRequest extends FormRequest
{
    use RedirectFailedTrait;

    /**
     * Determine if the user is authorized to make this request.
     *
     * All authenticated users are authorized to regenerate their recovery codes.
     *
     * @return bool Always returns true.
     *
     * @author  iSPA Team
     * @since   8.0.0 (2026-03-17)
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules for regenerating MFA recovery codes.
     *
     * Requires the current password as a string with a minimum of 8 characters.
     *
     * @return array<string, string> Validation rules keyed by field name.
     *
     * @author  iSPA Team
     * @since   8.0.0 (2026-03-17)
     */
    public function rules(): array
    {
        return [
            'current_password' => 'required|string|min:8',
        ];
    }
}
