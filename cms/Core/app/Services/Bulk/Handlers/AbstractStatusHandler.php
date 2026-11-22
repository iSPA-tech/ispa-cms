<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Services\Bulk\Handlers;

use RuntimeException;
use Illuminate\Support\Facades\DB;
use Cms\Core\Services\Bulk\BulkResult;

/**
 * Abstract handler for bulk status transitions on Eloquent models.
 *
 * Sets the 'status' column to a configured value for all resolved
 * models within a database transaction. Supports dry-run mode.
 *
 * @see AbstractBulkModelHandler
 * @package Cms\Core\Services\Bulk\Handlers
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
abstract class AbstractStatusHandler extends AbstractBulkModelHandler
{
    /**
     * Initialize the handler with model class, target status value, ability, and optional post-action callback.
     *
     * @param string        $modelClass  The Eloquent model class to operate on.
     * @param string        $statusValue The target status value to set.
     * @param string        $ability     The policy ability required (default: 'update').
     * @param \Closure|null $afterAction Optional callback executed after each model update.
     *
     * @author  iSPA Team
     * @since   8.0.0 (2026-03-17)
     */
    public function __construct(
        string $modelClass,
        protected string $statusValue,
        string $ability = 'update',
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
     * @throws InvalidArgumentException When the model is missing the status column.
     * @throws RuntimeException When a model fails to save.
     */
    public function handle(array $payload, bool $dryRun): BulkResult|array
    {
        $model = $this->newModel();
        $this->ensureColumn($model, 'status');

        $models = $this->resolveModels($payload);
        $ids = $models->pluck($model->getKeyName())->all();

        if ($dryRun) {
            return new BulkResult(true, count($ids), $ids);
        }

        return DB::transaction(function () use ($models, $payload, $ids) {
            foreach ($models as $model) {
                $model->status = $this->statusValue;

                if (!$model->save()) {
                    throw new RuntimeException('Failed to update status.');
                }

                $this->runAfterAction($model, $payload);
            }

            return new BulkResult(true, count($ids), $ids);
        });
    }
}
