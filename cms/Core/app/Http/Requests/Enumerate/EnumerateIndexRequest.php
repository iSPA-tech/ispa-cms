<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Http\Requests\Enumerate;

use Cms\Core\Constants\CoreTableConstant;
use Cms\Core\Http\RedirectFailedTrait;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;

/**
 * Validates input for listing/filtering enumerates.
 *
 * Rules: Optional type_id (must exist in enumerate_types), keyword (max 100), page, and per_page (max 200).
 *
 * @package Cms\Core\Http\Requests\Enumerate
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
class EnumerateIndexRequest extends FormRequest
{
    use RedirectFailedTrait;

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
            'type_id' => [
                'nullable',
                'integer',
                'min:1',
                Rule::exists(CoreTableConstant::ENUMERATE_TYPES, 'id'),
            ],
            'keyword' => 'nullable|string|max:100',
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:1|max:200',
        ];
    }
}
