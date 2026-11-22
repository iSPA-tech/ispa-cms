<?php

/*
 * Copyright (c) 2026 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

/**
 * Gate 0 Task 2: WidgetDefinitionSyncService contract tests.
 *
 * Characterizes and locks the frozen WidgetDefinitionSyncResult shape
 * (status ready|pending, incidentId, errorCode) and proves the service never
 * lets a post-commit export/publication failure propagate as an exception —
 * every outcome is a truthful, retry-safe result the caller (a controller
 * that already committed its database mutation) can report as sync_status
 * without ever answering HTTP 503 for that case.
 */

use App\Events\Widget\LegacyWidgetDefinitionChanged;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Artisan;
use Cms\Core\Tests\TestCase;
use Cms\Core\Models\Widget;
use Cms\Core\Models\WidgetTheme;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Cms\Core\Services\Widget\WidgetDefinitionSyncService;
use Cms\Core\Services\Widget\LegacyWidgetArtifactSetPublisher;
use Cms\Core\Services\Widget\DTO\WidgetDefinitionSyncResult;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    // These tests assert the export step, which only runs on an authoring environment.
    config(['ispa-cms.widget.catalog_authoring' => true]);
    $this->isolatedStoragePath = sys_get_temp_dir() . '/ispa-widget-sync-service-' . uniqid('', true);
    File::ensureDirectoryExists($this->isolatedStoragePath . '/app');
    $this->app->useStoragePath($this->isolatedStoragePath);
});

afterEach(function () {
    if (!empty($this->isolatedStoragePath)) {
        File::deleteDirectory($this->isolatedStoragePath);
    }
});

// ---------------------------------------------------------------------
// Frozen result shape.
// ---------------------------------------------------------------------

test('WidgetDefinitionSyncResult exposes the frozen ready/pending shape', function () {
    $ready = WidgetDefinitionSyncResult::ready();
    expect($ready->status)->toBe('ready')
        ->and($ready->incidentId)->toBeNull()
        ->and($ready->errorCode)->toBeNull()
        ->and($ready->isReady())->toBeTrue()
        ->and($ready->isPending())->toBeFalse();

    $pending = WidgetDefinitionSyncResult::pending('incident-123', 'WIDGET_EXPORT_FAILED');
    expect($pending->status)->toBe('pending')
        ->and($pending->incidentId)->toBe('incident-123')
        ->and($pending->errorCode)->toBe('WIDGET_EXPORT_FAILED')
        ->and($pending->isPending())->toBeTrue();

    expect($ready->toArray())->toBe([
        'sync_status' => 'ready',
        'sync_incident_id' => null,
        'sync_error_code' => null,
    ]);
});

// ---------------------------------------------------------------------
// Export failure never throws; it reports pending with an incident id.
// ---------------------------------------------------------------------

test('sync reports pending with an incident id when the export command fails, and never throws', function () {
    Artisan::shouldReceive('call')
        ->once()
        ->with('cms:export-widgets')
        ->andReturn(1);

    $service = new WidgetDefinitionSyncService(new LegacyWidgetArtifactSetPublisher());

    $result = $service->sync(['Default']);

    expect($result)->toBeInstanceOf(WidgetDefinitionSyncResult::class)
        ->and($result->isPending())->toBeTrue()
        ->and($result->incidentId)->not->toBeNull()
        ->and($result->errorCode)->toBe(WidgetDefinitionSyncService::ERROR_EXPORT_FAILED);
});

// ---------------------------------------------------------------------
// Publication failure never throws either; distinct error code.
// ---------------------------------------------------------------------

test('sync reports pending with a distinct error code when artifact publication fails', function () {
    Artisan::shouldReceive('call')
        ->once()
        ->with('cms:export-widgets')
        ->andReturn(0);

    // A concurrent lock holder makes the publisher fail deterministically
    // without touching the filesystem fault-injection machinery.
    $externalLock = \Illuminate\Support\Facades\Cache::lock('widget-artifact-generation-lock', 30);
    expect($externalLock->get())->toBeTrue();

    try {
        $publisher = new LegacyWidgetArtifactSetPublisher(lockWaitSeconds: 1);
        $service = new WidgetDefinitionSyncService($publisher);

        $result = $service->sync(['Default']);

        expect($result->isPending())->toBeTrue()
            ->and($result->errorCode)->toBe(WidgetDefinitionSyncService::ERROR_PUBLISH_FAILED)
            ->and($result->incidentId)->not->toBeNull();
    } finally {
        $externalLock->release();
    }
});

// ---------------------------------------------------------------------
// Committed identity: a real Widget/Theme mutation stays committed even
// when the post-commit sync is pending, and sync() itself is side-effect
// safe to call again (no duplicate retry blow-up).
// ---------------------------------------------------------------------

