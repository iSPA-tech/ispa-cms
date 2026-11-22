<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Http\Requests\Calendar;

use Cms\Core\Constants\CoreTableConstant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Response;
use Cms\Core\Http\RedirectFailedTrait;

/**
 * Validates input for creation of a calendar entry.
 *
 * Rules: Requires name and date_from strings; optional url.
 *
 * @package Cms\Core\Http\Requests\Calendar
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
class StoreCalendarRequest extends FormRequest
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
        'name' => 'required|string',
        'date_from' => 'required|string',
        'url' => 'string|nullable',
        'language_id' => 'required|integer|exists:' . CoreTableConstant::LANGUAGES . ',id',
        'status' => 'nullable|string|max:50|exists:' . CoreTableConstant::ENUMERATES . ',enum_key',
    ];
  }
}
