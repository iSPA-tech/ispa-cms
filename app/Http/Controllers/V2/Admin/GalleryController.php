<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace App\Http\Controllers\V2\Admin;

use Cms\Core\Responses\AppResponse;

use App\Enums\StatusEnum;
use Exception;
use App\Constants\Constant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Cms\Core\Models\Upload;
use Cms\Core\Services\ImageVariantService;
use Cms\Core\Http\Controllers\AdminController;
use App\Services\AdminUpdateContentService;
use Cms\Core\Interfaces\CategoryInterface;
use App\Repositories\Interfaces\GalleryInterface;
use Cms\Core\Http\Requests\Gallery\StoreGalleryRequest;
use Cms\Core\Http\Requests\Gallery\UpdateGalleryRequest;
use Cms\Core\Http\Resources\Gallery\GalleryEditResource;
use Cms\Core\Http\Requests\Gallery\GetGalleriesIndexRequest;
use Cms\Core\Http\Requests\Gallery\MultiDeleteGalleryRequest;
use Throwable;

class GalleryController extends AdminController
{
    /**
     * Controller constructor
     *
     * @param GalleryInterface $model
     * @param CategoryInterface $category
     *
     * @author malayvuong
     * @since 1.0.0
     */
    public function __construct(
        protected GalleryInterface  $model,
        protected CategoryInterface $category,
    )
    {
        parent::__construct();
    }

    /**
     * Quick Update resource.
     *
     * @param int $id
     *
     * @return mixed $res
     * @author malayvuong
     */
    public function quickUpdate(int $id): mixed
    {
        if ($id < 1) {
            return ires()->error()->message(__('mgs.model.404'));
        }

        $model = $this->model->find($id);

        if (!$model) {
            return ires()->error()->message(__('mgs.model.404'));
        }

        if (!user()?->can('update', $model)) {
            return ires()->error()->message(__('mgs.no.permission'));
        }

        DB::beginTransaction();
        try {
            $model->status = request()->input('status');

            $model->save();
        } catch (Exception $e) {
            Log::debug($e->getMessage());
            DB::rollBack();

            return ires()->error()->message($e->getMessage());
        }
        DB::commit();

        return ires()->message(__('mgs.ok.update', ['name' => __('nav.a.galleries')]));
    }

    /**
     * Switch a status of resource.
     *
     * @param int $id
     *
     * @return mixed $res
     * @author malayvuong
     */
    public function switch(int $id): mixed
    {
        if ($id < 1) {
            return ires()->error()->message(__('mgs.model.404'));
        }

        $model = $this->model->find($id);

        if (!$model) {
            return ires()->error()->message(__('mgs.model.404'));
        }

        if (!user()?->can('update', $model)) {
            return ires()->error()->message(__('mgs.no.permission'));
        }

        /** Todo: Make your change here */
        if (!$model->save()) {
            return ires()->error()->message(__('mgs.er.update', ['name' => __('nav.a.galleries')]));
        }

        return ires()->message(__('mgs.ok.update', ['name' => __('nav.a.galleries')]));
    }

    /**
     * Display a listing of the resource.
     *
     * @param GetGalleriesIndexRequest $request
     *
     * @return mixed $res
     * @author malayvuong
     */
    public function index(GetGalleriesIndexRequest $request): mixed
    {
        if (!user()?->can('viewAny', $this->model->model())) {
            return ires()->error()->message(__('mgs.no.permission'));
        }

        $model = $this->model->get([
            'order' => ['id' => 'desc'],
            'with' => [
                'language:id,name,path,flag',
                'images:id,target_id,disk,type,name,path,mime_type,file_size,target_folder,config',
                'category:id,name',
                'slugs:target_id,slug',
                'author',
            ],
            'page' => request()->input('pageSize', 10),
            'when' => [
                [
                    !empty($request->input('keywords')),
                    function ($q) use ($request) {
                        $str = $request->input('keywords');

                        return $q->where(function ($q) use ($str) {
                            $q->where('name', 'like', '%' . $str . '%')
                                ->orWhere('description', 'like', '%' . $str . '%')
                                ->orWhere('code', $str)
                                ->orWhere('sku', $str);
                        });
                    },
                ],
                [
                    $request->input('status', StatusEnum::PUBLISHED->value),
                    fn($query) => $query->when(
                        in_array($request->input('status'), [StatusEnum::PUBLISHED->value, StatusEnum::DRAFT->value]),
                        fn($q) => $q->where('status', $request->input('status', StatusEnum::PUBLISHED->value)))
                        ->when($request->input('status') === 'deleted', fn($q) => $q->onlyTrashed())
                        ->when($request->input('status') === 'all', fn($q) => $q->withTrashed()),
                ],
                [
                    !empty($request->input('categoryId')),
                    function ($q) use ($request): mixed {
                        $last = collect(explode(',', $request->input('categoryId')))->last();
                        if ($last !== 'all' && (int)$last > 0) {
                            //  Get all children categories ids
                            $ids = $this->category->getChildrenIds((int)$last);
                            $ids[] = (int)$last;
                            return $q->whereIn('category_id', $ids);
                        }

                        return $q;
                    },
                ],
            ],
        ]);

        return ires()->data($model);
    }

