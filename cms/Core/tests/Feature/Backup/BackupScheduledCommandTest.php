<?php

declare(strict_types=1);

use Cms\Core\Jobs\Backup\RunBackupJob;
use Cms\Core\Models\Backup\Backup;
use Cms\Core\Models\Backup\BackupCopy;
use Cms\Core\Models\Backup\BackupDestination;
use Cms\Core\Services\Backup\OAuth\OAuthConnector;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;

uses(Cms\Core\Tests\TestCase::class, RefreshDatabase::class);

function schedDest(array $attrs = []): BackupDestination
{
    static $n = 0;
    $n++;

    return BackupDestination::query()->create($attrs + ['name' => 'Dest ' . $n, 'driver' => 'local', 'config' => ['root' => '/tmp/x' . $n], 'retention_days' => 3, 'schedule' => 'daily@02:00']);
}

function schedCopy(BackupDestination $dest, string $createdAtUtc): void
{
    $backup = Backup::query()->create(['reason' => Backup::REASON_SCHEDULED, 'status' => Backup::STATUS_SUCCEEDED, 'db_driver' => 'sqlite', 'db_name' => 'db', 'format' => 'sqlite.gz']);
    $backup->forceFill(['created_at' => $createdAtUtc])->save();
    BackupCopy::query()->create(['backup_id' => $backup->id, 'destination_id' => $dest->id, 'path' => 'p', 'status' => BackupCopy::STATUS_STORED]);
}

beforeEach(function (): void {
    $this->tz = date_default_timezone_get();
    config(['app.timezone' => 'UTC']);
    date_default_timezone_set('UTC');
    Cache::flush();
    Queue::fake();
    config([
        'core.backup.job_timeout' => 840,
        'core.backup.queue' => 'backups',
        'queue.default' => 'redis',
        'queue.connections.redis.retry_after' => 900,
    ]);
    Carbon::setTestNow(Carbon::parse('2026-11-05 19:00:00', 'UTC')); // 02:00 on 2026-11-06 in Ho Chi Minh
});

afterEach(function (): void {
    Carbon::setTestNow();
    date_default_timezone_set($this->tz);
});

it('dispatches exactly when the VN time reaches the schedule', function (): void {
    $dest = schedDest();

    Carbon::setTestNow(Carbon::parse('2026-11-05 18:59:00', 'UTC'));
    $this->artisan('cms:backup-scheduled')->assertExitCode(0);
    Queue::assertNothingPushed();

    Carbon::setTestNow(Carbon::parse('2026-11-05 19:00:00', 'UTC'));
    $this->artisan('cms:backup-scheduled')->assertExitCode(0);
    Queue::assertPushed(RunBackupJob::class, 1);
    Queue::assertPushed(RunBackupJob::class, fn (RunBackupJob $j): bool => $j->reason === 'scheduled' && $j->destinationIds === [$dest->id] && $j->userId === null);
});

it('runs at most once per destination per VN day', function (): void {
    schedDest();
    $this->artisan('cms:backup-scheduled')->assertExitCode(0);
    $this->artisan('cms:backup-scheduled')->assertExitCode(0);
    Queue::assertPushed(RunBackupJob::class, 1);

    // The next VN day is due again.
    Carbon::setTestNow(Carbon::parse('2026-11-06 19:00:00', 'UTC'));
    $this->artisan('cms:backup-scheduled')->assertExitCode(0);
    Queue::assertPushed(RunBackupJob::class, 2);
});

it('skips a destination that already has a scheduled copy today in VN time', function (): void {
    $dest = schedDest();
    schedCopy($dest, '2026-11-05 19:30:00'); // 02:30 VN today
    Carbon::setTestNow(Carbon::parse('2026-11-05 20:00:00', 'UTC'));
    $this->artisan('cms:backup-scheduled')->assertExitCode(0);
    Queue::assertNothingPushed();
});

it('is not blocked by a scheduled copy from yesterday in VN time', function (): void {
    $dest = schedDest();
    schedCopy($dest, '2026-11-05 16:59:00'); // 23:59 VN yesterday
    $this->artisan('cms:backup-scheduled')->assertExitCode(0);
    Queue::assertPushed(RunBackupJob::class, 1);
});

it('serves every destination due together with one job', function (): void {
    $a = schedDest();
    $b = schedDest(['schedule' => 'daily@01:30']);
    schedDest(['schedule' => 'daily@23:00']); // not due yet
    $this->artisan('cms:backup-scheduled')->assertExitCode(0);
    Queue::assertPushed(RunBackupJob::class, 1);
    Queue::assertPushed(RunBackupJob::class, fn (RunBackupJob $j): bool => $j->destinationIds === [$a->id, $b->id]);
});

it('skips disabled, reconnect-needed, unscheduled and malformed destinations', function (): void {
    schedDest(['enabled' => false]);
    schedDest(['last_error_code' => OAuthConnector::RECONNECT]);
    schedDest(['schedule' => null]);
    schedDest(['schedule' => 'daily@25:00']);
    schedDest(['schedule' => 'hourly']);
    $this->artisan('cms:backup-scheduled')->assertExitCode(0);
    Queue::assertNothingPushed();
});

it('logs a refusal once per day, sets no marker and still exits 0', function (): void {
    schedDest();
    config(['queue.default' => 'sync']);
    Log::spy();

    $this->artisan('cms:backup-scheduled')->expectsOutput('BACKUP_QUEUE_SYNC')->assertExitCode(0);
    $this->artisan('cms:backup-scheduled')->assertExitCode(0);

    Queue::assertNothingPushed();
    Log::shouldHaveReceived('warning')->once()->with('Scheduled backup refused.', ['code' => 'BACKUP_QUEUE_SYNC']);

    // No marker was set, so a fixed queue config dispatches the same day.
    config(['queue.default' => 'redis']);
    $this->artisan('cms:backup-scheduled')->assertExitCode(0);
    Queue::assertPushed(RunBackupJob::class, 1);
});

it('registers the command every 15 minutes in the kernel', function (): void {
    $event = collect(app(Schedule::class)->events())->first(fn ($e): bool => str_contains((string) $e->command, 'cms:backup-scheduled'));
    expect($event)->not->toBeNull()->and($event->expression)->toBe('*/15 * * * *');
});
