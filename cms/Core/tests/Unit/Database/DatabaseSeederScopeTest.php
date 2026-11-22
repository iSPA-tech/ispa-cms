<?php

uses(\Cms\Core\Tests\TestCase::class);

use Database\Seeders\DatabaseSeeder;

/**
 * the shared DatabaseSeeder names only shipped modules (edition open or premium). Seeders of client
 * modules and client projects run from `Database\Seeders\Internal\ClientSeeder`, which the private repo keeps and
 * the open-source export leaves out.
 */
it('names no client module, internal module or client project', function () {
    $source = (string) file_get_contents((new ReflectionClass(DatabaseSeeder::class))->getFileName());

    expect(preg_match('/\\\\?PJ\\\\/', $source))->toBe(0, 'DatabaseSeeder names a project seeder');

    preg_match_all('/\\\\Cms\\\\([A-Za-z0-9]+)\\\\/', $source, $matches);
    foreach (array_unique($matches[1]) as $module) {
        $manifest = base_path("cms/{$module}/module.json");
        if (! is_dir(base_path("cms/{$module}"))) {
            // An optional module this install does not have (the open build ships only its bundle); the private tree,
            // which has every module, judges its edition. The seeder calls such modules behind class_exists().
            continue;
        }
        $edition = is_file($manifest) ? (json_decode((string) file_get_contents($manifest), true)['edition'] ?? null) : null;
        expect(in_array($edition, ['open', 'premium'], true))->toBeTrue("DatabaseSeeder names {$module} ({$edition})");
    }
});

it('runs the internal client seeder only when the private tree has it', function () {
    $source = (string) file_get_contents((new ReflectionClass(DatabaseSeeder::class))->getFileName());

    expect($source)->toContain('class_exists(\\Database\\Seeders\\Internal\\ClientSeeder::class)');
});
