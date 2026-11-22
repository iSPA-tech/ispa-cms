<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Http\Requests\Search;

use JetBrains\PhpStorm\ArrayShape;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates input for home page search queries.
 *
 * Rules: Optional query string (q), page (min 1), and limit (min 1, max 30).
 *
 * @package Cms\Core\Http\Requests\Search
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
class HomeSearchRequest extends FormRequest
{
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
     * @return array<string, mixed>
     */
    #[ArrayShape(['q' => "string", 'page' => "string", 'limit' => "string"])] public function rules(): array
    {
        return [
            'q' => 'string|nullable',
            'page' => 'integer|min:1|nullable',
            'limit' => 'integer|min:10|nullable',
        ];
    }
}
