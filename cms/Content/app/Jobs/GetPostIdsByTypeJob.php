<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Content\Jobs;

use App\Constants\Constant;
use Cms\Content\Models\Post;
use Illuminate\Bus\Queueable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

class GetPostIdsByTypeJob implements ShouldQueue
{
    /** @var bool */
    public bool $isDiscovery = false;


    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Create a new job instance.
     */
    public function __construct()
    {
        //
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        //  Count all post by type then put into cache/redis
        $types = ['post', 'video', 'audio', 'document'];

        foreach ($types as $type) {
            $postIds = Post::query()->where('type', $type)
                ->select('id')
                ->with('categories:id')
                ->get();

            $ids = array_unique($postIds->pluck('id')->toArray());
            //  Get all categories id
            $categoriesIds = $postIds->pluck('categories')->flatten()->pluck('id')->toArray();
            $categoriesIds = array_unique($categoriesIds);
            $categoryIdsKey = sprintf(Constant::CACHE_KEY_CATEGORY_IDS_BY_TYPE, $type);
            $postIdsKey = sprintf(Constant::CACHE_KEY_POST_IDS_BY_TYPE, $type);

            // replace by cache key CACHE_KEY_CATEGORY_IDS_BY_TYPE
            cache()->put($postIdsKey, $ids);
            cache()->put($categoryIdsKey, $categoriesIds);
        }
    }
}
