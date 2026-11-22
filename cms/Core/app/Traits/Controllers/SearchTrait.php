<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Traits\Controllers;

use Cms\Core\Responses\AppResponse;

/**
 * Trait SearchTrait
 *
 * Provides a generic search method for controller classes, allowing flexible filtering and selection
 * of model data based on request input. Designed for use in controllers that interact with models
 * supporting dynamic queries and selection.
 *
 * Usage:
 *   - Add this trait to your controller.
 *   - Ensure the controller has a $model property that supports get() with select, where, and when options.
 *
 * @package Cms\Core\Traits\Controllers
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
trait SearchTrait
{
    /**
     * Search for records based on request input and return an AppResponse.
     *
     * Filters input, builds where conditions, and supports custom selection and pagination.
     *
     * @return mixed  AppResponse wrapping the matched records
     *
     * @author iSPA Team
     * @since  8.0.0 (2026-03-17)
     */
    public function search(): mixed
    {
        $input = request()->input();

        $where = [];
        // Build where conditions from input, skipping pageSize and keys with '/'
        foreach ($input as $key => $value) {
            if ($key !== 'pageSize' && strpos($key, '/') < 0) {
                if ($key === 'keywords') {
                    // Search by name using LIKE for keywords
                    $where[] = ['name', 'like', '%' . $value . '%'];
                } else {
                    $where[$key] = $value;
                }
            }
        }

        // Determine selected columns
        $select = request()->has('select') ? explode(',', request()->input('select')) : ['id', 'name'];

        // Build the model query with select, where, and optional pagination
        $model = $this->model->get([
            'select' => $select,
            'where' => $where,
            'when' => [
                [
                    !empty($input['pageSize']),
                    fn($q) => $q->take($input['pageSize']),
                ],
                [
                    !empty($input['select']),
                    function ($query) use ($input) {
                        $columns = explode(',', $input['select']);
                        $select = ['type'];
                        foreach ($columns as $column) {
                            if (in_array($column, $this->model->model()->getFillable(), true)) {
                                $select[] = $column;
                            }
                        }
                        $query->select($select);
                    },
                ],
            ],
        ]);

        return ires()->data($model->toArray());
    }
}
