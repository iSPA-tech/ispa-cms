<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Content\Jobs;

use JsonException;
use Cms\Core\Helpers\RedisHelper;
use Cms\Content\Models\Post;
use Illuminate\Bus\Queueable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

class CountPostTypeJob implements ShouldQueue
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
     *
     * @throws JsonException
     */
    public function handle(): void
    {
        //  Count all post by type then put into cache/redis
        $types = ['post', 'video', 'audio', 'document'];
        foreach ($types as $type) {
            $count = Post::query()->where('type', $type)->count();
            cache()->put("post_count_by_type_$type", $count);

            if (config('ispa-cms.redis_enable')) {
                RedisHelper::set("post_count_by_type_$type", $count);
            }
        }
    }
}
