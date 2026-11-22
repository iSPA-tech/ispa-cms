<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Http\Requests\Province;

use Cms\Core\Http\RedirectFailedTrait;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates input for creation of a province.
 *
 * Rules: Requires name, global_name (max 255), and status enum key; optional slug, maps_id, type, priority.
 *
 * @package Cms\Core\Http\Requests\Province
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
class StoreProvinceRequest extends FormRequest
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
            'name' => 'string|required|max:255',
            'global_name' => 'string|required|max:255',
            'slug' => 'string|nullable|max:255',
            'maps_id' => 'string|nullable|max:255',
            'type' => 'numeric|nullable|max:127',
            'priority' => 'numeric|nullable|max:127',
            'status' => 'required|exists:enumerates,enum_key',
        ];
    }
}
