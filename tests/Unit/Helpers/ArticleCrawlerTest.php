<?php

declare(strict_types=1);

use App\Helpers\Crawler\ArticleCrawler;
use App\Helpers\Crawler\ArticleExtractor;
use App\Helpers\Crawler\GenericArticleExtractor;
use Facebook\WebDriver\Remote\RemoteWebElement;
use Tests\Support\FakeWebElement;

uses(\Tests\TestCase::class);

/*
 * The core crawler names no site: modules register extractors for their hosts under ArticleExtractor::TAG, and every
 * other page is read through the markup most sites share.
 */

function articleCrawlerStubExtractor(array $hosts): ArticleExtractor
{
    return new class($hosts) implements ArticleExtractor {
        public function __construct(private array $hostList)
        {
        }

        public function hosts(): array
        {
            return $this->hostList;
        }

        public function browserArguments(): array
        {
            return [];
        }

        public function extract(RemoteWebElement $page): array
        {
            return ['title' => 'from the site extractor', 'contents' => []];
        }
    };
}

test('without a registered extractor a page is read by the generic extractor', function () {
    $page = new FakeWebElement('html', children: [
        'meta[property="og:image"]' => new FakeWebElement('meta', ['content' => 'https://news.example.test/cover.jpg']),
        'meta[property="og:title"]' => new FakeWebElement('meta', ['content' => 'Sample title']),
        'meta[name="description"]' => new FakeWebElement('meta', ['content' => 'Sample summary']),
        'article' => new FakeWebElement('article', children: [
            'h2, h3, h4, p, img' => [
                new FakeWebElement('h2', text: 'Part one', outerHtml: '<h2>Part one</h2>'),
                new FakeWebElement('p', text: 'First.', outerHtml: '<p>First.</p>'),
                new FakeWebElement('p', text: ' ', outerHtml: '<p> </p>'),
                new FakeWebElement('img', ['src' => 'https://news.example.test/a.jpg']),
                new FakeWebElement('img', ['src' => 'https://news.example.test/b.jpg']),
                new FakeWebElement('p', text: 'Last.', outerHtml: '<p>Last.</p>'),
            ],
        ]),
    ]);

    $crawler = new ArticleCrawler([]);

    expect($crawler->extractorFor('https://news.example.test/2026/a-story'))->toBeInstanceOf(GenericArticleExtractor::class)
        ->and($crawler->extract('https://news.example.test/2026/a-story', $page))->toBe([
            'thumbnail' => 'https://news.example.test/cover.jpg',
            'title' => 'Sample title',
            'description' => 'Sample summary',
            'contents' => [
                ['type' => 'text', 'value' => '<h2>Part one</h2><p>First.</p>', 'is_translated' => false],
                ['type' => 'image', 'value' => ['https://news.example.test/a.jpg', 'https://news.example.test/b.jpg']],
                ['type' => 'text', 'value' => '<p>Last.</p>', 'is_translated' => false],
            ],
        ]);
});

test('the generic extractor falls back to the first heading and the main element', function () {
    $page = new FakeWebElement('html', children: [
        'h1' => new FakeWebElement('h1', text: 'Heading title'),
        'main' => new FakeWebElement('main', children: [
            'h2, h3, h4, p, img' => new FakeWebElement('p', text: 'Body.', outerHtml: '<p>Body.</p>'),
        ]),
    ]);

    expect((new ArticleCrawler([]))->extract('https://other.example.test/x', $page))->toBe([
        'thumbnail' => '',
        'title' => 'Heading title',
        'description' => '',
        'contents' => [['type' => 'text', 'value' => '<p>Body.</p>', 'is_translated' => false]],
    ]);
});

test('a registered extractor reads its host and its subdomains, not look-alike hosts', function () {
    $site = articleCrawlerStubExtractor(['news.example.test']);
    $crawler = new ArticleCrawler([$site]);

    expect($crawler->extractorFor('https://news.example.test/a'))->toBe($site)
        ->and($crawler->extractorFor('https://WWW.News.Example.Test/a'))->toBe($site)
        ->and($crawler->extractorFor('https://othernews.example.test/a'))->toBeInstanceOf(GenericArticleExtractor::class)
        ->and($crawler->extractorFor('not a url'))->toBeInstanceOf(GenericArticleExtractor::class)
        ->and($crawler->extract('https://news.example.test/a', new FakeWebElement('html')))
        ->toBe(['title' => 'from the site extractor', 'contents' => []]);
});

test('extractors registered under the container tag are used by default', function () {
    $site = articleCrawlerStubExtractor(['tagged.example.test']);
    app()->instance('tests.article-extractor', $site);
    app()->tag(['tests.article-extractor'], ArticleExtractor::TAG);

    expect((new ArticleCrawler())->extractorFor('https://tagged.example.test/a'))->toBe($site)
        ->and(app(ArticleCrawler::class)->extractorFor('https://tagged.example.test/a'))->toBe($site);
});
