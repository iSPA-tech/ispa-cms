<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace App\Helpers;

/**
 * Normalises crawled content blocks (`['type' => 'text'|'image'|'video', 'value' => …]`).
 *
 * Page loading and reading moved to {@see \App\Helpers\Crawler\ArticleCrawler}; the site-specific readers live in
 * the modules that register them.
 */
class CrawlData
{
    /**
     * Merge same data into one array in contents
     * -- if contents:[type: text, value: xxx] has the same type with previous one, merge them into one
     * -- depends on the type, we can merge them in different ways
     * -- if type = text, just join value to string by .=
     * -- if type = image, just push the value to the array
     *
     * @author malayvuong
     * @since 7.160.1175 - 2024-07-26, 10:49 ICT
     */
    public function mergeContents(array $contents = []): array
    {
        $newContent = $this->mergeImages($contents);
        $mergedContents = [];
        $previousType = '';
        foreach ($newContent as $content) {
            if ($content['type'] === 'image') {
                $valueCounter = count($content['value']);

                if ($valueCounter > 2) {
                    //  Split the value into many items that have 2 items in each
                    $newValueContent = array_chunk($content['value'], 2);
                } else {
                    $newValueContent = [$content['value']];
                }
                //  Push each item of $newValueContent to the $mergedContents by creating new content
                foreach ($newValueContent as $value) {
                    $mergedContents[] = [
                        ...$content,
                        'value' => $value,
                    ];
                }
            } else if ($content['type'] === $previousType && $content['type'] !== 'video') {
                $mergedContents[count($mergedContents) - 1]['value'] .= $content['value'];
            } else {
                $mergedContents[] = $content;
            }
            $previousType = $content['type'];
        }

        return $mergedContents;
    }

    /**
     * Merge image into one array
     *
     * @author malayvuong
     * @since 7.160.1175 - 2024-09-15, 20:37 ICT
     */
    public function mergeImages(array $contents = []): array
    {
        $result = [];
        $imageArray = [];

        // Loop through the array to process items
        foreach ($contents as $item) {
            if ($item['type'] === 'image') {
                // Merge all image URLs into one array
                $imageArray = [...$imageArray, ...$item['value']];
            } else {
                // If it's a text item, push the previous images and reset the image array
                if (!empty($imageArray)) {
                    $result[] = [
                        'type' => 'image',
                        'value' => $imageArray,
                    ];
                    $imageArray = []; // Reset image array after adding
                }
                // Add the current text item
                $result[] = $item;
            }
        }

        // Add any remaining images at the end
        if (!empty($imageArray)) {
            $result[] = [
                'type' => 'image',
                'value' => $imageArray,
            ];
        }

        return $result;
    }
}
