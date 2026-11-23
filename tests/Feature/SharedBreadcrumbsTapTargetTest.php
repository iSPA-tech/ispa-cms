<?php

uses(\Tests\TestCase::class);

/**
 * The shared public breadcrumb (`resources/views/components/breadcrumbs.blade.php`) is printed by every project theme
 * that does not ship its own. Its links measured 20 px tall on phones (found on the demo, 2026-11-23); they keep a
 * 44 px hit area below `md` and return to plain inline text from `md`, where the row must not grow.
 *
 * Read from the template source: rendering any view runs the site composers, which need the settings tables.
 */
it('gives every breadcrumb link a 44 px hit area on phones and keeps the compact row from md', function (): void {
    $source = file_get_contents(resource_path('views/components/breadcrumbs.blade.php'));

    // One link element in the template, used for every crumb that has a URL.
    expect(substr_count($source, '<a href="{{ $bread[\'url\'] }}"'))->toBe(1);

    preg_match("/@class\\(\\[\\s*'([^']+)',\\s*'([^']+)' => \\\$key === 0,/", $source, $match);

    expect($match)->toHaveCount(3);

    $every = preg_split('/\s+/', trim($match[1]));
    $home = preg_split('/\s+/', trim($match[2]));

    // Phones: a flex box at least 44 px tall. From md: inline text again, or the row grows by the line box.
    expect($every)->toContain('inline-flex', 'min-h-11', 'items-center', 'md:inline', 'md:min-h-0')
        // Only the home icon needs extra width; the padding is pulled back so nothing moves sideways.
        ->and($home)->toBe(['-mx-3', 'px-3', 'md:mx-0', 'md:px-0'])
        // The current page stays text, not a link.
        ->and($source)->toContain('aria-current="page"');
});
