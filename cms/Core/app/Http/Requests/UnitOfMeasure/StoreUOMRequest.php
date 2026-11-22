<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Http\Requests\UnitOfMeasure;

use App\Constants\Tables;
use Cms\Core\Http\RedirectFailedTrait;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates input for creation of a unit of measure.
 *
 * Rules: Requires unique code, name, and symbol strings.
 *
 * @package Cms\Core\Http\Requests\UnitOfMeasure
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
class StoreUOMRequest extends FormRequest
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
            'code' => 'string|required|unique:' . Tables::UNIT_OF_MEASURE . ',code',
            'name' => 'string|required',
            'symbol' => 'string|required',
        ];
    }
}
