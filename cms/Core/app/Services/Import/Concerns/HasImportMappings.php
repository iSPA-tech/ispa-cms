<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Services\Import\Concerns;

use Cms\Core\Services\Import\ImportContext;
use Illuminate\Support\Facades\Log;

/**
 * Provides declarative input mapping functionality for import definitions.
 *
 * Allows import definitions to specify column mappings (direct, FK lookup,
 * and transform) declaratively. The trait resolves foreign key references
 * with caching through the ImportContext and applies column renaming and
 * value transformations during the import pipeline.
 *
 * @see \Cms\Core\Services\Import\ImportContext
 * @see \Cms\Core\Services\Import\Concerns\HasTemplateMetadata
 * @package Cms\Core\Services\Import\Concerns
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
trait HasImportMappings
{
    /**
     * Define input column mappings for this import definition.
     *
     * Override to return an array of mapping descriptors. Each mapping
     * has a 'type' key ('direct', 'fk_lookup', or 'transform').
     *
     * @return array  Array of mapping configuration arrays
     */
    public function inputMappings(): array
    {
        return [];
    }
    
    /**
     * Apply input mappings to raw data
     * 
     * @param array $raw Raw input data
     * @param ImportContext $ctx Import context (used for caching)
     * @return array Transformed data with resolved FKs and mapped columns
     */
    protected function applyInputMappings(array $raw, ImportContext $ctx): array
    {
        $mappings = $this->inputMappings();
        
        if (empty($mappings)) {
            return $raw;
        }
        
        $result = [];
        $inputKeysToRemove = [];
        
        foreach ($mappings as $mapping) {
            $type = $mapping['type'] ?? 'direct';
            
            switch ($type) {
                case 'direct':
                    $this->applyDirectMapping($mapping, $raw, $result);
                    break;
                    
                case 'fk_lookup':
                    $this->applyFkLookupMapping($mapping, $raw, $result, $ctx, $inputKeysToRemove);
                    break;
                    
                case 'transform':
                    $this->applyTransformMapping($mapping, $raw, $result);
                    break;
                    
                default:
                    Log::warning("Unknown mapping type: {$type}");
            }
        }
        
        // Merge result back into raw data
        $raw = array_merge($raw, $result);
        
        // Remove input-only keys that were used for FK resolution
        foreach ($inputKeysToRemove as $key) {
            unset($raw[$key]);
        }
        
        return $raw;
    }
    
    /**
     * Apply direct column mapping (rename input key to target key).
     *
     * @param  array  $mapping  Mapping descriptor with 'input' and 'target' keys
     * @param  array  $raw      Raw input data row
     * @param  array  &$result  Accumulated results (passed by reference)
     * @return void
     */
    protected function applyDirectMapping(array $mapping, array $raw, array &$result): void
    {
        $input = $mapping['input'] ?? null;
        $target = $mapping['target'] ?? null;
        
        if (!$input || !$target) {
            return;
        }
        
        if (array_key_exists($input, $raw)) {
            $result[$target] = $raw[$input];
        }
    }
    
    /**
     * Apply FK lookup mapping with caching via ImportContext.
     *
     * Resolves a human-readable input value (e.g. name) to a foreign key ID
     * by querying the related model. Results are cached in the ImportContext
     * to avoid repeated database lookups for the same value.
     *
     * @param  array          $mapping            Mapping descriptor with 'input', 'target', 'lookup' keys
     * @param  array          $raw                Raw input data row
     * @param  array          &$result            Accumulated results (passed by reference)
     * @param  ImportContext  $ctx                Import context for FK resolution cache
     * @param  array          &$inputKeysToRemove Keys to strip from final output (passed by reference)
     * @return void
     */
    protected function applyFkLookupMapping(
        array $mapping, 
        array $raw, 
        array &$result, 
        ImportContext $ctx,
        array &$inputKeysToRemove
    ): void {
        $input = $mapping['input'] ?? null;
        $target = $mapping['target'] ?? null;
        $lookup = $mapping['lookup'] ?? null;
        $required = $mapping['required'] ?? false;
        $trim = $mapping['trim'] ?? true;
        
        if (!$input || !$target || !$lookup) {
            return;
        }
        
        // Get input value
        $inputValue = $raw[$input] ?? null;
        
        // Trim if needed
        if ($trim && is_string($inputValue)) {
            $inputValue = trim($inputValue);
        }
        
        // Skip if empty and not required
        if (empty($inputValue) && !$required) {
            $inputKeysToRemove[] = $input;
            return;
        }
        
        // Extract lookup configuration
        $modelClass = $lookup['model'] ?? null;
        $lookupColumn = $lookup['column'] ?? 'id';
        $resultColumn = $lookup['result'] ?? 'id';
        
        if (!$modelClass) {
            return;
        }
        
        // Build cache key
        $cacheKey = "{$modelClass}.{$lookupColumn}";
        
        // Initialize cache if needed
        if (!isset($ctx->cache[$cacheKey])) {
            $ctx->cache[$cacheKey] = [];
        }
        
        // Check cache first
        if (isset($ctx->cache[$cacheKey][$inputValue])) {
            $result[$target] = $ctx->cache[$cacheKey][$inputValue];
            $inputKeysToRemove[] = $input;
            return;
        }
        
        // Perform lookup
        try {
            $resolvedValue = $modelClass::where($lookupColumn, $inputValue)->value($resultColumn);
            
            if ($resolvedValue !== null) {
                // Cache the result
                $ctx->cache[$cacheKey][$inputValue] = $resolvedValue;
                $result[$target] = $resolvedValue;
                $inputKeysToRemove[] = $input;
            } else {
                // Not found - if required, this will fail validation later
                // For now, just don't set the target column
                $inputKeysToRemove[] = $input;
            }
        } catch (\Exception $e) {
            Log::warning("FK lookup failed for {$input}: " . $e->getMessage());
            $inputKeysToRemove[] = $input;
        }
    }
    
    /**
     * Apply transform mapping using a method name or callable.
     *
     * Invokes the transformer (either a method on the consuming class or
     * a closure) to convert the input value before assigning to the target key.
     *
     * @param  array  $mapping  Mapping descriptor with 'input', 'target', 'transformer' keys
     * @param  array  $raw      Raw input data row
     * @param  array  &$result  Accumulated results (passed by reference)
     * @return void
     */
    protected function applyTransformMapping(array $mapping, array $raw, array &$result): void
    {
        $input = $mapping['input'] ?? null;
        $target = $mapping['target'] ?? null;
        $transformer = $mapping['transformer'] ?? null;
        
        if (!$input || !$target || !$transformer) {
            return;
        }
        
        if (!array_key_exists($input, $raw)) {
            return;
        }
        
        // Check if transformer is a method on this class
        if (is_string($transformer) && method_exists($this, $transformer)) {
            $result[$target] = $this->{$transformer}($raw[$input], $raw);
        } elseif (is_callable($transformer)) {
            $result[$target] = $transformer($raw[$input], $raw);
        }
    }
}
