<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Http\Requests\EmailTemplate;

use Cms\Core\Http\RedirectFailedTrait;
use Cms\Core\Models\EmailTemplate;
use Cms\Core\Services\Mail\MailLayoutAllowlist;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates input for creation of an email template.
 *
 * Rules: Requires name, group, key_name (max 255), and content; optional language_id, mail_class (`system` /
 * `business`, default business), blade_layout (only a view on `core.mail_templates.layouts`), variables, sample_data,
 * description.
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
            'mail_class' => ['nullable', 'string', Rule::in(EmailTemplate::MAIL_CLASSES)],
            'blade_layout' => ['nullable', 'string', 'max:255', Rule::in(MailLayoutAllowlist::layouts())],
            'variables' => 'nullable|array',
            'sample_data' => 'nullable|array',
            'description' => 'nullable|string',
        ];
    }
}
