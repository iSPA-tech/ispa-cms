<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Http\Requests\Account;

use App\Constants\Tables;
use App\Models\User;
use Cms\Core\Models\Upload;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates input for updating the authenticated user's account profile.
 *
 * Rules: requires name. The sign-in name, e-mail and mobile are validated (format, uniqueness) only for a user who may
 * change them ({@see self::mayChange()}): the form disables them otherwise and the controller keeps their current value
 *. An avatar must be a free upload or the user's own current avatar; an upload attached to something else is
 * refused, so the avatar cannot take it over.
 *
 * @package Cms\Core\Http\Requests\Account
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
class PutUpdateAccountRequest extends FormRequest
{
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
        $user = $this->user();
        $rules = [
            'name' => 'required|string|max:255',
            'avatar' => 'nullable|array|max:1',
            'avatar.*.id' => ['required', 'integer', 'exists:uploads,id', $this->avatarIsFree($user)],
        ];
        $fieldRules = [
            'email' => 'required|string|email|max:255|unique:' . Tables::USERS . ',email,' . user('id'),
            'username' => 'required|string|max:255|unique:' . Tables::USERS . ',username,' . user('id'),
            'mobile' => 'nullable|string|max:255|unique:' . Tables::USERS . ',mobile,' . user('id'),
        ];
        foreach ($fieldRules as $field => $rule) {
            if (self::mayChange($user, $field)) {
                $rules[$field] = $rule;
            }
        }

        return $rules;
    }

    /**
     * Whether the user may change their own `username`, `email` or `mobile`: a Webmaster always may, anyone else needs
     * `user.<field>`, the same permission the own-profile form checks before it enables the field.
     */
    public static function mayChange(mixed $user, string $field): bool
    {
        if (! $user instanceof User) {
            return false;
        }

        return $user->isWebmaster() || $user->can('user.' . $field);
    }

    /**
     * The avatar is a free upload (attached to nothing) or already this user's avatar; anything else belongs to another
     * record and is refused.
     */
    private function avatarIsFree(mixed $user): \Closure
    {
        return static function (string $attribute, mixed $value, \Closure $fail) use ($user): void {
            $upload = Upload::query()->find((int) $value, ['id', 'target_type', 'target_id', 'category']);
            if ($upload === null || ($upload->target_id === null && $upload->target_type === null)) {
                return;
            }
            $ownAvatar = $user instanceof User && $upload->target_type === User::class
                && (int) $upload->target_id === (int) $user->getKey() && $upload->category === 'avatar';
            if (! $ownAvatar) {
                $fail(__('validation.exists', ['attribute' => $attribute]));
            }
        };
    }
}
