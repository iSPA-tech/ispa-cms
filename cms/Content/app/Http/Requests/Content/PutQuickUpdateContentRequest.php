<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Content\Http\Requests\Content;

use App\Enums\ContentStatusEnum;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates input for quick update of a content item.
 *
 * Rules: `col` is `status` or `is_confirmed`; `val` is a Content status value for `status`, a boolean for `is_confirmed`.
 *
 * @package Cms\Content\Http\Requests\Content
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
class PutQuickUpdateContentRequest extends FormRequest
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
    public function rules(): array
    {
        $col = $this->input('col');
        $val = match ($col) {
            'status' => ['required', 'string', Rule::in(array_column(ContentStatusEnum::cases(), 'value'))],
            'is_confirmed' => ['required', 'boolean'],
            default => ['nullable'],
        };

        return [
            'col' => ['required', 'string', Rule::in(['status', 'is_confirmed'])],
            'val' => $val,
        ];
    }
}
