<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Http\Requests\Module;

use Cms\Core\Http\RedirectFailedTrait;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates input for toggling a module's enabled/disabled status.
 *
 * Rules: Requires enabled as a boolean value. Only a Webmaster is authorized.
 *
 * @package Cms\Core\Http\Requests\Module
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
class UpdateModuleStatusRequest extends FormRequest
{
    use RedirectFailedTrait;

    /**
     * Determine if the user is authorized to make this request.
     *
     * Only a Webmaster may enable or disable modules; other admin users may
     * only view the module list.
     *
     * @return bool True when the signed-in user is a Webmaster.
     *
     * @author  iSPA Team
     * @since   8.0.0 (2026-03-17)
     * @last_updated 2026-11-06 - Webmaster only (,).
     */
    public function authorize(): bool
    {
        return $this->user()?->isWebmaster() === true;
    }

    /**
     * Get the validation rules for toggling a module's enabled/disabled status.
     *
     * Requires enabled as a boolean value.
     *
     * @return array<string, array<int, string>> Validation rules keyed by field name.
     *
     * @author  iSPA Team
     * @since   8.0.0 (2026-03-17)
     */
    public function rules(): array
    {
        return [
            'enabled' => ['required', 'boolean'],
        ];
    }
}
