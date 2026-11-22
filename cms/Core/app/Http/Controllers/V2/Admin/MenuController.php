<?php

/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Http\Controllers\V2\Admin;

use Cms\Core\Responses\AppResponse;

use Exception;
use App\Constants\Constant;
use Cms\Core\Helpers\RedisHelper;
use Cms\Core\Models\MenuItem;
use App\Constants\CacheConstants;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Cms\Core\Http\Controllers\AdminController;
use Cms\Core\Services\Cache\CorePublicMenuCacheService;
use Cms\Core\Interfaces\MenuInterface;
use Cms\Core\Interfaces\MenuItemInterface;
use Cms\Core\Http\Requests\Menu\StoreMenuRequest;
use Cms\Core\Http\Requests\Menu\UpdateMenuRequest;
use Cms\Core\Support\MenuCategoryExpansion;

/**
 * Manage navigation menus for Admin v2: list menus, edit hierarchical
 * menu items, create/update with nested children, and delete menus.
 * Handles cache/redis invalidation for menu groups after writes.
 *
 * @package Cms\Core\Http\Controllers\V2\Admin
 * @author  malayvuong
 * @since   1.0.0 (2022-01-17)
 * @last_updated 8.0.0 - 2025-09-11, 14:30 (+07:00)
 */
class MenuController extends AdminController
{
    /**
     * Controller constructor.
     *
     * @param MenuInterface $model Menu repository.
     * @param MenuItemInterface $item Menu item repository.
     *
     * @author malayvuong
     * @since  1.0.0
     */
    public function __construct(
        protected MenuInterface     $model,
        protected MenuItemInterface $item
    )
    {
        parent::__construct();
    }

    /**
     * List menus with top-level items and language info.
     *
     * @return mixed
     *
     * @author malayvuong
     * @since 1.0.0
     */
    public function index(): mixed
    {
        if (!user()?->can('viewAny', $this->model->model())) {
            return ires()->error()->message(__('mgs.no.permission'));
        }
        $model = $this->model->get([
            'select' => ['*'],
            'page' => request('limit', 30),
            'latest' => true,
            'with' => [
                'items' => function ($q): void {
                    $q->whereNull('parent_id')->with('items:parent_id');
                },
                'language:id,name,path,path_alias',
            ],
        ]);

        return ires()->data($model);
    }

    /**
     * Get a menu for editing with nested items prepared for the UI.
     *
     * @param int $id Menu identifier.
     *
     * @return mixed
     *
     * @author malayvuong
     * @since 1.0.0
     */
    public function edit(int $id): mixed
    {
        if (!$id) {
            return ires()->error()->message(__('mgs.id.404'));
        }

        $model = $this->model->find($id, ['*']);

        if (!$model) {
            return ires()->error()->message(__('mgs.model.404'));
        }

        if (!user()?->can('update', $model)) {
            return ires()->error()->message(__('mgs.no.permission'));
        }

        $items = $model->items()->where('parent_id', null)->orderBy('priority', 'ASC')->get();

        $list = [];
        foreach ($items as $key => $item) {
            //  Check custom field
            if (empty($item->custom)) {
                $item->custom = [
                    'isExpandChild' => false,
                    'icon' => null,
                    'color' => null,
                    'action' => '_self',
                    'slugs' => [],
                    'categoryId' => [],
                    'prefix' => [
                        'vi' => null,
                        'en' => null,
                    ],
                ];
            }

            $list[$key] = $this->getChild($item);
        }
        $model->setAttribute('parseItems', $list);

        return ires()->data($model);
    }

    /**
     * Recursively load children for a given menu item and attach to `items`.
     *
     * @param mixed $parent Parent menu item.
     *
     * @return mixed Menu item with `items` hydrated.
     *
     * @author malayvuong
     */
    protected function getChild($parent)
    {
        if (isset($parent->id) && $parent->id > 0) {
            $child = $this->item->model()->where('menu_id', $parent->menu_id)->where('parent_id', $parent->id)->orderBy('priority', 'ASC')->get();

            foreach ($child as $key => $ch) {
                //  Check custom field
                if (empty($ch->custom)) {
                    $ch->custom = [
                        'isExpandChild' => false,
                        'icon' => null,
                        'color' => null,
                        'action' => '_self',
                        'slugs' => [],
                        'categoryId' => [],
                        'prefix' => [
                            'vi' => null,
                            'en' => null,
                        ],
                    ];
                }

                $child[$key] = $this->getChild($ch);
            }
            $parent->items = $child;
        }

        return $parent;
    }

    /**
     * Update a menu and its nested items, then invalidate caches.
     *
     * @param int $id Menu identifier.
     * @param UpdateMenuRequest $request Validated update payload.
     *
     * @return mixed
     *
     * @author phamanhphien
     */
    public function update(int $id, UpdateMenuRequest $request): mixed
    {
        if (!$id) {
            return ires()->error()->message(__('mgs.id.404'));
        }

        $model = $this->model->find($id, ['*']);

        if (!$model) {
            return ires()->error()->message(__('mgs.model.404'));
        }

        if (!user()?->can('update', $model)) {
            return ires()->error()->message(__('mgs.no.permission'));
        }

        $model = $this->fill($model);
        DB::beginTransaction();
        try {
            $model->save();
            foreach (request('items') as $key => $item) {
                $this->storeItem($item, $model->id, 0, $key);
            }

            //  One call bumps every cache layer a menu write must touch —
            //  see CorePublicMenuCacheService::invalidateGroup().
            app(CorePublicMenuCacheService::class)
                ->invalidateGroup((string) $model->group_key, $model->language_id);
        } catch (Exception $e) {
            DB::rollBack();

            //  Return error
            return ires()->error()->message(__('mgs.er.update', ['name' => __('nav.a.' . $this->model->table())]));
        }

        DB::commit();

        return ires()->message(__('mgs.ok.update', ['name' => __('nav.a.' . $this->model->table())]));
    }

