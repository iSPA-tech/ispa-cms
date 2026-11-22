<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace App\Repositories\Eloquent;

use Cms\Core\Repositories\Eloquent\RepositoriesAbstract;
use App\Models\Gallery;
use App\Constants\Constant;
use App\Enums\StatusEnum;
use Cms\Core\Helpers\CacheHelper;
use Closure;
use Illuminate\Database\Eloquent\Collection;
use App\Repositories\Interfaces\GalleryInterface;

class GalleryRepository extends RepositoriesAbstract implements GalleryInterface
{
    public function __construct()
    {
        $this->setModel(Gallery::class);
    }

    /**
     * Get latest galleries by limit
     *
     * @param int $limit
     * @param bool $freshCache
     *
     * @return mixed
     * @author malayvuong
     * @since 7.160.1175 - 2024-08-26, 20:40 ICT
     */
    public function getLatestGalleries(int $limit, bool $freshCache = false): mixed
    {
        $ttl = $freshCache ? config('setting.cache.cache.gallery', 3600) : 1;
        $cacheKey = sprintf(Constant::CACHE_GALLERIES[Constant::GALLERY_LATEST], lang('path', 'vi'), $limit, 1);

        return cache()->remember($cacheKey, $ttl, function () use ($limit, $cacheKey, $ttl) {
            CacheHelper::getInstance()->store($cacheKey, 'Get latest galleries list by limit: ' . $limit, $ttl);

            $data = $this->get([
                'select' => [
                    'id', 'name', 'description',
                ],
                'where' => [
                    'status' => StatusEnum::PUBLISHED->value,
                    'language_id' => lang('id', get_lang_id()),
                ],
                'with' => [
                    'slugs' => $this->withPublicSlug(),
                    'images',
                ],
                'order' => $this->publicOrder(),
                'has' => [
                    'slugs' => $this->hasPublicSlug(),
                ],
                'take' => $limit,
            ]);

            return $data;
        });
    }

    /**
     * Get all galleries paginated
     *
     * @param int $pageSize
     * @param int $page
     * @param bool $freshCache
     *
     * @return mixed
     * @author malayvuong
     * @since 7.160.1175 - 2023-12-17, 10:35 ICT
     */
    public function getGalleries(int $pageSize, int $page = 1, bool $freshCache = false): mixed
    {
        $ttl = $freshCache ? config('setting.cache.cache.gallery', 3600) : 1;
        $cacheKey = sprintf(Constant::CACHE_GALLERIES[Constant::CACHE_BY_GALLERIES], lang('path', 'vi'), $pageSize, $page);

        return cache()->remember($cacheKey, $ttl, function () use ($pageSize, $page, $cacheKey, $ttl) {
            CacheHelper::getInstance()->store($cacheKey, 'Get all galleries list by pageSize: ' . $pageSize . ' and page: ' . $page, $ttl);

            return $this->get([
                'select' => [
                    'id', 'name', 'description', 'published_at', 'created_at',
                ],
                'where' => [
                    'status' => StatusEnum::PUBLISHED->value,
                    'language_id' => lang('id', get_lang_id()),
                ],
                'with' => [
                    'slugs' => $this->withPublicSlug(),
                    'images',
                ],
                'order' => $this->publicOrder(),
                'has' => [
                    'slugs' => $this->hasPublicSlug(),
                ],
                'page' => $pageSize,
            ]);
        });
    }

    /**
     * Get related galleries
     *
     * @param int $exceptId
     *
     * @return Collection|array
     * @since 6.62.6 - 2022-08-28, 17:31 ICT
     * @author malayvuong
     */
    public function getRelatedGalleries(int $exceptId = 0): Collection|array
    {
        return $this->get([
            'select' => [
                'id', 'name', 'description', 'published_at', 'created_at',
            ],
            'where' => [
                ['id', '!=', $exceptId],
                'status' => StatusEnum::PUBLISHED->value,
            ],
            'with' => [
                'slugs' => $this->withPublicSlug(),
            ],
            'order' => $this->publicOrder(),
            'has' => [
                'slugs' => $this->hasPublicSlug(),
            ],
        ]);
    }

    private function publicOrder(): array
    {
        return [
            'published_at' => 'DESC',
            'created_at' => 'DESC',
            'id' => 'DESC',
        ];
    }

    private function hasPublicSlug(): Closure
    {
        return static fn ($query) => $query
            ->whereNotNull('slug')
            ->where('slug', '!=', '');
    }

    private function withPublicSlug(): Closure
    {
        return static fn ($query) => $query
            ->select('id', 'target_id', 'target_type', 'slug')
            ->whereNotNull('slug')
            ->where('slug', '!=', '');
    }
}
