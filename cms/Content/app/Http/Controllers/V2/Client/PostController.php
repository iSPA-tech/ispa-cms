<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Content\Http\Controllers\V2\Client;

use Cms\Core\Responses\AppResponse;

use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Cms\Core\Interfaces\UserInterface;
use Cms\Content\Repositories\PostRepository;
use Cms\Core\Http\Resources\Website\ClientPostCollection;

class PostController extends Controller
{
    /**
     * PostController constructor.
     *
     * @author malayvuong
     * @since 6.62.6 - 2023-03-10, 01:15 ICT
     */
    public function __construct(
        protected PostRepository $post,
        protected UserInterface  $user,
    )
    {
        parent::__construct();
    }

    /**
     * Get post by client uuid
     * <p><em>This request only return status => all data will be inputted into Redis database</em></p>
     *
     * @author malayvuong
     * @since 6.62.6 - 2023-03-10, 01:00 ICT
     */
    public function index(string $uuid, Request $request): mixed
    {
        //  Get user id by uuid
        $user = $this->user->findByUuid($uuid, ['id']);

        if (!$user) {
            return ires()->error()->message(__('User not found!'));
        }

        $langId = lang('id', get_lang_id());

        //  Get posts list by user id
        $posts = $this->post->getByUserId($user->id, $request->all(), $langId);

        return ires()->data(new ClientPostCollection($posts));
    }
}
