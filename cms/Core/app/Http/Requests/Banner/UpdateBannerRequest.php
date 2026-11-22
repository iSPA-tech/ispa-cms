<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Http\Requests\Banner;

use Cms\Core\Http\RedirectFailedTrait;
use Illuminate\Foundation\Http\FormRequest;
use JetBrains\PhpStorm\ArrayShape;

/**
 * Validates input for modification of a banner.
 *
 * Rules: Requires name, url, exactly one image, and a valid status enum key.
 *
 * @package Cms\Core\Http\Requests\Banner
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
class UpdateBannerRequest extends FormRequest
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
             'url' => 'string|required',
             'images' => 'array|required|min:1|max:1',
             'status' => 'required|exists:enumerates,enum_key',
         ];
     }
}
