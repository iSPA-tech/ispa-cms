<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Http\Requests\Enumerate;

use Cms\Core\Http\RedirectFailedTrait;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates input for modification of an enumerate.
 *
 * Rules: Requires name (max 100), type (max 30), and type_id; optional name_alias and key_name.
 *
 * @package Cms\Core\Http\Requests\Enumerate
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
class UpdateEnumerateRequest extends FormRequest
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
            'name_alias' => 'string|nullable|max:100',
            'key_name' => 'string|nullable|max:50',
            'type' => 'string|required|max:30',
            'type_id' => 'numeric|required',
        ];
    }
}
