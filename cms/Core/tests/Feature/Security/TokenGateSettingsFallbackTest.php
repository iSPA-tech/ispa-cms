<?php
/*
 * Copyright (c) 2026 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

declare(strict_types=1);

/**
 * security review: the token gate caches its settings for a minute. When the cache store fails, the gate must
 * read the stored mode instead of falling back to the install default `log`, or a cache outage would silently switch
 * a site in `enforce` back to "count and allow".
 */

use App\Support\TokenGate;
use Cms\Core\Services\Security\TokenSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;

uses(Cms\Core\Tests\TestCase::class, RefreshDatabase::class);

test('a failing cache store keeps the stored enforcement mode', function (string $mode): void {
    app(TokenSettings::class)->update(['mode' => $mode]);
    app()->forgetInstance(TokenSettings::class);

    Cache::shouldReceive('remember')->andThrow(new RuntimeException('cache store down'));

    expect(app(TokenGate::class)->mode())->toBe($mode);
})->with(['enforce', 'off']);

test('with a working cache the stored mode is read through the snapshot', function (): void {
    app(TokenSettings::class)->update(['mode' => 'enforce']);

    expect(app(TokenGate::class)->mode())->toBe('enforce');
});
