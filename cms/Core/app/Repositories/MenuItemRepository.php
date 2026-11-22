<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Repositories;

use Cms\Core\Models\Category;
use Cms\Core\Models\MenuItem;
use Cms\Core\Interfaces\MenuItemInterface;
use Cms\Core\Repositories\Eloquent\RepositoriesAbstract;
use Cms\Core\Services\Cache\CorePublicMenuCacheService;
use Cms\Core\Support\MenuCategoryExpansion;

/**
 * Eloquent repository for menu item persistence and cached group-based queries.
 *
 * Implements {@see MenuItemInterface} for {@see MenuItem} persistence.
 * Provides cached retrieval of menu items by their parent menu group key,
 * with eager-loaded nested items, targets, and slugs.
 *
 * @see \Cms\Core\Models\MenuItem
 * @see \Cms\Core\Interfaces\MenuItemInterface
 *
 * @package Cms\Core\Repositories
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
class MenuItemRepository extends RepositoriesAbstract implements MenuItemInterface
{
    /**
     * Initialize the repository with the MenuItem model.
     *
     * @return void
     */
    public function __construct()
    {
        $this->setModel(MenuItem::class);
    }

    /**
     * Get Item List by Menu Group
     *
     * @param string $groupName
     * @param bool $freshCache
     * @param int|null $languageId `languages.id` of the menu; null takes the request language
     *
     * @return mixed
     * @author malayvuong
     * @since 7.160.1175 - 2023-11-11, 15:02 ICT
     */
    public function getMenuItemListByMenuGroup(string $groupName, bool $freshCache = false, ?int $languageId = null): mixed
    {
        $languageId ??= lang('id', get_lang_id());

        return app(CorePublicMenuCacheService::class)->rememberGroupItems(
            $groupName,
            $languageId,
            function () use ($groupName, $languageId) {
                $items = $this->get([
                    'select' => ['id', 'menu_id', 'name', 'title', 'type', 'url', 'target_type', 'parent_id', 'target_id', 'custom'],
                    'has' => [
                        'menu' => fn($q) => $q
                            ->where('group_key', $groupName)
                            ->where('language_id', $languageId)
                            ->published(),
                    ],
                    'where' => [
                        'parent_id' => null,
                    ],
                    'when' => [
                        [
                            "type='url'",
                            fn($q) => $q->with([
                                'items' => fn($q) => $q->select('id', 'menu_id', 'name', 'title', 'type', 'url', 'target_type', 'parent_id', 'target_id', 'custom')
                                    ->with([
                                        'target' => fn($f) => $f->with(['slugs']),
                                        'items' => fn($e) => $e->select('id', 'menu_id', 'name', 'title', 'type', 'url', 'target_type', 'parent_id', 'target_id', 'custom')
                                            ->with([
                                                'target' => fn($f) => $f->with(['slugs']),
                                            ])
                                            ->orderBy('priority', 'asc'),
                                    ])
                                    ->orderBy('priority', 'asc'),
                            ]),
                        ],
                        [
                            "type != 'url' && target_id is not null",
                            fn($q) => $q->with([
                                'target' => fn($f) => $f->with(['slugs']),
                            ])->when(
                                "type='category' && exists(custom.isExpandChild) && custom.isExpandChild===true",
                                fn($e) => $e->with([
                                    'target' => fn($f) => $f->with([
                                        'slugs', 'children', 'images',
                                    ])->whereHas('slugs'),
                                ])
                            ),
                        ],
                    ],
                    'order' => [
                        'priority' => 'asc',
                    ],
                ]);

                return $this->attachCategoryExpansionLevels($items);
            }
        );
    }

    private function attachCategoryExpansionLevels(mixed $items): mixed
    {
        if (! is_iterable($items)) {
            return $items;
        }

        foreach ($items as $item) {
            if (! $item instanceof MenuItem) {
                continue;
            }

            if ($item->type === 'category'
                && $item->relationLoaded('target')
                && $item->target instanceof Category) {
                $item->target->setAttribute(
                    'menu_expand_child_level',
                    MenuCategoryExpansion::level($item->custom),
                );
            }

            if ($item->relationLoaded('items')) {
                $this->attachCategoryExpansionLevels($item->items);
            }
        }

        return $items;
    }
}
