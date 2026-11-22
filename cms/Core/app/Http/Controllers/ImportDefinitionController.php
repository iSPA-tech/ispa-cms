<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Http\Controllers;

use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Cms\Core\Services\Import\ImportDefinitionRegistry;

/**
 * Serves import definition metadata for a given module/entity pair.
 *
 * Used by the admin frontend to fetch template headers, mapping schemas,
 * and notes for CSV/Excel import. Resolves definitions through the
 * ImportDefinitionRegistry and enforces per-entity import permissions.
 *
 * @see \Cms\Core\Services\Import\ImportDefinitionRegistry
 * @package Cms\Core\Http\Controllers
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
class ImportDefinitionController extends Controller
{
    /**
     * @param  ImportDefinitionRegistry  $registry
     */
    public function __construct(
        protected ImportDefinitionRegistry $registry
    )
    {
        parent::__construct();
    }

    /**
     * Return the import template metadata for a module/entity combination.
     *
     * Checks the user's import permission before returning the template key,
     * column headers, notes, and field-to-column mappings.
     *
     * @param  Request  $request
     * @param  string   $module  Module name (e.g. "Product", "Education")
     * @param  string   $entity  Entity name (e.g. "product", "instructor")
     * @return mixed             AppResponse with template metadata or error
     */
    public function show(Request $request, string $module, string $entity): mixed
    {
        // Resolve definition
        $definition = $this->registry->resolve($module, $entity);

        if (!$definition) {
            return ires()->error()->message("Import definition not found for {$module}/{$entity}");
        }

        // Check permission
        $permissionKey = $this->resolvePermissionKey($module, $entity);
        if (!user()?->can($permissionKey)) {
            return ires()->error()->message(__('mgs.no.permission'));
        }

        // Get metadata
        $templateKey = method_exists($definition, 'templateKey')
            ? $definition->templateKey()
            : "{$module}/{$entity}";

        $headers = method_exists($definition, 'templateHeaders')
            ? $definition->templateHeaders()
            : $definition->fillable();

        $notes = method_exists($definition, 'templateNotes')
            ? $definition->templateNotes()
            : [];

        $labels = method_exists($definition, 'templateLabels')
            ? $definition->templateLabels()
            : [];

        $mappings = method_exists($definition, 'inputMappings')
            ? $this->sanitizeMappings($definition->inputMappings())
            : [];

        return ires()->data([
            'templateKey' => $templateKey,
            'headers' => $headers,
            'notes' => $notes,
            'labels' => $labels,
            'mappings' => $mappings,
        ]);
    }

    /**
     * Resolve permission key from module and entity
     */
    protected function resolvePermissionKey(string $module, string $entity): string
    {
        $moduleLower = str($module)->lower();
        $entityLower = str($entity)->singular()->snake()->lower();

        return "$moduleLower.$entityLower.import";
    }

    /**
     * Sanitize mappings to remove sensitive class names
     */
    protected function sanitizeMappings(array $mappings): array
    {
        return array_map(static function ($mapping) {
            $sanitized = [
                'input' => $mapping['input'] ?? null,
                'target' => $mapping['target'] ?? null,
                'type' => $mapping['type'] ?? 'direct',
            ];

            // Add lookup label for FK lookups
            if ($mapping['type'] === 'fk_lookup' && isset($mapping['lookup'])) {
                $lookup = $mapping['lookup'];
                $modelClass = $lookup['model'] ?? '';
                $column = $lookup['column'] ?? '';
                $result = $lookup['result'] ?? 'id';

                // Extract just the model name without namespace
                $modelName = class_basename($modelClass);

                $sanitized['lookup_label'] = "{$modelName}.{$column} → {$result}";
                $sanitized['required'] = $mapping['required'] ?? false;
            }

            return $sanitized;
        }, $mappings);
    }
}
