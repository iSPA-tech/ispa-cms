<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Repositories\Interfaces;

/**
 * Base repository contract defining standard CRUD and query operations.
 *
 * All domain-specific repository interfaces extend this contract.
 * Implemented by: {@see RepositoriesAbstract}.
 *
 * @see \Cms\Core\Repositories\Eloquent\RepositoriesAbstract
 *
 * @package Cms\Core\Repositories\Interfaces
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
interface RepositoryInterface
{
    /**
     * Get the underlying Eloquent Model or Builder instance.
     *
     * @return \Illuminate\Database\Eloquent\Model|\Illuminate\Database\Eloquent\Builder
     *
     * @author malayvuong
     * @since  1.0.0
     */
    public function model();

    /**
     * Set the Model instance for this repository.
     *
     * @param  string|\Illuminate\Database\Eloquent\Model|\Illuminate\Database\Eloquent\Builder  $model
     * @return self
     *
     * @author malayvuong
     * @since  1.0.0
     */
    public function setModel($model);

    /**
     * Get model Class
     *
     * @return string
     * @author malayvuong
     * @since 7.160.1175 - 2025-06-23, 02:02 ICT
     */
    public function getModelClass(): string;

    /**
     * Get the database table name for the underlying model.
     *
     * @return string
     *
     * @author malayvuong
     * @since  1.0.0
     */
    public function table();

    /**
     * Reset the repository to a fresh model instance.
     *
     * @return self
     *
     * @author malayvuong
     * @since  1.0.0
     */
    public function reset();

    /**
     * Find a single record by its primary key ID.
     *
     * @param  int    $id      The primary key value.
     * @param  array  $select  Columns to select.
     * @param  array  $with    Relationships to eager-load.
     * @return \Illuminate\Database\Eloquent\Model|\Illuminate\Database\Eloquent\Collection|null
     *
     * @author malayvuong
     * @since  1.0.0
     */
    public function find(int $id, array $select = ['*'], array $with = []);

    /**
     * Find one record by condition (Where).
     *
     * @param  array  $where   Key-value where conditions.
     * @param  array  $select  Columns to select.
     * @param  array  $with    Relationships to eager-load.
     * @return object|null
     *
     * @author malayvuong
     * @since  1.0.0
     */
    public function findBy(array $where = [], array $select = ['*'], array $with = []);

    /**
     * Get model records with filtering, ordering, pagination, and relationship loading.
     *
     * @param  array  $params  Query parameters (where, order, take, page, select, with, etc.).
     * @return mixed
     *
     * @author malayvuong
     * @since  1.0.0
     */
    public function get(array $params);

    /**
     * Insert an array of data rows into the database.
     *
     * @param  array  $data  The rows to insert.
     * @return int
     *
     * @author malayvuong
     * @since  1.0.0
     */
    public function insert(array $data = []);

    /**
     * Upsert data (insert or update on duplicate key).
     *
     * @param  array  $data       The data rows.
     * @param  array  $whereKey   Columns used for matching existing rows.
     * @param  array  $updateKey  Columns to update on match.
     * @return mixed
     *
     * @author malayvuong
     * @since  1.0.0
     */
    public function upsert(array $data = [], array $whereKey = [], array $updateKey = []);

    /**
     * Delete records matching the given conditions.
     *
     * @param  array  $where  Key-value where conditions.
     * @return bool|null
     *
     * @author malayvuong
     * @since  1.0.0
     */
    public function deleteBy(array $where);

    /**
     * Create a new record from the given data.
     *
     * @param  array  $data  The attributes for the new record.
     * @return \Illuminate\Database\Eloquent\Model|\Illuminate\Database\Eloquent\Builder
     *
     * @author malayvuong
     * @since  1.0.0
     */
    public function create(array $data);

    /**
     * Check if a column value is unique and generate an alternative if not.
     *
     * @param  string  $column  The column to check.
     * @param  string  $value   The value to verify.
     * @param  int     $except  ID to exclude from the check.
     * @return string  The unique value (original or generated).
     *
     * @author malayvuong
     * @since  1.0.0
     */
    public function checkUnique(string $column, string $value, int $except = 0);

    /**
     * Update or create a record (upsert by condition).
     *
     * @param  array  $where  Conditions to find existing record.
     * @param  array  $data   Attributes to update or insert.
     * @return \Illuminate\Database\Eloquent\Builder|array
     *
     * @author malayvuong
     * @since  1.0.0
     */
    public function updateOrCreate(array $where = [], array $data = []);

    /**
     * Update records matching the given conditions.
     *
     * @param  array  $condition  Key-value where conditions.
     * @param  array  $data       Attributes to update.
     * @return bool
     *
     * @author malayvuong
     * @since  1.0.0
     */
    public function update(array $condition, array $data);
}
