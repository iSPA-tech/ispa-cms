<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Http\Requests\V3;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Form request for the V3 public contact submission endpoint.
 *
 * Normalises alternative field names before validation (e.g., full-name → name,
 * phone-number → phone_number, organization → company, url → page_url, id → source)
 * to accommodate various frontend form implementations. Validates: name (required),
 * email (required, valid email), company (optional, max 100), country (optional, max 2),
 * phone_number (optional, max 20), message (required), source (optional, max 50),
 * page_url (optional, valid URL), and subject (optional, max 255).
 *
 * @package Cms\Core\Http\Requests\V3
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
class StoreContactRequest extends FormRequest
{
    /**
     * Normalise alternative field names to canonical names before validation runs.
     *
     * Supports legacy field name variants from different frontend form implementations.
     *
     * @return void
     */
    protected function prepareForValidation(): void
    {
        $this->merge([
            'name' => $this->input('name')
                ?? $this->input('full-name')
                ?? $this->input('fullname'),
            'phone_number' => $this->input('phone_number')
                ?? $this->input('phone-number')
                ?? $this->input('mobile'),
            'company' => $this->input('company')
                ?? $this->input('organization'),
            'message' => $this->input('message')
                ?? $this->input('content'),
            'page_url' => $this->input('page_url')
                ?? $this->input('url'),
            'source' => $this->input('source')
                ?? $this->input('id'),
        ]);
    }

    /**
     * Determine if the user is authorized to make this request.
     * @return bool
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'email' => 'required|email|max:255',
            'company' => 'nullable|string|max:100',
            'country' => 'nullable|string|max:2',
            'phone_number' => 'nullable|string|max:20',
            'message' => 'required|string',
            'source' => 'nullable|string|max:50',
            'page_url' => 'nullable|url|max:1000',
            'subject' => 'nullable|string|max:255',
        ];
    }
}
