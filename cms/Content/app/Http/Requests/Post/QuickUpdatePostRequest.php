<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Content\Http\Requests\Post;

use App\Enums\StatusEnum;
use Cms\Content\Constants\ContentTableConstant as Tables;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Validates input for quick update of a post's status.
 *
 * Rules: requires status (must be "published" or "draft"). Authorization checks post.update permission.
 *
 * @package Cms\Content\Http\Requests\Post
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
class QuickUpdatePostRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     * @return bool
     */
    public function authorize(): bool
    {
        return $this->user()->can(str(Tables::POSTS)->singular() . '.update')
            || $this->user()?->can(str(Tables::POSTS)->singular() . '.update');
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'status' => 'required|string|in:'.StatusEnum::PUBLISHED->value.','.StatusEnum::DRAFT->value,
        ];
    }
}
