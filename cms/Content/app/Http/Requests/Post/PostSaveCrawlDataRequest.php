<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Content\Http\Requests\Post;

use App\Rules\ContentRequired;
use Cms\Core\Http\RedirectFailedTrait;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Validates input for saving crawled post data.
 *
 * Rules: requires title (max 255) and content (ContentRequired rule); optional description (max 500) and thumbnail (URL).
 *
 * @package Cms\Content\Http\Requests\Post
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
class PostSaveCrawlDataRequest extends FormRequest
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
            'title' => 'required|string|max:255',
            'description' => 'nullable|string|max:500',
            'thumbnail' => 'nullable|url',
            'content' => new ContentRequired,
        ];
    }
}
