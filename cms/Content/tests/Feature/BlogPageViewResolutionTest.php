<?php

declare(strict_types=1);

use Cms\Content\Http\Controllers\Website\BlogController;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\View;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

uses(\Tests\TestCase::class);

/**
 * the public blog pages (`/news`, reader, category, article) resolve the project's own template first, then
 * the module default (which extends the project layout); a project that cannot render module pages answers 404.
 * They never throw "View [pages.news] not found" (a 500).
 */
function blogProject(string $key, array $views): string
{
    $dir = sys_get_temp_dir() . '/blog-view-' . $key . '-' . bin2hex(random_bytes(4));
    foreach ($views as $relative => $body) {
        File::ensureDirectoryExists(dirname($dir . '/' . $relative));
        File::put($dir . '/' . $relative, $body);
    }
    View::addNamespace($key, $dir);
    config(['ispa-cms.folder' => $key . '::']);

    return $dir;
}

/** Call the controller's page resolver with the data a page would get. */
function renderBlogPage(string $page, array $data = []): string
{
    // The global view composer reads the language tables; these tests only look at the page markup.
    app()->bind(\App\Composers\AppComposer::class, fn () => new class {
        public function compose(): void
        {
        }
    });
    $controller = app(BlogController::class);
    $method = new ReflectionMethod($controller, 'pageView');

    return $method->invoke($controller, $page, $data)->render();
}

afterEach(function () {
    foreach (glob(sys_get_temp_dir() . '/blog-view-*') ?: [] as $dir) {
        File::deleteDirectory($dir);
    }
});

it('keeps the project template first, so a live site renders what it rendered before', function () {
    blogProject('liveproj', [
        'layout/index.blade.php' => '<html>@yield("content")</html>',
        'pages/news.blade.php' => '@extends("liveproj::layout.index")@section("content")PROJECT-NEWS @endsection',
    ]);

    expect(renderBlogPage('news', ['posts' => [], 'links' => null, 'breadcrumbs' => []]))->toContain('PROJECT-NEWS')
        ->not->toContain('module-default');
});

it('falls back to the module default, inside the project layout, when the project has no template for the page', function (string $page, array $data) {
    blogProject('layoutonly', ['layout/index.blade.php' => '<html><body>PROJECT-LAYOUT @yield("content")</body></html>']);

    $html = renderBlogPage($page, $data);

    expect($html)->toContain('PROJECT-LAYOUT')->toContain('data-blog-default="' . $page . '"');
})->with([
    'news' => ['news', ['posts' => new Illuminate\Pagination\LengthAwarePaginator([], 0, 12), 'links' => null, 'breadcrumbs' => []]],
    'reader' => ['reader', ['view' => (object) ['name' => 'About us', 'description' => 'D', 'content' => '<p>Body</p>']]],
    'category' => ['category', ['view' => (object) ['name' => 'Cat', 'append_posts' => null], 'links' => null, 'breadcrumbs' => []]],
    'article' => ['article', ['view' => (object) ['name' => 'Post', 'description' => 'D', 'content' => '<p>Body</p>'], 'cate' => null, 'breadcrumbs' => []]],
]);

it('answers 404, never a missing-view error, when the project has no layout to put the module page in', function (string $page) {
    blogProject('nolayout', ['pages/other.blade.php' => 'x']);

    expect(fn () => renderBlogPage($page))->toThrow(NotFoundHttpException::class);
})->with(['news', 'reader', 'category', 'article']);

it('a project template that exists for only some pages is used for those and the default for the rest', function () {
    blogProject('mixed', [
        'layout/index.blade.php' => '<html>@yield("content")</html>',
        'pages/article.blade.php' => '@extends("mixed::layout.index")@section("content")PROJECT-ARTICLE @endsection',
    ]);

    expect(renderBlogPage('article', ['view' => (object) [], 'cate' => null, 'breadcrumbs' => []]))->toContain('PROJECT-ARTICLE');
    expect(renderBlogPage('news', ['posts' => new Illuminate\Pagination\LengthAwarePaginator([], 0, 12), 'links' => null, 'breadcrumbs' => []]))
        ->toContain('data-blog-default="news"');
});

it('renders a post saved as content blocks, not the word "Array", in the module default', function (string $page) {
    blogProject('blocks', ['layout/index.blade.php' => '<html><body>@yield("content")</body></html>']);
    $blocks = [
        ['type' => 'text', 'value' => '<p>First block</p>', 'id' => 'c1'],
        ['type' => 'text', 'value' => '<p>Second block</p>', 'id' => 'c2'],
    ];

    $html = renderBlogPage($page, ['view' => (object) ['name' => 'Post', 'content' => $blocks], 'cate' => null, 'breadcrumbs' => []]);

    expect($html)->toContain('<p>First block</p>')->toContain('<p>Second block</p>')->not->toContain('Array');
})->with(['article', 'reader']);
