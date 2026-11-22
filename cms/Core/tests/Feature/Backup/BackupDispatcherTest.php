<?php

declare(strict_types=1);

use Cms\Core\Jobs\Backup\RunBackupJob;
use Cms\Core\Models\BackgroundTask;
use Cms\Core\Models\Backup\Backup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Cms\Core\Services\Backup\BackupDispatcher;

uses(Cms\Core\Tests\TestCase::class, RefreshDatabase::class);

beforeEach(function (): void {
    Queue::fake();
    config([
        'core.backup.job_timeout' => 840,
        'core.backup.queue' => 'backups',
        'queue.default' => 'redis',
        'queue.connections.redis.retry_after' => 900,
        'queue.connections.database.retry_after' => 90,
    ]);
});

it('creates the core.backup task and dispatches the job on the configured queue', function (): void {
    $task = (new BackupDispatcher())->dispatch(Backup::REASON_MANUAL, 7, [3]);

    expect($task->type)->toBe('core.backup')->and($task->module)->toBe('Core')
        ->and($task->status)->toBe(BackgroundTask::STATUS_QUEUED)->and($task->started_by)->toBe(7);
    Queue::assertPushedOn('backups', RunBackupJob::class, fn (RunBackupJob $job): bool => $job->taskUuid === $task->uuid
        && $job->reason === 'manual' && $job->userId === 7 && $job->destinationIds === [3] && $job->tries === 1 && $job->timeout === 840);
});

it('refuses when the connection retry_after is not above the job timeout', function (): void {
    config(['queue.default' => 'database']);
    expect(fn () => (new BackupDispatcher())->dispatch(Backup::REASON_MANUAL, null))->toThrow(RuntimeException::class, 'BACKUP_QUEUE_TIMEOUT_TOO_LOW');

    config(['queue.default' => 'redis', 'queue.connections.redis.retry_after' => 840]);
    expect(fn () => (new BackupDispatcher())->dispatch(Backup::REASON_MANUAL, null))->toThrow(RuntimeException::class, 'BACKUP_QUEUE_TIMEOUT_TOO_LOW');
    expect(BackgroundTask::query()->count())->toBe(0);
    Queue::assertNothingPushed();
});

it('refuses the sync queue', function (): void {
    config(['queue.default' => 'sync']);
    expect(fn () => (new BackupDispatcher())->dispatch(Backup::REASON_MANUAL, null))->toThrow(RuntimeException::class, 'BACKUP_QUEUE_SYNC');
    expect(BackgroundTask::query()->count())->toBe(0);
    Queue::assertNothingPushed();
});
