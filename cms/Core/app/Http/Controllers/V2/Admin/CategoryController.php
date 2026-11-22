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
use App\Enums\StatusEnum;
use Illuminate\Support\Str;
use Illuminate\Http\Request;
use App\Services\SlugService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Services\AdminUpdateContentService;
use Cms\Core\Models\Category;
use Cms\Core\Interfaces\UploadInterface;
use Cms\Core\Interfaces\CategoryInterface;
use Cms\Core\Http\Controllers\AdminController;
use Cms\Core\Services\Cache\CategoryCacheInvalidationService;
use Cms\Core\Repositories\Interfaces\SeoInterface;
use Cms\Core\Repositories\Interfaces\SlugInterface;
use Cms\Core\Http\Resources\Category\CategoryCollection;
use Cms\Core\Http\Requests\Category\StoreCategoryRequest;
use Cms\Core\Http\Resources\Category\EditCategoryResource;
use Cms\Core\Http\Requests\Category\UpdateCategoryRequest;
use Cms\Core\Http\Requests\Category\UpdatePriorityRequest;

/**
 * Manage categories in Admin v2: search, hierarchical listing, create/update with
 * slug and SEO synchronization, priority adjustments, image/document syncing, and
 * soft-delete/restore operations.
 *
 * @package Cms\Core\Http\Controllers\V2\Admin
 * @author  malayvuong
 * @since   1.0.0 (2022-01-15)
 */
class CategoryController extends AdminController
{
    /**
     * Controller constructor
     *
     * @param CategoryInterface $model
     * @param SlugInterface $slug
     * @param SeoInterface $seo
     * @param UploadInterface $upload
     * @param SlugService $slugService
     *
     * @author malayvuong
     * @since  1.0.0
     */
    public function __construct(
        protected CategoryInterface $model,
        protected SlugInterface     $slug,
        protected SeoInterface      $seo,
        protected UploadInterface   $upload,
        protected SlugService       $slugService
    )
    {
        parent::__construct();
    }

    /**
     * Update priority
     *
     * @param int $id
     * @param UpdatePriorityRequest $request
     *
     * @return mixed
     * @author vuongnm
     * @since 7.160.1175 - 2023-09-20, 20:23 ICT
     */
    public function updatePriority(int $id, UpdatePriorityRequest $request): mixed
    {
        if ($id < 1) {
            return ires()->error()->message(__('mgs.id.404'));
        }

        $model = $this->model->find($id);

        if (!$model) {
            return ires()->error()->message(__('mgs.model.404'));
        }

        if (!user()?->can('update', $model)) {
            return ires()->error()->message(__('mgs.no.permission'));
        }

        $cacheBefore = $this->categoryCacheSnapshot($model);

        DB::beginTransaction();
        try {
            $model->priority = $request->input('priority');
            $model->save();
            $cacheAfter = $this->categoryCacheSnapshot($model);
            $this->scheduleCategoryCacheInvalidation($cacheBefore, $cacheAfter);
        } catch (Exception $e) {
            Log::debug($e->getMessage());
            DB::rollBack();
            return ires()->error()->message($e->getMessage());
        }
        DB::commit();

        return ires()->message(__('mgs.ok.update', ['name' => __('nav.a.categories')]));
    }

    /**
     * Search category
     *
     * @return mixed
     * @author malayvuong
     * @since 6.11.5 - 2022-03-13, 16:32
     */
    public function search()
    {
        $model = $this->model->get([
            'select' => ['id', 'name'],
            'when' => [
                [
                    request('query', '') !== '',
                    function ($q): void {
                        $q->whereFulltext(['name', 'description', 'content', 'hashtag'], request('query'))->orWhere('name', 'like', '%' . request('query') . '%');
                    },
                ],
            ],
        ]);

        return ires()->data($model);
    }

