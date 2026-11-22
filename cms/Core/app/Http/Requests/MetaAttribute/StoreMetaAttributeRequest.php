<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Http\Requests\MetaAttribute;

use Cms\Core\Http\RedirectFailedTrait;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates input for creation of a meta attribute.
 *
 * Rules: Requires name, type (bigInt|string|text|tinyInt|date|boolean), and label; optional options array.
 *
 * @package Cms\Core\Http\Requests\MetaAttribute
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
class StoreMetaAttributeRequest extends FormRequest
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
            'name' => 'string|required',
            'type' => 'string|required|in:bigInt,string,text,tinyInt,date,boolean',
            'options' => 'array|nullable',
            'label' => 'string|required',
        ];
    }
}
