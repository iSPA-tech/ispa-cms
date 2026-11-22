<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Content\Http\Requests\Post;


use App\Rules\ContentRequired;
use Cms\Content\Rules\StructuredContentBlocks;
use Cms\Core\Http\RedirectFailedTrait;
use Illuminate\Foundation\Http\FormRequest;
use Cms\Core\Constants\CoreTableConstant;

/**
 * Validates input for creation of a post.
 *
 * Rules: requires name (max 255), type (max 20), content (ContentRequired), status (must exist in enumerates),
 * and slug; optional description (max 500) and video_url (max 500).
 *
 * @package Cms\Content\Http\Requests\Post
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
class StorePostRequest extends FormRequest
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
            'type' => 'required|string|max:20',
            'description' => 'nullable|string|max:500',
            'video_url' => 'nullable|string|max:500',
            'content' => [new ContentRequired, new StructuredContentBlocks],
            'review_pipeline' => 'nullable|array',
            'review_pipeline.editorial_comparison' => 'nullable|array',
            'review_pipeline.editorial_comparison.engine_uuids' => 'nullable|array|size:2',
            'review_pipeline.editorial_comparison.engine_uuids.*' => 'uuid|distinct',
            'review_pipeline.editorial_comparison.vehicles' => 'nullable|array|size:2',
            'review_pipeline.editorial_comparison.suggestion' => 'nullable|array',
            'review_pipeline.editorial_comparison.suggestion_meta' => 'nullable|array',
            'review_pipeline.editorial_comparison.review' => 'nullable|array',
            'review_pipeline.editorial_comparison.review_meta' => 'nullable|array',
            'status' => 'required|exists:' . CoreTableConstant::ENUMERATES . ',enum_key',
            'slugs.slug' => 'string|required',
        ];
    }
}
