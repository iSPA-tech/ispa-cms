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
use Cms\Core\Services\Backup\Destinations\DestinationRegistry;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Shape of a new backup destination. Driver-specific settings, credentials and the retention-versus-lock rule are
 * checked afterwards by {@see \Cms\Core\Services\Backup\Destinations\DestinationRules}, which returns machine codes.
 */
class StoreBackupDestinationRequest extends FormRequest
{
    use RedirectFailedTrait;

    /** Schedule format stored on destinations: `daily@HH:MM` (Asia/Ho_Chi_Minh). */
    public const SCHEDULE_PATTERN = '/^daily@([01]\d|2[0-3]):[0-5]\d$/';

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
     * Validation rules for creating a destination.
     *
     * @return array<string, mixed> Rules keyed by field.
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'driver' => ['required', 'string', Rule::in(app(DestinationRegistry::class)->keys())],
            'config' => ['nullable', 'array'],
            'credentials' => ['nullable', 'array'],
            'credentials.*' => ['nullable', 'string', 'max:20000'],
            'retention_days' => ['nullable', 'integer', 'min:1', 'max:3650'],
            'lock_days' => ['nullable', 'integer', 'min:0', 'max:3650'],
            'schedule' => ['nullable', 'string', 'regex:' . self::SCHEDULE_PATTERN],
            'use_for_safety' => ['nullable', 'boolean'],
            'enabled' => ['nullable', 'boolean'],
        ];
    }
}
