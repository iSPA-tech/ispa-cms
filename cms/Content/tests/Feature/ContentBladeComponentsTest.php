<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;

uses(\Tests\TestCase::class, RefreshDatabase::class);

/**
 * Tag name to Content class map of the 15 post widgets.
 *
 * @return array<string, string>
 */
function p11t22Tags(): array
{
    return [
        'about-us' => 'AboutUs',
        'document-latest' => 'DocumentLatest',
        'latest-audio' => 'LatestAudio',
        'latest-document' => 'LatestDocument',
        'latest-events' => 'LatestEvents',
        'latest-glossary' => 'LatestGlossary',
        'latest-video' => 'LatestVideo',
        'post-feature' => 'PostFeature',
        'post-latest' => 'PostLatest',
        'post-slider' => 'PostSlider',
        'posts-by-category' => 'PostsByCategory',
        'related-posts' => 'RelatedPosts',
        'save-bookmark' => 'SaveBookmark',
        'static-page-view' => 'StaticPageView',
        'tags-related' => 'TagsRelated',
    ];
}

/**
 * Renders one tag with its default arguments and returns a normalized outcome string.
 *
 * @param string $tag Blade tag name without the x- prefix.
 *
 * @return string
 */
function p11t22Render(string $tag): string
{
    try {
        return trim(preg_replace('/\s+/', ' ', Blade::render('<x-' . $tag . ' />')));
    } catch (Throwable $e) {
        return 'EXCEPTION ' . preg_replace(['/App\\\\View\\\\Components\\\\|Cms\\\\Content\\\\View\\\\Components\\\\/', '/ \(View: [^)]*\)/'], '', $e::class . ': ' . strtok($e->getMessage(), "\n"));
    }
}

test('every old tag renders the same HTML as before the move', function () {
    $snapshot = json_decode((string) file_get_contents(__DIR__ . '/../Fixtures/post-components-html.json'), true);
    foreach (array_keys(p11t22Tags()) as $tag) {
        expect(p11t22Render($tag))->toBe($snapshot[$tag], $tag);
    }
});

test('the 15 widgets resolve to Content classes under their old tags and the content namespace', function () {
    foreach (p11t22Tags() as $tag => $class) {
        $fqcn = 'Cms\\Content\\View\\Components\\' . $class;
        expect(class_exists($fqcn))->toBeTrue($tag);
        expect(file_exists(base_path('app/View/Components/' . $class . '.php')))->toBeFalse($tag);
        expect(app('blade.compiler')->getClassComponentAliases()[$tag] ?? null)->toBe($fqcn);
    }
    expect(p11t22Render('content::post-latest'))->toBe(p11t22Render('post-latest'));
});

test('app View Components no longer imports Content', function () {
    foreach (glob(base_path('app/View/Components/*.php')) as $file) {
        expect(str_contains(file_get_contents($file), 'Cms\\Content\\'))->toBeFalse(basename($file));
    }
});
