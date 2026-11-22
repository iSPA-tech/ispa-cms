<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Services\Import;

use Illuminate\Database\Eloquent\Model;

/**
 * Immutable context passed to import definition validation rules.
 *
 * Provides access to the resolved row, import context, row index,
 * raw data, existing model (if matched), and import options for
 * context-aware validation rule generation.
 *
 * @see ImportDefinitionInterface::rules()
 * @package Cms\Core\Services\Import
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
readonly class ImportValidationContext
{
    /**
     * Initialize the validation context with row data, import context, and existing model reference.
     *
     * @param ResolvedRow   $row           The resolved row after transformation and mapping.
     * @param ImportContext $importContext The shared import context.
     * @param int           $rowIndex      The 1-based row index in the source file.
     * @param array         $rawRow        The original raw row data before transformation.
     * @param Model|null    $existingModel The matched existing model for update, or null for create.
     * @param ImportOptions $options       The import options controlling behavior.
     *
     * @author  iSPA Team
     * @since   8.0.0 (2026-03-17)
     */
    public function __construct(
        public ResolvedRow   $row,
        public ImportContext $importContext,
        public int           $rowIndex,
        public array         $rawRow,
        public ?Model        $existingModel,
        public ImportOptions $options
    )
    {
    }
}
