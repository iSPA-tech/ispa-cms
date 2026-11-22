<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Http\Controllers\V2\Admin\Concerns;

use RuntimeException;
use InvalidArgumentException;
use Illuminate\Support\Facades\DB;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * Concern for bulk-deleting multiple Eloquent models in a single request.
 *
 * Provides ID sanitisation, model resolution, per-model authorisation
 * checks, and transactional deletion. Designed for V2 Admin controllers
 * that need a "mass destroy" endpoint with consistent error handling.
 *
 * @see \Cms\Core\Http\Controllers\AdminController
 * @package Cms\Core\Http\Controllers\V2\Admin\Concerns
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
trait HandlesMassDestroy
{
    /**
     * Sanitise and deduplicate an array of IDs for mass deletion.
     *
     * Trims string IDs, removes null/empty values, and returns a
     * unique, re-indexed array ready for a whereIn query.
     *
     * @param  array $ids  Raw ID values from the request
     * @return array       Cleaned, unique ID values
     *
     * @author iSPA Team
     * @since  8.0.0 (2026-03-17)
     */
    protected function resolveMassDestroyIds(array $ids): array
    {
        $resolved = [];

        foreach ($ids as $id) {
            if (is_string($id)) {
                $id = trim($id);
            }

            if ($id === null || $id === '') {
                continue;
            }

            $resolved[] = $id;
        }

        return array_values(array_unique($resolved));
    }

    /**
     * Delete multiple models in a single database transaction.
     *
     * Resolves models by the given key, checks authorisation for each
     * one, and either calls the provided delete callback or the model's
     * own delete() method. Throws if any ID is missing or unauthorised.
     *
     * @param  string        $modelClass      Fully-qualified Eloquent model class
     * @param  array         $ids             IDs (or UUIDs) to delete
     * @param  string        $ability         Policy ability to check (empty string to skip)
     * @param  string        $keyName         Column name used for lookup (default 'id')
     * @param  callable|null $deleteCallback  Optional custom delete logic per model
     * @return array{deleted_count: int, deleted_ids: array}
     *
     * @throws AuthorizationException  When the user lacks the required ability
     * @throws InvalidArgumentException  When no IDs are provided
     * @throws ModelNotFoundException  When one or more IDs do not exist
     * @throws RuntimeException  When a model's delete() call returns false
     *
     * @author iSPA Team
     * @since  8.0.0 (2026-03-17)
     */
    protected function performMassDestroy(
        string $modelClass,
        array $ids,
        string $ability = 'delete',
        string $keyName = 'id',
        ?callable $deleteCallback = null
    ): array {
        $resolvedIds = $this->resolveMassDestroyIds($ids);

        if (!$resolvedIds) {
            throw new InvalidArgumentException('No ids provided for mass destroy.');
        }

        $models = $modelClass::query()->whereIn($keyName, $resolvedIds)->get();
        $foundIds = $models->pluck($keyName)->all();
        $missingIds = array_values(array_diff($resolvedIds, $foundIds));

        if ($missingIds) {
            $exception = new ModelNotFoundException();
            $exception->setModel($modelClass, $missingIds);
            throw $exception;
        }

        $deletedIds = [];

        DB::transaction(function () use ($models, $keyName, $ability, $deleteCallback, &$deletedIds) {
            foreach ($models as $model) {
                if ($ability !== '' && !user()?->can($ability, $model)) {
                    throw new AuthorizationException(__('mgs.no.permission'));
                }

                if ($deleteCallback) {
                    $deleteCallback($model);
                } elseif (!$model->delete()) {
                    throw new RuntimeException('Failed to delete model.');
                }

                $deletedIds[] = $model->getAttribute($keyName);
            }
        });

        return [
            'deleted_count' => count($deletedIds),
            'deleted_ids' => $deletedIds,
        ];
    }
}
