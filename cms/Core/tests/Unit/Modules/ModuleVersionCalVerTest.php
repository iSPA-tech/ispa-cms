<?php

declare(strict_types=1);

uses(\Cms\Core\Tests\TestCase::class);

// module versions follow CalVer `YYYY.MM.NN` (year, two-digit month, two-digit counter of the
// releases of that month), like widget bundles.

const MODULE_CALVER = '/^\d{4}\.(0[1-9]|1[0-2])\.\d{2}$/';

it('gives every module a YYYY.MM.NN version', function (): void {
    $wrong = [];
    foreach (glob(base_path('cms/*/module.json')) ?: [] as $manifest) {
        $version = json_decode((string) file_get_contents($manifest), true)['version'] ?? null;
        if (! is_string($version) || preg_match(MODULE_CALVER, $version) !== 1) {
            $wrong[basename(dirname($manifest))] = $version;
        }
    }

    expect($wrong)->toBe([]);
});

it('gives the module template a version the generator fills with this month', function (): void {
    $template = json_decode((string) file_get_contents(base_path('cms/Core/resources/module-template/module.json')), true);

    expect($template['version'])->toBe('__MODULE_VERSION__');
});
