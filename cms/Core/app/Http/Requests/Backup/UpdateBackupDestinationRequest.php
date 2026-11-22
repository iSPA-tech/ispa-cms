<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

declare(strict_types=1);

namespace Cms\Core\Http\Requests\Backup;

use Cms\Core\Http\RedirectFailedTrait;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Shape of a destination update. Every field is optional; the driver cannot change. A blank or omitted credential
 * keeps the stored value (handled by the controller).
 */
class UpdateBackupDestinationRequest extends FormRequest
{
    use RedirectFailedTrait;

    /**
     * Access is decided by the `admin.webmaster` route middleware.
     *
     * @return bool Always true.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Validation rules for updating a destination.
     *
     * @return array<string, mixed> Rules keyed by field.
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:120'],
            'driver' => ['prohibited'],
            'config' => ['sometimes', 'nullable', 'array'],
            'credentials' => ['sometimes', 'nullable', 'array'],
            'credentials.*' => ['nullable', 'string', 'max:20000'],
            'retention_days' => ['sometimes', 'required', 'integer', 'min:1', 'max:3650'],
            'lock_days' => ['sometimes', 'required', 'integer', 'min:0', 'max:3650'],
            'schedule' => ['sometimes', 'nullable', 'string', 'regex:' . StoreBackupDestinationRequest::SCHEDULE_PATTERN],
            'use_for_safety' => ['sometimes', 'boolean'],
            'enabled' => ['sometimes', 'boolean'],
        ];
    }
}
