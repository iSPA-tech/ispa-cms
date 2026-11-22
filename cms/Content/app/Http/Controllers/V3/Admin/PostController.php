<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Content\Http\Controllers\V3\Admin;

use Cms\Core\Responses\AppResponse;

use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Cms\Content\Interfaces\PostInterface;
use Cms\Content\Http\Resources\Post\PostCollection;

class PostController extends Controller
{
    /**
     * PostController constructor
     *
     * @param PostInterface $post
     *
     * @author malayvuong
     * @since 6.62.6 - 2023-04-01, 10:10 ICT
     */
    public function __construct(protected PostInterface $post)
    {
        parent::__construct();
    }

    /**
     * Get post list
     *
     * @author malayvuong
     * @since 6.62.6 - 2023-04-01, 00:41 ICT
     */
    public function index(Request $request)
    {
        $model = $this->post->get([
            'where' => [],
            'with' => [
                'author:id,name,username',
                'category:id,name',
                'image:target_id,name,path,type,file_size,mime_type,category',
                'slugs:id,slug,target_id',
                'uploaded',
            ],
            'page' => $request->input('perPage', 10),
            'latest' => true,
            'when' => [
                [
                    $request->has('keywords') && !empty($request->input('keywords')),
                    function ($query) use ($request) {
                        $keywords = str(urldecode($request->input('keywords')))->replace([' ', '-'], '%');

                        $query->where(function ($query) use ($keywords): mixed {
                            $query->where('name', 'like', "%$keywords%")
                                ->orWhere('description', 'like', "%$keywords%")
                                ->orWhere('content', 'like', "%$keywords%");
                        });
                    },
                ],
                [
                    $request->input('categoryId'),
                    fn($query) => $query->where('category_id', $request->input('categoryId')),
                ],
                [
                    $request->input('userId'),
                    fn($query) => $query->where('user_id', $request->input('userId')),
                ],
                [
                    $request->input('type'),
                    fn($query) => $query->where('type', $request->input('type')),
                ],
                [
                    $request->input('status', 'all') !== 'all',
                    fn($query) => $query->where('status', $request->input('status')),
                ],
            ],
        ]);

        return ires()->data(new PostCollection($model));
    }
}
