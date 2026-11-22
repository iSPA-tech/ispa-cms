<?php

uses(\Cms\Core\Tests\TestCase::class);

// A fresh install must not point the logo, favicon or footer/dark logo at the owner's own uploads.
it('seeds the image settings empty so a fresh install carries no owner media', function (string $key) {
    $rows = collect(require base_path('cms/Core/database/seeders/exported/settings.php'))
        ->keyBy('setting_key');

    expect($rows->has($key))->toBeTrue();
    expect(json_decode((string) $rows[$key]['value'], true))->toBe([]);
})->with(['image.logo', 'image.favicon', 'image.logo_footer', 'image.logo_dark']);

it('falls back to the bundled logo in the Default header when the logo setting is empty', function () {
    $view = file_get_contents(base_path('projects/Default/views/components/header.blade.php'));

    expect($view)->toContain("iSeo('logo_dark.url') ?: asset('images/ispa-logo.png')");
    expect(is_file(base_path('public/images/ispa-logo.png')))->toBeTrue();
});

// A fresh install must not mail the owner's team inbox or push the owner's mail host into .env.
it('seeds no owner mail address, mail host or social page', function () {
    $values = collect(require base_path('cms/Core/database/seeders/exported/settings.php'))
        ->pluck('value')
        ->filter(fn ($value) => is_string($value))
        ->implode("\n");

    expect($values)->not->toContain('ispa.io')
        ->and($values)->not->toContain('privateemail.com');
});
