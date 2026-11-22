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
 * Validates input for verifying an authentication challenge.
 *
 * Rules: Requires challenge_id (UUID) and code (string, max 64 chars).
 *
 * @package Cms\Core\Http\Requests\Auth
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
class VerifyAuthChallengeRequest extends FormRequest
{
    use RedirectFailedTrait;

    /**
     * Determine if the user is authorized to make this request.
     *
     * All users (including unauthenticated) are authorized to verify an auth challenge.
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
     * Get the validation rules for verifying an authentication challenge.
     *
     * Requires challenge_id as a valid UUID and code as a string (max 64 characters).
     *
     * @return array<string, string> Validation rules keyed by field name.
     *
     * @author  iSPA Team
     * @since   8.0.0 (2026-03-17)
     */
    public function rules(): array
    {
        return [
            'challenge_id' => 'required|uuid',
            'code' => 'required|string|max:64',
        ];
    }
}
