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
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Cms\Core\Services\Bulk\BulkActionHandler;

/**
 * Abstract base for Eloquent model-based bulk action handlers.
 *
 * Provides common ID normalization (max 20), model resolution,
 * column existence checks, authorization by ability, and post-action
 * callback support. Subclasses implement the handle() method.
 *
 * @see BulkActionHandler
 * @package Cms\Core\Services\Bulk\Handlers
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
abstract class AbstractBulkModelHandler implements BulkActionHandler
{
    /**
     * @param class-string<Model> $modelClass
     * @param  string $ability
     * @param  Closure|null $afterAction
     */
    public function __construct(
        protected string $modelClass,
        protected string $ability,
        protected ?Closure $afterAction = null
    ) {
        $this->ensureValidModelClass();
    }

    /**
     * {@inheritdoc}
     * @return array
     */
    public function rules(): array
    {
        $model = $this->newModel();

        return [
            'ids.*' => [
                'integer',
                Rule::exists($model->getTable(), $model->getKeyName()),
            ],
        ];
    }

    /**
     * {@inheritdoc}
     *
     * @param  Authenticatable|null $user
     * @param  array $payload
     * @return void
     * @throws AuthorizationException When the user is null or lacks the required ability.
     */
    public function authorize(?Authenticatable $user, array $payload): void
    {
        $models = $this->resolveModels($payload);

        if (!$user) {
            throw new AuthorizationException('Forbidden');
        }

        foreach ($models as $model) {
            if (!$user->can($this->ability, $model)) {
                throw new AuthorizationException('Forbidden');
            }
        }
    }

    /**
     * @return Collection<int, Model>
     *
     * @throws InvalidArgumentException When IDs are empty, exceed the limit, or are invalid.
     */
    protected function resolveModels(array $payload): Collection
    {
        $ids = $this->normalizeIds($payload['ids'] ?? []);

        /** @var Model $model */
        $model = $this->newModel();
        $keyName = $model->getKeyName();

        $models = $this->modelClass::query()
            ->whereIn($keyName, $ids)
            ->get();

        if ($models->count() !== count($ids)) {
            throw new InvalidArgumentException('One or more ids are invalid.');
        }

        return $models;
    }

    /**
     * @param array<int, int|string> $ids
     * @return array<int, int>
     *
     * @throws InvalidArgumentException When IDs are empty or exceed the limit of 20.
     */
    protected function normalizeIds(array $ids): array
    {
        $normalized = array_values(array_unique(array_map('intval', $ids)));

        if (empty($normalized)) {
            throw new InvalidArgumentException('Ids are required.');
        }

        if (count($normalized) > 20) {
            throw new InvalidArgumentException('Ids limit exceeded.');
        }

        return $normalized;
    }

    /**
     * Ensure the model's table has the specified column.
     *
     * @param Model  $model  The model instance to check.
     * @param string $column The column name to verify.
     * @return void
     *
     * @throws InvalidArgumentException When the required column is missing.
     */
    protected function ensureColumn(Model $model, string $column): void
    {
        $connection = $model->getConnectionName();
        $table = $model->getTable();

        if (!Schema::connection($connection)->hasColumn($table, $column)) {
            throw new InvalidArgumentException("Missing required column: {$column}.");
        }
    }

    protected function newModel(): Model
    {
        /** @var Model $model */
        $model = new $this->modelClass();

        return $model;
    }

    protected function runAfterAction(Model $model, array $payload): void
    {
        if ($this->afterAction) {
            ($this->afterAction)($model, $payload);
        }
    }

    /**
     * Ensure the model class exists and extends Eloquent Model.
     *
     * @return void
     *
     * @throws InvalidArgumentException When the model class is not found or is not an Eloquent Model.
     */
    protected function ensureValidModelClass(): void
    {
        if (!class_exists($this->modelClass)) {
            throw new InvalidArgumentException('Model class not found.');
        }

        if (!is_subclass_of($this->modelClass, Model::class)) {
            throw new InvalidArgumentException('Model class must extend Eloquent Model.');
        }
    }
}
