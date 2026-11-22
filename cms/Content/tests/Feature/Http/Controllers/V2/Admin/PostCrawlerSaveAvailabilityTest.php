<?php

uses(\Cms\Core\Tests\TestCase::class);

use App\Clients\CmsClient;
use App\Clients\OpenAIClient;
use App\Helpers\Crawler\ArticleCrawler;
use App\Models\User;
use Cms\Content\Events\PostCrawlDataSubmitted;
use Cms\Content\Interfaces\PostInterface;
use Cms\Core\Interfaces\CategoryInterface;
use Cms\Core\Interfaces\CategoryTargetInterface;
use Illuminate\Support\Facades\Event;

/*
 * "Crawl post" Save hands the article to the module or project that listens to PostCrawlDataSubmitted. A site without
 * one gets no Save button (the crawl answer says can_save false) and the save endpoint refuses instead of answering a
 * success that stores nothing.
 */

beforeEach(function () {
    app()->instance(CategoryInterface::class, \Mockery::mock(CategoryInterface::class));
    app()->instance(CategoryTargetInterface::class, \Mockery::mock(CategoryTargetInterface::class));
    app()->instance(CmsClient::class, \Mockery::mock(CmsClient::class));
    app()->instance(OpenAIClient::class, \Mockery::mock(OpenAIClient::class));

    $posts = \Mockery::mock(PostInterface::class);
    $posts->shouldReceive('model->where->exists')->andReturn(false);
    app()->instance(PostInterface::class, $posts);

    app()->instance(ArticleCrawler::class, new class extends ArticleCrawler {
        public function crawl(string $url): array
        {
            return ['title' => 'Sample title', 'contents' => []];
        }
    });

    Event::forget(PostCrawlDataSubmitted::class);

    $this->user = new PostCrawlerSaveTestUser();
    $this->user->id = 78;
});

function postCrawlerSavePayload(): array
{
    return [
        'url' => 'https://news.example.test/a-story',
        'title' => 'Sample title',
        'content' => [['type' => 'text', 'value' => '<p>Body</p>']],
    ];
}

test('without a listener the crawl answer hides Save and the save endpoint refuses', function () {
    $this->withoutMiddleware()->actingAs($this->user)
        ->postJson('/api/v2/admin/post/crawler', ['url' => 'https://news.example.test/a-story', 'translate' => false])
        ->assertOk()
        ->assertJsonPath('data.title', 'Sample title')
        ->assertJsonPath('data.can_save', false);

    $this->withoutMiddleware()->actingAs($this->user)
        ->postJson('/api/v2/admin/post/crawler/save', postCrawlerSavePayload())
        ->assertStatus(400)
        ->assertJsonPath('status', 'error')
        ->assertJsonPath('message', 'Saving crawled articles is not available on this site.');
});

test('with a listener the crawl answer shows Save and the article is handed over', function () {
    $received = [];
    Event::listen(PostCrawlDataSubmitted::class, function (PostCrawlDataSubmitted $event) use (&$received) {
        $received[] = $event->data['url'] ?? null;
    });

    $this->withoutMiddleware()->actingAs($this->user)
        ->postJson('/api/v2/admin/post/crawler', ['url' => 'https://news.example.test/a-story', 'translate' => false])
        ->assertOk()
        ->assertJsonPath('data.can_save', true);

    $this->withoutMiddleware()->actingAs($this->user)
        ->postJson('/api/v2/admin/post/crawler/save', postCrawlerSavePayload())
        ->assertOk()
        ->assertJsonPath('status', 'success');

    expect($received)->toBe(['https://news.example.test/a-story']);
});

class PostCrawlerSaveTestUser extends User
{
    public function can($abilities, $arguments = []): bool
    {
        return true;
    }
}
