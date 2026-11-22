<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Http\Requests\Slug;

use Cms\Core\Http\RedirectFailedTrait;
use Illuminate\Foundation\Http\FormRequest;
use JetBrains\PhpStorm\ArrayShape;

/**
 * Validates input for creation of a slug.
 *
 * Rules: Requires slug string; optional table and id references.
 *
 * @package Cms\Core\Http\Requests\Slug
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
class StoreSlugRequest extends FormRequest
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
    #[ArrayShape(['slug' => 'string', 'table' => 'string', 'id' => 'string', 'language_id' => 'string', 'target_id' => 'string'])]
 public function rules(): array
 {
     return [
         'slug' => 'string|required',
         'table' => 'string|nullable',
         'id' => 'numeric|nullable',
         'language_id' => 'numeric|nullable',
         'target_id' => 'numeric|nullable',
     ];
 }
}
