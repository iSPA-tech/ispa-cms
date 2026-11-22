<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Http\Requests\Widget;

use Cms\Core\Http\RedirectFailedTrait;
use Cms\Core\Support\Widget\WidgetNameTranslations;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Form request for updating an existing Widget resource.
 *
 * Validates: name (required, max 255), description (optional, max 500), js (optional,
 * max 500), template array (required, min 1 item) where each template must have name
 * (max 255), content (required), optional css, and optional query array (each with name
 * and value strings). Also validates an optional inputs array where each input must have
 * name (max 255), type (max 255), and optional options array.
 *
 * Also validates optional `deleted_template_ids` / `deleted_input_ids` arrays of
 * positive integers (Gate 0 Task 3 staged child deletion). Sending
 * `deleted_template_ids` opts the `template` array out of its usual
 * required|min:1 rule: staged-deletion saves may resubmit no templates at
 * all and rely solely on existing (untouched) themes plus the deletion set.
 * Omitting `deleted_template_ids` keeps the legacy required|min:1 rule
 * exactly as before.
 *
 * @package Cms\Core\Http\Requests\Widget
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
class UpdateWidgetRequest extends FormRequest
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
        // Presence of deleted_template_ids (regardless of it being empty)
        // opts this save into the staged-deletion contract for templates,
        // where omitted-from-payload themes simply survive untouched. That
        // makes an empty/absent `template` array legitimate, so the
        // required|min:1 rule only applies when the field is absent —
        // preserving current behavior exactly for callers who never send it.
        $templateRule = $this->has('deleted_template_ids') ? 'array' : 'array|required|min:1';

        return [
            // Per-language names: only vi/en, strings up to the column length (WidgetNameTranslations).
            ...WidgetNameTranslations::rules('name_translations'),
            ...WidgetNameTranslations::rules('description_translations', WidgetNameTranslations::DESCRIPTION_MAX),
            ...WidgetNameTranslations::rules('template.*.name_translations'),
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:500',
            'js' => 'nullable|string|max:500',
            'template' => $templateRule,
            'template.*.name' => 'required|string|max:255',
            'template.*.content' => 'required|string',
            'template.*.css' => 'nullable|string',
            'template.*.query' => 'nullable|array',
            'template.*.query.*.name' => 'required|string',
            'template.*.query.*.value' => 'required|string',
            'inputs' => 'nullable|array',
            'inputs.*.name' => 'required|string|max:255',
            'inputs.*.type' => 'required|string|max:255',
            'inputs.*.options' => 'nullable|array',
            'deleted_template_ids' => ['sometimes', 'array'],
            'deleted_template_ids.*' => ['integer', 'min:1'],
            'deleted_input_ids' => ['sometimes', 'array'],
            'deleted_input_ids.*' => ['integer', 'min:1'],
        ];
    }
}