    /**
     * Delete a category
     *
     * @param int $id
     *
     * @return mixed
     * @author malayvuong
     * @since 1.0.0 - 2022-01-18, 9:58
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

        if (!user()?->can('delete', $model)) {
            return ires()->error()->message(__('mgs.no.permission'));
        }

        $cacheBefore = $this->categoryCacheSnapshot($model);

        DB::beginTransaction();
        try {
            $model->delete();
            $cacheAfter = $this->categoryCacheSnapshot($model, true);
            $this->scheduleCategoryCacheInvalidation($cacheBefore, $cacheAfter);

            Db::commit();
        } catch (Exception $e) {
            Log::debug($e->getMessage());
            DB::rollBack();

            return ires()->error()->message($e->getMessage());
        }

        return ires()->message('Delete Success');
    }

    /**
     * Update category
     *
     * @param int $id
     * @param UpdateCategoryRequest $request
     *
     * @return mixed
     * @author malayvuong
     * @since 1.0.0 - 2022-01-17, 21:17
     * @last_updated 6.19.6 - 2022-05-01, 16:57 ICT
     * - change Db::commit() position
     * - update docblock
     */
    public function update(int $id, UpdateCategoryRequest $request): mixed
    {
        if ($id < 1) {
            return ires()->error()->message(__('mgs.id.404'));
        }

        $model = $this->model->find($id);

        if (!$model) {
            return ires()->error()->message(__('mgs.model.404'));
        }

        if (!user()?->can('update', $model)) {
            return ires()->error()->message(__('mgs.no.permission'));
        }

        $cacheBefore = $this->categoryCacheSnapshot($model);

        $model = $this->fill($model);

        DB::beginTransaction();

        try {
            $model->save();

            //  Update content
            $this->contentUpdate($request, $model);
            //  Sync images
            $model->syncImages($request->input('images', []));
            if (user()?->can('category.seo')) {
                $model->syncSeo($request->input('seos', []));
            }
            $cacheAfter = $this->categoryCacheSnapshot($model);
            $this->scheduleCategoryCacheInvalidation($cacheBefore, $cacheAfter);
        } catch (Exception $e) {
            Log::debug($e->getMessage());
            DB::rollBack();
            return ires()->error()->message($e->getMessage());
        }

        DB::commit();

        return ires()->message(__('mgs.ok.update', ['name' => __('nav.a.categories')]));
    }

    /**
     * Fill table
     *
     * @param mixed|null $model
     *
     * @return mixed
     * @author malayvuong
     */
    protected function fill($model = null): mixed
    {
        if (!$model) {
            $model = $this->model->model();
        }
        $request = request();

        $model->fill([
            'name' => $request->input('name'),
            'config' => $request->input('config'),
            'featured' => (bool)$request->input('featured'),
            'parent_id' => collect($request->input('parent_id'))->last(),
            'description' => $request->input('description'),
            'language_id' => $request->input('language_id', 0) > 0 ? $request->input('language_id') : null,
            'status' => $request->input('status', StatusEnum::PUBLISHED->value),
            'type' => $request->input('type'),
        ]);

        if (!$model->id || $model->id < 1) {
            /** Set uuid string */
            $model->uid = $this->model->checkUnique('uid', Str::random(10));
        }

        return $model;
    }

    /**
     * model Update
     *
     * @param Request $request
     * @param mixed $model
     *
     * @return void
     * @author malayvuong
     * @since 1.0.0 - 2022-01-24, 17:29
     */
    protected function contentUpdate(Request $request, mixed $model): void
    {
        $service = new AdminUpdateContentService();

        $service->updateSlug($request, $model);

        $service->updateSeo($request, $model);
    }

    protected function scheduleCategoryCacheInvalidation(array $before, array $after): void
    {
        DB::afterCommit(static function () use ($before, $after): void {
            app(CategoryCacheInvalidationService::class)->invalidateSnapshots($before, $after);
        });
    }

    protected function categoryCacheSnapshot(mixed $model, bool $withTrashed = false): array
    {
        $category = $this->freshCategoryForCacheSnapshot($model, $withTrashed);

        return app(CategoryCacheInvalidationService::class)->snapshot($category);
    }

