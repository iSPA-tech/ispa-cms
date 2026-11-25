<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Http\Requests\EmailTemplate;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Response;
use Cms\Core\Http\RedirectFailedTrait;
use Cms\Core\Models\EmailTemplate;
use Cms\Core\Services\Mail\MailLayoutAllowlist;
use Illuminate\Validation\Rule;

/**
 * Validates input for modification of an email template.
 *
 * Rules: every field optional (only the sent fields change); when sent: name, group, key_name and content are
 * non-empty strings, mail_class is `system` or `business`, blade_layout is empty or a view on
 * `core.mail_templates.layouts`, variables and sample_data are objects/lists.
 *
 * @package Cms\Core\Http\Requests\EmailTemplate
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
class UpdateEmailTemplateRequest extends FormRequest
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
      'name' => 'sometimes|required|string|max:255',
      'group' => 'sometimes|required|string|max:255',
      'key_name' => 'sometimes|required|string|max:255',
      'content' => 'sometimes|required|string',
      'language_id' => 'sometimes|nullable|integer|exists:languages,id',
      'mail_class' => ['sometimes', 'required', 'string', Rule::in(EmailTemplate::MAIL_CLASSES)],
      'blade_layout' => ['sometimes', 'nullable', 'string', 'max:255', Rule::in(MailLayoutAllowlist::layouts())],
      'variables' => 'sometimes|nullable|array',
      'sample_data' => 'sometimes|nullable|array',
      'description' => 'sometimes|nullable|string',
    ];
  }
}
