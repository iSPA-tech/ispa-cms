<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Http\Requests\WorkflowProject;

use Illuminate\Foundation\Http\FormRequest;
use Cms\Core\Http\RedirectFailedTrait;

/**
 * Validates input for modification of a workflow project.
 *
 * Rules: Optional name, slug, and trigger_event (max 255 each) with sometimes rule.
 *
 * @package Cms\Core\Http\Requests\WorkflowProject
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
class UpdateWorkflowProjectRequest extends FormRequest
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
        'name' => 'sometimes|string|max:255',
        'slug' => 'sometimes|string|max:255',
        'trigger_event' => 'sometimes|string|max:255',
        'target_entity_type' => 'sometimes|string|max:255',
        'is_active' => 'sometimes|boolean',
        'tags' => 'sometimes|array|nullable',
        'nodes' => 'sometimes|array|nullable',
        'edges' => 'sometimes|array|nullable',
    ];
  }
}
