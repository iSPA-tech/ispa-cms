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
 * Contract for import definitions that configure the import pipeline.
 *
 * Defines the model class, fillable fields, unique key strategies,
 * type casts, defaults, transformers, validation rules, lifecycle hooks,
 * pivot handling, and template generation metadata for a specific entity
 * import.
 *
 * @see CoreImportEngine
 * @package Cms\Core\Services\Import
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
interface ImportDefinitionInterface
{
    /**
     * Return the fully qualified Eloquent model class name for the import target.
     *
     * @return string The model class name (e.g. \App\Models\User::class).
     *
     * @author  iSPA Team
     * @since   8.0.0 (2026-03-17)
     */
    public function modelClass(): string;

    /**
     * Return the list of fillable column names that the import pipeline may populate.
     *
     * @return array<int, string> Flat array of column names.
     *
     * @author  iSPA Team
     * @since   8.0.0 (2026-03-17)
     */
    public function fillable(): array;
    
    /**
     * Define the primary unique key columns.
     * MUST be a flat array of column names (AND logic).
     * e.g. ['email'] or ['name', 'dob']
     * 
     * @return array
     */
    public function uniqueBy(): array;

    /**
     * Optional fallback unique keys if uniqueSets() matches need to be tried in order.
     * Should be an array of arrays (sets).
     * e.g. [['original_id'], ['email'], ['name', 'dob']]
     * 
     * @return array|null 
     */
    public function uniqueSets(): ?array;
    
    /**
     * Return column type casts applied to raw import values before persistence.
     *
     * @return array<string, string> Map of column name to cast type (e.g. ['price' => 'float']).
     *
     * @author  iSPA Team
     * @since   8.0.0 (2026-03-17)
     */
    public function casts(): array;

    /**
     * Return default values for columns not present in the import row.
     *
     * @return array<string, mixed> Map of column name to default value.
     */
    public function defaults(): array;
    
    /**
     * @return array<string, callable>
     *
     * @author  iSPA Team
     * @since   8.0.0 (2026-03-17)
     */
    public function transformers(): array;
    
    /**
     * Transform a raw import row into a ResolvedRow ready for validation and persistence.
     *
     * @param array<string, mixed> $raw The raw row data from the import source.
     * @param ImportContext $ctx The current import context with shared state.
     * @return ResolvedRow The resolved row with mapped and transformed values.
     *
     * @author  iSPA Team
     * @since   8.0.0 (2026-03-17)
     */
    public function resolveRow(array $raw, ImportContext $ctx): ResolvedRow;

    /**
     * Return the validation rules applied to each resolved import row.
     *
     * @param ImportValidationContext $ctx Validation context providing row index and metadata.
     * @return array<string, mixed> Laravel validation rules keyed by field name.
     *
     * @author  iSPA Team
     * @since   8.0.0 (2026-03-17)
     */
    public function rules(ImportValidationContext $ctx): array;
    
    // Hooks

    /**
     * Hook invoked before validation runs on a resolved import row.
     *
     * @param ResolvedRow $row The resolved row about to be validated.
     * @param ImportContext $ctx The current import context.
     * @return void
     *
     * @author  iSPA Team
     * @since   8.0.0 (2026-03-17)
     */
    public function beforeValidate(ResolvedRow $row, ImportContext $ctx): void;

    /**
     * Hook invoked after validation passes for a resolved import row.
     *
     * @param ResolvedRow $row The validated resolved row.
     * @param ImportContext $ctx The current import context.
     * @return void
     *
     * @author  iSPA Team
     * @since   8.0.0 (2026-03-17)
     */
    public function afterValidate(ResolvedRow $row, ImportContext $ctx): void;

    /**
     * Hook invoked immediately before a resolved row is upserted into the database.
     *
     * @param ResolvedRow $row The resolved row about to be persisted.
     * @param ImportContext $ctx The current import context.
     * @return void
     *
     * @author  iSPA Team
     * @since   8.0.0 (2026-03-17)
     */
    public function beforeUpsert(ResolvedRow $row, ImportContext $ctx): void;

    /**
     * Hook invoked after a row has been successfully upserted into the database.
     *
     * @param Model $model The persisted Eloquent model instance.
     * @param ResolvedRow $row The resolved row that was upserted.
     * @param ImportContext $ctx The current import context.
     * @return void
     */
    public function afterUpsert(Model $model, ResolvedRow $row, ImportContext $ctx): void;

    /**
     * Handle pivot/relationship synchronization after the main model has been upserted.
     *
     * @param ResolvedRow $row The resolved row containing pivot data.
     * @param Model $model The persisted Eloquent model to attach relations to.
     * @param ImportContext $ctx The current import context.
     * @return void
     */
    public function handlePivotRelations(ResolvedRow $row, Model $model, ImportContext $ctx): void;
    
    // Template generation methods (optional, can be implemented via trait)
    
    /**
     * Get template key for file organization
     * Example: "Education/instructors" or "Ecommerce/orders"
     * 
     * @return string
     */
    public function templateKey(): string;
    
    /**
     * Get headers for template file (including virtual headers for FK mapping)
     * Example: ['code', 'name', 'semester_code', 'instructor_email']
     * 
     * @return array
     */
    public function templateHeaders(): array;
    
    /**
     * Get notes/hints for template usage
     * Example: ['dob_format' => 'YYYY-MM-DD or DD/MM/YYYY', 'status' => 'active, inactive, pending']
     * 
     * @return array
     */
    public function templateNotes(): array;
    
    /**
     * Define input column mappings (optional, for declarative mapping approach)
     * Returns array of mapping specifications for transforming input columns to model columns
     * 
     * Mapping types:
     * - direct: Simple column rename/passthrough
     * - fk_lookup: Resolve foreign key by looking up reference value
     * - transform: Apply custom transformation
     * 
     * Example:
     * [
     *   ['type' => 'direct', 'input' => 'name', 'target' => 'name'],
     *   ['type' => 'fk_lookup', 'input' => 'semester_original_id', 'target' => 'semester_id',
     *    'lookup' => ['model' => Semester::class, 'column' => 'original_id', 'result' => 'id'],
     *    'required' => false, 'trim' => true]
     * ]
     * 
     * @return array
     */
    public function inputMappings(): array;
}
