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
use Throwable;
use JsonException;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Cms\Core\Http\Controllers\AdminController;
use Cms\Content\Interfaces\ContentInterface;
use App\Repositories\Interfaces\ProvinceInterface;
use Cms\Core\Http\Requests\Province\UpdateProvinceRequest;

class ProvinceController extends AdminController
{
    /**
     * Controller constructor
     *
     * @param ProvinceInterface $model
     * @param ContentInterface|null $content Null while the Content module is disabled (its binding is absent); province
     *                                       content blocks are then neither loaded nor saved.
     *
     * @author malayvuong
     * @since 1.0.0
     */
    public function __construct(
        protected ProvinceInterface $model,
        protected ?ContentInterface $content = null
    )
    {
        parent::__construct();
    }

    /**
     * Get the list
     *
     * @author malayvuong
     *
     * @since 1.0.0 - 2022-02-18, 14:15
     */
    public function index(): mixed
    {
        return ires()->data([]);
    }

    /**
     * Delete a post
     *
     * @throws Throwable
     * @since 1.0.0 - 2022-01-25, 14:15
     * @author malayvuong
     *
     */
    public function destroy(int $id): mixed
    {
        if ($id < 1) {
            return ires()->error()->message(__('mgs.id.404'));
        }

        $model = $this->model->find($id, ['id']);

        if (!$model) {
            return ires()->error()->message(__('mgs.model.404'));
        }

        if (!user()?->can('delete', $model)) {
            return ires()->error()->message(__('mgs.no.permission'));
        }

        Db::beginTransaction();

        try {
            $model->delete();

            DB::commit();
        } catch (Exception $e) {
            Log::debug($e->getMessage());
            DB::rollBack();

            return ires()->error()->message($e->getMessage());
        }

        return ires()->message(__('mgs.ok.delete', ['name' => __('nav.a.provinces')]));
    }

    /**
     * get folder tree by ID
     *
     * @param int $id
     *
     * @return mixed
     *
     * @author malayvuong
     *
     * @since  1.0.0
     */
    public function getFolderTree(int $id): mixed
    {
        $model = $this->model->get([
            'select' => ['id', 'name', 'parent_id', 'uid', 'type'],
            'where' => [
                'parent_id' => ($id > 0 ? $id : null),
            ],
            'order' => [
                'name' => 'asc',
            ],
            'count' => ['children'],
        ]);

        return ires()->data($model);
    }

    /**
     * Quick Update resource.
     *
     * @param int $id
     *
     * @return mixed $res
     *
     * @author malayvuong
     */
    public function quickUpdate(int $id): mixed
    {
        if ($id < 1) {
            return ires()->error()->message(__('mgs.error.404'));
        }

        $model = $this->model->find($id);

        if (!$model) {
            return ires()->error()->message(__('mgs.error.404'));
        }

        if (!user()?->can('update', $model)) {
            return ires()->error()->message(__('mgs.no.permission'));
        }

        $list = ['name', 'uid', 'global_name', 'slug', 'type', 'parent_id'];

        if (!in_array(request('col'), $list, true)) {
            return ires()->error()->message(__('mgs.error.request-not-valid'));
        }

        $model[request('col')] = request('value');

        if (!$model->save()) {
            return ires()->error()->message(__('mgs.er.update', ['name' => __('nav.a.province')]));
        }

        return ires()->message(__('mgs.ok.update', ['name' => __('nav.a.province')]));
    }