    /**
     * Hydrate a Menu model instance with request data.
     *
     * @param mixed|null $model Existing model or null for a new one.
     *
     * @return mixed Populated model instance.
     *
     * @author malayvuong
     */
    protected function fill($model = null): mixed
    {
        if (!$model) {
            $model = $this->model->model();
        }
        /** Fill all request by $fillable */
        $model->fill(request()->only($model->getFillable()));

        return $model;
    }

    /**
     * Store menu item to db
     *    "index" will be priority of menu item
     *
     * @param array|object $data
     *
     * @author malayvuong
     * @since  1.0.0
     */
    /**
     * Persist a menu item and its children recursively.
     *
     * @param mixed $data Item payload (array-like).
     * @param int $menu_id Parent menu id.
     * @param int $idParent Parent item id (0 for root).
     * @param int $index Order index under the parent.
     *
     * @return void
     *
     * @author malayvuong
     */
    public function storeItem(mixed $data, int $menu_id, int $idParent, int $index): void
    {
        if (!$data || (is_array($data) && count($data) < 1)) {
            return;
        }

        $data = collect($data);

        if ((int)$data->get('id') > 0) {
            $model = MenuItem::query()->find($data->get('id'));
        } else {
            $model = new MenuItem;
        }
        $model->menu_id = $menu_id;
        $model->parent_id = $idParent > 0 ? $idParent : null; //  Set nullable
        $model->name = $data->get('name');
        $model->title = $data->get('title');
        $model->url = $data->get('url');
        $model->target_type = $data->get('target_type');
        $model->target_id = $data->get('target_id');
        $model->type = $data->get('type');
        $model->custom = $data->get('type') === 'category'
            ? MenuCategoryExpansion::normalizeCustom($data->get('custom'))
            : $data->get('custom');
        $model->priority = $index;

        $model->save();

        if ((int)$data->get('id') < 1) {
            $model->fresh();
        }

        if ($data->get('items') && is_array($data->get('items'))) {
            foreach ($data->get('items') as $key => $item) {
                $this->storeItem($item, $menu_id, $model->id, $key);
            }
        }
    }

    /**
     * Create a menu with nested items and clear related caches.
     *
     * @param StoreMenuRequest $request Validated payload with `items` tree.
     *
     * @return mixed
     *
     * @author malayvuong
     * @since 1.0.0
     */
    public function store(StoreMenuRequest $request): mixed
    {
        $model = $this->fill();

        if (!user()?->can('create', $model)) {
            return ires()->error()->message(__('mgs.no.permission'));
        }

        DB::beginTransaction();

        try {
            $model->save();

            //  Begin Add items
            foreach (request('items') as $key => $item) {
                $this->storeItem($item, $model->id, 0, $key);
            }

            //  Fire event to clear cache
        } catch (Exception $e) {
            Log::debug($e);
            DB::rollBack();

            return ires()->error()->message(__('mgs.er.create', ['name' => __('nav.a.' . $this->model->table())]));
        }

        DB::commit();

        app(CorePublicMenuCacheService::class)
            ->invalidateGroup((string) $model->group_key, $model->language_id);

        return ires()->message(__('mgs.ok.create', ['name' => __('nav.a.' . $this->model->table())]));
    }

    /**
     * Delete a menu and invalidate caches.
     *
     * @param int $id Menu identifier.
     *
     * @return mixed
     *
     * @author malayvuong
     * @since 1.0.0 - 2022-01-22, 19:22
     */
    public function destroy(int $id): mixed
    {
        if ($id < 1) {
            return ires()->error()->message(__('mgs.id.404'));
        }

        $model = $this->model->find($id);

        if (!$model) {
            return ires()->error()->message(__('mgs.model.404'));
        }

        Db::beginTransaction();

        //  Read before delete(): the row is gone afterwards.
        $groupKey = (string) $model->group_key;
        $languageId = $model->language_id;

        try {
            $model->delete();

            DB::commit();
        } catch (Exception $e) {
            Log::debug($e->getMessage());
            DB::rollBack();

            return ires()->error()->message($e->getMessage());
        }

        app(CorePublicMenuCacheService::class)
            ->invalidateGroup($groupKey, $languageId);

        return ires()->message(__('mgs.ok.delete', ['name' => __('nav.a.menus')]));
    }

    /**
     * Transform a flat menu item collection into a nested tree.
     *
     * @param array $outputData Accumulator (usually []).
     * @param int|null $parentId Current parent id to collect children for.
     * @param array $data Flat list of items.
     *
     * @return array Nested items tree.
     *
     * @author malayvuong
     */
    protected function parseData($outputData, $parentId, $data = [])
    {
        if (!$outputData || !is_array($outputData)) {
            $outputData = [];
        }

        foreach ($data as $index => $item) {
            if ($item->parent_id === $parentId) {
                $item['isShow'] = false;

                $children = $this->parseData($item->children, $item->id, $data);

                $item['children'] = $children;

                $outputData[] = $item;

                unset($data[$index]);
            }
        }

        return $outputData;
    }
}
