<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Content\Repositories;

use Cms\Content\Models\Content;
use Illuminate\Support\Facades\Request;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Database\Eloquent\Collection;
use Cms\Content\Interfaces\ContentInterface;
use Cms\Core\Repositories\Eloquent\RepositoriesAbstract;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Eloquent repository for generic content entries with dynamic filtering.
 *
 * Implements {@see ContentInterface} for {@see Content} persistence.
 * Supports paginated listing with conditional where clauses built from request input.
 *
 * @see Content
 * @see ContentInterface
 * @package Cms\Content\Repositories
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
class ContentRepository extends RepositoriesAbstract implements ContentInterface
{
    /**
     * Initialize the repository and bind the Content model.
     *
     * @author iSPA Team
     * @since  8.0.0 (2026-03-17)
     */
    public function __construct()
    {
        $this->setModel(Content::class);
    }

    /**
     * Get contents list by target input
     *
     * @param  Request|FormRequest $request
     * @param  array $targetIds
     * @param  string $targetType
     * @return Collection|LengthAwarePaginator|array|null
     * @author vuongnm
     * @since 6.62.6 - 2023-02-08, 15:25 ICT
     */
    public function getContents(Request|FormRequest $request, array $targetIds = [], string $targetType = ''): Collection|LengthAwarePaginator|array|null
    {
        return $this->get([
            'select' => [
                'id', 'uuid', 'name', 'description', 'language_id', 'target_id', 'target_type',
                'user_id', 'created_at', 'updated_at', 'status', 'is_confirmed', 'deleted_at',
            ],
            'latest' => true,
            'whereIn' => [
                'target_id' => $targetIds,
            ],
            'where' => [
                'target_type' => $targetType,
            ],
            'page' => $request->input('per_page', 10),
            'with' => [
                'target' => function ($query) {
                    $query->select('id', 'name', 'uid');
                },
                'language' => function ($query) {
                    $query->select('id', 'name', 'path', 'flag');
                },
            ],
            'when' => $this->getWhenCondition($request),
        ]);
    }

    /**
     * Get "when" condition
     *
     * @param Request|FormRequest $request
     *
     * @return array
     * @author vuongnm
     * @since 6.62.6 - 2023-02-08, 15:10 ICT
     */
    public function getWhenCondition(Request|FormRequest $request): array
    {
        return [
            [
                !empty ($request->input('query', '')),
                function ($q) use ($request) {
                    $columnName = in_array($request->input('filter_by', 'name'), ['name', 'description'])
                        ? $request->input('filter_by', 'name')
                        : 'name';
                    $q->where($columnName, 'like', '%' . $request->input('query') . '%');
                },
            ],
            [
                $request->input('user_id', 0) > 0,
                function ($q) use ($request) {
                    return $q->where('user_id', $request->input('user_id'));
                },
            ],
            [
                $request->input('language_id', 0) !== 'all',
                function ($q) use ($request) {
                    return $q->where('language_id', $request->input('language_id'));
                },
            ],
            [
                $request->input('startDate'),
                function ($q) use ($request) {
                    $startDate = now()->parse($request->input('startDate'))->setTimeFromTimeString('00:00');
                    $endDate = now()->parse($request->input('endDate'))->setTimeFromTimeString('23:59');

                    return $q->whereBetween('created_at', [$startDate, $endDate]);
                },
            ],
            [
                in_array($request->input('type', 'published'), ['published', 'draft']),
                function ($q) use ($request) {
                    return $q->where('status', $request->input('type', 'published'));
                },
            ],
            [
                $request->input('type', 'published') === 'deleted',
                function ($q) {
                    return $q->onlyTrashed();
                },
            ],
            [
                $request->input('type', 'published') === 'confirmed',
                function ($q) {
                    return $q->where('is_confirmed', true);
                },
            ],
            [
                $request->input('type', 'published') === 'all',
                function ($q) {
                    return $q->withTrashed();
                },
            ],
        ];
    }
}
