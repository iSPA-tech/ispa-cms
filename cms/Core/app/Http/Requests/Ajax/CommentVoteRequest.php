<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Http\Requests\Ajax;

use Cms\Core\Http\RedirectFailedTrait;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Validates input for submitting a comment vote via AJAX.
 *
 * Rules: Requires type (string), targetId (integer), email, fullname, and content; optional image upload (max 3MB).
 *
 * @package Cms\Core\Http\Requests\Ajax
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
class CommentVoteRequest extends FormRequest
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
     * @return array<string, ValidationRule|array|string>
     */
    public function rules(): array
    {
        return [
            'type' => 'string|required',
            'targetId' => 'integer|required',
            'images' => 'nullable|image|max:3072',
            'email' => 'required|email',
            'fullname' => 'required|string',
            'content' => 'required|string',
        ];
    }
}
