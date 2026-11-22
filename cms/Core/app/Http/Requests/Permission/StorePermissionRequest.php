<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Http\Requests\Permission;

use Cms\Core\Http\RedirectFailedTrait;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates input for creation of a permission.
 *
 * Rules: Requires group (max 50); additional name, title, and rules fields available but currently commented out.
 *
 * @package Cms\Core\Http\Requests\Permission
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
class StorePermissionRequest extends FormRequest
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
            'group' => 'string|required|max:50',
//            'name' => 'string|required|max:50',
//            'title' => 'string|required|max:255',
//            'rules' => 'array|required|min:1',
        ];
    }
}
