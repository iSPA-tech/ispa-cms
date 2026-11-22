<?php

declare(strict_types=1);

use App\Http\Controllers\Website\AjaxController;
use App\Repositories\Interfaces\TrackInterface;
use Cms\Content\Http\Controllers\Website\ContentAjaxController;
use Cms\Content\Interfaces\ContentInterface;
use Cms\Content\Jobs\CountPostTypeJob;
use Cms\Content\Jobs\GetPostIdsByTypeJob;
use Cms\Content\Services\PostTopViewsService;
use Cms\Content\View\Components\BestViewOfPost;
use Illuminate\Support\Facades\Blade;

uses(\Tests\TestCase::class);

/**
 * Content code that lived in the app or in Core moved into the Content module on 2026-11-21 (decision: code goes
 * to the module that owns its tables; Content can be disabled). Route URIs, names and the Blade tag stay the same; the
 * route table is pinned in tests/Fixtures/route-snapshot-content.json (ContentRoutesMovedTest).
 */
test('the app ajax controller no longer depends on the Content module', function () {
    $constructor = (new ReflectionClass(AjaxController::class))->getConstructor();
    $types = array_map(static fn (ReflectionParameter $p): string => (string) $p->getType(), $constructor->getParameters());

    expect($types)->not->toContain(ContentInterface::class)
        ->and(method_exists(AjaxController::class, 'contentView'))->toBeFalse()
        ->and(method_exists(ContentAjaxController::class, 'contentView'))->toBeTrue();
});

test('the Core route files no longer register a Content controller', function () {
    expect((string) file_get_contents(base_path('cms/Core/routes/api.php')))->not->toContain('Cms\\Content\\')
        ->and((string) file_get_contents(base_path('routes/web.php')))->not->toContain('content-view');
});

test('the best-view-of-post tag renders the Content component and its query lives in Content', function () {
    expect(Blade::getClassComponentAliases()['best-view-of-post'] ?? null)->toBe(BestViewOfPost::class)
        ->and(is_file(base_path('app/View/Components/BestViewOfPost.php')))->toBeFalse()
        ->and(method_exists(TrackInterface::class, 'getBestViewOfPostByRange'))->toBeFalse()
        ->and(method_exists(PostTopViewsService::class, 'bestViewedPosts'))->toBeTrue();
});

test('the post count jobs live in Content and the old class names still resolve for queued payloads', function () {
    expect(new App\Jobs\CountPostTypeJob())->toBeInstanceOf(CountPostTypeJob::class)
        ->and(new App\Jobs\GetPostIdsByTypeJob())->toBeInstanceOf(GetPostIdsByTypeJob::class);
});
