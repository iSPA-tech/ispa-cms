<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Content\View\Components;

use Closure;
use Illuminate\View\Component;
use Illuminate\Contracts\View\View;
use Cms\Content\Interfaces\PostInterface;

class AboutUs extends Component
{
    protected PostInterface $post;

    protected int $id;

    public bool $template;

    /**
     * Create a new component instance.
     *
     * @return void
     */
    public function __construct(PostInterface $post, int $id = 0, bool $template = false)
    {
        $this->post = $post;
        $this->id = $id;
        $this->template = $template;
    }

    /**
     * Get the view / contents that represent the component.
     *
     * @return View|Closure|string
     */
    public function render(): View|string|Closure
    {
        if ($this->id > 0) {
            $langId = lang('id', get_lang_id());
            $article = cache()->remember(
                make_key(['type' => 'article', 'id' => $this->id, 'lang' => $langId]),
                config('setting.cache.blog', 86400),
                function () use ($langId) {
                    return $this->post->model()
                        ->where([
                            'id' => $this->id,
                            'language_id' => $langId,
                        ])
                        ->with([
                            'seo:target_id,title,description',
                        ])->first();
                }
            );
        } else {
            $article = cache()->remember(
                make_key(['type' => 'article', 'keyword' => 'about', 'lang' => lang()->id]),
                config('setting.cache.blog', 86400),
                function () {
                    return $this->post->model()->whereJsonContains('hashtag', 'about')->where([
                        'language_id' => lang()->id,
                    ])->with([
                        'seo:target_id,title,description',
                    ])->first();
                }
            );
        }

        return view(($this->template ? config('ispa-cms.folder') : '') . 'components.about-us', [
            'article' => $article,
        ]);
    }
}
