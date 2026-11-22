<?php

declare(strict_types=1);

use Cms\Content\Http\Controllers\Website\BlogController;
use Cms\Content\Interfaces\PostInterface;
use Cms\Core\Interfaces\CategoryInterface;
use Cms\Core\Interfaces\CategoryTargetInterface;
use Cms\Core\Repositories\Interfaces\SlugInterface;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Route;

uses(\Tests\TestCase::class);

/**
 * a document opened through an article URL (`/bai-viet/{cate}/{slug}`) answered 404 on a
 * client site. It now answers 301 to the document page where the project declares document routes.
 */
function blogControllerWithDocument(?object $document): BlogController
{
    $post = Mockery::mock(PostInterface::class);
    $post->shouldReceive('getPostDetailBySlugAndCategorySlug')
        ->with('wcd-23rd-1989', 'wrong-cate', 'document', Mockery::any())
        ->andReturn($document);

    app()->instance(PostInterface::class, $post);
    app()->instance(CategoryInterface::class, Mockery::mock(CategoryInterface::class));
    app()->instance(SlugInterface::class, Mockery::mock(SlugInterface::class));
    app()->instance(CategoryTargetInterface::class, Mockery::mock(CategoryTargetInterface::class));

    return app(BlogController::class);
}

function callRedirectToDocument(BlogController $controller): ?RedirectResponse
{
    return (new ReflectionMethod($controller, 'redirectToDocument'))->invoke($controller, 'wrong-cate', 'wcd-23rd-1989');
}

function registerDocumentRoutes(): void
{
    foreach (['vi' => 'tai-lieu', 'en' => 'document'] as $lang => $prefix) {
        Route::get('/__test-' . $prefix . '/{cate}/{slug}', static fn () => '')->name('document.show.' . $lang);
    }
    Route::getRoutes()->refreshNameLookups();
}

it('redirects 301 to the document page under the document category slug', function () {
    registerDocumentRoutes();
    $document = (object) ['category' => (object) ['slugs' => collect([(object) ['slug' => 'su-diep-duc-giao-hoang']])]];

    $response = callRedirectToDocument(blogControllerWithDocument($document));

    expect($response)->toBeInstanceOf(RedirectResponse::class)
        ->and($response->getStatusCode())->toBe(301)
        ->and($response->getTargetUrl())->toContain('/su-diep-duc-giao-hoang/wcd-23rd-1989');
});

it('keeps the 404 when no document has that slug', function () {
    registerDocumentRoutes();

    expect(callRedirectToDocument(blogControllerWithDocument(null)))->toBeNull();
});

it('keeps the 404 on sites without document routes', function () {
    $document = (object) ['category' => (object) ['slugs' => collect([(object) ['slug' => 'x']])]];

    expect(Route::has('document.show.vi'))->toBeFalse()
        ->and(callRedirectToDocument(blogControllerWithDocument($document)))->toBeNull();
});
