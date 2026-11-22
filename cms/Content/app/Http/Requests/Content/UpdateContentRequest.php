<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Content\Http\Requests\Content;

use Cms\Core\Http\RedirectFailedTrait;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates input for modification of a content item.
 *
 * Rules: requires name (max 255); optional description (max 500), content array, and video_url (active URL, max 500).
 *
 * @package Cms\Content\Http\Requests\Content
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
class UpdateContentRequest extends FormRequest
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
            'description' => 'string|nullable|max:500',
            'content' => 'array|nullable',
            'video_url' => 'nullable|active_url|max:500',
        ];
    }
}
