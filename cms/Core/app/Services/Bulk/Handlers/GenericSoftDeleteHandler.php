<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Services\Bulk\Handlers;

use RuntimeException;
use InvalidArgumentException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Cms\Core\Services\Bulk\BulkResult;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Generic bulk soft-delete handler for Eloquent models with SoftDeletes.
 *
 * Validates that the model uses the SoftDeletes trait and has the
 * deleted_at column, then soft-deletes all resolved models within
 * a transaction.
 *
 * @see AbstractBulkModelHandler
 * @package Cms\Core\Services\Bulk\Handlers
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
class GenericSoftDeleteHandler extends AbstractBulkModelHandler
{
    /**
     * Initialize the handler with model class, ability, and optional post-action callback.
     *
     * @param string        $modelClass  The Eloquent model class to operate on.
     * @param string        $ability     The policy ability required (default: 'delete').
     * @param \Closure|null $afterAction Optional callback executed after each model delete.
     *
     * @author  iSPA Team
     * @since   8.0.0 (2026-03-17)
     */
    public function __construct(
        string $modelClass,
        string $ability = 'delete',
        ?\Closure $afterAction = null
    ) {
        parent::__construct($modelClass, $ability, $afterAction);
    }

    /**
     * {@inheritdoc}
     *
     * @param  array $payload
     * @param  bool $dryRun
     * @return BulkResult|array
     * @throws InvalidArgumentException When the model does not support soft deletes.
     * @throws RuntimeException When a model fails to delete.
     */
    public function handle(array $payload, bool $dryRun): BulkResult|array
    {
        $model = $this->newModel();
        $this->ensureSoftDeletes($model);

        $models = $this->resolveModels($payload);
        $ids = $models->pluck($model->getKeyName())->all();

        if ($dryRun) {
            return new BulkResult(true, count($ids), $ids);
        }

        return DB::transaction(function () use ($models, $payload, $ids) {
            foreach ($models as $model) {
                if (!$model->delete()) {
                    throw new RuntimeException('Failed to delete model.');
                }

                $this->runAfterAction($model, $payload);
            }

            return new BulkResult(true, count($ids), $ids);
        });
    }

    /**
     * Ensure the model uses the SoftDeletes trait and has the deleted_at column.
     *
     * @param Model $model The model instance to check.
     * @return void
     *
     * @throws InvalidArgumentException When the model does not support soft deletes.
     */
    protected function ensureSoftDeletes($model): void
    {
        if (!in_array(SoftDeletes::class, class_uses_recursive($this->modelClass), true)) {
            throw new InvalidArgumentException('Model does not support soft deletes.');
        }

        $column = $model->getDeletedAtColumn();
        $connection = $model->getConnectionName();
        $table = $model->getTable();

        if (!Schema::connection($connection)->hasColumn($table, $column)) {
            throw new InvalidArgumentException('Model does not support soft deletes.');
        }
    }
}
