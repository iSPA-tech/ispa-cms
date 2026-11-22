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
 * Input for starting a backup now: optional destination uuids (empty = every enabled destination).
 */
class RunBackupRequest extends FormRequest
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
     * Validation rules for a manual backup run.
     *
     * @return array<string, mixed> Rules keyed by field.
     */
    public function rules(): array
    {
        return [
            'destinations' => ['nullable', 'array', 'max:50'],
            'destinations.*' => ['required', 'string', 'uuid'],
        ];
    }
}
