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
 * Validates input for confirming authenticator enrollment on a user account.
 *
 * Rules: Requires a 6-digit string code for TOTP verification.
 *
 * @package Cms\Core\Http\Requests\Account
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
class ConfirmAuthenticatorEnrollmentRequest extends FormRequest
{
    use RedirectFailedTrait;

    /**
     * Determine if the user is authorized to make this request.
     *
     * All authenticated users are authorized to confirm authenticator enrollment.
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
     * Get the validation rules for confirming authenticator enrollment.
     *
     * Requires a 6-digit string code for TOTP verification.
     *
     * @return array<string, string> Validation rules keyed by field name.
     *
     * @author  iSPA Team
     * @since   8.0.0 (2026-03-17)
     */
    public function rules(): array
    {
        return [
            'code' => 'required|string|digits:6',
        ];
    }
}
