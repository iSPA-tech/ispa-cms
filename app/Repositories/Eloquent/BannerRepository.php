<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace App\Repositories\Eloquent;

use Cms\Core\Repositories\Eloquent\RepositoriesAbstract;
use App\Models\Banner;
use Cms\Core\Facades\CMSCache;
use App\Enums\StatusEnum;
use Cms\Core\Helpers\CacheKeyHelper;
use Illuminate\Database\Eloquent\Collection;
use App\Repositories\Interfaces\BannerInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class BannerRepository extends RepositoriesAbstract implements BannerInterface
{
    public function __construct()
    {
        $this->setModel(Banner::class);
    }

    /**
     * Get banner list by type
     *
     * @param string $type
     * @param array $closedBanners
     * @param int $limit
     *
     * @return mixed
     * @author malayvuong
     * @since 7.160.1175 - 2024-02-17, 11:44 ICT
     */
    public function getBanners(string $type = '', array $closedBanners = [], int $limit = 1): mixed
    {
        return CMSCache::rememberRandomWithTracking(
            CacheKeyHelper::bannerType($type) . '_' . md5(serialize($closedBanners)),
            function () use ($type, $closedBanners, $limit) {
                return $this->get([
                    'select' => [
                        'id', 'name', 'url', 'custom', 'click', 'view', 'limit_by',
                        'limit_click', 'limit_view', 'limit_from', 'limit_to',
                    ],
                    'where' => [
                        'type' => $type,
                        'language_id' => lang('id'),
                        'status' => StatusEnum::PUBLISHED->value,
                        fn($query) => $query
                            ->where(fn($q) => $q
                                ->where('limit_by', 1)
                                ->where(function ($q2) {
                                    $q2->where(fn($q3) => $q3->whereNotNull('limit_from')->where('limit_from', '<=', now()->format('Y-m-d H:i:00')))
                                        ->orWhereNull('limit_from');
                                })
                                ->where(function ($q2) {
                                    $q2->where(fn($q3) => $q3->whereNotNull('limit_to')->where('limit_to', '>=', now()->format('Y-m-d H:i:00')))
                                        ->orWhereNull('limit_to');
                                })
                            )
                            ->orWhere(fn($q) => $q->where('limit_by', 2)->where('limit_click', '>', 0)->whereColumn('click', '<', 'limit_click'))
                            ->orWhere(fn($q) => $q->where('limit_by', 3)->where('limit_view', '>', 0)->whereColumn('view', '<', 'limit_view'))
                            ->orWhere('limit_by', 0),
                    ],
                    'latest' => true,
                    'take' => $limit,
                    'with' => ['images'],
                    'when' => [
                        [
                            count($closedBanners) > 0,
                            fn($query) => $query->whereNotIn('id', $closedBanners),
                        ],
                    ],
                ]);
            }, 7200, 12000);
    }

    /**
     * Get banner list by type (use for website)
     *
     * @param string $type
     *
     * @return Collection|LengthAwarePaginator|array
     * @author malayvuong
     * @since 6.19.6 - 2022-06-26, 11:35 ICT
     */
    public function GetBannerList(string $type = 'slider_main'): Collection|LengthAwarePaginator|array
    {
        return $this->get([
            'select' => [
                'id', 'name', 'url', 'custom', 'click', 'view', 'limit_by',
                'limit_click', 'limit_view', 'limit_from', 'limit_to',
            ],
            'where' => [
                'type' => $type,
                'language_id' => lang('id', 1),
            ],
            'latest' => true,
        ]);
    }
}
