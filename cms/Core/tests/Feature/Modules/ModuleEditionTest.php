<?php

declare(strict_types=1);

use Cms\Core\Services\Modules\ModuleEdition;
use Illuminate\Support\Facades\Artisan;

uses(Cms\Core\Tests\TestCase::class);

/**
 * Write module.json files under a temp root: name => manifest.
 *
 * @param array<string, array<string, mixed>> $modules
 */
function medFixture(array $modules): string
{
    $root = sys_get_temp_dir() . '/med-' . bin2hex(random_bytes(6));
    foreach ($modules as $name => $manifest) {
        mkdir($root . '/' . $name, 0775, true);
        file_put_contents($root . '/' . $name . '/module.json', json_encode(['name' => $name] + $manifest));
    }

    return $root;
}

it('treats a missing or invalid edition as internal and Game modules as game by name', function () {
    expect(ModuleEdition::of('Demo', []))->toBe('internal')
        ->and(ModuleEdition::of('Demo', ['edition' => 'bogus']))->toBe('internal')
        ->and(ModuleEdition::of('Demo', ['edition' => 'premium']))->toBe('premium')
        ->and(ModuleEdition::of('GameCore', []))->toBe('game')
        ->and(ModuleEdition::of('GameCore', ['edition' => 'open']))->toBe('game')
        // Game* modules declare `client`; a declared non-open value counts, `open` never does.
        ->and(ModuleEdition::of('GameCore', ['edition' => 'client']))->toBe('client')
        ->and(ModuleEdition::of('GameCore', ['edition' => 'bogus']))->toBe('game');
});

it('flags an open module that requires a non-open module, matching names case-insensitively', function () {
    $root = medFixture([
        'Core' => ['edition' => 'open'],
        'Pro' => ['edition' => 'premium'],
        'Hub' => ['edition' => 'internal'],
        'Bare' => [],
        'Cli' => ['edition' => 'client'],
    ]);
    $manifest = ['edition' => 'open'];

    expect(ModuleEdition::dependencyViolations('Demo', $manifest, ['core'], $root))->toBe([]);
    foreach (['pro' => 'premium', 'Hub' => 'internal', 'Bare' => 'internal', 'Cli' => 'client'] as $name => $bucket) {
        $problems = ModuleEdition::dependencyViolations('Demo', $manifest, [$name], $root);
        expect($problems)->toHaveCount(1)->and($problems[0])->toContain($bucket);
    }
    // Unknown dependencies are not judged; non-open modules may require anything.
    expect(ModuleEdition::dependencyViolations('Demo', $manifest, ['missing'], $root))->toBe([])
        ->and(ModuleEdition::dependencyViolations('Demo', ['edition' => 'premium'], ['Hub'], $root))->toBe([])
        ->and(ModuleEdition::requireNames(['core', 'Pro' => '*']))->toBe(['core', 'Pro']);
});

it('reports modules by bucket and fails on an open-to-premium dependency', function () {
    $ok = medFixture(['A' => ['edition' => 'open'], 'B' => ['edition' => 'client', 'requires' => ['a']], 'GameX' => []]);
    $code = Artisan::call('cms:module-editions', ['--path' => $ok, '--json' => true]);
    $out = json_decode(Artisan::output(), true);
    expect($code)->toBe(0)->and($out['buckets']['open'])->toBe(['A'])->and($out['buckets']['client'])->toBe(['B'])
        ->and($out['buckets']['game'])->toBe(['GameX'])->and($out['violations'])->toBe([]);

    $bad = medFixture(['A' => ['edition' => 'open', 'requires' => ['b']], 'B' => ['edition' => 'premium']]);
    expect(Artisan::call('cms:module-editions', ['--path' => $bad]))->toBe(1);
    expect(Artisan::output())->toContain('Open module A requires b, which is premium.');
});

it('gives every module in the repository a declared edition and keeps the open dependency rule', function () {
    $path = base_path('cms');
    $undeclared = [];
    foreach (glob($path . '/*/module.json') ?: [] as $file) {
        $name = basename(dirname($file));
        $manifest = json_decode((string) file_get_contents($file), true);
        if (str_starts_with($name, 'Game')) {
            // Game* modules are the owner's own and stay `client` (other owner modules pin this in
            // their own tests, which never ship in the public release).
            expect($manifest['edition'] ?? null)->toBe('client', $name);

            continue;
        }
        if (!ModuleEdition::isDeclared($manifest)) {
            $undeclared[] = $name;
        }
        expect(ModuleEdition::dependencyViolations($name, $manifest, ModuleEdition::requireNames($manifest['requires'] ?? []), $path))->toBe([]);
    }
    expect($undeclared)->toBe([]);
    // Owner buckets 2026-11-14 (checked where the module is present: the open-source tree ships only its bundle).
    foreach (['Webmail' => 'open', 'Ecommerce' => 'premium', 'MailHub' => 'internal', 'Automation' => 'premium'] as $module => $edition) {
        if (is_dir($path . '/' . $module)) {
            expect(ModuleEdition::ofFolder($module, $path))->toBe($edition, $module);
        }
    }
});
