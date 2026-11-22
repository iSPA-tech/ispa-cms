<?php
/*
 * Copyright (c) 2026 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace App\Helpers\Crawler;

use Exception;
use App\Helpers\CrawlData;
use Facebook\WebDriver\WebDriverBy;
use Facebook\WebDriver\Chrome\ChromeOptions;
use Facebook\WebDriver\Remote\RemoteWebDriver;
use Facebook\WebDriver\Remote\RemoteWebElement;
use Facebook\WebDriver\Remote\DesiredCapabilities;

/**
 * Loads an article page in headless Chrome (a Selenium server on localhost:4444) and reads it.
 *
 * The page is read by the extractor a module registered for its host ({@see ArticleExtractor::TAG}), else by
 * {@see GenericArticleExtractor}. Neighbouring content blocks are merged by {@see CrawlData::mergeContents()}.
 */
class ArticleCrawler
{
    protected string $serverUrl = 'http://localhost:4444';

    /**
     * @param iterable<ArticleExtractor>|null $extractors null = the extractors modules registered under ArticleExtractor::TAG
     */
    public function __construct(private ?iterable $extractors = null)
    {
    }

    /**
     * Crawl one article URL. Returns an empty array when the page cannot be loaded.
     *
     * @return array{thumbnail?: string, title?: string, description?: string, contents?: array<int, array>}
     */
    public function crawl(string $url): array
    {
        //  Unlimited execution time
        set_time_limit(0);

        $extractor = $this->extractorFor($url);
        $crawledData = [];
        try {
            $chromeOptions = new ChromeOptions();
            $chromeOptions->addArguments(['--headless', '--no-sandbox', '--disable-dev-shm-usage', ...$extractor->browserArguments()]);

            $capabilities = DesiredCapabilities::chrome();
            $capabilities->setCapability(ChromeOptions::CAPABILITY, $chromeOptions);
            $driver = RemoteWebDriver::create($this->serverUrl, $capabilities);

            // get the content of the page
            $driver->get($url);
            $driver->manage()->timeouts()->pageLoadTimeout(30);
            $driver->wait()->until(function () use ($driver) {
                return $driver->executeScript('return document.readyState') === 'complete';
            });

            $page = $driver->findElement(WebDriverBy::tagName('html'));

            if ($driver->executeScript('return document.readyState') === 'complete') {
                $crawledData = $this->read($extractor, $page);
            }

            $driver->close();
        } catch (Exception $e) {
            // log the error
            logger($e->getMessage());
        }

        return $crawledData;
    }

    /**
     * Read an already loaded page with the extractor for its URL.
     *
     * @return array{thumbnail?: string, title?: string, description?: string, contents?: array<int, array>}
     */
    public function extract(string $url, RemoteWebElement $page): array
    {
        return $this->read($this->extractorFor($url), $page);
    }

    /**
     * The registered extractor that lists the URL's host (or a parent domain of it), else the generic one.
     */
    public function extractorFor(string $url): ArticleExtractor
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        if ($host !== '') {
            foreach ($this->extractors ?? app()->tagged(ArticleExtractor::TAG) as $extractor) {
                foreach ($extractor->hosts() as $known) {
                    $known = strtolower($known);
                    if ($host === $known || str_ends_with($host, '.' . $known)) {
                        return $extractor;
                    }
                }
            }
        }

        return new GenericArticleExtractor();
    }

    private function read(ArticleExtractor $extractor, RemoteWebElement $page): array
    {
        $data = $extractor->extract($page);
        $data['contents'] = (new CrawlData())->mergeContents($data['contents'] ?? []);

        return $data;
    }
}
