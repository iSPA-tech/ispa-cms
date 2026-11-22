<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace App\Http\Controllers\V2\Admin;

use Cms\Core\Responses\AppResponse;

use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Cms\Core\Models\Upload;
use Cms\Core\Services\ImageVariantService;
use Cms\Core\Http\Controllers\AdminController;
use Cms\Core\Interfaces\UploadInterface;
use Cms\Core\Http\Resources\Banner\BannerResource;
use Cms\Core\Http\Resources\Banner\BannerCollection;
use Cms\Core\Http\Requests\Banner\StoreBannerRequest;
use App\Repositories\Interfaces\BannerInterface;
use Cms\Core\Http\Requests\Banner\UpdateBannerRequest;
use Cms\Core\Http\Requests\Banner\MultiDeleteBannerRequest;
use Throwable;

class BannerController extends AdminController
{
    /**
     * Controller constructor
     *
     * @param BannerInterface $model
     * @param UploadInterface $upload
     *
     * @author malayvuong
     * @since 1.0.0
     */
    public function __construct(protected BannerInterface $model, protected UploadInterface $upload)
    {
        parent::__construct();
    }

    /**
     * Display a listing of the resource.
     *
     *
     * @return mixed $res
     * @author malayvuong
     */
    public function index(): mixed
    {
        if (!user()?->can('viewAny', $this->model->model())) {
            return ires()->error()->message(__('mgs.no.permission'));
        }

        $model = $this->model->get([
            'page' => request('pageSize', 10),
            'latest' => true,
            'with' => [
                'author:id,name',
            ],
        ]);

        return ires()->data(new BannerCollection($model));
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param StoreBannerRequest $request
     *
     * @return mixed $res
     * @author malayvuong
     */
    public function store(StoreBannerRequest $request): mixed
    {
        $model = $this->fill();

        if (!user()?->can('create', $model)) {
            return ires()->error()->message(__('mgs.no.permission'));
        }

        DB::BeginTransaction();

        try {
            $model->save();
            //  Sync images
            $model->syncImages($request->input('images', []));
            $this->warmBannerImageVariants($model);
            //  Remove cache
        } catch (Exception $e) {
            Log::debug($e->getMessage());
            DB::rollBack();

            return ires()->error()->message($e->getMessage());
        }

        DB::commit();

        return ires()->message(__('mgs.ok.create', ['name' => __('nav.a.banners')]));
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param int $id
     *
     * @return mixed $res
     * @author malayvuong
     */
    public function edit(int $id): mixed
    {
        if ($id < 1) {
            return ires()->error()->message(__('mgs.id.404'));
        }

        $model = $this->model->find($id, ['*'], ['images']);

        if (!$model) {
            return ires()->error()->message(__('mgs.model.404'));
        }

        if (!user()?->can('update', $model)) {
            return ires()->error()->message(__('mgs.no.permission'));
        }

        return ires()->data(new BannerResource($model));
    }

    /**
     * Update the specified resource in storage.
     *
     * @param int $id
     * @param UpdateBannerRequest $request
     *
     * @return mixed $res
     * @author malayvuong
     */
    public function update(int $id, UpdateBannerRequest $request): mixed
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

        $model = $this->fill($model);

        DB::beginTransaction();

        try {
            $model->save();
            //  Sync images
            $model->syncImages($request->input('images', []));
            $this->warmBannerImageVariants($model);
            //  Remove cache
        } catch (Exception $e) {
            Log::debug($e);
            DB::rollBack();

            return ires()->error()->message($e->getMessage());
        }

        DB::commit();

        return ires()->message(__('mgs.ok.update', ['name' => __('nav.a.banners')]));
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param int $id
     *
     * @return mixed $res
     * @author malayvuong
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

        if (!$model->delete()) {
            return ires()->error()->message(__('mgs.er.delete', ['name' => __('nav.a.banners')]));
        }
        //  Remove cache

        return ires()->message(__('mgs.ok.delete', ['name' => __('nav.a.banners')]));
    }

    /**
     * Remove the multiple specified resource from storage.
     *
     * @param MultiDeleteBannerRequest $request
     *
     * @return mixed $res
     * @author malayvuong
     */
    public function massDestroy(MultiDeleteBannerRequest $request): mixed
    {
        if (!user()?->can('delete', $this->model->model())) {
            return ires()->error()->message(__('mgs.no.permission'));
        }

        return ires()->message(__('mgs.ok.delete', ['name' => __('nav.a.banners')]));
    }

    /**
     * Fill table
     *
     * @param null $model
     *
     * @return mixed $model
     * @author malayvuong
     */
    protected function fill($model = null): mixed
    {
        if (!$model) {
            $model = $this->model->model();
        }

        $model->fill(request()->only($model->getFillable()));

        if (!$model->id) {
            $model->user_id = user('id');
        }

        //  Parse date
        if ($model->limit_by === 1 && count(request('limit_date', [])) === 2) {
            $model->limit_from = request('limit_date')[0];
            $model->limit_to = request('limit_date')[1];
        }

        return $model;
    }

    private function warmBannerImageVariants(mixed $banner): void
    {
        if (!is_object($banner) || !method_exists($banner, 'images')) {
            return;
        }

        try {
            $variantService = app(ImageVariantService::class);

            foreach ($banner->images()->get() as $image) {
                if ($image instanceof Upload) {
                    $variantService->ensureContext($image, 'banner');
                }
            }
        } catch (Throwable $e) {
            Log::warning('Banner image variant warmup failed', [
                'banner_id' => $banner->id ?? null,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
