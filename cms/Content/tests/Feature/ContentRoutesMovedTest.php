<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;

// The global Localization middleware reads the languages table, so API requests need a migrated database.
uses(\Tests\TestCase::class, RefreshDatabase::class);

/**
 * Route table of every moved Content controller, captured.
 *
 * @return array<int, array<string, mixed>>
 */
function contentRouteSnapshot(): array
{
    return json_decode((string) file_get_contents(base_path('tests/Fixtures/route-snapshot-content.json')), true, 512, JSON_THROW_ON_ERROR);
}

/**
 * Find a registered route by URI and method.
 */
function findContentRoute(string $uri, array $methods): ?Illuminate\Routing\Route
{
    foreach (Route::getRoutes()->getRoutes() as $route) {
        if ($route->uri() === $uri && $route->methods() === $methods) {
            return $route;
        }
    }

    return null;
}

test('moved Content routes keep URI, name, middleware, constraints and controller method', function () {
    $folder = config('ispa-cms.folder_name', 'Default');

    foreach (contentRouteSnapshot() as $expected) {
        if ($expected['project_only'] && $folder === 'Default') {
            continue;
        }

        $route = findContentRoute($expected['uri'], $expected['methods']);
        expect($route)->not->toBeNull("missing route {$expected['uri']}");

        $action = ltrim($route->getActionName(), '\\');
        expect($action)->toStartWith('Cms\\Content\\Http\\Controllers\\');

        $short = substr($action, strlen('Cms\\Content\\Http\\Controllers\\'));
        $short = str_replace('BookmarkAjaxController@', 'AjaxController@', $short);
        $wheres = $route->wheres;
        ksort($wheres);

        expect($short)->toBe($expected['action'], $expected['uri'])
            ->and($route->getName())->toBe($expected['name'], $expected['uri'])
            ->and(array_values($route->gatherMiddleware()))->toBe($expected['middleware'], $expected['uri'])
            ->and($wheres)->toBe($expected['wheres'], $expected['uri']);
    }
});

test('no Content controller is left in app Http Controllers or routes', function () {
    foreach ([
        'Website/BlogController', 'V2/Public/ArticleController', 'V2/Public/PostController', 'V2/Public/ReaderController',
        'V3/Public/CategoryController', 'V3/Public/PostController', 'V3/Public/TagController',
        'V2/Client/PostController', 'V2/Client/BookmarkController', 'V3/Admin/PostController',
    ] as $controller) {
        expect(file_exists(base_path("app/Http/Controllers/{$controller}.php")))->toBeFalse($controller)
            ->and(file_exists(base_path("cms/Content/app/Http/Controllers/{$controller}.php")))->toBeTrue($controller);
    }

    expect(file_get_contents(base_path('routes/web.php')))->not->toContain('BlogController')
        ->and(file_get_contents(base_path('routes/api.php')))->not->toContain("'prefix' => 'category',\n                'as' => 'category.'");
});

test('moved API routes still reach their middleware stack and never answer 404', function (string $method, string $uri) {
    $status = $this->json($method, 'https://localhost' . $uri)->getStatusCode();

    expect($status)->not->toBe(404)->and($status)->toBeGreaterThanOrEqual(400)->and($status)->toBeLessThan(500);
})->with([
    'v3 category list (api.verify)' => ['GET', '/api/v3/category'],
    'v3 post latest (api.verify)' => ['GET', '/api/v3/post/latest'],
    'v3 tag list (api.verify)' => ['GET', '/api/v3/tag'],
    'v2 public article (api.verify)' => ['GET', '/api/v2/public/article'],
    'v2 public reader (api.verify)' => ['GET', '/api/v2/public/view/about-us'],
    'v2 client bookmark (api.verify)' => ['GET', '/api/v2/client/bookmark'],
    'v2 client post list (api.verify)' => ['GET', '/api/v2/client/6f1c0f0e-8a3b-4b9e-9a55-3d0f2c1b7e11/post'],
    'v3 admin post list (sanctum)' => ['GET', '/api/v3/admin/post'],
]);

test('public blog and reader pages resolve to the Content controller', function (string $uri, string $name) {
    $request = Illuminate\Http\Request::create($uri, 'GET');
    $route = Route::getRoutes()->match($request);

    expect($route->getName())->toBe($name)
        ->and($route->getActionName())->toStartWith('Cms\\Content\\Http\\Controllers\\Website\\BlogController@');
})->with([
    ['/blog/some-category/some-post', 'article.en'],
    ['/blog/some-category', 'category.en'],
    ['/bai-viet/some-category/some-post', 'article.vi'],
    ['/bai-viet/some-category', 'category.vi'],
]);
