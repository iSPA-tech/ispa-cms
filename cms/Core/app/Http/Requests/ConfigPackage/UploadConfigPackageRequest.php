<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

declare(strict_types=1);

namespace Cms\Core\Http\Requests\ConfigPackage;

/**
 * Input for importing a configuration package: one `.json` file of at most 20 MB (multipart field `file`).
 */
class UploadConfigPackageRequest extends ConfigPackageRequest
{
    /** Maximum upload size in kilobytes (20 MB, same as PackageFormat::MAX_DECODED_BYTES). */
    public const MAX_KILOBYTES = 20480;

    /**
     * Validation rules for a package upload.
     *
     * @return array<string, mixed> Rules keyed by field.
     */
    public function rules(): array
    {
        return [
            'file' => [
                'required',
                'file',
                'max:' . self::MAX_KILOBYTES,
                'extensions:json',
                'mimetypes:application/json,text/plain',
            ],
        ];
    }
}
