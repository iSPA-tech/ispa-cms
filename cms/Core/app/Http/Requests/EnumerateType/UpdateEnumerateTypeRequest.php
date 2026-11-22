<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Http\Requests\EnumerateType;

use Cms\Core\Http\RedirectFailedTrait;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates input for modification of an enumerate type.
 *
 * Rules: Requires name (max 100) and key_name (alpha_dash, max 50).
 *
 * @package Cms\Core\Http\Requests\EnumerateType
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
class UpdateEnumerateTypeRequest extends FormRequest
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
            'name' => 'string|required|max:100',
            'key_name' => 'alpha_dash|required|max:50',
        ];
    }
}
