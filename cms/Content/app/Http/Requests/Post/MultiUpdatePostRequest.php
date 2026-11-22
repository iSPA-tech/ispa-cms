<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Content\Http\Requests\Post;

use Cms\Core\Http\RedirectFailedTrait;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates input for bulk update of posts.
 *
 * Rules: requires col (column name, max 255), value, and selected_id (array of post IDs, min 1).
 *
 * @package Cms\Content\Http\Requests\Post
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
class MultiUpdatePostRequest extends FormRequest
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
            'col' => 'required|string|max:255',
            'value' => 'required',
            'selected_id' => 'required|array|min:1',
        ];
    }
}
