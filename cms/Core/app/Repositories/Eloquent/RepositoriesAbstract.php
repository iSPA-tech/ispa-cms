<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Repositories\Eloquent;

use Closure;
use Exception;
use Carbon\Carbon;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\App;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Cms\Core\Models\Category;
use Cms\Core\Repositories\Interfaces\RepositoryInterface;
use Cms\Core\Services\CategoryConfigQuery;

/**
 * Base abstract repository providing common Eloquent query operations.
 *
 * Implements {@see RepositoryInterface} with standard CRUD, filtering,
 * pagination, caching-aware lookups, and conditional query building.
 * All concrete repositories extend this class.
 *
 * @see \Cms\Core\Repositories\Interfaces\RepositoryInterface
 * @see \Illuminate\Database\Eloquent\Model
 *
 * @package Cms\Core\Repositories\Eloquent
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
abstract class RepositoriesAbstract implements RepositoryInterface
{
    /** @var Model|Builder */
    protected Model|Builder $inner;

    /** @var String $modelClass */
    protected string $modelClass;

    /**
     * Set Model instance
     *
     * @param $model
     * @param  mixed $model
     *
     * @return self
     *
     * @author malayvuong
     * @since 1.0.0
     */
    public function setModel($model): self
    {
        if ($model instanceof Model || $model instanceof Builder) {
            $this->inner = $model;
            $this->modelClass = get_class($model);
        } else {
            $this->modelClass = $model;
            $this->inner = is_string($model) ? new $model() : $model;
        }

        return $this;
    }

    /**
     * Get model Class
     *
     * @return string
     * @author malayvuong
     * @since 7.160.1175 - 2025-06-23, 02:02 ICT
     */
    public function getModelClass(): string
    {
        return $this->modelClass;
    }

    /**
     * Get table name
     *
     * @return string
     *
     * @author malayvuong
     *
     * @since 1.0.0
     */
    public function table(): string
    {
        return $this->inner->getTable();
    }

    /**
     * Find by ID
     *
     * @param int $id
     * @param string[] $select
     * @param array $with
     *
     * @return Builder|Builder[]|Collection|Model|null
     *
     * @since 1.0.0
     * @author malayvuong
     */
    public function find(int $id, array $select = ['*'], array $with = []): Model|Collection|Builder|array|null
    {
        $model = $this->with($with)->select($select)->find($id);

        $this->reset();

        return $model;
    }

    /**
     * With relationship init
     *
     * @author malayvuong
     * @since 1.0.0
     */
    protected function with(array $with = []): Model|Builder
    {
        if (!empty($with)) {
            $this->inner = $this->model()->with($with);
        }

        return $this->inner;
    }

    /**
     * Get Model instance
     *
     * @return Model|Builder
     *
     * @author malayvuong
     *
     * @since  1.0.0
     */
    public function model(): Model|Builder
    {
        return $this->inner;
    }

    /**
     * Reset Model repositories
     *
     * @return self
     *
     * @since 1.0.0
     * @author malayvuong
     */
    public function reset(): self
    {
        $this->inner = App::make($this->modelClass);


        return $this;
    }

    /**
     * Get model eloquent
     *
     * @param array $params
     *
     * @return mixed
     * @since 1.0.0
     * @author malayvuong
     */
    public function get(array $params): mixed
    {
        $params = array_merge([
            'where' => [],
            'order' => [],
            'take' => null,
            'page' => 0,
            'current_page' => null,
            'select' => ['*'],
            'with' => [],
            'when' => [],
            'withTrashed' => false,
            'latest' => false,
            'count' => [],
            'has' => [],
            'hasMorph' => [],
            'filter' => [],
            'scopes' => [],
            'sql' => false,
        ], $params);

        //  Set where condition
        $this->apply($params['where']);

        $data = $this->inner;

        //  Set model data
        if (!empty($params['filter'])) {
            $data = $this->filter($data, $params['filter']);
        }

        //  Set order by
        if ($params['latest']) {
            $data = $data->latest();
        }
        foreach ($params['order'] as $column => $direction) {
            if ($direction !== null) {
                $data = $data->orderBy($column, $direction);
            }
        }

        //  Set select column
        if ($params['select']) {
            $data = $data->select($params['select']);
        }

        // Set get data with Trashed item
        if ($params['withTrashed']) {
            $data = $data->withTrashed();
        }

        //  Where has
        if ($params['has']) {
            foreach ($params['has'] as $relation => $condition) {
                if ($condition instanceof Closure) {
                    $data = $data->whereHas($relation, $condition);
                } else {
                    $data = $data->whereHas($condition);
                }
            }
        }

        //  Where has morph
        if ($params['hasMorph']) {
            foreach ($params['hasMorph'] as $relation => $types) {
                $callback = null;

                if (is_array($types) && array_key_exists('types', $types)) {
                    $callback = $types['callback'] ?? null;
                    $types = $types['types'];
                }

                $types = is_array($types) ? $types : [$types];

                if ($callback instanceof Closure) {
                    $data = $data->whereHasMorph($relation, $types, $callback);
                } else {
                    $data = $data->whereHasMorph($relation, $types);
                }
            }
        }

        //  Set with relationship
        if (is_array($params['with']) && count($params['with']) > 0) {
            $data = $data->with($params['with']);
        }

        //  Apply local scopes on the root query.
        //
        //  `with` cannot express a scope, and a scope is the only way to reach
        //  a builder hook: withImage() registers an afterQuery callback that
        //  answers every R2 ledger probe for the batch in one query. That hook
        //  has to be attached by the caller — Builder::get() applies global
        //  scopes to a clone while running after-query callbacks on the
        //  original builder, so a hook added from a global scope never fires.
        foreach ($params['scopes'] as $scope => $arguments) {
            if (is_int($scope)) {
                $scope = $arguments;
                $arguments = [];
            }

            $data = $data->{$scope}(...(array) $arguments);
        }

        if (is_array($params['when']) && count($params['when']) > 0) {
            foreach ($params['when'] as $when) {
                if (is_array($when) && count($when) === 2) {
                    $data = $data->when($when[0], $when[1]);
                }
            }
        }

        if ($params['count']) {
            $data = $data->withCount($params['count']);
        }

        if ($params['sql']) {
            $data->dd();
        }

        if (!empty($params['take'])) {
            if ((int)$params['take'] === 1) {
                $result = $data->first();
            } else {
                $result = $data->take($params['take'])->get();
            }
        } else if (isset($params['page']) && $params['page'] > 0) {
            $result = $data->paginate($params['page'], ['*'], 'page', $params['current_page']);
        } else {
            $result = $data->get();
        }
        //  Reset model
        $this->reset();

        return $result;
    }

    /**
     * Apply where condition to model
     *
     * @param array $where
     * @param null $model
     *
     * @author malayvuong
     *
     * @since 1.0.0
     */
    protected function apply(array $where, &$model = null): void
    {
        $newModel = $this->model();

        foreach ($where as $field => $value) {
            if (is_array($value)) {
                [$field, $condition, $val] = $value;
                $condition = strtoupper($condition);
                $newModel = match ($condition) {
                    'ONLY_TRASHED' => $newModel->onlyTrashed(),
                    'IN' => $this->isCategoryConfigPath($field)
                        ? CategoryConfigQuery::whereConfigIn($this->asBuilder($newModel), $this->categoryConfigPath($field), (array) $val)
                        : $newModel->whereIn($field, $val),
                    'NOT_IN' => $this->isCategoryConfigPath($field)
                        ? CategoryConfigQuery::whereConfigIn($this->asBuilder($newModel), $this->categoryConfigPath($field), (array) $val, not: true)
                        : $newModel->whereNotIn($field, $val),
                    default => $this->isCategoryConfigPath($field)
                        ? CategoryConfigQuery::whereConfigOperator($this->asBuilder($newModel), $this->categoryConfigPath($field), $condition, $val)
                        : $newModel->where($field, $condition, $val),
                };
            } else if ($value instanceof Closure) {
                $newModel = $newModel->where($value);
            } else {
                $newModel = $this->isCategoryConfigPath($field)
                    ? CategoryConfigQuery::whereConfig($this->asBuilder($newModel), $this->categoryConfigPath($field), $value)
                    : $newModel->where($field, $value);
            }
        }

        // If $model is passed by reference, update it; otherwise, keep working on $this->inner
        if ($model !== null) {
            $model = $newModel;
        } else {
            $this->inner = $newModel;
        }
    }

    private function isCategoryConfigPath(mixed $field): bool
    {
        return is_string($field)
            && is_a($this->modelClass, Category::class, true)
            && str_starts_with($field, 'config->');
    }

    private function categoryConfigPath(string $field): string
    {
        return substr($field, strlen('config->'));
    }

    private function asBuilder(Model|Builder $model): Builder
    {
        return $model instanceof Builder ? $model : $model->newQuery();
    }


    /**
     * Index filter params apply
     *
     * @param  mixed $model
     * @param  array $data
     * @return mixed
     * @author malayvuong
     * @since 6.62.6 - 2023-05-30, 20:10 ICT
     */
    public function filter(mixed $model, array $data = []): mixed
    {
        $fillable = $this->reset()->model()->getFillable();
        $timezone = get_timezone();

        return $model
            ->when(
                !empty($data['keywords']),
                fn($q) => $q->where(fn($query) => $query
                    ->where('name', 'like', '%' . $data['keywords'] . '%'))
                    ->when(
                        in_array('content', $fillable, true),
                        fn($query) => $query->orWhere('content', 'like', '%' . $data['keywords'] . '%')
                    )
            )
            ->when(
                !empty($data['categoryId'] ?? '') && is_array($data['categoryId']),
                fn($q) => $q->whereIn('category_id', $data['categoryId'])
            )
            ->when(
                !empty($data['status']),
                fn($query) => $query
                    ->when($data['status'] === 'deleted', fn($q) => $q->onlyTrashed())
                    ->when($data['status'] === 'all', fn($q) => $q->withTrashed())
                    ->when(
                        !in_array($data['status'], ['deleted', 'all']),
                        fn($q) => $q->where('status', $data['status'])
                    ),
            )
            ->when(
                !empty($data['confirmed']) && $data['confirmed'] !== 'all' &&
                in_array('is_confirmed', $fillable, true),
                fn($query) => $query->where('is_confirmed', (int)$data['confirmed'] > 0)
            )
            ->when(
                !empty($data['languageId']),
                fn($q) => $q->where('language_id', (int)$data['languageId']),
            )
            ->when(
                !empty($data['userId']) && (int)$data['userId'] > 0,
                fn($q) => $q->where('user_id', $data['userId']),
            )
            ->when(
                !empty($data['dateRange'])
                && count($data['dateRange']) === 2
                && !empty($data['dateRange'][0]) && $data['dateRange'][0] !== 'null'
                && !empty($data['dateRange'][1]) && $data['dateRange'][1] !== 'null',
                fn($q) => $q->whereBetween('created_at', [
                    Carbon::parse($data['dateRange'][0])
                        ->setTimezone($timezone)->setTimeFromTimeString('00:00'),
                    Carbon::parse($data['dateRange'][1])
                        ->setTimezone($timezone)->setTimeFromTimeString('23:59'),
                ])
            )
            ->when(
                !empty($data['placeId']) && (int)$data['placeId'] > 0,
                fn($q) => $q
                    ->when(
                        in_array('target_id', $fillable, true),
                        fn($q) => $q->where(fn($query) => $query->where('target_id', $data['placeId'])
                            ->where('target_type', 'like', '%\\Place'))
                    )
                    ->when(
                        in_array('place_id', $fillable, true),
                        fn($q) => $q->where('place_id', $data['placeId'])
                    )
            );
    }

    /**
     * Upsert data
     *
     * @param array $data
     * @param array $whereKey
     * @param array $updateKey
     *
     * @return mixed
     *
     * @since  1.0.0
     * @author malayvuong
     */
    public function upsert(array $data = [], array $whereKey = [], array $updateKey = []): mixed
    {
        $return = $this->inner->upsert($data, $whereKey, $updateKey);

        $this->reset();

        return $return;
    }

    /**
     * Delete element by conditions
     *
     * @param array $where
     *
     * @return bool|null
     *
     * @author malayvuong
     *
     * @since  1.0.0
     */
    public function deleteBy(array $where): ?bool
    {
        DB::beginTransaction();

        try {
            if (!empty($where)) {
                $this->apply($where);
            }
            $deleted = $this->inner->delete();

            $this->reset();
        } catch (Exception $e) {
            DB::rollBack();

            return $e->getMessage();
        }

        DB::commit();

        return $deleted;
    }

    /**
     * Create new item
     *
     * @param  array $data
     * @param  string $relationName
     * @return Model|Builder
     * @since 1.0.0
     * @last_updated 6.19.6 - 2022-05-24, 20:55 ICT - by malayvuong
     * @author malayvuong
     */
    public function create(array $data, string $relationName = null): Model|Builder
    {
        $result = $this->inner->create($data);

        $this->reset();

        //  Check model has this relation, then insert data
        if ($relationName && !empty($data[$relationName]) && method_exists($result, $relationName)) {
            $result->{$relationName}()->insert($data[$relationName]);
        }

        return $result;
    }

    /**
     * Insert array data into database
     *
     * @param array $data
     *
     * @return int
     *
     * @author malayvuong
     *
     * @since 1.0.0
     */
    public function insert(array $data = []): int
    {
        if (!empty($data)) {
            return $this->inner->insert($data);
        }

        return 0;
    }

    /**
     * Check unique column
     *
     * @param string $column
     * @param string $value
     * @param int $except
     *
     * @return string
     *
     * @since  1.0.0
     * @author malayvuong
     */
    public function checkUnique(string $column, string $value, int $except = 0): string
    {
        $where = [$column => $value];
        if ($except > 0) {
            $where['id'] = $except;
        }
        $check = $this->findBy($where, ['id']);

        if ($check) {
            if ($column === 'uuid') {
                return $this->checkUnique($column, Str::uuid());
            }

            return $this->checkUnique($column, Str::random(strlen($value)));
        }

        return $value;
    }

    /**
     * Find one by condition (Where)
     *
     * @param array $where
     * @param string[] $select
     * @param array $with
     *
     * @return object|null
     *
     * @since 1.0.0
     * @author malayvuong
     */
    public function findBy(array $where = [], array $select = ['*'], array $with = []): object|null
    {
        if (!empty($where)) {
            $this->apply($where);
        }

        $model = $this->with($with)->select($select)->orderBy('id', 'desc')->first();

        $this->reset();

        return $model;
    }

    /**
     * Update or Create (UoC)
     *
     * @param array $where
     * @param array $data
     *
     * @return array|Builder
     *
     * @author malayvuong
     *
     * @since  1.0.0
     */
    public function updateOrCreate(array $where = [], array $data = []): Builder|array
    {
        if (empty($where) || empty($data)) {
            return [];
        }

        return $this->inner->updateOrInsert($where, $data);
    }

    /**
     * First or Create (FoC)
     *
     * @param array $where
     * @param array $data
     *
     * @return array|Builder
     *
     * @author malayvuong
     *
     * @since  1.0.0
     */
    public function firstOrCreate(array $where = [], array $data = []): Builder|array
    {
        if (empty($where) || empty($data)) {
            return [];
        }

        return $this->inner->firstOrCreate($where, $data);
    }

    /**
     * Update data
     *
     * @param array $condition
     * @param array $data
     *
     * @return bool
     */
    public function update(array $condition, array $data): bool
    {
        $this->apply($condition);
        $return = $this->inner->update($data);
        $this->reset();

        return $return;
    }
}
