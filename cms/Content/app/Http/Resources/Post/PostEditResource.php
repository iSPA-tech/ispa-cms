<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Content\Http\Resources\Post;

use JsonException;
use Illuminate\Http\Request;
use Cms\Content\Models\Post;
use Cms\Core\Models\Upload;
use Cms\Core\Models\Category;
use Cms\Core\Models\Language;
use Illuminate\Http\Resources\Json\JsonResource;
use Cms\Core\Http\Resources\Category\CategoryResource;

/**
 * JSON resource for a Post model in edit mode with full relational data.
 *
 * Transforms a Post model into an edit-ready response including resolved images
 * within block content, hierarchical category IDs (parent chain), slugs, SEO
 * metadata, and decoded HTML entities in text blocks. Falls back to building
 * SEO entries from hashtags when no dedicated SEO records exist.
 *
 * Output: { ...model_attributes, images, slugs, categories, category, content,
 *           category_id (hierarchical), seos }
 * Used by: PostController::edit()
 *
 * @see \Cms\Content\Models\Post
 * @see \Cms\Content\Http\Controllers\V2\Admin\PostController::edit()
 * @package Cms\Content\Http\Resources\Post
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
class PostEditResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @param  Request $request
     * @return array<string, mixed>
     * @throws JsonException
     */
    public function toArray(Request $request): array
    {
        $editableCategories = $this->whenLoaded('editableCategories', function () {
            function getParentIds($categoryId, &$parentIds = [])
            {
                $parent = Category::query()->where('id', $categoryId)->first();

                if ($parent) {
                    $parentIds[] = $parent->id;
                    getParentIds($parent->parent_id, $parentIds);
                }

                return $parentIds;
            }

            $resultIds = [];
            // Assuming $yourArray contains your provided array
            $yourArray = $this->resource->editableCategories;
            foreach ($yourArray as $key => $category) {
                $currentIds = getParentIds($category['id']);
                //  Reverse array to get parent first
                $currentIds = array_reverse($currentIds);
                $resultIds[] = $currentIds;
            }

            return $resultIds;
        });

        //  Loaded images
        $contents = [];
        if ($this->resource->content && is_array($this->resource->content)) {
            foreach ($this->resource->content as $content) {
                $newContent = $content;
                if ($content['type'] === 'image') {
                    $newContent['value'] = [];
                    foreach ($content['value'] as $value) {
                        if (isset($value['id'])) {
                            $upload = Upload::query()->where('id', $value['id'])->first();

                            $newContent['value'][] = $upload;
                        } else {
                            $newContent['value'][] = $value;
                        }
                    }
                } else if ($content['type'] === 'text') {
                    $newContent['value'] = html_entity_decode($content['value']);
                }

                $contents[] = $newContent;
            }
        }

        $post = [
            ...$this->resource->toArray(),
            'images' => $this->whenLoaded('images', $this->resource->images),
            'slugs' => $this->whenLoaded('latestSlug'),
            'categories' => $this->whenLoaded('categories'),
            'category' => $this->whenLoaded('category', new CategoryResource($this->resource->category)),
        ];
        $post['content'] = $contents;
        $post['category_id'] = $editableCategories;

        //  if !exists 'seos', and available 'hashtag', then set the hashtag into 'seos.*.hashtag'
        if (empty($post['seos']) && !empty($post['hashtag'])) {
            $hashtags = is_array($post['hashtag']) ? $post['hashtag'] : json_decode($post['hashtag'], true, 512, JSON_THROW_ON_ERROR);

            $languages = Language::query()->where(['for_web' => true])->get();
            foreach ($languages as $language) {
                $post['seos'][] = [
                    'target_type' => Post::class, 'target_id' => $post['id'], 'language_id' => $language->id,
                    'title' => '', 'description' => '',
                    'hashtag' => implode(', ', $hashtags),
                    'facebook' => [], 'twitter' => [], 'custom' => [], 'config' => [],
                ];
            }
        }

        return $post;
    }
}
