<?php
/*
 * Copyright (c) 2026 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace App\Helpers\Crawler;

use Facebook\WebDriver\WebDriverBy;
use Facebook\WebDriver\Remote\RemoteWebElement;

/**
 * Reads an article from any page through the markup most sites share.
 *
 * - thumbnail: `og:image`
 * - title: `og:title`, else the first `<h1>`
 * - description: `og:description`, else the `description` meta tag
 * - contents: the headings, paragraphs and images of the first `<article>` (else `<main>`, else `<body>`), in page order
 */
final class GenericArticleExtractor implements ArticleExtractor
{
    public function hosts(): array
    {
        return [];
    }

    public function browserArguments(): array
    {
        return [];
    }

    public function extract(RemoteWebElement $page): array
    {
        return [
            'thumbnail' => $this->metaContent($page, 'meta[property="og:image"]'),
            'title' => $this->metaContent($page, 'meta[property="og:title"]') ?: $this->firstText($page, 'h1'),
            'description' => $this->metaContent($page, 'meta[property="og:description"]')
                ?: $this->metaContent($page, 'meta[name="description"]'),
            'contents' => $this->contents($page),
        ];
    }

    private function metaContent(RemoteWebElement $page, string $selector): string
    {
        $tags = $page->findElements(WebDriverBy::cssSelector($selector));

        return $tags === [] ? '' : trim((string) $tags[0]->getAttribute('content'));
    }

    private function firstText(RemoteWebElement $page, string $selector): string
    {
        $elements = $page->findElements(WebDriverBy::cssSelector($selector));

        return $elements === [] ? '' : trim((string) $elements[0]->getText());
    }

    /**
     * @return list<array{type: string, value: string|list<string>, is_translated?: bool}>
     */
    private function contents(RemoteWebElement $page): array
    {
        $roots = $page->findElements(WebDriverBy::cssSelector('article'))
            ?: $page->findElements(WebDriverBy::cssSelector('main'))
            ?: $page->findElements(WebDriverBy::cssSelector('body'));

        if ($roots === []) {
            return [];
        }

        $contents = [];
        foreach ($roots[0]->findElements(WebDriverBy::cssSelector('h2, h3, h4, p, img')) as $element) {
            if ($element->getTagName() === 'img') {
                $src = trim((string) $element->getAttribute('src'));
                if ($src !== '') {
                    $contents[] = ['type' => 'image', 'value' => [$src]];
                }

                continue;
            }

            if (trim((string) $element->getText()) === '') {
                continue;
            }

            $contents[] = ['type' => 'text', 'value' => (string) $element->getDomProperty('outerHTML'), 'is_translated' => false];
        }

        return $contents;
    }
}
