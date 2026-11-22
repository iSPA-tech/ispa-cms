<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Http\Requests\WidgetTheme;

use Cms\Core\Support\Widget\WidgetNameTranslations;
use Illuminate\Foundation\Http\FormRequest;
use Cms\Core\Http\RedirectFailedTrait;

/**
 * Validates input for creation of a widget theme.
 *
 * Rules: No additional validation rules; authorization only.
 *
 * @package Cms\Core\Http\Requests\WidgetTheme
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
class StoreWidgetThemeRequest extends FormRequest
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
      'widget_id' => ['required', 'integer', 'exists:widgets,id'],
      'name' => ['required', 'string', 'max:255'],
      'content' => ['required', 'string'],
      'css' => ['nullable', 'string'],
      'query' => ['nullable', 'array'],
      'project_name' => ['nullable', 'array'],
      // Per-language design names: only vi/en, strings up to the column length.
      ...WidgetNameTranslations::rules('name_translations'),
    ];
  }
}
