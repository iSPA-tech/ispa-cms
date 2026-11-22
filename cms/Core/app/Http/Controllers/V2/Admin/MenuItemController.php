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
use Illuminate\Support\Facades\DB;
use Cms\Core\Http\Controllers\AdminController;
use Cms\Core\Models\Menu;
use Cms\Core\Services\Cache\CorePublicMenuCacheService;
use Cms\Core\Interfaces\MenuItemInterface;

/**
 * Manage individual menu items for Admin v2.
 * Currently supports deleting a specific item from a menu.
 *
 * @package Cms\Core\Http\Controllers\V2\Admin
 * @author  malayvuong
 * @since   6.2.7 (2022-02-22)
 * @last_updated 8.0.0 - 2025-09-11, 14:40 (+07:00)
 */
class MenuItemController extends AdminController
{
    /**
     * Menu item constructor
     *
     * @param  MenuItemInterface $model
     * @author malayvuong
     * @since 6.2.7 - 2022-02-22, 14:34
     */
    public function __construct(protected MenuItemInterface $model)
    {
        parent::__construct();
    }

    /**
     * Delete a menu item from a given menu.
     *
     * @param int $menuId Menu identifier.
     * @param int $id Menu item identifier.
     *
     * @return mixed
     *
     * @author malayvuong
     * @since 6.2.7 - 2022-01-22, 19:22
     */
    public function destroy(int $menuId, int $id): mixed
    {
        if ($id < 1) {
            return ires()->error()->message(__('mgs.id.404'));
        }

        $model = $this->model->findBy([
            'id' => $id,
            'menu_id' => $menuId,
        ]);

        if (!$model) {
            return ires()->error()->message(__('mgs.model.404'));
        }

        Db::beginTransaction();

        try {
            $model->delete();
        } catch (Exception $e) {
            DB::rollBack();

            return ires()->error()->message($e->getMessage());
        }

        //  This path deleted items without bumping a single cache layer, so
        //  the website kept serving the removed entry until TTLs lapsed.
        if ($menu = Menu::query()->find($menuId)) {
            app(CorePublicMenuCacheService::class)
                ->invalidateGroup((string) $menu->group_key, $menu->language_id);
        }

        DB::commit();

        return ires()->message(__('mgs.ok.delete', ['name' => __('nav.a.menu_items')]));
    }
}
