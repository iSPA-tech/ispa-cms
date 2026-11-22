<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Http\Requests\Gallery;

use Cms\Core\Http\RedirectFailedTrait;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates input for modification of a gallery.
 *
 * Rules: Requires name (max 255) and status enum key; optional description (max 500), hashtag array, content array.
 *
 * @package Cms\Core\Http\Requests\Gallery
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
class UpdateGalleryRequest extends FormRequest
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
            'description' => 'nullable|string|max:500',
            'hashtag' => 'nullable|array',
            'content' => 'nullable|array',
            'status' => 'required|exists:enumerates,enum_key',
        ];
    }
}
