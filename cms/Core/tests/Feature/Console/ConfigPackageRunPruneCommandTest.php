<?php

use Cms\Core\Models\ConfigPackageRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;

uses(Cms\Core\Tests\TestCase::class, RefreshDatabase::class);

function runAged(int $daysOld): ConfigPackageRun
{
    $run = ConfigPackageRun::query()->create([
        'kind' => 'apply',
        'status' => 'succeeded',
        'sections' => ['settings'],
        'package_checksum' => 'sha256:abc',
        'summary' => ['created' => 2],
        'plan' => ['steps' => [1]],
    ]);
    $run->setPayload(['k' => 'v'])->save();
    ConfigPackageRun::query()->whereKey($run->id)->update(['created_at' => now()->subDays($daysOld)]);

    return $run;
}

test('prune clears payload and plan of old runs and keeps the metadata row', function () {
    $old = runAged(20);
    $young = runAged(3);

    expect(Artisan::call('cms:config-package-prune'))->toBe(0);

    $old = $old->fresh();
    expect($old->hasPayload())->toBeFalse()
        ->and($old->plan)->toBeNull()
        ->and($old->kind)->toBe('apply')
        ->and($old->status)->toBe('succeeded')
        ->and($old->summary)->toBe(['created' => 2])
        ->and($old->package_checksum)->toBe('sha256:abc');

    $young = $young->fresh();
    expect($young->payload())->toBe(['k' => 'v'])->and($young->plan)->toBe(['steps' => [1]]);
});

test('the days option is respected', function () {
    $run = runAged(5);
    Artisan::call('cms:config-package-prune', ['--days' => 10]);
    expect($run->fresh()->hasPayload())->toBeTrue();

    Artisan::call('cms:config-package-prune', ['--days' => 2]);
    expect($run->fresh()->hasPayload())->toBeFalse();
});

test('days below 1 fails', function () {
    expect(Artisan::call('cms:config-package-prune', ['--days' => 0]))->toBe(1);
});

test('the migration is idempotent and reversible', function () {
    $migration = require base_path('cms/Core/database/migrations/2026_11_05_100000_create_core_config_package_runs_table.php');

    $migration->up();
    expect(Schema::hasTable('core_config_package_runs'))->toBeTrue();

    $migration->down();
    expect(Schema::hasTable('core_config_package_runs'))->toBeFalse();

    $migration->up();
    expect(Schema::hasTable('core_config_package_runs'))->toBeTrue();
});
