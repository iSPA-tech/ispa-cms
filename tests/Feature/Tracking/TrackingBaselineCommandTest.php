<?php

use App\Constants\Tables;
use App\Models\Tracking\TrackingSiteTotal;
use App\Models\Tracking\TrackingTargetTotal;
use App\Services\Tracking\TrackingAggregateReconciler;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\Support\TrackingTestSchema;

uses(Tests\TestCase::class);

beforeEach(function (): void {
    TrackingTestSchema::create();
    $this->manifestPath = storage_path('framework/testing/tracking-baseline.json');
    File::delete($this->manifestPath);
});

afterEach(function (): void {
    File::delete($this->manifestPath);
    TrackingTestSchema::drop();
});

test('baseline builder emits a deterministic bounded manifest without mutation', function (): void {
    DB::table(Tables::TRACKS)->insert([
        [
            'target_type' => 'Cms\\Content\\Models\\Post',
            'target_id' => 42,
            'view' => 7,
            'created_at' => now(),
            'updated_at' => now(),
        ],
        [
            'target_type' => null,
            'target_id' => 0,
            'view' => 3,
            'created_at' => now(),
            'updated_at' => now(),
        ],
    ]);

    $arguments = [
        '--checkpoint-id' => 'checkpoint-a',
        '--max-id' => 2,
        '--limit' => 100,
        '--output' => $this->manifestPath,
    ];
    expect(Artisan::call('cms:tracking-backfill-target-totals', $arguments))->toBe(0)
        ->and(File::exists($this->manifestPath))->toBeTrue()
        ->and(TrackingSiteTotal::query()->value('baseline_manifest_id'))->toBeNull()
        ->and(TrackingTargetTotal::query()->count())->toBe(0);

    $first = File::get($this->manifestPath);
    expect(Artisan::call('cms:tracking-backfill-target-totals', $arguments))->toBe(0)
        ->and(File::get($this->manifestPath))->toBe($first)
        ->and(data_get(json_decode($first, true), 'site.baseline_views'))->toBe(10)
        ->and(data_get(json_decode($first, true), 'targets.0.target_key'))->toBe('post:42');
});

test('baseline import is dry by default force imports once and rejects replacement', function (): void {
    DB::table(Tables::TRACKS)->insert([
        'target_type' => 'Cms\\Content\\Models\\Post',
        'target_id' => 42,
        'view' => 7,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $manifest = app(TrackingAggregateReconciler::class)
        ->buildBaselineManifest('checkpoint-a', 1);
    File::ensureDirectoryExists(dirname($this->manifestPath));
    File::put($this->manifestPath, json_encode($manifest, JSON_THROW_ON_ERROR));

    expect(Artisan::call('cms:tracking-import-baseline', [
        '--manifest' => $this->manifestPath,
    ]))->toBe(0)
        ->and(TrackingSiteTotal::query()->value('baseline_manifest_id'))->toBeNull();

    expect(Artisan::call('cms:tracking-import-baseline', [
        '--manifest' => $this->manifestPath,
        '--force' => true,
    ]))->toBe(0)
        ->and(TrackingSiteTotal::query()->value('baseline_views'))->toBe(7)
        ->and(TrackingTargetTotal::query()->where('target_key', 'post:42')->value('baseline_views'))
        ->toBe(7);

    expect(Artisan::call('cms:tracking-import-baseline', [
        '--manifest' => $this->manifestPath,
        '--force' => true,
    ]))->toBe(0)
        ->and(Artisan::output())->toContain('mutations=0');

    DB::table(Tables::TRACKS)->where('id', 1)->update(['view' => 8]);
    $replacement = app(TrackingAggregateReconciler::class)
        ->buildBaselineManifest('checkpoint-b', 1);
    File::put($this->manifestPath, json_encode($replacement, JSON_THROW_ON_ERROR));

    expect(Artisan::call('cms:tracking-import-baseline', [
        '--manifest' => $this->manifestPath,
        '--force' => true,
    ]))->toBe(1)
        ->and(TrackingSiteTotal::query()->value('baseline_views'))->toBe(7);
});

test('reconciliation fails on raw aggregate or receipt mismatch', function (): void {
    DB::table(Tables::TRACKS)->insert([
        'target_type' => 'Cms\\Content\\Models\\Post',
        'target_id' => 42,
        'view' => 7,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $manifest = app(TrackingAggregateReconciler::class)
        ->buildBaselineManifest('checkpoint-a', 1);
    app(TrackingAggregateReconciler::class)->importBaselineManifest($manifest);

    expect(Artisan::call('cms:tracking-reconcile', ['--max-id' => 1]))->toBe(0);

    DB::table(Tables::TRACKING_TARGET_TOTALS)
        ->where('target_key', 'post:42')
        ->increment('baseline_views');

    expect(Artisan::call('cms:tracking-reconcile', ['--max-id' => 1]))->toBe(1)
        ->and(Artisan::output())->toContain('target_mismatches=1');
});

test('partial manifests cannot be imported', function (): void {
    DB::table(Tables::TRACKS)->insert([
        [
            'target_type' => 'Cms\\Content\\Models\\Post',
            'target_id' => 1,
            'view' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ],
        [
            'target_type' => 'Cms\\Content\\Models\\Post',
            'target_id' => 2,
            'view' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ],
    ]);
    $manifest = app(TrackingAggregateReconciler::class)
        ->buildBaselineManifest('checkpoint-a', 2, 1, 1);
    File::ensureDirectoryExists(dirname($this->manifestPath));
    File::put($this->manifestPath, json_encode($manifest, JSON_THROW_ON_ERROR));

    expect(Artisan::call('cms:tracking-import-baseline', [
        '--manifest' => $this->manifestPath,
        '--force' => true,
    ]))->toBe(1)
        ->and(TrackingSiteTotal::query()->value('baseline_manifest_id'))->toBeNull();
});

test('baseline scan stops at the requested row limit', function (): void {
    DB::table(Tables::TRACKS)->insert([
        [
            'target_type' => 'Cms\\Content\\Models\\Post',
            'target_id' => 1,
            'view' => 2,
            'created_at' => now(),
            'updated_at' => now(),
        ],
        [
            'target_type' => 'Cms\\Content\\Models\\Post',
            'target_id' => 2,
            'view' => 3,
            'created_at' => now(),
            'updated_at' => now(),
        ],
    ]);

    $manifest = app(TrackingAggregateReconciler::class)
        ->buildBaselineManifest('checkpoint-limited', 2, 0, 1);

    expect($manifest['complete'])->toBeFalse()
        ->and($manifest['range_last_id'])->toBe(1)
        ->and(data_get($manifest, 'site.raw_views'))->toBe(2)
        ->and($manifest['target_count'])->toBe(1);
});
