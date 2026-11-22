<?php

/*
 * Copyright (c) 2026 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Services\Widget;

/**
 * Walks stored page content (decoded `posts.content` and similar block lists) and visits every
 * widget block, including blocks nested inside non-widget containers (columns, groups, ...).
 *
 * A widget block is any array with `type === 'widget'`. Its own fields (value, inputs, options) are
 * never descended into, so filled-in values are only ever touched by the visitor itself.
 */
class PageWidgetBlockWalker
{
    /**
     * @param array<array-key, mixed> $content Decoded block list.
     * @param callable(array<string, mixed>, list<array-key>): array<string, mixed> $visit Receives the
     *        block and its path from the content root; returns the (possibly rewritten) block.
     * @param list<array-key> $path Path of `$content` from the root (internal recursion).
     * @return array<array-key, mixed> Content with every visited block replaced by the visitor result.
     */
    public function walk(array $content, callable $visit, array $path = []): array
    {
        foreach ($content as $key => $item) {
            if (!is_array($item)) {
                continue;
            }

            $itemPath = [...$path, $key];

            $content[$key] = ($item['type'] ?? null) === 'widget'
                ? $visit($item, $itemPath)
                : $this->walk($item, $visit, $itemPath);
        }

        return $content;
    }
}
