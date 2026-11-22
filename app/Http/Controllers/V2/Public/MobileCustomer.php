<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace App\Http\Controllers\V2\Public;

use App\Constants\Constant;
use App\Events\SaveTracking;
use App\Http\Controllers\Controller;
use Cms\Core\Interfaces\CategoryInterface;

class MobileCustomer extends Controller
{
    protected CategoryInterface $model;

    protected array $languages;

    public function __construct(CategoryInterface $model)
    {
        $this->model = $model;
        $this->languages = [1, 2];
    }

    public function categories()
    {
        $currentLang = in_array((int)request('languageId', 0), $this->languages, true) ? (int)request('languageId') : 1;

        $model = $this->model->get([
            'select' => [
                'id', 'name', 'description', 'language_id',
            ],
            'where' => [
                'language_id' => $currentLang,
                'type' => request('type', 0),
                'config->showApi' => true,
                ['config->apiList', 'like', '%mobile_customer%'],
            ],
            'with' => [
                'language:id,name,path,flag',
                'image:target_id,id,name,path,mime_type',
            ],
            'when' => [
                [
                    request('query') !== null,
                    function ($q) {
                        return $q->whereFulltext(['name', 'description', 'content', 'hashtag'], request('query'));
                    },
                ],
            ],
        ]);

        event(new SaveTracking([Constant::PAGE_VIEW, 'categories', 'api']));

        return ires()->data($model);
    }

    /**
     * Get category detail
     *
     * @author malayvuong
     *
     * @since 1.0.0 - 2022-01-17, 21:9
     */
    public function show(int $id)
    {
        if ($id < 1) {
            return ires()->error()->message(__('mgs.id.404'));
        }
        $currentLang = in_array((int)request('languageId', 0), $this->languages, true) ? (int)request('languageId') : 1;

        $model = $this->model->findby([
            'id' => $id,
            'config->showApi' => true,
            ['config->apiList', 'like', '%mobile_customer%'],
        ], [
            'id', 'name', 'description', 'language_id',
        ], [
            'language:id,name,path,flag',
            'images:target_id,id,name,path,mime_type',
            'posts' => function ($q) use ($currentLang) {
                $q->select('id', 'uid', 'name', 'description', 'published_at', 'language_id')->where([
                    'language_id' => $currentLang,
                    'type' => 'post',
                    'config->showApi' => true,
                    ['config->apiList', 'like', '%mobile_customer%'],
                ])->where(function ($query) {
                    $query->whereNull('published_at')->orWhere('published_at', '<=', now());
                })->with([
                    'language:id,name,path,flag',
                    'image:target_id,id,name,path,mime_type',
                ]);
            },
        ]);

        if (!$model) {
            event(new SaveTracking([Constant::PAGE_VIEW, 'category', 'api']));

            return ires()->error()->message(__('mgs.model.404'));
        }
        event(new SaveTracking([Constant::PAGE_VIEW, 'category', 'api'], get_class($model), $model->id));

        if ($model->parent_id === null) {
            $model->parent_id = 0;
        }

        foreach ($model->posts as $post) {
            $post->web_view = route('api-web-view-article', ['uid' => $post->uid]);
            $post->json = route('api-web-json-article', ['uid' => $post->uid]);
        }

        return ires()->data($model);
    }

    /**
     * Return list by multiple level
     *-------------------
     *
     * @param array $list | array data
     * @param int|null $parentId
     * @param string $prefix | Prefix of name
     * @param array $returnArray | Array will return to client
     *
     * @return array
     *
     **@author malayvuong
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
