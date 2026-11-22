<?php

use App\Services\Tracking\TrackingTargetKeyResolver;

uses(Tests\TestCase::class);

beforeEach(function (): void {
    config([
        'app.url' => 'https://example.com',
        'tracking.public_hosts' => ['example.com'],
        'tracking.target_aliases' => [
            'Cms\\Content\\Models\\Post' => 'post',
        ],
        'tracking.dynamic_route_resolvers' => [],
        'tracking.public_path_patterns' => [],
    ]);
});

test('entity identity wins over route and slug changes', function (): void {
    $resolver = app(TrackingTargetKeyResolver::class);

    $first = $resolver->resolve(
        'Cms\\Content\\Models\\Post',
        123,
        'article.show',
        ['slug' => 'old-slug'],
        'https://example.com/articles/old-slug'
    );
    $second = $resolver->resolve(
        'Cms\\Content\\Models\\Post',
        123,
        'article.show',
        ['slug' => 'new-slug'],
        'https://example.com/articles/new-slug'
    );

    expect($first)->toMatchArray([
        'target_key' => 'post:123',
        'target_kind' => 'entity',
        'is_rankable' => true,
    ])->and($second['target_key'])->toBe('post:123');
});

test('static named routes use a stable canonical key', function (): void {
    $target = app(TrackingTargetKeyResolver::class)->resolve(
        null,
        0,
        'Contact.Show',
        [],
        'https://example.com/contact?utm_source=test'
    );

    expect($target)->toMatchArray([
        'target_key' => 'route:contact.show',
        'target_kind' => 'route',
        'is_rankable' => true,
    ]);
});

test('dynamic route identity parameters do not collapse targets', function (): void {
    config([
        'tracking.dynamic_route_resolvers.article.show' => [
            'identity_parameters' => ['post'],
            'optional_identity_parameters' => ['page'],
            'presentation_parameters' => ['locale'],
        ],
    ]);

    $resolver = app(TrackingTargetKeyResolver::class);
    $first = $resolver->resolve(
        null,
        0,
        'article.show',
        ['locale' => 'vi', 'post' => 12],
        'https://example.com/vi/articles/12'
    );
    $second = $resolver->resolve(
        null,
        0,
        'article.show',
        ['locale' => 'en', 'post' => 13, 'page' => 2],
        'https://example.com/en/articles/13/2'
    );

    expect($first['target_key'])->toBe('route:article.show:post:12')
        ->and($second['target_key'])->toBe('route:article.show:post:13:page:2')
        ->and($first['target_key'])->not->toBe($second['target_key']);
});

test('configured dynamic routes do not become static when identity parameters are absent', function (): void {
    config([
        'tracking.dynamic_route_resolvers.article.show' => [
            'identity_parameters' => ['post'],
            'optional_identity_parameters' => [],
            'presentation_parameters' => ['locale'],
        ],
        'tracking.public_path_patterns' => ['#^/articles(?:/|$)#'],
    ]);

    $target = app(TrackingTargetKeyResolver::class)->resolve(
        null,
        0,
        'article.show',
        [],
        'https://example.com/articles'
    );

    expect($target['target_key'])->toStartWith('path:sha256:')
        ->and($target['target_key'])->not->toBe('route:article.show');
});

test('unconfigured dynamic routes fall back to normalized public path hashes', function (): void {
    config(['tracking.public_path_patterns' => ['#^/articles(?:/|$)#']]);

    $resolver = app(TrackingTargetKeyResolver::class);

    $first = $resolver->resolve(
        null,
        0,
        'article.show',
        ['slug' => 'hello'],
        'https://example.com//articles/hello/?utm_source=a#part'
    );
    $same = $resolver->resolve(
        null,
        0,
        'article.show',
        ['slug' => 'hello'],
        'https://example.com/articles/hello?ref=b'
    );
    $other = $resolver->resolve(
        null,
        0,
        'article.show',
        ['slug' => 'world'],
        'https://example.com/articles/world'
    );

    expect($first['target_key'])->toStartWith('path:sha256:')
        ->and($first['target_key'])->toBe($same['target_key'])
        ->and($first['target_key'])->not->toBe($other['target_key'])
        ->and($first)->toMatchArray([
            'target_kind' => 'path',
            //  The normalized path is carried for display; the hashed key alone
            //  identifies nothing in receipts or the target catalog.
            'public_path' => '/articles/hello',
            'is_rankable' => false,
        ]);
});

test('path fallback is disabled until a project declares a public allowlist', function (): void {
    $target = app(TrackingTargetKeyResolver::class)->resolve(
        null,
        0,
        null,
        [],
        'https://example.com/reset/private-token'
    );

    expect($target)->toBeNull();
});

test('private signed and cross-host urls remain unclassified', function (string $url): void {
    $target = app(TrackingTargetKeyResolver::class)->resolve(
        null,
        0,
        null,
        [],
        $url
    );

    expect($target)->toBeNull();
})->with([
    'admin' => 'https://example.com/admin/posts',
    'login' => 'https://example.com/login',
    'preview' => 'https://example.com/preview/post',
    'signed' => 'https://example.com/download?signature=secret&expires=123',
    'cross host' => 'https://evil.example/articles/hello',
]);

test('excluded route names cannot fall through to a public path target', function (): void {
    $target = app(TrackingTargetKeyResolver::class)->resolve(
        null,
        0,
        'admin.magic-link',
        [],
        'https://example.com/public-looking-path'
    );

    expect($target)->toBeNull();
});

test('named routes cannot bypass signed or cross-host url rejection', function (string $url): void {
    $target = app(TrackingTargetKeyResolver::class)->resolve(
        null,
        0,
        'download.show',
        [],
        $url
    );

    expect($target)->toBeNull();
})->with([
    'signed named route' => 'https://example.com/download?signature=secret&expires=123',
    'cross-host named route' => 'https://evil.example/download',
]);

test('every emitted target key is lowercase ascii and bounded', function (): void {
    config([
        'tracking.dynamic_route_resolvers.catalog.show' => [
            'identity_parameters' => ['category'],
            'optional_identity_parameters' => [],
            'presentation_parameters' => [],
        ],
    ]);

    $target = app(TrackingTargetKeyResolver::class)->resolve(
        null,
        0,
        'Catalog.Show',
        ['category' => 'Điện Thoại'],
        'https://example.com/catalog/dien-thoai'
    );

    expect($target['target_key'])->toMatch('/^[a-z0-9][a-z0-9._:-]{0,190}$/');
});

test('lossy route parameter forms cannot collapse into one target key', function (): void {
    config([
        'tracking.dynamic_route_resolvers.catalog.show' => [
            'identity_parameters' => ['key'],
            'optional_identity_parameters' => [],
            'presentation_parameters' => [],
        ],
    ]);

    $resolver = app(TrackingTargetKeyResolver::class);
    $keys = collect(['a+b', 'a b', 'a-b', 'PostA', 'posta'])
        ->map(fn (string $value): string => $resolver->resolve(
            null,
            0,
            'catalog.show',
            ['key' => $value],
            'https://example.com/catalog/item'
        )['target_key']);

    expect($keys->unique()->count())->toBe(5);
});
