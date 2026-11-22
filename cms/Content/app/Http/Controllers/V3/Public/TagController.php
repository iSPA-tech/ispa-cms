<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Content\Http\Controllers\V3\Public;

use Cms\Core\Responses\AppResponse;

use JsonException;
use Illuminate\Http\Request;
use Cms\Core\Models\Tag;
use App\Http\Controllers\Controller;
use Cms\Content\Interfaces\TagInterface;
use Cms\Content\Services\Cache\ContentPublicTagCacheService;

/**
 * Public V3 tag endpoints for the public homepage content.
 */
class TagController extends Controller
{
    /**
     * Post controller constructor
     *
     * @param TagInterface $tag
     *
     * @author malayvuong
     * @since 7.160.1175 - 2024-04-20, 16:22 ICT
     */
    public function __construct(
        protected TagInterface $tag,
        protected ContentPublicTagCacheService $publicTagCache,
    )
    {
        parent::__construct();
    }

    /**
     * Get place detail by slug
     *
     * @param string $slug
     * @param Request $request
     *
     * @return mixed
     * @since 7.160.1175 - 2024-05-21, 15:43 ICT
     * @author malayvuong
     */
    public function show(string $slug, Request $request): mixed
    {
        $tag = $this->publicTagCache->rememberTag(
            $slug,
            static fn (): ?Tag => Tag::query()->ofType(Tag::TYPE_POST)->where('slug', $slug)->first(),
        );
        if (!$tag) {
            return ires()->error()->message(__('web.abort.404'))->code(404);
        }

        $page = max(1, (int) $request->input('page', 1));
        $pageSize = max(1, (int) $request->input('pageSize', 24));
        $pageSize = min(100, $pageSize);

        $tags = $this->publicTagCache->rememberPosts(
            (int) $tag->id,
            $page,
            $pageSize,
        );

        $data = [
            'tags' => $tags,
            'tag' => $tag,
        ];

        // The shared cache owner replaces the retired non-expiring `tag:{slug}`
        // Redis shape and reconstructs request-specific paginator links.
        return ires()->data($data);
    }

    /**
     * Show tags list with image
     *
     * @author malayvuong
     * @since 7.160.1175 - 2025-06-09, 22:28 ICT
     */
    public function index()
    {
        $allTags = $this->publicTagCache->rememberIndex(static function () {
            return Tag::query()->ofType(Tag::TYPE_POST)
                ->select(['id', 'name', 'slug', 'description'])
                ->latest()
                ->get();
        });

        return ires()->data($allTags);
    }

}