    /**
     * Get 3 level tree
     *
     * @param string $id
     *
     * @return mixed $res
     * @author phamanhphien
     */
    public function getList(string $id)
    {
        $list = [];
        $expsId = [];
        //$model = $this->model->findBy([['parent_id','<>',$id]], ['id','name']);
        $model = $this->model->get([
            'when' => [[$id, function ($q) use ($id): mixed {
                return $q->where('parent_id', ((int)$id > 0 ? $id : null));
            }]],
            'order' => [
                'name' => 'asc',
            ],
            'select' => ['id', 'name as label', 'parent_id', 'uid'],
        ]);

        if ($model) {
            $expsId = $model->pluck('id');
            foreach ($model as $key => $e) {
                $item = $e;
                $item['children'] = $this->model->get([
                    'where' => [['parent_id', '=', (int)$e->id]],
                    'select' => ['id', 'name as label', 'parent_id', 'uid'],
                    'order' => [
                        'name' => 'asc',
                    ],
                ]);
                if ($item['children']) {
                    foreach ($item['children'] as $cKey => $f) {
                        $temp = $this->model->get([
                            'where' => [['parent_id', '=', (int)$f->id]],
                            'select' => ['id', 'name as label', 'parent_id', 'uid'],
                        ]);
                        if (count($temp) > 0) {
                            $f['children'] = [['label' => '', 'id' => 0]];
                        }
                    }
                }
                $list[] = $item;
            }
        }

        return ires()->data(['list' => $list, 'expsId' => $expsId]);
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param string|null $uuid
     *
     * @return mixed $res
     */
    public function edit(string $id): mixed
    {
        if (!$id) {
            return ires()->error()->message('ID not found');
        }

        $relations = ['parent'];
        if ($this->content !== null) {
            $relations['contents'] = function ($q): void {
                $q->with([
                    'audios:id,target_id,name,path,mime_type',
                    'language:id,name,flag',
                ])->select('id', 'name', 'target_id', 'uuid', 'description', 'video_url', 'language_id', 'content');
            };
        }

        $province = $this->model->findBy(['id' => $id], ['*'], $relations);

        if (!$province) {
            return ires()->error()->message('Province not found');
        }

        if (!request()->user()->can('update', $province)) {
            return ires()->error()->message('No Permission');
        }

        return ires()->data($province);
    }

    /**
     * Search for filter
     *
     *
     * @return mixed
     *
     * @author phamanhphien
     *
     * @since  1.0.0
     */
    public function search()
    {
        // Resolving an already-selected value by exact id must find it
        // regardless of tree level: some FKs into `provinces` (e.g.
        // GeoPlaces `places.province_id`, used to reference a ward/commune
        // leaf node after address enrichment) legitimately point at a
        // type=4 ward rather than a type=1/2 province, and the caller
        // already knows the exact id it wants.
        $hasId = request()->filled('id');

        $data = $this->model->get([
            'select' => ['id', 'name'],
            'where' => [
                'type' => function ($q) use ($hasId) {
                    if ($hasId) {
                        return;
                    }
                    $q->where('type', 1)->orWhere('type', 2);
                },
            ],
            'when' => [
                [
                    $hasId,
                    function ($q): void {
                        $q->where('id', request('id'));
                    },
                ],
                [
                    request('keywords') && request('keywords') !== '',
                    function ($q): void {
                        $q->whereFulltext(['name', 'global_name', 'config'], request('keywords'));
                    },
                ],
            ],
            'page' => request('limit', 20),
            'orderBy' => [
                'parent_id' => 'asc',
            ],
        ]);

        return ires()->data($data);
    }

    /**
     * Store new item
     *
     * @author malayvuong
     *
     * @since 1.0.0 - 2022-01-23, 2:48
     */
    public function store(StoreProvinceReques $request): mixed
    {
        $model = $this->fill();

        if (!user()?->can('create', $model)) {
            return ires()->error()->message(__('mgs.no.permission'));
        }

        DB::beginTransaction();

        try {
            $model->save();

            $this->contentsUpdate($model);

            DB::commit();
        } catch (Exception $e) {
            Log::debug($e->getMessage());
            DB::rollBack();

            return ires()->error()->message($e->getMessage());
        }

        return ires()->message(__('mgs.ok.save', ['name' => __('nav.a.provinces')]));
    }

    /**
     * Fill the model
     *
     * @author malayvuong
     *
     * @since 1.0.0 - 2022-01-23, 2:51
     */
    protected function fill($model = null)
    {
        if (!$model) {
            $model = $this->model->model();
        }

        $model->fill(request()->only($model->getFillable()));

        if (!$model->id || $model->id < 1) {
            /** Set uuid string */
            $model->uid = $this->model->checkUnique('uid', Str::random(10));
        }

        return $model;
    }

    /**
     * Update other request
     *
     * @param
     *
     * @throws JsonException
     *
     * @since 1.0.0 - 2022-01-22, 18:31
     *
     * @author malayvuong
     */
    protected function contentsUpdate($model): void
    {
        if ($this->content === null) {
            return;
        }

        if ($model && $model->id > 0) {
            //  Save content
            foreach (request('contents') as $content) {
                if (isset($content['name'], $content['content'][0]['value'])) {
                    if (isset($content['id']) && $content['id'] > 0) {
                        $save = $this->content->find($content['id']);
                        $save->fill(collect($content)->only($this->content->model()->getFillable())->toArray())->save();
                    } else {
                        $save = $this->content->reset()->model();
                        $save->fill(collect($content)->only($this->content->model()->getFillable())->toArray());

                        $save->uuid = $this->content->checkUnique('uuid', Str::uuid());
                        $save->target_type = get_class($model);
                        $save->target_id = $model->id;
                        $save->user_id = auth()->id();

                        $save->save();
                    }
                    $save->fresh();
                    //  Save Audio
                    if (count($content['audios']) > 0) {
                        $this->upload->reset()->model()->whereIn('id', collect($content['audios'])->pluck('id'))->update(['target_id' => $save->id]);
                    }
                    //  Save image
                    if (is_array(request('images')) && count(request('images')) > 0) {
                        $this->upload->reset()->model()->whereIn('id', collect(request('images'))->pluck('id'))->update(['target_id' => $model->id]);
                    }
                }
            }
        }
    }

    /**
     * Update item
     *
     * @author malayvuong
     *
     * @since 1.0.0 - 2022-01-23, 9:59
     */
    public function update(int $id, UpdateProvinceRequest $request): mixed
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

            $this->contentsUpdate($model);

            DB::commit();
        } catch (Exception $e) {
            Log::debug($e->getMessage());
            DB::rollBack();

            return ires()->error()->message($e->getMessage());
        }

        return ires()->message(__('mgs.ok.update', ['name' => __('nav.a.provinces')]));
    }
}
