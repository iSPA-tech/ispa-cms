<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Http\Requests\Contact;

use Cms\Core\Http\RedirectFailedTrait;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates input for creation of a contact.
 *
 * Rules: Requires name, email, mobile, and organization; optional address, url, source, type, birthday.
 *
 * @package Cms\Core\Http\Requests\Contact
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
class StoreContactRequest extends FormRequest
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
            'name' => 'string|required|max:255',
            'email' => 'email|required|max:255',
            'mobile' => 'string|required|max:20',
            'address' => 'string|nullable|max:500',
            'organization' => 'nullable|required|max:100',
            'url' => 'string|nullable|max:1000',
            'source' => 'string|nullable|max:50',
            'type' => 'string|nullable|max:20',
            'birthday' => 'date|nullable',
        ];
    }
}
