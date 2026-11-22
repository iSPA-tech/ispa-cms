<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Http\Requests\Menu;


use Cms\Core\Http\RedirectFailedTrait;
use Illuminate\Foundation\Http\FormRequest;
use Cms\Core\Constants\CoreTableConstant;
use Cms\Core\Rules\MenuCategoryExpansionRule;

/**
 * Validates input for creation of a menu.
 *
 * Rules: Requires name (max 255), group_key (max 20), language_id (must exist), and status enum key.
 *
 * @package Cms\Core\Http\Requests\Menu
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
class StoreMenuRequest extends FormRequest
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
            'group_key' => 'required|string|max:20',
            'language_id' => 'required|numeric|exists:' . CoreTableConstant::LANGUAGES . ',id',
            'status' => 'required|exists:enumerates,enum_key',
            'items' => ['sometimes', 'array', new MenuCategoryExpansionRule()],
        ];
    }
}
