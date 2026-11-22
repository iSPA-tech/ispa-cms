<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Content\Http\Controllers\V2\Admin;

use Cms\Core\Responses\AppResponse;

use App\Http\Controllers\Controller;
use Cms\Content\Interfaces\PostInterface;
use App\Repositories\Interfaces\CommentInterface;

/**
 * Admin dashboard controller for content module analytics.
 *
 * Provides cached dashboard data including recent posts, scheduled (incoming) posts,
 * comment counts, and post-type distribution. Supports a force-reload parameter to
 * invalidate the 5-minute cache window.
 *
 * @see PostInterface
 * @see \App\Repositories\Interfaces\CommentInterface
 * @package Cms\Content\Http\Controllers\V2\Admin
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
class DashboardController extends Controller
{
    /**
     * Controller constructor
     *
     * @param  PostInterface $model
     * @param  CommentInterface $cmt
     * @author malayvuong
     * @since 1.0.0
     */
    public function __construct(
        protected PostInterface    $model,
        protected CommentInterface $cmt,
    )
    {
        parent::__construct();
    }

    /**
     * Retrieve cached post dashboard analytics data.
     *
     * Returns recent posts, scheduled (incoming) posts, total comment count,
     * and post-type distribution. Cached for 5 minutes with optional force reload.
     *
     * @return mixed  Dashboard data or permission error response
     *
     * @author malayvuong
     * @since 7.160.1175 - 2025-06-22, 23:31 ICT
     */
    public function postDashboard(): mixed
    {
        if (!auth()->user()?->can('viewAny', $this->model->model())) {
            return ires()->error()->message(__('mgs.no.permission'));
        }

        $shouldReload = filter_var(request()->query('reload'), FILTER_VALIDATE_BOOLEAN);
        $cacheKey = 'content.post.dashboard';

        if ($shouldReload) {
            cache()->forget($cacheKey);
        }

        $dashboard = cache()->remember($cacheKey, 300, function () {
            $now = now();

            $recentPosts = collect($this->model->get([
                'select' => [
                    'uuid',
                    'name',
                    'type',
                    'status',
                    'created_at',
                    'updated_at',
                    'deleted_at',
                    'category_id',
                ],
                'latest' => true,
                'withTrashed' => true,
                'take' => 10,
                'with' => [
                    'category' => function ($query) {
                        $query->select(['id', 'name']);
                    },
                ],
            ]))
                ->map(static function ($post) {
                    return [
                        'uuid' => $post->uuid,
                        'name' => $post->name,
                        'type' => $post->type,
                        'status' => $post->status,
                        'created_at' => optional($post->created_at)->toDateTimeString(),
                        'updated_at' => optional($post->updated_at)->toDateTimeString(),
                        'deleted_at' => optional($post->deleted_at)->toDateTimeString(),
                        'category' => $post->category ? [
                            'id' => $post->category->id,
                            'name' => $post->category->name,
                        ] : null,
                    ];
                })
                ->values()
                ->all();

            $incomingPosts = collect($this->model->get([
                'select' => [
                    'uuid',
                    'name',
                    'type',
                    'status',
                    'created_at',
                    'published_at',
                    'deleted_at',
                    'category_id',
                ],
                'order' => ['published_at' => 'asc'],
                'take' => 10,
                'with' => [
                    'category' => function ($query) {
                        $query->select(['id', 'name']);
                    },
                ],
                'where' => [
                    ['published_at', '>', now()],
                ],
            ]))
                ->map(static function ($post) {
                    return [
                        'uuid' => $post->uuid,
                        'name' => $post->name,
                        'type' => $post->type,
                        'status' => $post->status,
                        'created_at' => optional($post->created_at)->toDateTimeString(),
                        'published_at' => optional($post->published_at)->toDateTimeString(),
                        'deleted_at' => optional($post->deleted_at)->toDateTimeString(),
                        'category' => $post->category ? [
                            'id' => $post->category->id,
                            'name' => $post->category->name,
                        ] : null,
                    ];
                })
                ->values()
                ->all();

            $totalComments = (int)$this->cmt->model()
                ->query()
                ->where('target_type', $this->model->getModelClass())
                ->count();

            $postTypes = $this->model->model()->query()
                ->selectRaw("COALESCE(NULLIF(type, ''), 'unknown') as type_key")
                ->selectRaw('COUNT(*) as total')
                ->groupBy('type_key')
                ->orderByDesc('total')
                ->get()
                ->mapWithKeys(static function ($row): array {
                    $typeKey = $row->type_key ?: 'unknown';

                    return [$typeKey => (int)$row->total];
                })
                ->toArray();

            return [
                'refreshed_at' => $now->toDateTimeString(),
                'recents' => $recentPosts,
                'incoming' => $incomingPosts,
                'commentCount' => $totalComments,
                'postTypes' => $postTypes,
            ];
        });

        return ires()->data($dashboard);
    }
}
