<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Http\Requests\WorkflowProject;

use Cms\Core\Constants\CoreTableConstant as Tables;
use Cms\Core\Http\RedirectFailedTrait;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates input for creation of a workflow project.
 *
 * Rules: Requires name (max 255), unique slug (max 255), and description (max 1000).
 *
 * @package Cms\Core\Http\Requests\WorkflowProject
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
class StoreWorkflowProjectRequest extends FormRequest
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
            'slug' => 'required|string|max:255|unique:' . Tables::WORKFLOW_PROJECT . ',slug',
            'description' => 'nullable|string|max:1000',
            'trigger_event' => 'required|string|max:255',
            'target_entity_type' => 'required|string|max:255',
            'is_active' => 'boolean',
            'tags' => 'array|nullable',
            'nodes' => 'array|nullable',
            'edges' => 'array|nullable',
        ];
    }
}
