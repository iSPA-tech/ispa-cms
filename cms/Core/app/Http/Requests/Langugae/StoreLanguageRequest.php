<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Http\Requests\Langugae;

use Cms\Core\Http\RedirectFailedTrait;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates input for creation of a language.
 *
 * Rules: Requires name (max 100) and a unique lowercase path slug (max 10); optional path_alias, currency, code, time_zone, flag, status, is_locked.
 *
 * @package Cms\Core\Http\Requests\Langugae
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
class StoreLanguageRequest extends FormRequest
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
     * Normalise the status to the bare key stored on languages (`status_published` -> `published`).
     *
     * @return void
     */
    protected function prepareForValidation(): void
    {
        $status = $this->input('status');
        if (is_string($status)) {
            $this->merge(['status' => preg_replace('/^status_/', '', $status)]);
        }
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * The unique check on `path` deliberately includes soft-deleted rows, so a
     * deleted language cannot be shadowed by a new one with the same path.
     *
     * @return array
     */
    public function rules(): array
    {
        return [
            'name' => 'string|required|max:100',
            'path' => ['required', 'string', 'max:10', 'regex:/^[a-z]{2,3}(-[a-z0-9]{2,8})?$/', Rule::unique('languages', 'path')],
            'path_alias' => ['nullable', 'string', 'max:10', 'regex:/^[a-z]{2,3}(-[A-Za-z0-9]{2,8})?$/'],
            'currency' => 'string|nullable|max:10',
            'code' => ['nullable', 'string', 'max:10', 'uppercase'],
            'time_zone' => ['nullable', 'string', 'max:50', 'timezone'],
            'flag' => ['nullable', 'string', 'max:50', 'regex:/^[a-z0-9_-]+\.(svg|png)$/i'],
            'status' => 'sometimes|string|in:draft,pending,published,archived',
            'is_locked' => 'sometimes|boolean',
            'for_web' => 'sometimes|boolean',
            'for_adm' => 'sometimes|boolean',
            'for_app' => 'sometimes|boolean',
            'default_web' => 'sometimes|boolean',
            'default_adm' => 'sometimes|boolean',
            'default_app' => 'sometimes|boolean',
        ];
    }
}
