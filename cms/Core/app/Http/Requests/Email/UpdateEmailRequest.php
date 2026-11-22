<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Http\Requests\Email;

use Cms\Core\Http\RedirectFailedTrait;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates input for modification of an email.
 *
 * Rules: Requires subject (max 255), name (max 255), email (max 255), and content.
 *
 * @package Cms\Core\Http\Requests\Email
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
class UpdateEmailRequest extends FormRequest
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
            'subject' => 'string|required|max:255',
            'name' => 'string|required|max:255',
            'email' => 'string|required|max:255',
            'content' => 'string|required',
        ];
    }
}
