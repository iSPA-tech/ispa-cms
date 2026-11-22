<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Services\Bulk\Handlers;

use Closure;
use InvalidArgumentException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Cms\Core\Constants\CoreTableConstant;
use Cms\Core\Services\Bulk\BulkResult;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Bulk handler that replaces category associations for selected models.
 *
 * Syncs the BelongsToMany categories relation on each resolved model
 * with the provided category IDs. Supports pivot values (e.g., target_type)
 * and limits the maximum number of categories per operation.
 *
 * @see AbstractBulkModelHandler
 * @package Cms\Core\Services\Bulk\Handlers
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
class ReplaceCategoriesHandler extends AbstractBulkModelHandler
{
    /**
     * @param class-string<Model> $modelClass
     * @param  string $relationName
     * @param  string $ability
     * @param  Closure|null $afterAction
     * @param  int $maxCategories
     */
    public function __construct(
        string $modelClass,
        protected string $relationName = 'categories',
        string $ability = 'update',
        ?Closure $afterAction = null,
        protected int $maxCategories = 50
    ) {
        parent::__construct($modelClass, $ability, $afterAction);
    }

    /**
     * {@inheritdoc}
     * @return array
     */
    public function rules(): array
    {
        return array_merge(parent::rules(), [
            'changes' => ['required', 'array'],
            'changes.category_ids' => ['required', 'array', 'min:1', 'max:' . $this->maxCategories],
            'changes.category_ids.*' => [
                'integer',
                Rule::exists(CoreTableConstant::CATEGORIES, 'id'),
            ],
        ]);
    }

    /**
     * {@inheritdoc}
     * @param  array $payload
     * @param  bool $dryRun
     * @return BulkResult|array
     */
    public function handle(array $payload, bool $dryRun): BulkResult|array
    {
        $models = $this->resolveModels($payload);
        $model = $this->newModel();
        $ids = $models->pluck($model->getKeyName())->all();
        $categoryIds = $this->normalizeCategoryIds($payload['changes']['category_ids'] ?? []);

        if ($dryRun) {
            return new BulkResult(true, count($ids), $ids, ['category_ids' => $categoryIds]);
        }

        return DB::transaction(function () use ($models, $payload, $categoryIds, $ids) {
            foreach ($models as $model) {
                $relation = $this->resolveCategoriesRelation($model);
                $pivotValues = $this->pivotValues($relation, $model);

                if (empty($pivotValues)) {
                    $relation->sync($categoryIds);
                } else {
                    $relation->syncWithPivotValues($categoryIds, $pivotValues);
                }

                $this->runAfterAction($model, $payload);
            }

            return new BulkResult(true, count($ids), $ids, ['category_ids' => $categoryIds]);
        });
    }

    /**
     * @param array<int, int|string> $ids
     * @return array<int, int>
     *
     * @throws InvalidArgumentException When category IDs are empty or exceed the limit.
     */
    protected function normalizeCategoryIds(array $ids): array
    {
        $normalized = array_values(array_unique(array_map('intval', $ids)));

        if (empty($normalized)) {
            throw new InvalidArgumentException('Category ids are required.');
        }

        if (count($normalized) > $this->maxCategories) {
            throw new InvalidArgumentException('Category ids limit exceeded.');
        }

        return $normalized;
    }

    /**
     * Resolve the BelongsToMany categories relation from the model.
     *
     * @param Model $model The model to resolve the relation from.
     * @return BelongsToMany The resolved categories relation.
     *
     * @throws InvalidArgumentException When the relation is missing or not a BelongsToMany.
     */
    protected function resolveCategoriesRelation(Model $model): BelongsToMany
    {
        if (!method_exists($model, $this->relationName)) {
            throw new InvalidArgumentException("Missing {$this->relationName} relation.");
        }

        $relation = $model->{$this->relationName}();

        if (!$relation instanceof BelongsToMany) {
            throw new InvalidArgumentException("{$this->relationName} must be a BelongsToMany relation.");
        }

        return $relation;
    }

    /**
     * @return array<string, mixed>
     */
    protected function pivotValues(BelongsToMany $relation, Model $model): array
    {
        $table = $relation->getTable();

        if (Schema::hasColumn($table, 'target_type')) {
            return ['target_type' => $model::class];
        }

        return [];
    }
}