    protected function freshCategoryForCacheSnapshot(mixed $model, bool $withTrashed = false): ?Category
    {
        $id = $model instanceof Category ? $model->getKey() : $model;

        if (empty($id)) {
            return null;
        }

        $query = Category::query();

        if ($withTrashed) {
            $query->withTrashed();
        }

        return $query
            ->with('slugs:id,slug,target_id,target_type,language_id')
            ->find((int) $id);
    }

    /**
     * Get category to edit
     *
     * @param int $id
     *
     * @return mixed
     * @author malayvuong
     * @since 1.0.0 - 2022-01-17, 21:9
     */
    public function edit(int $id): mixed
    {
        if ($id < 1) {
            return ires()->error()->message(__('mgs.id.404'));
        }

        $model = $this->model->find($id, ['*'], [
            'latestSlug',
            'seos',
            'uploaded',
        ]);

        if (!$model) {
            return ires()->error()->message(__('mgs.model.404'));
        }

        if (!user()?->can('update', $model)) {
            return ires()->error()->message(__('mgs.no.permission'));
        }

        if (empty($model->parent_id)) {
            $model->parent_id = [];
        }

        return ires()->data(new EditCategoryResource($model));
    }

    /**
     * Store category
     *
     * @param StoreCategoryRequest $request
     *
     * @return mixed
     * @author malayvuong
     * @since 1.0.0 - 2022-01-17, 19:24
     */
    public function store(StoreCategoryRequest $request): mixed
    {
        if (!user()?->can('category.create')) {
            return ires()->error()->message(__('mgs.no.permission'));
        }

        DB::beginTransaction();

        try {
            $model = $this->fill();

            $model->save();

            //  Update content
            $this->contentUpdate($request, $model);
            //  Sync images
            $model->syncImages($request->input('images', []));
            $cacheAfter = $this->categoryCacheSnapshot($model);
            $this->scheduleCategoryCacheInvalidation([], $cacheAfter);
        } catch (Exception $e) {
            Log::debug($e);
            DB::rollBack();

            return ires()->error()->message($e->getMessage());
        }

        DB::commit();

        return ires()->data($model)->message(__('mgs.ok.save', ['name' => __('nav.a.categories')]))->code(201);
    }

    /**
     * Get the list
     *
     * @return mixed
     *
     * @author malayvuong
     * @since 1.0.0 - 2022-01-17, 18:4
     */
    public function index(): mixed
    {
        if (!user()?->can('viewAny', $this->model->model())) {
            return ires()->error()->message(__('mgs.no.permission'));
        }

        $request = request();

        $categories = $this->model->get([
            // All list columns except the heavy `content` longText (loaded by the detail endpoint).
            'select' => [
                'id', 'uid', 'name', 'description', 'hashtag', 'parent_id', 'language_id', 'priority',
                'type', 'featured', 'status', 'config', 'created_at', 'updated_at', 'deleted_at',
            ],
            'when' => [
                [
                    $request->has('type') && (int)$request->input('type') > -1,
                    fn($query) => $query->where('type', request()->input('type')),
                ],
            ],
            'order' => [
                'language_id' => 'asc',
                'priority' => 'asc',
            ],
            'with' => [
                'latestSlug',
                'language:id,name',
            ],
        ]);

        //  multiple level
        $categories = multiLevel($categories);

        return ires()->data(new CategoryCollection($categories));
    }

    /**
     * Return list by multiple level
     *
     * @param array $list
     * @param int|null $parentId
     * @param string $prefix
     * @param array $returnArray
     *
     * @return array
     * @author malayvuong
     */
    protected function multi(array $list = [], int $parentId = null, string $prefix = ' ', array $returnArray = [['id' => 0, 'name' => 'ROOT']]): array
    {
        if (count($list) < 1) {
            return $returnArray;
        }

        foreach ($list as $key => $item) {
            if ($item && $item['parent_id'] === $parentId) {
                $item['mapped_name'] = $prefix . $item['name'];
                $returnArray[] = $item;
                //  Delete item from source array
                unset($list[$key]);
                //  Continue to insert item to array
                $returnArray = $this->multi($list, $item['id'], $prefix . '|-- ', $returnArray);
            }
        }

        return $returnArray;
    }
}
