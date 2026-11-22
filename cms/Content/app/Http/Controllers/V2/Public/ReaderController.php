<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Content\Http\Controllers\V2\Public;

use App\Constants\Constant;
use App\Events\SaveTracking;
use Illuminate\Contracts\View\View;
use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\Factory;
use Cms\Content\Interfaces\PostInterface;
use Illuminate\Contracts\Foundation\Application;

class ReaderController extends Controller
{
    protected PostInterface $post;

    protected array $languages;

    /**
     * ReaderController constructor
     *
     * @author malayvuong
     *
     * @since 6.19.6 - 2022-04-11, 16:21 ICT
     */
    public function __construct(PostInterface $post)
    {
        $this->post = $post;
        $this->languages = [1, 2];
    }

    /**
     * View About Us
     *
     * @author malayvuong
     *
     * @since 6.19.6 - 2022-04-11, 16:21 ICT
     */
    public function about_us()
    {
        $uid = in_array((int)request('languageId', 1), $this->languages, true) ? 'aboutus-vi' : 'aboutus-en';

        return $this->get_post($uid);
    }

    /**
     * View About Us
     *
     * @author malayvuong
     *
     * @since 6.19.6 - 2022-04-11, 16:21 ICT
     */
    public function privacy_policy()
    {
        $uid = in_array((int)request('languageId', 1), $this->languages, true) ? 'privacy-vi' : 'privacy-en';

        return $this->get_post($uid);
    }

    /**
     * View About Us
     *
     * @author malayvuong
     *
     * @since 6.19.6 - 2022-04-11, 16:21 ICT
     */
    public function terms_of_service()
    {
        $uid = in_array((int)request('languageId', 1), $this->languages, true) ? 'tos-vi' : 'tos-en';

        return $this->get_post($uid);
    }

    /**
     * Get the post
     *
     * @param string $uid
     *
     * @return Factory|View|Application
     *
     * @author malayvuong
     *
     * @since 6.19.6 - 2022-04-11, 16:40 ICT
     */
    protected function get_post(string $uid): Factory|View|Application
    {
        $post = $this->post->findBy([
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

        if (!$post) {
            event(new SaveTracking([Constant::PAGE_VIEW, 'article', 'api', 'web-view']));
            abort(404, $uid . ' page does not exist on this database');
        }

        event(new SaveTracking(
            [Constant::PAGE_VIEW, 'article', 'api', 'web-view'],
            get_class($post),
            $post->id
        ));

        return view('api.view-article', compact('post'));
    }
}
