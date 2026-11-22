<?php

/*
 * Copyright (c) 2026 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

uses(\Cms\Core\Tests\TestCase::class);

use Illuminate\Support\Facades\File;

beforeEach(function () {
    $this->feRoot = sys_get_temp_dir().'/manifest-lint-fe-'.uniqid('', true);
    File::ensureDirectoryExists($this->feRoot);
});

afterEach(function () {
    File::deleteDirectory($this->feRoot);
});

/**
 * Write a "Sample" module manifest merged with extra keys and run the lint on the temp tree.
 *
 * @param  array<string, mixed>  $extra
 */
function lintFrontend(string $root, array $extra): \Illuminate\Testing\PendingCommand
{
    File::ensureDirectoryExists("{$root}/Sample");
    File::put("{$root}/Sample/module.json", json_encode(['name' => 'Sample', 'version' => '1.0.0'] + $extra));

    return test()->artisan('cms:manifest-lint', ['--path' => $root]);
}

it('accepts valid frontend, install and distribution declarations', function () {
    lintFrontend($this->feRoot, [
        'frontend' => ['entries' => ['resources/assets/js/app.js', 'resources/assets/css/app.css'], 'styles' => 'module'],
        'install' => ['artisan' => ['cms:sample:seed']],
        'distribution' => 'online',
    ])->expectsOutputToContain('1 modules, 0 errors, 0 warnings')->assertExitCode(0);
});

it('accepts an empty entries list with styles project-theme and distribution deploy', function () {
    lintFrontend($this->feRoot, [
        'frontend' => ['entries' => [], 'styles' => 'project-theme'],
        'distribution' => 'deploy',
        'distribution_reason' => 'Styled by the project theme.',
    ])->expectsOutputToContain('1 modules, 0 errors, 0 warnings')->assertExitCode(0);
});

it('rejects frontend entries that are outside resources, contain dot-dot, are absolute or not strings', function (mixed $entry) {
    lintFrontend($this->feRoot, ['frontend' => ['entries' => [$entry]]])
        ->expectsOutputToContain('frontend.entries')
        ->assertExitCode(1);
})->with([
    'outside resources' => ['public/app.js'],
    'dot-dot' => ['resources/../app.js'],
    'absolute' => ['/resources/app.js'],
    'windows absolute' => ['C:\\resources\\app.js'],
    'empty' => [''],
    'missing (number)' => [5],
    'no extension of a buildable type' => ['resources/assets/readme.txt'],
]);

it('rejects frontend that is not an object or has entries that are not a list', function () {
    lintFrontend($this->feRoot, ['frontend' => 'app.js'])
        ->expectsOutputToContain("'frontend' must be an object")->assertExitCode(1);
});

it('rejects frontend without an entries list', function () {
    lintFrontend($this->feRoot, ['frontend' => ['styles' => 'none']])
        ->expectsOutputToContain("frontend.entries must be a list")->assertExitCode(1);
});

it('rejects an unknown frontend field', function () {
    lintFrontend($this->feRoot, ['frontend' => ['entries' => [], 'bogus' => 1]])
        ->expectsOutputToContain("frontend has unknown field 'bogus'")->assertExitCode(1);
});

it('rejects an unknown styles value', function () {
    lintFrontend($this->feRoot, ['frontend' => ['entries' => [], 'styles' => 'theme']])
        ->expectsOutputToContain('frontend.styles must be module, project-theme or none')->assertExitCode(1);
});

it('rejects install.artisan commands outside the module namespace or with arguments', function (string $command) {
    lintFrontend($this->feRoot, ['install' => ['artisan' => [$command]]])
        ->expectsOutputToContain('install.artisan')
        ->assertExitCode(1);
})->with([
    'other module' => ['cms:other:seed'],
    'core command' => ['migrate'],
    'with argument' => ['cms:sample:seed --force'],
    'with value' => ['cms:sample:seed now'],
    'no name' => ['cms:sample:'],
]);

it('rejects an install object that is malformed', function () {
    lintFrontend($this->feRoot, ['install' => ['artisan' => 'cms:sample:seed']])
        ->expectsOutputToContain('install.artisan must be a list')->assertExitCode(1);
});

it('uses the kebab-case module name for install.artisan', function () {
    File::ensureDirectoryExists("{$this->feRoot}/DigitalLibrary");
    File::put("{$this->feRoot}/DigitalLibrary/module.json", json_encode([
        'name' => 'DigitalLibrary', 'version' => '1.0.0',
        'install' => ['artisan' => ['cms:digital-library:seed']],
    ]));

    $this->artisan('cms:manifest-lint', ['--path' => $this->feRoot])
        ->expectsOutputToContain('1 modules, 0 errors, 0 warnings')->assertExitCode(0);
});

it('rejects an unknown distribution value', function () {
    lintFrontend($this->feRoot, ['distribution' => 'cloud'])
        ->expectsOutputToContain("'distribution' must be online or deploy")->assertExitCode(1);
});

it('requires distribution_reason when distribution is deploy', function () {
    lintFrontend($this->feRoot, ['distribution' => 'deploy'])
        ->expectsOutputToContain("distribution 'deploy' requires a non-empty 'distribution_reason'")->assertExitCode(1);
});

it('rejects distribution_reason without distribution deploy', function () {
    lintFrontend($this->feRoot, ['distribution' => 'online', 'distribution_reason' => 'x'])
        ->expectsOutputToContain("'distribution_reason' is only allowed with distribution 'deploy'")->assertExitCode(1);
});

it('requires distribution deploy for styles project-theme', function () {
    lintFrontend($this->feRoot, ['frontend' => ['entries' => [], 'styles' => 'project-theme']])
        ->expectsOutputToContain("frontend.styles 'project-theme' requires distribution 'deploy'")->assertExitCode(1);

    $this->feRoot .= '-b';
    File::ensureDirectoryExists($this->feRoot);
    lintFrontend($this->feRoot, ['frontend' => ['entries' => [], 'styles' => 'project-theme'], 'distribution' => 'online'])
        ->expectsOutputToContain("frontend.styles 'project-theme' requires distribution 'deploy'")->assertExitCode(1);
    File::deleteDirectory($this->feRoot);
});
