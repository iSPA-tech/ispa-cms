<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Http\Requests\User;

use Cms\Core\Services\UserTagService;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/** Body `{tag_uuids: [], user_uuids?: [], filter?: {...}}` of the bulk tag attach/detach endpoints; exactly one of `user_uuids` or `filter` (422 otherwise). No RedirectFailedTrait. */
class UsersTagAttachRequest extends FormRequest
{
    /** Attaching and detaching tags needs `user.update`. */
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('user.update');
    }

    /** Tag uuids are required; the user selection is checked by withValidator(). */
    public function rules(): array
    {
        return [
            'tag_uuids' => ['required', 'array', 'min:1', 'max:20'], 'tag_uuids.*' => ['uuid'],
            'user_uuids' => ['nullable', 'array', 'max:' . UserTagService::FILTER_RUN_CAP], 'user_uuids.*' => ['uuid'],
            'filter' => ['nullable', 'array'],
        ] + UserTagService::filterRules('filter.');
    }

    /** Reject both or neither of `user_uuids` / `filter`. */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            if (!empty($this->input('user_uuids')) === is_array($this->input('filter'))) {
                $v->errors()->add('user_uuids', 'Chọn người dùng hoặc dùng bộ lọc, không dùng cả hai.');
            }
        });
    }
}
