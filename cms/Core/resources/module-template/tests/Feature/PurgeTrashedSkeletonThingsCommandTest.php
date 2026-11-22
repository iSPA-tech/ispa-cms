<?php

declare(strict_types=1);

namespace Cms\Skeleton\Tests\Feature;

use Cms\Skeleton\Models\SkeletonThing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class PurgeTrashedSkeletonThingsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_old_trashed_rows_are_removed_and_recent_ones_kept(): void
    {
        $old = SkeletonThing::query()->create(['name' => 'Old']);
        $recent = SkeletonThing::query()->create(['name' => 'Recent']);
        $old->delete();
        $recent->delete();
        SkeletonThing::withTrashed()->whereKey($old->id)->update(['deleted_at' => now()->subDays(40)]);
        SkeletonThing::withTrashed()->whereKey($recent->id)->update(['deleted_at' => now()->subDays(5)]);

        $this->artisan('skeleton:purge-trashed')->assertSuccessful();

        $this->assertSame(['Recent'], SkeletonThing::withTrashed()->pluck('name')->all());
    }

    public function test_zero_days_is_refused(): void
    {
        $this->artisan('skeleton:purge-trashed', ['--days' => '0'])->assertFailed();
    }
}
