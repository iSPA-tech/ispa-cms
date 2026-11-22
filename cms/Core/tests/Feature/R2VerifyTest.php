<?php

use Cms\Core\Models\UploadR2Object;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

uses(\Cms\Core\Tests\TestCase::class);

beforeEach(function () {
    Schema::dropIfExists('upload_r2_objects');
    Schema::create('upload_r2_objects', function (Blueprint $table) {
        $table->id();
        $table->string('relative_path');
        $table->string('status');
        $table->string('source_sha256');
        $table->unsignedBigInteger('source_size');
        $table->timestamp('verified_at')->nullable();
        $table->timestamp('head_checked_at')->nullable();
        $table->text('last_error')->nullable();
        $table->timestamps();
    });
});

it('verifies the synced backlog despite an old verified timestamp and respects the prefix', function () {
    $rows = [];
    foreach (['image/2026/07/a.jpg', 'image/2026/08/a.jpg'] as $path) {
        $rows[] = UploadR2Object::create([
            'relative_path' => $path, 'status' => 'synced',
            'source_sha256' => hash('sha256', 'bytes'), 'source_size' => 5,
            'verified_at' => now()->subDays(30),
        ]);
    }
    $client = Mockery::mock();
    $client->shouldReceive('getObject')->once()->with(['Bucket' => 'test', 'Key' => $rows[0]->relative_path])
        ->andReturn(['Body' => Utils::streamFor('bytes'), 'ContentLength' => 5]);
    $disk = Mockery::mock();
    $disk->shouldReceive('getClient')->andReturn($client);
    Storage::shouldReceive('disk')->with('r2')->andReturn($disk);
    config(['filesystems.disks.r2.bucket' => 'test']);

    $this->artisan('upload:r2-verify --mode=hash --only-unverified --prefix=image/2026/07/')
        ->assertSuccessful();

    expect($rows[0]->fresh()->status)->toBe('verified')
        ->and($rows[1]->fresh()->status)->toBe('synced');
});

it('does not let include-failed bypass the checkpoint or prefix', function () {
    foreach (['image/2026/07/old.jpg', 'image/2026/08/other.jpg'] as $path) {
        UploadR2Object::create([
            'relative_path' => $path, 'status' => 'failed',
            'source_sha256' => hash('sha256', 'bytes'), 'source_size' => 5,
        ]);
    }
    $client = Mockery::mock();
    $client->shouldNotReceive('headObject');
    $disk = Mockery::mock();
    $disk->shouldReceive('getClient')->andReturn($client);
    Storage::shouldReceive('disk')->with('r2')->andReturn($disk);

    $this->artisan('upload:r2-verify --include-failed --after-id=1 --prefix=image/2026/07/')
        ->assertSuccessful();
});

it('schedules bounded backlog verification and eviction', function () {
    config(['filesystems.disks.r2.key' => 'test', 'filesystems.disks.r2.delete_local_after_sync' => true]);
    $schedule = new \Illuminate\Console\Scheduling\Schedule;
    $method = new ReflectionMethod(\App\Console\Kernel::class, 'schedule');
    $method->invoke(app(\Illuminate\Contracts\Console\Kernel::class), $schedule);
    $events = collect($schedule->events());
    $verify = $events->first(fn ($event) => str_contains($event->command ?? '', '--only-unverified'));
    $evict = $events->first(fn ($event) => str_contains($event->command ?? '', 'upload:r2-evict-local'));

    expect($verify->expression)->toBe('*/5 * * * *')
        ->and($verify->command)->toContain('--mode=hash', '--max-runtime=240')
        ->and($evict->expression)->toBe('*/10 * * * *')
        ->and($evict->command)->toContain('--days=7', '--max-runtime=240');
});

it('never verifies remote bytes that differ despite the same size', function () {
    $row = UploadR2Object::create([
        'relative_path' => 'image/a.jpg', 'status' => 'synced',
        'source_sha256' => hash('sha256', 'bytes'), 'source_size' => 5,
    ]);
    $client = Mockery::mock();
    $client->shouldReceive('getObject')->once()
        ->andReturn(['Body' => Utils::streamFor('other'), 'ContentLength' => 5]);
    $disk = Mockery::mock();
    $disk->shouldReceive('getClient')->andReturn($client);
    Storage::shouldReceive('disk')->with('r2')->andReturn($disk);

    $this->artisan('upload:r2-verify --mode=hash --only-unverified')->assertFailed();

    expect($row->fresh()->status)->toBe('failed')->and($row->fresh()->verified_at)->toBeNull();
});