    /**
     * Show the form for creating a new resource.
     *
     *
     * @return mixed $res
     * @author malayvuong
     */
    public function create()
    {
        return $res;
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param StoreGalleryRequest $request
     *
     * @return mixed $res
     * @author malayvuong
     */
    public function store(StoreGalleryRequest $request): mixed
    {
        $model = $this->fill();

        if (!user()?->can('create', $model)) {
            return ires()->error()->message(__('mgs.no.permission'));
        }

        DB::beginTransaction();

        try {
            $model->save();

            //  Update Content
            $this->contentUpdate($request, $model);

            //  Sync images
            $model->syncImages($request->input('images', []));
            //  Sync slug
            $model->syncSlug([...$request->input('slugs', []), 'language_id' => $model->language_id]);
            //  Sync SEO
            $model->syncSeo($request->input('seos', []));
            $this->warmGalleryImageVariants($model);
        } catch (Exception $e) {
            Log::debug($e);
            DB::rollBack();

            return ires()->error()->message($e->getMessage());
        }

        Db::commit();

        return ires()->message(__('mgs.ok.create', ['name' => __('nav.a.galleries')]));
    }

    /**
     * Fill table
     *
     * @param null $model
     *
     * @return mixed $model
     **@author malayvuong
     */
    protected function fill($model = null): mixed
    {
        if (!$model) {
            $model = $this->model->model();
        }

        $model->fill(request()->only($model->getFillable()));

        if (!empty(request()->input('category_id', []))) {
            $model->category_id = collect(request()->input('category_id')[0])->last();
        } else {
            $model->category_id = null;
        }

        return $model;
    }

    /**
     * model Update
     *
     * @param Request $request
     * @param $model
     *
     * @author malayvuong
     * @since 1.0.0 - 2022-01-24, 17:29
     * @last_updated 6.19.6 - 2022-05-01, 21:06 ICT - by malayvuong
     * - change request from `slug` to `slugs`
     */
    protected function contentUpdate(Request $request, $model): void
    {
        $service = new AdminUpdateContentService();
        $service->updateSlug($request, $model);
        $service->updateSeo($request, $model);
        $service->syncCategory($request, $model);

        //  Remove cache
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param int $id
     *
     * @return mixed $res
     *
     * @author malayvuong
     */
    public function edit(int $id): mixed
    {
        if ($id < 1) {
            return ires()->error()->message(__('mgs.model.404'));
        }

        $model = $this->model->findBy(['id' => $id], ['*'], ['slugs', 'seos', 'images', 'language', 'editableCategories']);

        if (!$model) {
            return ires()->error()->message(__('mgs.model.404'));
        }

        if (!user()?->can('update', $model)) {
            return ires()->error()->message(__('mgs.no.permission'));
        }

        return ires()->data(new GalleryEditResource($model));
    }

    /**
     * Update the specified resource in storage.
     *
     * @param int $id
     * @param UpdateGalleryRequest $request
     *
     * @return mixed $res
     * @author malayvuong
     *
     */
    public function update(int $id, UpdateGalleryRequest $request): mixed
    {
        if ($id < 1) {
            return ires()->error()->message(__('mgs.model.404'));
        }

        $model = $this->model->find($id);

        if (!$model) {
            return ires()->error()->message(__('mgs.model.404'));
        }

        if (!user()?->can('update', $model)) {
            return ires()->error()->message(__('mgs.no.permission'));
        }

        DB::beginTransaction();

        try {
            $model = $this->fill($model);

            $model->save();

            //  Update Content
            $this->contentUpdate($request, $model);

            //  Sync images
            $model->syncImages($request->input('images', []));
            //  Sync slug
            $model->syncSlug([...$request->input('slugs', []), 'language_id' => $model->language_id]);
            //  Sync SEO
            $model->syncSeo($request->input('seos', []));
            $this->warmGalleryImageVariants($model);
        } catch (Exception $e) {
            Log::debug($e->getMessage());
            DB::rollBack();

            return ires()->error()->message($e->getMessage());
        }

        Db::commit();

        return ires()->message(__('mgs.ok.update', ['name' => __('nav.a.galleries')]));
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param int $id
     *
     * @return mixed $res
     * @author malayvuong
     *
     */
    public function destroy(int $id): mixed
    {
        if ($id < 1) {
            return ires()->error()->message(__('mgs.model.404'));
        }

        $model = $this->model->find($id);

        if (!$model) {
            return ires()->error()->message(__('mgs.model.404'));
        }

        if (!user()?->can('delete', $model)) {
            return ires()->error()->message(__('mgs.no.permission'));
        }

        if (!$model->delete()) {
            return ires()->error()->message(__('mgs.er.delete', ['name' => __('nav.a.galleries')]));
        }

        return ires()->message(__('mgs.ok.delete', ['name' => __('nav.a.galleries')]));
    }

    /**
     * Remove the multiple specified resource from storage.
     *
     * @param MultiDeleteGalleryRequest $request
     *
     * @return mixed $res
     * @author malayvuong
     *
     */
    public function massDestroy(MultiDeleteGalleryRequest $request): mixed
    {
        if (!user()?->can('delete', $this->model->model())) {
            return ires()->error()->message(__('mgs.no.permission'));
        }

        return ires()->message(__('mgs.ok.delete', ['name' => __('nav.a.galleries')]));
    }

    private function warmGalleryImageVariants(mixed $gallery): void
    {
        if (!is_object($gallery) || !method_exists($gallery, 'images')) {
            return;
        }

        try {
            $variantService = app(ImageVariantService::class);

            foreach ($gallery->images()->get() as $index => $image) {
                if (!$image instanceof Upload) {
                    continue;
                }

                $variantService->ensureContext($image, 'gallery.item');

                if ($index === 0) {
                    $variantService->ensureContext($image, 'gallery.cover');
                }
            }
        } catch (Throwable $e) {
            Log::warning('Gallery image variant warmup failed', [
                'gallery_id' => $gallery->id ?? null,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
