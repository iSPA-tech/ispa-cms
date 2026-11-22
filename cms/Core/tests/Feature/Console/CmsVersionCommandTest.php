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
    $this->versionFile = base_path('VERSION');
    $this->originalVersion = File::get($this->versionFile);
});

afterEach(function () {
    File::put($this->versionFile, $this->originalVersion);
});

it('displays the current CalVer version', function () {
    File::put($this->versionFile, '2026.03.0');

    $this->artisan('cms:version')
        ->expectsOutputToContain('2026.03.0')
        ->assertExitCode(0);
});

it('displays version in json format', function () {
    File::put($this->versionFile, '2026.03.1');

    $this->artisan('cms:version', ['--json' => true])
        ->expectsOutputToContain('"version": "2026.03.1"')
        ->assertExitCode(0);
});
