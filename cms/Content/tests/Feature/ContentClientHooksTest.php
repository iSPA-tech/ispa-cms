<?php

declare(strict_types=1);

use App\Enums\StatusEnum;
use Cms\Content\Events\PostCrawlDataSubmitted;
use Cms\Content\Events\PostPublished;
use Cms\Content\Models\Post;
use Cms\Content\Observers\PostObserver;
use Cms\Content\Providers\ContentServiceProvider;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;

uses(\Tests\TestCase::class, RefreshDatabase::class);

/*
 * Content reaches site-specific behaviour only through hooks a module or project registers:
 * PostPublished and PostCrawlDataSubmitted events, `content.purge_schedule` and `content.translate_system_prompt`.
 */

function contentHooksPost(): Post
{
    $post = new Post();
    $post->id = 21;
    $post->exists = true;
    $post->syncOriginal();

    return $post;
}

test('publishing a post announces PostPublished with the post id', function () {
    Event::fake([PostPublished::class]);
    $post = contentHooksPost();
    $post->status = StatusEnum::PUBLISHED->value;

    (new PostObserver())->updated($post);

    Event::assertDispatched(PostPublished::class, fn (PostPublished $event): bool => $event->postId === 21);
});

test('an update that does not publish announces nothing', function () {
    Event::fake([PostPublished::class]);
    $post = contentHooksPost();
    $post->name = 'Renamed';

    (new PostObserver())->updated($post);

    Event::assertNotDispatched(PostPublished::class);
});

test('the crawl data event carries the submitted payload', function () {
    $event = new PostCrawlDataSubmitted(['url' => 'https://example.test/a']);

    expect($event->data)->toBe(['url' => 'https://example.test/a']);
});

test('the deleted-post purge is scheduled unless a project turns it off', function () {
    $scheduled = function (): int {
        $this->app->instance(Schedule::class, new Schedule());
        $method = new ReflectionMethod(ContentServiceProvider::class, 'registerCommandSchedules');
        $method->invoke(new ContentServiceProvider($this->app));

        return collect(app(Schedule::class)->events())
            ->filter(fn ($event): bool => str_contains((string) $event->command, 'content:purge-deleted-posts'))
            ->count();
    };

    config(['content.purge_schedule' => true]);
    expect($scheduled())->toBe(1);

    config(['content.purge_schedule' => false]);
    expect($scheduled())->toBe(0);
});

test('the Content code names no client project or module', function () {
    $needle = strtolower('Guu' . 'Xe');
    $hits = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path('cms/Content/app'), FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if (str_contains(strtolower((string) file_get_contents($file->getPathname())), $needle)) {
            $hits[] = $file->getPathname();
        }
    }

    expect($hits)->toBe([]);
});
