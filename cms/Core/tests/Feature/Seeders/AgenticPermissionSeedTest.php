<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

declare(strict_types=1);

/**
 * Proves the Core permission seeder creates the agentic permissions checked by AgenticAuthorization
 * (agentic.viewAny, agentic.update, agentic.approve) exactly once, and stays idempotent on re-run.
 */

use Cms\Core\Database\Seeders\PermissionTableSeed;
use Cms\Core\Models\Permission;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(Cms\Core\Tests\TestCase::class, RefreshDatabase::class);

it('seeds the agentic permissions exactly once and stays idempotent', function () {
    (new PermissionTableSeed())->run();

    foreach (['viewAny', 'update', 'approve'] as $name) {
        expect(Permission::query()->where('group', 'agentic')->where('name', $name)->count())->toBe(1);
    }

    $total = Permission::query()->count();

    (new PermissionTableSeed())->run();

    expect(Permission::query()->count())->toBe($total);
    foreach (['viewAny', 'update', 'approve'] as $name) {
        expect(Permission::query()->where('group', 'agentic')->where('name', $name)->count())->toBe(1);
    }
});

it('seeds the data_scope.manage permission with a bilingual title', function () {
    (new PermissionTableSeed())->run();

    $rows = Permission::query()->where('group', 'data_scope')->where('name', 'manage')->get();

    expect($rows)->toHaveCount(1);
    expect($rows->first()->title)->toBe('Manage data scope | Quản lý phạm vi dữ liệu');
});

it('fills an existing data_scope.manage row whose title is empty', function () {
    Permission::query()->create(['group' => 'data_scope', 'name' => 'manage', 'title' => '']);

    (new PermissionTableSeed())->run();

    $rows = Permission::query()->where('group', 'data_scope')->where('name', 'manage')->get();

    expect($rows)->toHaveCount(1);
    expect($rows->first()->title)->toBe('Manage data scope | Quản lý phạm vi dữ liệu');
});

it('keeps a non-empty custom title on an existing permission row', function () {
    Permission::query()->create(['group' => 'data_scope', 'name' => 'manage', 'title' => 'Custom']);

    (new PermissionTableSeed())->run();

    $rows = Permission::query()->where('group', 'data_scope')->where('name', 'manage')->get();

    expect($rows)->toHaveCount(1);
    expect($rows->first()->title)->toBe('Custom');
});