test('the underlying mutation stays committed and retrying sync is safe and non-throwing', function () {
    $widget = Widget::query()->create(['name' => 'Committed Widget']);
    WidgetTheme::query()->create([
        'widget_id' => $widget->id,
        'name' => 'Committed Theme',
        'content' => '<section><h2>Committed</h2></section>',
        'project_name' => ['Default'],
    ]);

    Artisan::shouldReceive('call')
        ->once()
        ->with('cms:export-widgets')
        ->andReturn(1);

    $service = new WidgetDefinitionSyncService(new LegacyWidgetArtifactSetPublisher());
    $firstResult = $service->sync(['Default']);

    expect($firstResult->isPending())->toBeTrue();
    // The widget/theme rows committed independently of the sync outcome —
    // this proves the DB mutation was never rolled back by a sync failure.
    expect(Widget::query()->whereKey($widget->id)->exists())->toBeTrue();

    // Retry: export now succeeds, publication proceeds normally, no
    // exception, no duplicate incident state leaks across calls.
    Artisan::shouldReceive('call')
        ->once()
        ->with('cms:export-widgets')
        ->andReturn(0);

    $secondResult = $service->sync(['Default']);

    expect($secondResult->isReady())->toBeTrue()
        ->and($secondResult->incidentId)->toBeNull();
});

// ---------------------------------------------------------------------
// Chunk 2 Task 15a: sync() dispatches the stable root
// LegacyWidgetDefinitionChanged event after EVERY sync attempt --
// ready and pending alike -- because the underlying database mutation
// already committed either way. This is the seam the cms/Widget module's
// after-commit materialization listener hangs off; Core itself imports
// only the root App\Events\Widget event class here.
// ---------------------------------------------------------------------

test('sync dispatches the root LegacyWidgetDefinitionChanged event on the ready path', function () {
    Event::fake([LegacyWidgetDefinitionChanged::class]);

    Artisan::shouldReceive('call')
        ->once()
        ->with('cms:export-widgets')
        ->andReturn(0);

    $service = new WidgetDefinitionSyncService(new LegacyWidgetArtifactSetPublisher());
    $result = $service->sync(['Default']);

    expect($result->isReady())->toBeTrue();

    Event::assertDispatched(
        LegacyWidgetDefinitionChanged::class,
        static fn (LegacyWidgetDefinitionChanged $event): bool => $event->affectedProjects === ['Default']
            && $event->syncStatus === WidgetDefinitionSyncResult::STATUS_READY
            && $event->incidentId === null
    );
});

test('sync dispatches the root LegacyWidgetDefinitionChanged event on the pending path too', function () {
    Event::fake([LegacyWidgetDefinitionChanged::class]);

    Artisan::shouldReceive('call')
        ->once()
        ->with('cms:export-widgets')
        ->andReturn(1);

    $service = new WidgetDefinitionSyncService(new LegacyWidgetArtifactSetPublisher());
    $result = $service->sync(['Default']);

    expect($result->isPending())->toBeTrue()
        ->and($result->incidentId)->not->toBeNull();

    // The event carries the SAME incident id the caller's HTTP response
    // reports, so a listener-side materialization run can be correlated in
    // logs with the legacy publish incident that immediately preceded it.
    Event::assertDispatched(
        LegacyWidgetDefinitionChanged::class,
        static fn (LegacyWidgetDefinitionChanged $event): bool => $event->affectedProjects === ['Default']
            && $event->syncStatus === WidgetDefinitionSyncResult::STATUS_PENDING
            && $event->incidentId === $result->incidentId
    );
});

// ---------------------------------------------------------------------
// syncAll() drives the publisher's full-known-project resync (used by
// both the operator command and the protected admin retry action).
// ---------------------------------------------------------------------

test('syncAll resyncs every project the publisher currently knows about', function () {
    $widget = Widget::query()->create(['name' => 'Multi Project Widget']);
    WidgetTheme::query()->create([
        'widget_id' => $widget->id,
        'name' => 'Multi Theme',
        'content' => '<section><h2>Multi</h2></section>',
        'project_name' => ['Alpha', 'Beta'],
    ]);

    Artisan::shouldReceive('call')
        ->once()
        ->with('cms:export-widgets')
        ->andReturn(0);

    $service = new WidgetDefinitionSyncService(new LegacyWidgetArtifactSetPublisher());
    $result = $service->syncAll();

    expect($result->isReady())->toBeTrue();

    $manifest = LegacyWidgetArtifactSetPublisher::readActiveManifest();
    expect($manifest)->not->toBeNull()
        ->and($manifest->projectEntry('Alpha'))->not->toBeNull()
        ->and($manifest->projectEntry('Beta'))->not->toBeNull();
});
