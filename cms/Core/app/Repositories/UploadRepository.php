<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Repositories;

use App\Constants\Constant;
use Cms\Core\Models\Upload;
use Cms\Core\Interfaces\UploadInterface;
use Cms\Core\Repositories\Eloquent\RepositoriesAbstract;

/**
 * Class UploadRepository
 * Eloquent repository for the Upload model. Includes helpers for content/image/logo relations and caching helpers.
 *
 * @extends RepositoriesAbstract
 * @implements UploadInterface
 * @see Upload
 *
 * @method \Illuminate\Database\Eloquent\Model|\Illuminate\Database\Eloquent\Collection|\Illuminate\Database\Eloquent\Builder|array|null find(int $id, array $select = ['*'], array $with = [])
 * @method mixed get(array $params)
 * @package Cms\Core\Repositories
 */
class UploadRepository extends RepositoriesAbstract implements UploadInterface
{
    /**
     * Initialize the repository and bind the Upload model.
     *
     * @author  iSPA Team
     * @since   8.0.0 (2026-03-17)
     */
    public function __construct()
    {
        $this->setModel(Upload::class);
    }

    /**
     * Get file upload detail
     *
     * @param array $condition
     *
     * @return object|array|null
     * @author malayvuong
     * @since 6.62.6 - 2022-08-16, 21:38 ICT
     */
    public function findByCache(array $condition): object|array|null
    {
        //  Check array if empty, or not have key id, key slug, key category, then return null
        if (!isset($condition['id'], $condition['slug']) || empty($condition)) {
            return null;
        }

        //  CPL: Make cacheKey from make_key function with array data type=>'document' and document_id=>$id and category=>$cate and slug=>$slug
        $cacheKey = make_key(['type' => Constant::CACHE_KEY_UPLOADS, 'file_id' => $condition['id'], 'slug' => $condition['slug']]);

        //  CPL: Get upload info by id by interface, get relationship with category
        return cache()->remember($cacheKey, config('setting.cache.document', 3600), function () use ($condition, $cacheKey) {

            return $this->find($condition['id'], [
                'id', 'name', 'path', 'type', 'mime_type', 'file_size', 'name_alias', 'alt_text',
                'category', 'custom', 'config', 'created_at',
            ], [
                'categories' => function ($q) {
                    $q->select('id', 'name', 'parent_id', 'type', 'status')->with([
                        'slugs:target_id,slug',
                    ]);
                },
                'content:id,target_id,name,description,content',
            ]);
        });
    }

    /**
     * Update attach file in article or somewhere else
     *
     * @param array $data
     * @param object|null $model
     *
     * @return void
     * @author malayvuong
     * @since 6.62.6 - 2022-10-05, 00:10 ICT
     */
    public function updateAttach(array $data, object $model = null): void
    {
        $attach_id_list = [];
        if (($model->id ?? 0) > 0) {
            if (!empty($data)) {
                $attach_id_list = collect($data)->pluck('id')->toArray();
            }
            if (count($attach_id_list) > 0) {
                $this->update([
                    ['id', 'IN', $attach_id_list],
                ], [
                    'target_id' => $model->id,
                ]);
            }
        }
    }

    /**
     * Update Images target
     *
     * @param object|null $model
     *
     * @return void
     * @author malayvuong
     *
     * @since  1.0.0
     */
    public function updateImage(object $model = null): void
    {
        $img_id_list = [];
        if (($model->id ?? 0) > 0) {
            if (request()->has('images')) {
                $img_id_list = collect(request('images'))->pluck('id')->toArray();
            } else if (request()->has('image')) {
                $img_id_list[] = collect(request('image'))->get('id');
            }
            if (count($img_id_list) > 0) {
                $this->update([
                    ['id', 'IN', $img_id_list],
                ], [
                    'target_id' => $model->id,
                ]);
            }
        }
    }

    /**
     * Update Images target
     *
     * @param object|null $model
     *
     * @return void
     * @author malayvuong
     *
     * @since  1.0.0
     */
    public function updateLogo(object $model = null): void
    {
        if (($model->id ?? 0) > 0 && request()->has('logo') && count(request('logo', [])) > 0) {
            $this->update([
                ['id', 'IN', collect(request('logo'))->pluck('id')->toArray()],
            ], [
                'target_id' => $model->id,
            ]);
        }
    }

    /**
     * Update content image
     *
     * @param object|null $model
     *
     * @return void
     * @author malayvuong
     *
     * @since  1.0.0
     */
    public function updateContent(object $model = null): void
    {
        $idList = [];
        if (isset($model->id) && $model->id > 0 && request('content')) {
            foreach (request('content') as $item) {
                if ($item['type'] === 'image' && count($item['value']) > 0) {
                    foreach ($item['value'] as $val) {
                        if (isset($val['id']) && $val['id'] > 0) {
                            $idList[] = $val['id'];
                        }
                    }
                }
            }
            if (count($idList) > 0) {
                $this->update([
                    ['id', 'IN', $idList],
                ], [
                    'target_id' => $model->id,
                ]);
            }
        }
    }

    /**
     * Get new object of images in content attribute via model
     *
     * @param object|null $model
     *
     * @return object
     *
     * @author malayvuong
     *
     * @since  1.0.0
     */
    public function getContent(object $model = null): object
    {
        if (!$model || !$model->id) {
            return $model;
        }

        $content = [];
        foreach ($model->content as $item) {
            if ($item['type'] === 'image' && count($item['value']) > 0) {
                $value = collect($item['value'])->pluck('id')->toArray();
                $item['value'] = $this->model()->whereIn('id', $value)->get();
            }
            if (!is_countable($item['value']) || (count($item['value']) > 0)) {
                $content[] = $item;
            }
        }
        $model->content = $content;

        return $model;
    }
}
