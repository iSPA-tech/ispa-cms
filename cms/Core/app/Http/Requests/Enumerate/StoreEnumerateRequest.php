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
 * Validates input for creation of an enumerate.
 *
 * Rules: Requires name (max 100), enum_key (max 50), type (max 30), and type_id; optional name_alias.
 *
 * @package Cms\Core\Http\Requests\Enumerate
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
class StoreEnumerateRequest extends FormRequest
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
            'enum_key' => 'string|required|max:50',
            'type' => 'string|required|max:30',
            'type_id' => 'numeric|required',
        ];
    }
}
