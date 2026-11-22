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
 * Form request for creating a new Widget resource.
 *
 * Validates: name (required, max 255), description (optional, max 500), js (optional,
 * max 500), template array (required, min 1 item) where each template must have name
 * (max 255), content (required), optional css, and optional query array (each with name
 * and value strings). Also validates an optional inputs array where each input must have
 * name (max 255), type (max 255), and optional options array.
 *
 * `deleted_template_ids` / `deleted_input_ids` (Gate 0 Task 3 staged child
 * deletion) are prohibited on create: a brand-new Widget has no existing
 * children to stage a deletion against.
 *
 * @package Cms\Core\Http\Requests\Widget
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
class StoreWidgetRequest extends FormRequest
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
            // Per-language names: only vi/en, strings up to the column length (WidgetNameTranslations).
            ...WidgetNameTranslations::rules('name_translations'),
            ...WidgetNameTranslations::rules('description_translations', WidgetNameTranslations::DESCRIPTION_MAX),
            ...WidgetNameTranslations::rules('template.*.name_translations'),
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:500',
            'js' => 'nullable|string|max:500',
            'template' => 'array|required|min:1',
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
            'deleted_template_ids' => ['prohibited'],
            'deleted_input_ids' => ['prohibited'],
        ];
    }
}
