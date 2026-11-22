<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Repositories;

use Cms\Core\Facades\CMSCache;
use App\Constants\Constant;
use App\Enums\StatusEnum;
use Cms\Core\Helpers\CacheHelper;
use Cms\Core\Models\Menu;
use Cms\Core\Helpers\CacheKeyHelper;
use Cms\Core\Interfaces\MenuInterface;
use Cms\Core\Repositories\Eloquent\RepositoriesAbstract;

/**
 * Eloquent repository for menu persistence, cached navigation queries, and language-aware listing.
 *
 * Implements {@see MenuInterface} for {@see Menu} persistence.
 * Provides cached menu detail retrieval by group name, group-key lookups
 * with nested items/targets/slugs, and language-filtered menu lists.
 *
 * @see \Cms\Core\Models\Menu
 * @see \Cms\Core\Interfaces\MenuInterface
 *
 * @package Cms\Core\Repositories
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
class MenuRepository extends RepositoriesAbstract implements MenuInterface
{
    /**
     * Initialize the repository with the Menu model.
     *
     * @return void
     */
    public function __construct()
    {
        $this->setModel(Menu::class);
    }

    /**
     * Get menu for client
     *
     * @param string $groupName
     * @param bool $freshCache
     *
     * @return mixed
     * @author malayvuong
     * @since 7.160.1175 - 2023-11-11, 08:53 ICT
     */
    public function getMenuDetailByGroupName(string $groupName, bool $freshCache = false): mixed
    {
        $ttl = $freshCache ? config('setting.cache.cache.menu', 3600) : 1;

        $cacheKey = sprintf(Constant::CACHE_MENUS[Constant::MENU_BY_GROUP_KEY], $groupName, lang('path', 'vi'));

        return cache()->remember($cacheKey, $ttl, function () use ($cacheKey, $ttl, $groupName) {
            CacheHelper::getInstance()->store($cacheKey, 'Menu ' . $groupName . ' Language ID ' . lang('path', 'vi'), $ttl);

            return $this->get([
                'take' => 1,
                'where' => [
                    'status' => StatusEnum::PUBLISHED->value,
                    'language_id' => lang('id', get_lang_id()),
                    'group_key' => $groupName,
                ],
                'with' => [
                    'items' => function ($q) {
                        $q->select('id', 'menu_id', 'name', 'title', 'type', 'url', 'target_type', 'parent_id', 'target_id', 'custom')
                            ->whereNull('parent_id')
                            ->orderBy('priority', 'asc');
                    },
                ],
            ]);
        });
    }

    /**
     * Get menu by group key (first one latest)
     *
     * @param string $groupKey
     * @param int $langId
     *
     * @return object|null
     * @author malayvuong
     * @since 6.62.6 - 2023-03-03, 08:30 ICT
     */
    public function getMenuByGroupKey(string $groupKey): ?object
    {
        return CMSCache::rememberWithTracking(
            CacheKeyHelper::menuByGroupKey($groupKey),
            function () use ($groupKey) {
                return $this->get([
                    'take' => 1,
                    'where' => [
                        'status' => StatusEnum::PUBLISHED->value,
                        'language_id' => CacheKeyHelper::getLangId(),
                        'group_key' => $groupKey,
                    ],
                    'with' => [
                        'items' => function ($q) {
                            return $q
                                ->select('id', 'menu_id', 'name', 'title', 'type', 'url', 'target_type', 'parent_id', 'target_id', 'custom')
                                ->whereNull('parent_id')
                                ->with([
                                    'items' => function ($e) {
                                        return $e
                                            ->select('id', 'menu_id', 'name', 'title', 'type', 'url', 'target_type', 'parent_id', 'target_id', 'custom')
                                            ->when(
                                                "type='url'",
                                                function ($q) {
                                                    return $q->with([
                                                        'items' => fn($q) => $q
                                                            ->select('id', 'menu_id', 'name', 'title', 'type', 'url', 'target_type', 'parent_id', 'target_id', 'custom')
                                                            ->orderBy('priority', 'asc'),
                                                    ]);
                                                }
                                            )
                                            ->when(
                                                "type != 'url' && target_id is not null",
                                                function ($q) {
                                                    return $q->with([
                                                        'target' => fn($f) => $f->with(['slugs']),
                                                    ])->when(
                                                        "type='category' && exists(custom.isExpandChild) && custom.isExpandChild===true",
                                                        fn($e) => $e->with([
                                                            'target' => fn($f) => $f->with([
                                                                'slugs', 'children', 'images',
                                                            ])->whereHas('slugs'),
                                                        ])
                                                    );
                                                }
                                            );
                                    },
                                ]);
                        },
                    ],
                ]);
            },
            86400
        );
    }

    /**
     * Get menu list
     *
     * @param array $query
     * @param int $langId
     *
     * @return mixed
     * @author malayvuong
     * @since 6.62.6 - 2023-03-03, 08:30 ICT
     */
    public function getMenuList(array $query, int $langId): mixed
    {
        $where = array_merge([
            'status' => StatusEnum::PUBLISHED->value,
            'language_id' => $langId,
        ], $query);
//        $cacheKey = make_key([
//            'type' => Constant::CACHE_KEY_MENUS,
//            'lang' => $langId,
//            'query' => implode('.', $query)
//        ]);

//        return cache()->remember(
//            $cacheKey,
//            config('setting.cache.menu', 1),
//            function () use ($query, $langId) {
        return $this->get([
            'where' => $where,
            'with' => [
                'items' => function ($query) {
                    $query->when('target_id > 0', function ($query) {
                        $query->with('target');
                    });
                },
            ],
            'order' => [
                'id' => 'DESC',
            ],
        ]);
//            }
//        );
    }
}
