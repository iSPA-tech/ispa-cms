<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Content\Http\Controllers\V2\Public;

use Cms\Core\Responses\AppResponse;

use App\Constants\Constant;
use App\Events\SaveTracking;
use App\Http\Controllers\Controller;
use Cms\Content\Interfaces\PostInterface;

class ArticleController extends Controller
{
    protected PostInterface $post;

    /**
     * ArticleController constructor
     *
     * @author malayvuong
     *
     * @since 6.19.6 - 2022-04-11, 16:21 ICT
     */
    public function __construct(PostInterface $post)
    {
        $this->post = $post;

        parent::__construct();
    }

    /**
     * Article view detail webview
     *
     * @author malayvuong
     *
     * @since 6.19.6 - 2022-04-11, 16:21 ICT
     */
    public function article_view(string $uid)
    {
        if (!$uid) {
            abort(404);
        }

        $post = $this->get_post($uid);

        if (!$post) {
            event(new SaveTracking([Constant::PAGE_VIEW, 'article', 'api', 'web-view']));
            abort(404);
        }

        event(new SaveTracking(
            [Constant::PAGE_VIEW, 'article', 'api', 'web-view'],
            get_class($post),
            $post->id
        ));

        return view('api.view-article', compact('post'));
    }

    /**
     * Article view detail json
     *
     * @author malayvuong
     *
     * @since 6.19.6 - 2022-04-11, 16:21 ICT
     */
    public function article_json(string $uid): mixed
    {
        if (!$uid) {
            return ires()->error()->message(__('mgs.id.404'));
        }

        $post = $this->get_post($uid);

        if (!$post) {
            event(new SaveTracking([Constant::PAGE_VIEW, 'article', 'api', 'web-json']));
            abort(404);
        }

        //  Todo: init content data to json

        event(new SaveTracking(
            [Constant::PAGE_VIEW, 'article', 'api', 'web-json'],
            get_class($post),
            $post->id
        ));

        return ires()->data($post);
    }

    /**
     * Get the post
     *
     * @author malayvuong
     *
     * @since 6.19.6 - 2022-04-11, 16:40 ICT
     */
    protected function get_post(string $uid)
    {
        return $this->post->findBy([
            'type' => 'post',
            'uid' => $uid,
            'config->showApi' => true,
        ], [
            'id', 'name', 'description', 'hashtag', 'content', 'video_url', 'language_id', 'user_id', 'published_at',
        ], [
            'language:id,name,path,flag',
            'author:id,name',
            'images:id,name,path,mime_type,target_id,main',
        ]);
    }
}
