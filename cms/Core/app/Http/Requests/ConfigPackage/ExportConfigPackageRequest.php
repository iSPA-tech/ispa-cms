<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

declare(strict_types=1);

namespace Cms\Core\Http\Requests\ConfigPackage;

use Cms\Core\Services\ConfigPackage\PackageFormat;
use Illuminate\Validation\Rule;

/**
 * Input for starting a configuration package export: chosen sections and the translation scope.
 */
class ExportConfigPackageRequest extends ConfigPackageRequest
{
    /** Translation scopes the exporter supports. */
    public const TRANSLATION_SCOPES = ['web', 'adm', 'app', 'all'];

    /**
     * Validation rules for an export.
     *
     * @return array<string, mixed> Rules keyed by field.
     */
    public function rules(): array
    {
        return [
            'sections' => ['required', 'array', 'min:1', 'max:' . count(PackageFormat::SECTIONS)],
            'sections.*' => ['required', 'string', 'distinct', Rule::in(PackageFormat::SECTIONS)],
            'translation_scope' => ['nullable', 'string', Rule::in(self::TRANSLATION_SCOPES)],
        ];
    }
}
