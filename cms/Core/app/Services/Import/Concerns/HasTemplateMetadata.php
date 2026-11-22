<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Services\Import\Concerns;

/**
 * Provides default implementations for import template metadata methods.
 *
 * Import definitions using this trait automatically derive the template key
 * from the model class namespace and expose fillable columns as default
 * template headers. Override individual methods to customise the generated
 * import template.
 *
 * @see \Cms\Core\Services\Import\Concerns\HasImportMappings
 * @package Cms\Core\Services\Import\Concerns
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
trait HasTemplateMetadata
{
    /**
     * Derive a template key from the model class namespace.
     *
     * Extracts the module name and model name from the fully-qualified
     * class name and returns "{Module}/{models}" in lowercase.
     *
     * @return string  Template key in "{Module}/{model}s" format
     */
    public function templateKey(): string
    {
        $modelClass = $this->modelClass();
        $parts = explode('\\', $modelClass);
        
        // Extract module and model name
        // Example: Cms\Education\Models\Instructor => Education/instructors
        $module = $parts[1] ?? 'Core';
        $model = end($parts);
        
        return $module . '/' . strtolower($model) . 's';
    }
    
    /**
     * Return column headers for the import template.
     *
     * Defaults to the model's fillable columns. Override to include
     * virtual headers used for FK mapping or human-readable columns.
     *
     * @return array  List of column header strings
     */
    public function templateHeaders(): array
    {
        return $this->fillable();
    }
    
    /**
     * Return instructional notes for the import template.
     *
     * Defaults to an empty array. Override to provide per-column hints
     * or formatting instructions displayed in the template header row.
     *
     * @return array  Array of note strings keyed by column name
     */
    public function templateNotes(): array
    {
        return [];
    }

    /**
     * Return selectable values for Excel template columns.
     *
     * Defaults to an empty array. Override to provide option lists keyed by
     * template header name. CSV/TXT templates ignore these values.
     *
     * @return array<string, array<int, string>>
     */
    public function templateDropdowns(): array
    {
        return [];
    }
}
