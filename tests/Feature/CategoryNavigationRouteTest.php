<?php

declare(strict_types=1);

use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

uses(TestCase::class);

/*
 * the public `{category}/{slug}` catch-all is on unless a project turns it off from its provider
 * (`ispa-cms.routes.category_navigation`); the route file names no project.
 */

function webRoutesHave(string $name, bool $categoryNavigation): bool
{
    // The catch-all belongs to project sites; a Default site answers an unknown address with a 404 (650e193e4).
    config([
        'ispa-cms.folder_name' => 'ExampleProject',
        'ispa-cms.routes.category_navigation' => $categoryNavigation,
    ]);
    $router = new Router(app('events'), app());
    $original = Route::getFacadeRoot();
    Route::swap($router);
    try {
        require base_path('routes/web.php');
    } finally {
        Route::swap($original);
    }
    $router->getRoutes()->refreshNameLookups();

    return $router->has($name);
}

test('the category catch-all is registered by default', function () {
    expect(config('ispa-cms.routes.category_navigation'))->toBeTrue()
        ->and(webRoutesHave('cate-navigate', true))->toBeTrue();
});

test('a project that turns it off gets no category catch-all', function () {
    expect(webRoutesHave('cate-navigate', false))->toBeFalse();
});
