<?php

declare(strict_types=1);

use Cms\Core\Services\Modules\Packages\PackageVerificationException;
use Cms\Core\Services\Modules\Packages\ReleaseManifest;
use Cms\Core\Services\Modules\Packages\RuntimePlacement;

uses(Cms\Core\Tests\TestCase::class);

/**
 * Manifest of "Demo" 2026.03.0; placement only reads name and version.
 */
function rpManifest(): ReleaseManifest
{
    return ReleaseManifest::fromVerifiedBytes(json_encode([
        'format' => 1,
        'name' => 'Demo',
        'version' => '2026.03.0',
        'zip_sha256' => hash('sha256', 'zip'),
        'zip_size' => 1,
        'unpacked_size' => 1,
        'files' => ['Demo/module.json' => hash('sha256', 'a')],
        'requires' => [],
        'irreversible' => false,
        'custom_code' => false,
        'php_min' => '8.3',
        'built_at' => '2026-11-06T10:00:00Z',
        'key_id' => str_repeat('a', 16),
    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
}

/**
 * Remove a temp tree without following symlinks.
 */
function rpRemove(string $path): void
{
    if (is_link($path) || is_file($path)) {
        @unlink($path);

        return;
    }
    if (!is_dir($path)) {
        return;
    }
    foreach (array_diff(scandir($path), ['.', '..']) as $entry) {
        rpRemove($path . '/' . $entry);
    }
    @rmdir($path);
}

/**
 * Snapshot a tree (type, link target or content hash per path) without following symlinks.
 *
 * @return array<string, string>
 */
function rpSnapshot(string $root, string $relative = ''): array
{
    $out = [];
    $dir = $relative === '' ? $root : $root . '/' . $relative;
    foreach (array_diff(scandir($dir) ?: [], ['.', '..']) as $entry) {
        $path = $relative === '' ? $entry : $relative . '/' . $entry;
        $full = $root . '/' . $path;
        if (is_link($full)) {
            $out[$path] = 'link:' . readlink($full);
        } elseif (is_dir($full)) {
            $out[$path] = 'dir';
            $out += rpSnapshot($root, $path);
        } else {
            $out[$path] = 'file:' . hash_file('sha256', $full);
        }
    }

    return $out;
}

/**
 * Run a placement and return the PackageVerificationException error code (or null on success).
 */
function rpPlaceCode(RuntimePlacement $placement, string $staged, string $runtime): ?string
{
    try {
        $placement->place($staged, rpManifest(), $runtime);
    } catch (PackageVerificationException $e) {
        return $e->errorCode;
    }

    return null;
}

beforeEach(function (): void {
    $dir = sys_get_temp_dir() . '/rp-' . bin2hex(random_bytes(6));
    mkdir($dir, 0755, true);
    $this->tmp = realpath($dir);
    $this->staging = $this->tmp . '/staging';
    $this->runtime = $this->tmp . '/runtime-modules';
    mkdir($this->runtime);
    $this->staged = $this->staging . '/Demo';
    mkdir($this->staged . '/app', 0755, true);
    chmod($this->staged, 0755);
    chmod($this->staged . '/app', 0755);
    file_put_contents($this->staged . '/module.json', '{"name":"Demo","version":"2026.03.0"}');
    file_put_contents($this->staged . '/app/Demo.php', '<?php');
    chmod($this->staged . '/module.json', 0644);
    chmod($this->staged . '/app/Demo.php', 0644);
});

afterEach(function (): void {
    rpRemove($this->tmp);
});

it('moves the module into .versions and links it atomically', function (): void {
    $target = (new RuntimePlacement())->place($this->staged, rpManifest(), $this->runtime);

    $expected = $this->runtime . '/.versions/Demo-2026.03.0';
    expect($target)->toBe($expected)
        ->and(is_link($this->runtime . '/Demo'))->toBeTrue()
        ->and(readlink($this->runtime . '/Demo'))->toBe('.versions/Demo-2026.03.0')
        ->and(realpath($this->runtime . '/Demo'))->toBe(realpath($expected))
        ->and(file_get_contents($this->runtime . '/Demo/module.json'))->toBe('{"name":"Demo","version":"2026.03.0"}')
        ->and(fileperms($expected) & 0777)->toBe(0755)
        ->and(fileperms($expected . '/app') & 0777)->toBe(0755)
        ->and(fileperms($expected . '/module.json') & 0777)->toBe(0644)
        ->and(fileperms($expected . '/app/Demo.php') & 0777)->toBe(0644)
        ->and(file_exists($this->staged))->toBeFalse()
        ->and(array_keys(rpSnapshot($this->runtime)))->toEqualCanonicalizing([
            '.versions', '.versions/Demo-2026.03.0', '.versions/Demo-2026.03.0/app',
            '.versions/Demo-2026.03.0/app/Demo.php', '.versions/Demo-2026.03.0/module.json', 'Demo',
        ]);
});

it('refuses an existing version folder', function (): void {
    mkdir($this->runtime . '/.versions/Demo-2026.03.0', 0755, true);
    file_put_contents($this->runtime . '/.versions/Demo-2026.03.0/old.txt', 'old');
    $before = rpSnapshot($this->runtime);

    expect(rpPlaceCode(new RuntimePlacement(), $this->staged, $this->runtime))->toBe(RuntimePlacement::TARGET_EXISTS)
        ->and(rpSnapshot($this->runtime))->toBe($before)
        ->and(file_exists($this->staged))->toBeFalse();
});

it('refuses an existing module in the runtime folder', function (): void {
    mkdir($this->runtime . '/Demo');
    file_put_contents($this->runtime . '/Demo/module.json', 'old');
    $before = rpSnapshot($this->runtime);

    expect(rpPlaceCode(new RuntimePlacement(), $this->staged, $this->runtime))->toBe(RuntimePlacement::LINK_EXISTS)
        ->and(rpSnapshot($this->runtime))->toBe($before)
        ->and(file_exists($this->staged))->toBeFalse();
});

it('refuses staging and runtime on different filesystems', function (): void {
    $staging = $this->staging;
    $placement = new RuntimePlacement(static fn (string $path): int => str_starts_with($path, $staging) ? 1 : 2);
    $before = rpSnapshot($this->runtime);

    expect(rpPlaceCode($placement, $this->staged, $this->runtime))->toBe(RuntimePlacement::CROSS_DEVICE)
        ->and(rpSnapshot($this->runtime))->toBe($before)
        ->and(file_exists($this->staged))->toBeFalse();
});

it('refuses a staged folder not named after the module', function (): void {
    rename($this->staged, $this->staging . '/Other');
    $before = rpSnapshot($this->runtime);

    expect(rpPlaceCode(new RuntimePlacement(), $this->staging . '/Other', $this->runtime))->toBe(RuntimePlacement::STAGED_INVALID)
        ->and(rpSnapshot($this->runtime))->toBe($before)
        ->and(file_exists($this->staging . '/Other'))->toBeFalse();
});
