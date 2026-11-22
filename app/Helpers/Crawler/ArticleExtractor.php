<?php
/*
 * Copyright (c) 2026 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace App\Helpers\Crawler;

use Facebook\WebDriver\Remote\RemoteWebElement;

/**
 * Reads one article out of a page that knows its own layout.
 *
 * The core crawler ({@see ArticleCrawler}) ships only {@see GenericArticleExtractor}. A module that needs a better
 * result for particular sites registers its extractors in its service provider:
 *
 *     $this->app->tag([MySiteExtractor::class], ArticleExtractor::TAG);
 *
 * The first registered extractor that lists the page's host wins; every other page gets the generic extractor.
 */
interface ArticleExtractor
{
    /** Container tag modules use to register extractors. */
    public const TAG = 'crawler.article-extractors';

    /**
     * Hosts this extractor reads, without scheme ("news.example.com"); a subdomain of a listed host matches too.
     *
     * @return list<string>
     */
    public function hosts(): array;

    /**
     * Extra headless Chrome arguments the site needs before the page loads (for example a user agent).
     *
     * @return list<string>
     */
    public function browserArguments(): array;

    /**
     * Read the article from the loaded page.
     *
     * `contents` is a list of blocks: `['type' => 'text', 'value' => '<p>…</p>']`, `['type' => 'image', 'value' =>
     * [url, …]]` or `['type' => 'video', 'value' => embed url]`. The crawler merges neighbouring blocks afterwards.
     *
     * @return array{thumbnail?: string, title?: string, description?: string, contents?: array<int, array>}
     */
    public function extract(RemoteWebElement $page): array;
}
