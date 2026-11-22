<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Console\Output\BufferedOutput;

uses(Cms\Core\Tests\TestCase::class);

/**
 * Create a fixture modules folder with a lock file; returns [root, modulesPath, lockPath].
 *
 * @param array<string, array<string, string>> $modules module name => relative file => contents
 *
 * @return array{0: string, 1: string, 2: string}
 */
function pkgaFixture(array $modules): array
{
    $root = sys_get_temp_dir() . '/pkga-' . bin2hex(random_bytes(6));
    foreach ($modules as $name => $files) {
        mkdir($root . '/mods/' . $name, 0775, true);
        file_put_contents($root . '/mods/' . $name . '/module.json', json_encode([
            'name' => $name, 'alias' => strtolower($name), 'version' => '2026.03.0', 'providers' => [], 'files' => [],
        ]));
        foreach ($files as $relative => $contents) {
            @mkdir(dirname($root . '/mods/' . $name . '/' . $relative), 0775, true);
            file_put_contents($root . '/mods/' . $name . '/' . $relative, $contents);
        }
    }
    file_put_contents($root . '/composer.lock', json_encode(['packages' => [], 'packages-dev' => []]));

    return [$root, $root . '/mods', $root . '/composer.lock'];
}

/**
 * Remove a temp tree.
 */
function pkgaRemove(string $path): void
{
    if (is_link($path) || is_file($path)) {
        unlink($path);

        return;
    }
    foreach (is_dir($path) ? array_diff(scandir($path) ?: [], ['.', '..']) : [] as $entry) {
        pkgaRemove($path . '/' . $entry);
    }
    @rmdir($path);
}

/**
 * Run the audit with --json and index the rows by module.
 *
 * @return array<string, array{module: string, ok: bool, code: string, message: string}>
 */
function pkgaRun(string $mods, string $lock): array
{
    $buffer = new BufferedOutput();
    Artisan::call('cms:module-package-audit', ['--modules-path' => $mods, '--lock' => $lock, '--json' => true], $buffer);
    $rows = json_decode($buffer->fetch(), true, 512, JSON_THROW_ON_ERROR);
    expect(array_is_list($rows))->toBeTrue();

    return array_column($rows, null, 'module');
}

it('reports one row per module with the first refusal reason', function (): void {
    [$root, $mods, $lock] = pkgaFixture([
        'Clean' => ['app/Thing.php' => "<?php\n"],
        'Core' => [],
        'GameFoo' => [],
        'Vited' => ['vite.config.js' => 'export default {}'],
        'Importer' => ['admin/page.js' => "import x from 'lodash';\n"],
    ]);
    $before = glob(sys_get_temp_dir() . '/pkgbuild-lint-*') ?: [];
    try {
        $rows = pkgaRun($mods, $lock);
    } finally {
        pkgaRemove($root);
    }

    expect(array_keys($rows))->toBe(['Clean', 'Core', 'GameFoo', 'Importer', 'Vited']);
    expect($rows['Clean']['ok'])->toBeTrue()->and($rows['Clean']['code'])->toBe('');
    expect($rows['Core']['code'])->toBe('FORBIDDEN_MODULE');
    expect($rows['GameFoo']['code'])->toBe('FORBIDDEN_MODULE');
    expect($rows['Vited']['code'])->toBe('VITE_CONFIG')->and($rows['Vited']['ok'])->toBeFalse();
    expect($rows['Importer']['code'])->toBe('ADMIN_IMPORT');
    expect(glob(sys_get_temp_dir() . '/pkgbuild-lint-*') ?: [])->toBe($before);
});

it('writes no zip and no manifest and needs no signing key', function (): void {
    [$root, $mods, $lock] = pkgaFixture(['Clean' => ['app/Thing.php' => "<?php\n"]]);
    try {
        pkgaRun($mods, $lock);
        $leftovers = array_merge(glob($root . '/**/*.zip') ?: [], glob($root . '/*.zip') ?: [], glob($root . '/mods/*.zip') ?: []);
    } finally {
        pkgaRemove($root);
    }

    expect($leftovers)->toBe([]);
});

it('prints a table and a summary without --json', function (): void {
    [$root, $mods, $lock] = pkgaFixture(['Clean' => []]);
    try {
        $buffer = new BufferedOutput();
        Artisan::call('cms:module-package-audit', ['--modules-path' => $mods, '--lock' => $lock], $buffer);
        $text = $buffer->fetch();
    } finally {
        pkgaRemove($root);
    }

    expect($text)->toContain('Clean')->and($text)->toContain('1 of 1 modules can be packaged.');
});
