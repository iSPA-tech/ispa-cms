<?php
/*
 * Copyright (c) 2026 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

declare(strict_types=1);

namespace Cms\Core\Http\Requests\Admin;

use Cms\Core\Services\Security\TokenAudience;
use Cms\Core\Services\Security\TokenSettings;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates a change of the API token settings (also used by the preview action). Every field is optional so a
 * partial change is possible; values outside the fixed option lists are a 422. Authorization is the `admin.webmaster`
 * route middleware.
 *
 * @package Cms\Core\Http\Requests\Admin
 */
class UpdateTokenSettingsRequest extends FormRequest
{
    /**
     * Access is enforced by route middleware.
     *
     * @return bool
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Validation rules.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $rules = [
            'lifetime' => ['sometimes', 'array'],
            'idle_days' => ['sometimes', 'array'],
            'max_live_tokens' => ['sometimes', 'integer', 'min:' . TokenSettings::MIN_LIVE_TOKENS, 'max:' . TokenSettings::MAX_LIVE_TOKENS],
            'mode' => ['sometimes', Rule::in(TokenSettings::MODES)],
            'unused_days' => ['sometimes', 'integer', Rule::in(TokenSettings::UNUSED_DAY_OPTIONS)],
            'notify_new_device' => ['sometimes', 'boolean'],
            'confirm_never_expire' => ['sometimes', 'boolean'],
        ];
        foreach (TokenAudience::userAudiences() as $audience) {
            $rules['lifetime.' . $audience] = ['sometimes', 'string', Rule::in(array_keys(TokenSettings::LIFETIME_OPTIONS))];
            $rules['idle_days.' . $audience] = ['sometimes', 'integer', Rule::in(TokenSettings::IDLE_DAY_OPTIONS)];
        }

        return $rules;
    }

    /**
     * Reject unknown audiences in the per-audience maps.
     *
     * @param \Illuminate\Validation\Validator $validator Validator instance.
     * @return void
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            foreach (['lifetime', 'idle_days'] as $group) {
                $unknown = array_diff(array_keys((array) $this->input($group, [])), TokenAudience::userAudiences());
                if ($unknown !== []) {
                    $validator->errors()->add($group, 'UNKNOWN_TOKEN_AUDIENCE');
                }
            }
        });
    }

    /**
     * Whether the change sets any audience to "never expires".
     *
     * @return bool
     */
    public function setsNever(): bool
    {
        return in_array(TokenSettings::NEVER, array_values((array) $this->input('lifetime', [])), true);
    }
}
