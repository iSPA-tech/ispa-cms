<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace App\Http\Controllers\V3\Admin;

use Cms\Core\Responses\AppResponse;

use App\Enums\StatusEnum;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Cms\Core\Interfaces\CategoryInterface;
use V3\Resources\Admin\Category\CategoryResource;
use V3\Resources\Admin\Category\CategoryCollection;
use V3\Requests\Admin\Category\StoreCategoryRequest;
use V3\Requests\Admin\Category\UpdateCategoryRequest;

class CategoryController extends Controller
{
    /**
     * CategoryController constructor
     *
     * @param CategoryInterface $category
     *
     * @author malayvuong
     * @since 6.62.6 - 2023-04-02, 15:56 ICT
     */
    public function __construct(
        protected CategoryInterface $category,
    )
    {
        parent::__construct();
    }

    /**
     * Create new category
     *
     * @author malayvuong
     * @since 6.62.6 - 2023-04-11, 12:54 ICT
     */
    public function store(StoreCategoryRequest $request): mixed
    {
        $data = [
            'name' => $request->input('name'),
            'parent_id' => collect($request->input('parentId'))->last(),
            'description' => $request->input('description'),
            'language_id' => $request->input('languageId', 0) > 0 ? $request->input('languageId') : null,
            'status' => $request->input('status', StatusEnum::PUBLISHED->value),
            'type' => getCategoryTypeIdByString($request->input('type')),
            'uid' => str()->random(10),
        ];
        $category = $this->category->create($data);

        //  Sync images relationship
        if (!empty($request->input('images', []))) {
            $imageData = [];
            foreach ($request->input('images', []) as $index => $image) {
                $imageData[$image['id']] = [
                    'target_type' => get_class($this->category->model()),
                    'order' => $index,
                    'category' => 'images',
                    'main' => $index === 0,
                ];
            }
            $category->uploaded()->sync($imageData);
        }


        return ires()->data(new CategoryResource($category));
    }

    /**
     * Update category by uid
     *
     * @param string $uid
     * @param UpdateCategoryRequest $request
     *
     * @return mixed
     * @author malayvuong
     * @since 6.62.6 - 2023-04-10, 21:00 ICT
     */
    public function update(string $uid, UpdateCategoryRequest $request): mixed
    {
        $category = $this->category->findBy(['uid' => $uid]);

        if (!$category) {
            return ires()->error()->code(404)->message('Category not found');
        }

        //  Update parentID
        if (!empty($request->input('parentId'))) {
            if (is_array($request->input('parentId'))) {
                $category->parent_id = collect($request->input('parentId'))->last();
            } else {
                $category->parent_id = $request->input('parentId');
            }
        }

        $category->update($request->validated());

        //  Sync images relationship
        $imageData = [];
        if (!empty($request->input('images', []))) {
            foreach ($request->input('images') as $index => $img) {
                $imageData[$img['id']] = [
                    'target_type' => get_class($this->category->model()),
                    'order' => $index,
                    'category' => 'images',
                    'main' => $index === 0,
                ];
            }
        }
        $category->uploaded()->sync($imageData);

        return ires()->data(new CategoryResource($category));
    }

    /**
     * Get category detail to edit
     *
     * @author malayvuong
     * @since 6.62.6 - 2023-04-08, 15:51 ICT
     */
    public function edit(string $uid): mixed
    {
//        if (! user()?->can('category.update')) {
//            return ires()->error()->code(403)->message('You do not have permission to access this page');
//        }

        $category = $this->category->findBy(['uid' => $uid], ['*'], [
            'image:target_id,name,path,type,file_size,mime_type,category',
            'slugs:id,slug,target_id',
            'uploaded',
        ]);

        return ires()->data(new CategoryResource($category));
    }

    /**
     * Get category list
     *
     * @param Request $request
     *
     * @return mixed
     * @author malayvuong
     * @since 6.62.6 - 2023-04-02, 15:57 ICT
     */
    public function index(Request $request): mixed
    {
        $categories = $this->category->get([
            'where' => [
                'type' => $request->input('type', 'article'),
            ],
            'latest' => true,
            'with' => [
                'image:target_id,name,path,type,file_size,mime_type,category',
                'slugs:id,slug,target_id',
            ],
        ]);

        //  multiple level
        $categories = multiLevel($categories);

        return ires()->data(new CategoryCollection($categories));
    }
}
