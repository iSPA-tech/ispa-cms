<?php

declare(strict_types=1);

use Cms\Core\Services\Modules\Packages\PackageVerificationException;
use Cms\Core\Services\Modules\Packages\ReleaseManifest;

uses(Cms\Core\Tests\TestCase::class);

/**
 * A valid format 1 manifest for the reader tests of the optional `admin_packages` field.
 *
 * @return array<string, mixed>
 */
function rmManifest(array $overrides = []): array
{
    return array_merge([
        'format' => 1,
        'name' => 'Widget',
        'version' => '2026.11.0',
        'zip_sha256' => hash('sha256', 'zip'),
        'zip_size' => 10,
        'unpacked_size' => 20,
        'files' => ['Widget/module.json' => hash('sha256', 'a')],
        'requires' => [],
        'irreversible' => false,
        'custom_code' => true,
        'php_min' => '8.3',
        'built_at' => '2026-11-12T10:00:00Z',
        'key_id' => '0123456789abcdef',
    ], $overrides);
}

/**
 * Parse manifest bytes and return the refusal code, or null when accepted.
 */
function rmErrorCode(array $manifest): ?string
{
    try {
        ReleaseManifest::fromVerifiedBytes(json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    } catch (PackageVerificationException $e) {
        return $e->errorCode;
    }

    return null;
}

it('reads a manifest without admin_packages as before', function () {
    $manifest = ReleaseManifest::fromVerifiedBytes(json_encode(rmManifest(), JSON_UNESCAPED_SLASHES));

    expect($manifest->adminPackages)->toBeNull()
        ->and(array_keys($manifest->toArray()))->toBe(ReleaseManifest::FIELDS);
});

it('accepts a valid admin_packages object and keeps it in toArray', function () {
    $packages = ['@vueuse/core' => 14, 'axios' => 1, 'lodash-es' => 4, 'vuedraggable' => 4];
    $manifest = ReleaseManifest::fromVerifiedBytes(json_encode(rmManifest(['admin_packages' => $packages]), JSON_UNESCAPED_SLASHES));

    expect($manifest->adminPackages)->toBe($packages)
        ->and($manifest->toArray()['admin_packages'])->toBe($packages)
        ->and(array_key_last($manifest->toArray()))->toBe('admin_packages');
});

it('refuses a malformed admin_packages field', function (mixed $value) {
    expect(rmErrorCode(rmManifest(['admin_packages' => $value])))->toBe('MODULE_PACKAGE_SCHEMA');
})->with([
    'list' => [['vuedraggable']],
    'empty' => [[]],
    'string' => ['vuedraggable'],
    'string major' => [['vuedraggable' => '4']],
    'negative major' => [['vuedraggable' => -1]],
    'float major' => [['vuedraggable' => 4.5]],
    'uppercase name' => [['VueDraggable' => 4]],
    'path name' => [['../evil' => 1]],
    'bad scope' => [['@/x' => 1]],
    'too many' => [array_combine(array_map(fn (int $i) => 'pkg' . $i, range(1, 51)), array_fill(0, 51, 1))],
]);
