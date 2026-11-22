<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Http\Requests\V3;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Validates input for fetching featured categories in V3 API.
 *
 * Rules: Optional limit and childLimit (integers, min 3, max 100).
 *
 * @package Cms\Core\Http\Requests\V3
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
class GetFeaturedCategoriesRequest extends FormRequest
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
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'limit' => 'integer|min:3|max:100',
            'childLimit' => 'integer|min:3|max:100',
        ];
    }
}
