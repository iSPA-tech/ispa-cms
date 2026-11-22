<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace App\Jobs;

use App\Models\Slug;
use GuzzleHttp\Client;
use App\Constants\Constant;
use Cms\Content\Models\Post;
use Illuminate\Bus\Queueable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

class SubmitIndexNowWebmasterJob implements ShouldQueue
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
     * @throws GuzzleException
     */
    public function handle(): void
    {
        if (!getenv('FE_DOMAIN') || !getenv('INDEX_NOW_KEY')) {
            logger('SubmitIndexNowWebmasterJob: Missing environment variables');
            return;
        }
        //  Get all slugs that need to be indexed by index_now_at is null and 1 day < created_at < now
        $slugs = Slug::query()
            ->where(function ($query) {
                $query->where('target_type', get_class(new Post()))
                    ->orWhere(function ($q) {
                        $q->where('updated_at', '>', now()->subDay())
                            ->where('updated_at', '<', now());
                    });
            })
            ->whereNull('index_now_at')
            ->with('language')
            ->when('target_type' === get_class(new Post()), fn($q) => $q->with([
                'target' => fn($q) => $q->select('id', 'name')
                    ->whereHas('category')
                    ->published()
                    ->with([
                        'slugs:slug,target_id',
                        'category' => fn($q) => $q->with('slugs:slug,target_id')->select('id', 'name'),
                    ]),
            ]))
            ->get();

        $interface = [
            'host' => getenv('FE_DOMAIN'),
            'key' => getenv('INDEX_NOW_KEY'),
            'keyLocation' => "https://" . getenv('FE_DOMAIN') . "/" . getenv('INDEX_NOW_KEY') . '.txt',
            'urlList' => [],
        ];
        $slugIds = [];
        if ($slugs->isEmpty()) {
            logger('SubmitIndexNowWebmasterJob: No slug to index');
            return;
        }
        //  Set the slugs into urlList
        foreach ($slugs as $slug) {
            if ($slug->target_type && !empty($slug->target)) {
                $endPointSlug = $slug->slug;

                if (str($slug->target_type)->contains('Post')) {
                    $endPointSlug = $this->getPostSlug($slug->target);
                }

                $interface['urlList'][] = "https://" . getenv('FE_DOMAIN')
                    . "/" . $this->getPrefix(optional($slug)->target_type, optional($slug)->language_id)
                    . "/" . $endPointSlug;
                $slugIds[] = $slug->id;
            }
        }
//        logger('Ids' . json_encode($slugIds));
        //  Submit to index_now if urlList is not empty
        if (!empty($interface['urlList']) && $this->submitIndexNow($interface)) {
            //  Update index_now_at for slugs
            Slug::query()->whereIn('id', $slugIds)->update(['index_now_at' => now()]);
        } else {
            logger('SubmitIndexNowWebmasterJob: Failed to submit to index_now');
        }
    }

    /**
     * Get post slug
     *
     * @param mixed|null $post
     *
     * @return string
     * @author malayvuong
     * @since 7.160.175 - 2024-05-14, 16:14 ICT
     */
    protected function getPostSlug(mixed $post = null): string
    {
        if (empty($post) || !$post->category || !$post->category->slug) {
            return '';
        }

        return $post->category->slug . '/' . $post->slug;
    }

    /**
     * Get prefix by language_id
     *
     * @param string $type
     * @param int $languageId
     *
     * @return string
     * @author malayvuong
     * @since 7.160.1175 - 2024-05-14, 16:06 ICT
     */
    protected function getPrefix(string $type = '', int $languageId = 1): string
    {
        if (empty($type)) {
            return '';
        }

        $prefix = '';

        if (str($type)->contains('Place')) {
            if ($languageId > 1) {
                $prefix = 'place';
            } else {
                $prefix = 'dia-diem';
            }
        } else if (str($type)->contains('Category') || str($type)->contains('Post')) {
            if ($languageId > 1) {
                $prefix = 'article';
            } else {
                $prefix = 'bai-viet';
            }
        } else if (str($type)->contains('Brand')) {
            $prefix = 'brand';
        } else if (str($type)->contains('Gallery')) {
            $prefix = 'gallery';
        }

        return $prefix;
    }

    /**
     * Submit to IndexNow
     *
     * @throws GuzzleException
     * @since 7.160.1175 - 2024-05-14, 16:29 ICT
     * @author malayvuong
     */
    protected function submitIndexNow(array $data = []): bool
    {
        //  Prepare the http request
        $client = new Client();
        $response = $client->request('POST', 'https://www.bing.com/indexnow', [
            'headers' => [
                'Content-Type' => 'application/json',
            ],
            'json' => $data,
        ]);

        //  Log the response
        return $response->getStatusCode() === 200;
    }
}
