<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Http\Requests\Translate;

use Cms\Core\Http\RedirectFailedTrait;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates input for client-side text translation.
 *
 * Rules: Requires content, from_language (2-char, vi|en), and to_language (2-char, vi|en|ja|ko|cn|ku|fr).
 *
 * @package Cms\Core\Http\Requests\Translate
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
class ClientTranslateTextRequest extends FormRequest
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
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'content' => 'string|required',
            'from_language' => 'string|required|size:2|in:vi,en',
            'to_language' => 'string|required|size:2|in:vi,en,ja,ko,cn,ku,fr',
        ];
    }
}
