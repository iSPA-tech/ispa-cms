<?php

declare(strict_types=1);

use Cms\Core\Services\Modules\Packages\PackageBuilder;
use Cms\Core\Services\Modules\Packages\PackageBuildException;

uses(Cms\Core\Tests\TestCase::class);

/**
 * Create a temp root with a valid fixture module "Demo" (version 2026.03.0) and an optional CHANGELOG.md.
 *
 * @return string the temp root
 */
function pcFixture(?string $changelog): string
{
    $root = sys_get_temp_dir() . '/pkgc-' . bin2hex(random_bytes(6));
    mkdir($root . '/mods/Demo/app', 0775, true);
    mkdir($root . '/out', 0775, true);
    file_put_contents($root . '/mods/Demo/module.json', json_encode(['name' => 'Demo', 'alias' => 'demo', 'version' => '2026.03.0', 'providers' => [], 'files' => []]));
    file_put_contents($root . '/mods/Demo/app/Thing.php', "<?php\n// thing\n");
    file_put_contents($root . '/composer.lock', json_encode(['packages' => [], 'packages-dev' => []]));
    file_put_contents($root . '/shared-packages.json', json_encode(['format' => 1, 'packages' => []]));
    config(['core.module_packages.shared_admin_packages_file' => $root . '/shared-packages.json']);
    if ($changelog !== null) {
        file_put_contents($root . '/mods/Demo/CHANGELOG.md', $changelog);
    }

    return $root;
}

/**
 * Remove a temp tree.
 */
function pcRemove(string $path): void
{
    if (is_link($path) || is_file($path)) {
        unlink($path);

        return;
    }
    foreach (array_diff(is_dir($path) ? (scandir($path) ?: []) : [], ['.', '..']) as $entry) {
        pcRemove($path . '/' . $entry);
    }
    @rmdir($path);
}

/**
 * Build the fixture module.
 *
 * @return array{0: PackageBuilder, 1: array<string, mixed>}
 */
function pcBuild(string $root): array
{
    $builder = new PackageBuilder();

    return [$builder, $builder->build('Demo', $root . '/mods', $root . '/out', '0123456789abcdef', $root . '/composer.lock')];
}

it('extracts the section of the exact version and not the next or a longer one', function () {
    $md = "# Changelog\n\n## 2026.03.10 - 2026-04-01\n- ten\n\n## 2026.03.1 - 2026-03-20\n- one\n\n## 2026.03.0 - 2026-03-01\n- zero\n- second line\n\n### Detail\nmore\n\n## 2026.02.0\n- old\n";

    expect(PackageBuilder::changelogSection($md, '2026.03.0'))->toBe("- zero\n- second line\n\n### Detail\nmore")
        ->and(PackageBuilder::changelogSection($md, '2026.03.1'))->toBe('- one')
        ->and(PackageBuilder::changelogSection($md, '2026.03.10'))->toBe('- ten')
        ->and(PackageBuilder::changelogSection($md, '2026.02.0'))->toBe('- old')
        ->and(PackageBuilder::changelogSection($md, '2026.04.0'))->toBeNull();
});

it('keeps html as plain text and accepts a bracketed heading', function () {
    $md = "## [2026.03.0] - 2026-03-01\n- <script>alert(1)</script> **bold**\n";

    expect(PackageBuilder::changelogSection($md, '2026.03.0'))->toBe('- <script>alert(1)</script> **bold**');
});

it('returns null for an empty section and truncates a long one to 20 KB', function () {
    expect(PackageBuilder::changelogSection("## 2026.03.0\n\n## 2026.02.0\n- x\n", '2026.03.0'))->toBeNull();

    $long = PackageBuilder::changelogSection("## 2026.03.0\n" . str_repeat("- line é\n", 5000), '2026.03.0');
    expect(strlen($long))->toBeLessThanOrEqual(PackageBuilder::CHANGELOG_MAX_BYTES)
        ->and(mb_check_encoding($long, 'UTF-8'))->toBeTrue();
});

it('builds with no warning when the changelog has the version section, and ships the file', function () {
    $root = pcFixture("## 2026.03.0 - 2026-03-01\n- first release\n");
    [$builder, $built] = pcBuild($root);

    expect($builder->warnings())->toBe([])
        ->and($built['manifest_data']['files'])->toHaveKey('Demo/CHANGELOG.md');
    pcRemove($root);
});

it('warns when CHANGELOG.md is missing or has no section for the version, and still builds', function () {
    $root = pcFixture(null);
    [$builder] = pcBuild($root);
    expect($builder->warnings())->toHaveCount(1)->and($builder->warnings()[0])->toContain('CHANGELOG.md');
    pcRemove($root);

    $root = pcFixture("## 2026.04.0\n- next\n");
    [$builder] = pcBuild($root);
    expect($builder->warnings()[0])->toContain('2026.03.0');
    pcRemove($root);
});

it('refuses the build when require_changelog is on and the section is missing', function () {
    config(['core.module_packages.require_changelog' => true]);
    $root = pcFixture("## 2026.04.0\n- next\n");
    try {
        pcBuild($root);
        throw new RuntimeException('expected refusal');
    } catch (PackageBuildException $e) {
        expect($e->errorCode)->toBe(PackageBuildException::CHANGELOG);
    } finally {
        pcRemove($root);
    }

    $root = pcFixture("## 2026.03.0\n- ok\n");
    [$builder] = pcBuild($root);
    expect($builder->warnings())->toBe([]);
    pcRemove($root);
});

it('keeps the switch off by default', function () {
    expect(config('core.module_packages.require_changelog'))->toBeFalse();
});
