<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Http\Requests\User;

use Cms\Core\Http\RedirectFailedTrait;
use Cms\Core\Services\Security\AdminMfaService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates input for saving a user setting.
 *
 * Accepts only canonical localization and account-security settings.
 *
 * @package Cms\Core\Http\Requests\User
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
class PutSaveSettingRequest extends FormRequest
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
            'localization' => ['sometimes', 'array:language,timezone'],
            'localization.language' => ['sometimes', 'array:value,type,options'],
            'localization.language.value' => [
                'required_with:localization.language',
                'string',
                Rule::exists('languages', 'path')->where(static fn ($query) => $query
                    ->where('status', 'published')
                    ->whereNull('deleted_at')),
            ],
            'localization.language.type' => ['sometimes', 'string', Rule::in(['select'])],
            'localization.language.options' => ['sometimes', 'array'],
            'localization.timezone' => ['sometimes', 'array:value,type,options'],
            'localization.timezone.value' => ['required_with:localization.timezone', 'string', Rule::in(timezone_identifiers_list())],
            'localization.timezone.type' => ['sometimes', 'string', Rule::in(['select'])],
            'localization.timezone.options' => ['sometimes', 'array'],
            'security' => ['sometimes', 'array:email_change_password,mfa_mode,two_fa'],
            'security.email_change_password' => ['sometimes', 'array:value,type'],
            'security.email_change_password.value' => ['required_with:security.email_change_password', 'boolean'],
            'security.email_change_password.type' => ['sometimes', 'string', Rule::in(['boolean'])],
            'security.mfa_mode' => ['sometimes', 'array:value,type,options'],
            'security.mfa_mode.value' => ['required_with:security.mfa_mode', 'string', Rule::in(AdminMfaService::validModes())],
            'security.mfa_mode.type' => ['sometimes', 'string', Rule::in(['select'])],
            'security.mfa_mode.options' => ['sometimes', 'array'],
            'security.two_fa' => ['sometimes', 'array:value,type'],
            'security.two_fa.value' => ['required_with:security.two_fa', 'boolean'],
            'security.two_fa.type' => ['sometimes', 'string', Rule::in(['boolean'])],
        ];
    }

    protected function withValidator($validator): void
    {
        $validator->after(static function ($validator): void {
            foreach (array_keys($validator->getData()) as $key) {
                if (!in_array($key, ['localization', 'security'], true)) {
                    $validator->errors()->add($key, 'This setting field is not writable.');
                }
            }
        });
    }
}
