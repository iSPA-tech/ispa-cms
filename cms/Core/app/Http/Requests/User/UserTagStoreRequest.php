<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Http\Requests\User;

use Cms\Core\Services\UserTagService;
use Illuminate\Foundation\Http\FormRequest;

/** Validates creating a user tag. Plain Laravel validation (422); deliberately no RedirectFailedTrait (it answers 406). */
class UserTagStoreRequest extends FormRequest
{
    /** Creating a tag needs `user.update`, like attaching it. */
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('user.update');
    }

    /** The name is required, at most 120 characters. */
    public function rules(): array
    {
        return ['name' => ['required', 'string', 'max:' . UserTagService::NAME_MAX]];
    }
}
