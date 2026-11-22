<?php
/*
 * Copyright (c) 2026 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace App\Http\Controllers\V3\Public\Concerns;

use Cms\Core\Models\Upload;
use Cms\Core\Services\ImageVariantService;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Cropped preview variants for public post cards. Extracted from
 * CategoryController because PostController::getAll's filtered branch calls
 * decoratePostPreviewImages too — as a CategoryController-only method it
 * 500'd every keyword/tag/category search the moment a consumer (the
 * RelatedArticles strip) started sending keywords.
 */
trait DecoratesPostPreviewImages
{
    /**
     * Attach cropped preview variants only for posts that actually have an image relation.
     */
    protected function decoratePostPreviewImages(mixed $posts): mixed
    {
        if (method_exists($posts, 'getCollection') && method_exists($posts, 'setCollection')) {
            $posts = $posts->setCollection(
                $this->decoratePostPreviewImages($posts->getCollection())
            );

            return $posts;
        }

        if (! $posts instanceof Collection && ! method_exists($posts, 'map')) {
            return $posts;
        }

        $posts->map(function ($post): mixed {
            if (!is_object($post) || !isset($post->image) || $post->image === null || !is_object($post->image) || !method_exists($post->image, 'cropImage')) {
                return $post;
            }

            $image = $post->image;
            $coverUrl = $this->imageVariantUrl($image, 'post.card', 'cover_lg');
            if ($coverUrl !== null) {
                $image->cover_lg = $coverUrl;
            }

            if (method_exists($image, 'setAppends')) {
                $image->setAppends(['url']);
            }

            if (method_exists($post, 'setRelation')) {
                $post->setRelation('image', $image);
            }

            return $post;
        });

        return $posts;
    }

    /**
     * Resolve the expected image variant for a public post card.
     */
    protected function imageVariantUrl(mixed $image, string $context, string $presetName): ?string
    {
        if (!is_object($image)) {
            return null;
        }

        if ($image instanceof Upload) {
            try {
                return app(ImageVariantService::class)->urlForContext($image, $context, $presetName);
            } catch (Throwable $exception) {
                logger()->warning('Skip context-aware V3 category image variant generation', [
                    'upload_id' => $image->id,
                    'context' => $context,
                    'preset' => $presetName,
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        if (method_exists($image, 'cropImage')) {
            try {
                return $image->cropImage($presetName);
            } catch (Throwable $exception) {
                logger()->warning('Skip V3 category image variant fallback', [
                    'context' => $context,
                    'preset' => $presetName,
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        return null;
    }
}
