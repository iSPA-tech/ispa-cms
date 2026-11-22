<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(Cms\Core\Tests\TestCase::class, RefreshDatabase::class);

function pruneTestRow(?int $readDaysAgo, int $createdDaysAgo): string
{
    $id = (string) Str::uuid();
    DB::table('notifications')->insert([
        'id' => $id,
        'type' => 'T',
        'notifiable_type' => 'App\\Models\\User',
        'notifiable_id' => 1,
        'data' => '{}',
        'read_at' => $readDaysAgo === null ? null : now()->subDays($readDaysAgo),
        'created_at' => now()->subDays($createdDaysAgo),
        'updated_at' => now()->subDays($createdDaysAgo),
    ]);

    return $id;
}

test('prune removes old read rows and very old unread rows and keeps recent ones', function () {
    $oldRead = pruneTestRow(40, 45);
    $recentRead = pruneTestRow(5, 60);
    $veryOldUnread = pruneTestRow(null, 100);
    $recentUnread = pruneTestRow(null, 50);

    expect(Artisan::call('cms:notifications-prune'))->toBe(0);

    expect(DB::table('notifications')->pluck('id')->all())->toEqualCanonicalizing([$recentRead, $recentUnread])
        ->and(DB::table('notifications')->pluck('id')->all())->not->toContain($oldRead, $veryOldUnread);
});

test('prune rejects options below 1', function () {
    $id = pruneTestRow(null, 100);

    expect(Artisan::call('cms:notifications-prune', ['--read-days' => 0]))->toBe(1)
        ->and(DB::table('notifications')->where('id', $id)->exists())->toBeTrue();
});
