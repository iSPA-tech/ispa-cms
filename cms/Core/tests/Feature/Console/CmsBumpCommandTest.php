<?php

/*
 * Copyright (c) 2026 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

uses(\Cms\Core\Tests\TestCase::class);

use Illuminate\Support\Facades\File;

/**
 * Build a throwaway project tree so the command never touches the real repo.
 */
beforeEach(function () {
    $this->tree = storage_path('framework/testing/cms-bump-' . bin2hex(random_bytes(4)));
    File::ensureDirectoryExists($this->tree . '/cms/Core');
    File::ensureDirectoryExists($this->tree . '/cms/Other');

    $this->versionFile = $this->tree . '/VERSION';
    $this->coreModulePath = $this->tree . '/cms/Core/module.json';
    $this->coreComposerPath = $this->tree . '/cms/Core/composer.json';
    File::put($this->coreModulePath, "{\n    \"name\": \"Core\",\n    \"version\": \"2026.03.04\",\n    \"requires\": []\n}\n");
    File::put($this->coreComposerPath, "{\n    \"name\": \"cms/core\",\n    \"version\": \"2026.03.04\"\n}\n");
    File::put($this->tree . '/cms/Other/module.json', "{\n    \"name\": \"Other\",\n    \"version\": \"2026.02.07\"\n}\n");
    File::put($this->tree . '/cms/Other/composer.json', "{\n    \"name\": \"cms/other\"\n}\n");
});

afterEach(function () {
    File::deleteDirectory($this->tree);
});

it('refuses to write the real repo while tests run', function () {
    $real = trim(File::get(base_path('VERSION')));

    $this->artisan('cms:bump', ['type' => 'patch'])
        ->expectsOutputToContain('Refusing to write')
        ->assertExitCode(1);

    expect(trim(File::get(base_path('VERSION'))))->toBe($real);
});

// the release and every module use CalVer YYYY.MM.NN (year, two-digit month, two-digit counter of
// that month's releases), and each module versions itself: a release bump never touches module.json.

it('bumps the release to the next number of this month and leaves the modules alone', function () {
    $this->travelTo('2026-03-15');
    File::put($this->versionFile, '2026.03.00');
    $module = File::get($this->coreModulePath);

    $this->artisan('cms:bump', ['--base' => $this->tree])
        ->expectsOutputToContain('2026.03.00 -> 2026.03.01')
        ->assertExitCode(0);

    expect(trim(File::get($this->versionFile)))->toBe('2026.03.01')
        ->and(File::get($this->coreModulePath))->toBe($module);
});

it('starts a new month at 01, whatever the bump type', function () {
    $this->travelTo('2026-04-15');
    File::put($this->versionFile, '2026.03.02');

    $this->artisan('cms:bump', ['type' => 'minor', '--base' => $this->tree])->assertExitCode(0);

    expect(trim(File::get($this->versionFile)))->toBe('2026.04.01');
});

it('starts a new year at 01', function () {
    $this->travelTo('2027-01-10');
    File::put($this->versionFile, '2026.12.05');

    $this->artisan('cms:bump', ['type' => 'major', '--base' => $this->tree])->assertExitCode(0);

    expect(trim(File::get($this->versionFile)))->toBe('2027.01.01');
});

it('reads an older one-digit counter', function () {
    $this->travelTo('2026-03-15');
    File::put($this->versionFile, '2026.03.1');

    $this->artisan('cms:bump', ['--base' => $this->tree])->assertExitCode(0);

    expect(trim(File::get($this->versionFile)))->toBe('2026.03.02');
});

it('bumps one module with --module, its composer.json too, and keeps the file layout', function () {
    $this->travelTo('2026-03-15');
    File::put($this->versionFile, '2026.03.00');

    $this->artisan('cms:bump', ['--module' => 'Core', '--base' => $this->tree])
        ->expectsOutputToContain('Core 2026.03.04 -> 2026.03.05')
        ->assertExitCode(0);

    expect(File::get($this->coreModulePath))->toBe("{\n    \"name\": \"Core\",\n    \"version\": \"2026.03.05\",\n    \"requires\": []\n}\n")
        ->and(json_decode(File::get($this->coreComposerPath), true)['version'])->toBe('2026.03.05')
        ->and(trim(File::get($this->versionFile)))->toBe('2026.03.00')
        ->and(json_decode(File::get($this->tree . '/cms/Other/module.json'), true)['version'])->toBe('2026.02.07');
});

it('starts a module in a new month at 01', function () {
    $this->travelTo('2026-03-15');

    $this->artisan('cms:bump', ['--module' => 'Other', '--base' => $this->tree])->assertExitCode(0);

    expect(json_decode(File::get($this->tree . '/cms/Other/module.json'), true)['version'])->toBe('2026.03.01');
});

it('refuses a module the tree does not have', function () {
    $this->artisan('cms:bump', ['--module' => 'Missing', '--base' => $this->tree])
        ->expectsOutputToContain('Module Missing not found')
        ->assertExitCode(1);
});

it('shows dry-run without modifying files', function () {
    $this->travelTo('2026-03-15');
    File::put($this->versionFile, '2026.03.00');

    $this->artisan('cms:bump', ['--dry-run' => true, '--base' => $this->tree])
        ->expectsOutputToContain('[dry-run] Version bump: 2026.03.00 -> 2026.03.01')
        ->assertExitCode(0);

    expect(trim(File::get($this->versionFile)))->toBe('2026.03.00');
});

it('rejects invalid CalVer format', function () {
    File::put($this->versionFile, '2026.3.0');

    $this->artisan('cms:bump', ['--base' => $this->tree])
        ->expectsOutputToContain('Invalid CalVer format')
        ->assertExitCode(1);
});
