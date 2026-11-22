<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Http\Requests\Workflow;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates input for validating a workflow expression.
 *
 * Rules: Requires expression string; optional modelClass and sampleVars.
 *
 * @package Cms\Core\Http\Requests\Workflow
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
class ValidateExpressionRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * All authenticated users are authorized to validate workflow expressions.
     *
     * @return bool Always returns true.
     *
     * @author  iSPA Team
     * @since   8.0.0 (2026-03-17)
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, string>
     */
    public function rules(): array
    {
        return [
            'expression' => 'required|string',
            'modelClass' => 'string|nullable',
            'sampleVars' => 'array|nullable',
            'sampleSteps' => 'array|nullable',
            'sampleTrigger' => 'array|nullable',
            'allowDb' => 'boolean',
            'targetType' => 'required_with:allowDb,targetId|string|nullable',
            'targetId' => 'required_with:allowDb,targetType|integer|nullable',
            'strict' => 'boolean',
        ];
    }
}
