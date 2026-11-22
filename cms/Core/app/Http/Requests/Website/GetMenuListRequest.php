<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Http\Requests\Website;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates input for fetching a website menu list.
 *
 * Rules: Optional type string filter.
 *
 * @package Cms\Core\Http\Requests\Website
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
class GetMenuListRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     * @return bool
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\Rule|array|string>
     */
    public function rules(): array
    {
        return [
            'type' => 'string|nullable'
        ];
    }
}
