<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Workflows\Runtime;

use Cms\Core\Workflows\Runtime\Exceptions\InvalidRootModelException;
use Cms\Core\Workflows\Runtime\Exceptions\WorkflowRootNotFoundException;
use Cms\Core\Workflows\Security\WorkflowCallableGuard;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Loads the root Eloquent model for a workflow instance by class and ID.
 *
 * Validates that the target class exists, is an Eloquent model and passes WorkflowCallableGuard (no sensitive
 * model becomes a run's root), with optional soft-delete inclusion for trashed records.
 *
 * @package Cms\Core\Workflows\Runtime
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
class RootModelLoader
{
    /**
     * Load the root model for a workflow instance.
     *
     * @param class-string<Model> $targetType
     * @param  int|string $targetId
     * @param  bool $includeTrashed
     *
     * @return Model
     * @throws InvalidRootModelException
     * @throws WorkflowRootNotFoundException
     */
    public function load(string $targetType, int|string $targetId, bool $includeTrashed = false): Model
    {
        if (!class_exists($targetType)) {
            throw new InvalidRootModelException("Root model class {$targetType} does not exist");
        }

        if (!is_subclass_of($targetType, Model::class)) {
            throw new InvalidRootModelException("Root model class {$targetType} is not an Eloquent model");
        }

        if (!app(WorkflowCallableGuard::class)->allowsModel($targetType)) {
            throw new InvalidRootModelException("Root model class {$targetType} is not allowed for workflows");
        }

        /** @var Model $query */
        $query = $targetType::query();

        if ($includeTrashed && in_array(SoftDeletes::class, class_uses_recursive($targetType), true)) {
            $query = $query->withTrashed();
        }

        /** @var Model|null $model */
        $model = $query->find($targetId);

        if (!$model) {
            $exception = new WorkflowRootNotFoundException("Root model not found for {$targetType}#{$targetId}");
            $exception->setModel($targetType, [$targetId]);
            throw $exception;
        }

        return $model;
    }
}
