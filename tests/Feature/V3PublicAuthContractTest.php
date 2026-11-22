<?php

use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as RouteFacade;
use Tests\TestCase;

uses(TestCase::class);

function acmeRouteMiddlewareFor(string $uri, string $method = 'GET'): array
{
    $route = collect(RouteFacade::getRoutes())->first(
        fn (Route $route): bool => $route->uri() === $uri
            && in_array(strtoupper($method), $route->methods(), true)
    );

    expect($route)->not->toBeNull(sprintf('Expected route [%s %s] to exist.', $method, $uri));

    return $route->gatherMiddleware();
}

test('acme v3 public read routes use api verify without user sanctum auth', function (string $uri): void {
    $middleware = acmeRouteMiddlewareFor($uri);

    expect($middleware)
        ->toContain('api.verify')
        ->not->toContain('auth:sanctum');
})->with([
    'static settings' => ['api/v3/static/settings'],
    'static main menu' => ['api/v3/static/main-menu'],
    'category all' => ['api/v3/category/all'],
    'post all' => ['api/v3/post/all'],
    'post latest' => ['api/v3/post/latest'],
    'tag index' => ['api/v3/tag'],
]);
