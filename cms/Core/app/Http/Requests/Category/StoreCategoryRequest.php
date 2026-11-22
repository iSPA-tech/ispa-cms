<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Http\Requests\Category;

use Cms\Core\Http\RedirectFailedTrait;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates input for creation of a category.
 *
 * Rules: Requires name (max 255), status enum key, and slug; optional priority and type (numeric, max 127).
 *
 * @package Cms\Core\Http\Requests\Category
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
class StoreCategoryRequest extends FormRequest
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
            'name' => 'required|string|max:255',
            'priority' => 'nullable|numeric|max:127',
            'type' => 'nullable|numeric|max:127',
            //      'hashtag' => 'nullable|array|max:10',
            'status' => 'required|exists:enumerates,enum_key',
            'slugs.slug' => 'required|string|max:255',
        ];
    }
}
