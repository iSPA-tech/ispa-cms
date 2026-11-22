<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Http\Requests\EmailTemplate;

use Cms\Core\Http\RedirectFailedTrait;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates input for creation of an email template.
 *
 * Rules: Requires name, group, key_name (max 255), and content; optional language_id, blade_layout, variables, sample_data, description.
 *
 * @package Cms\Core\Http\Requests\EmailTemplate
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
class StoreEmailTemplateRequest extends FormRequest
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
            'group' => 'required|string|max:255',
            'key_name' => 'required|string|max:255',
            'content' => 'required|string',
            'language_id' => 'nullable|integer|exists:languages,id',
            'blade_layout' => 'nullable|string|max:255',
            'variables' => 'nullable|array',
            'sample_data' => 'nullable|array',
            'description' => 'nullable|string',
        ];
    }
}
