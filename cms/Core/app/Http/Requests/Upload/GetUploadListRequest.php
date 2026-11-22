<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Http\Requests\Upload;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Validates input for listing/filtering uploads.
 *
 * Rules: Optional type filter (image|avatar|video|audio|archive|document|all).
 *
 * @package Cms\Core\Http\Requests\Upload
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
class GetUploadListRequest extends FormRequest
{
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
     * @return array<string, ValidationRule|array|string>
     */
    public function rules(): array
    {
        return [
            'type' => 'string|nullable|in:image,avatar,video,audio,archive,document,all',
            'owner' => 'string|nullable|in:all,mine',
            'usage' => 'string|nullable|in:all,used,unused',
            'created_from' => 'date|nullable',
            'created_to' => 'date|nullable|after_or_equal:created_from',
            'target_folder' => 'string|nullable|max:100',
        ];
    }
}
