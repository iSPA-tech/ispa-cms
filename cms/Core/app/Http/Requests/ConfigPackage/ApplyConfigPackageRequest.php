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
 * Input for applying a dry run: `choices` = section => item key => bool (or section => list of ticked keys); may be
 * empty. The applier honours only keys the dry run offered.
 */
class ApplyConfigPackageRequest extends ConfigPackageRequest
{
    /**
     * Validation rules for an apply.
     *
     * @return array<string, mixed> Rules keyed by field.
     */
    public function rules(): array
    {
        return [
            'choices' => ['present', 'array', 'max:20'],
            'choices.*' => ['array', 'max:5000'],
        ];
    }

    /**
     * Choices as sent (section => choices), empty when none.
     *
     * @return array<string, array<array-key, mixed>>
     */
    public function choices(): array
    {
        $choices = $this->validated('choices');

        return is_array($choices) ? $choices : [];
    }
}
