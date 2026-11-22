<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Http\Requests\Storage;

use Cms\Core\Http\RedirectFailedTrait;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates input for creation of a storage.
 *
 * Rules: Requires name (max 255), code (max 20), and status enum key.
 *
 * @package Cms\Core\Http\Requests\Storage
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
class StoreStorageRequest extends FormRequest
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
            'code' => 'string|required|max:20',
            'status' => 'required|exists:enumerates,enum_key',
        ];
    }
}
