<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

declare(strict_types=1);

namespace Cms\Core\Services\Backup;

use Cms\Core\Models\BackgroundTask;
use Cms\Core\Models\Backup\Backup;
use Cms\Core\Models\Backup\BackupCopy;
use Cms\Core\Models\Backup\BackupDestination;
use Cms\Core\Services\BackgroundTasks\BackgroundTaskRecorder;
use Cms\Core\Services\Backup\Destinations\DestinationDisk;
use Cms\Core\Services\Backup\Destinations\ObjectKey;
use Cms\Core\Services\Backup\Dump\DumperFactory;
use Cms\Core\Services\Backup\Dump\DumpProcessRunner;
use Cms\Core\Services\Backup\Dump\DumpResult;
use Cms\Core\Services\Backup\Dump\FreeSpaceGuard;
use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Takes one verified dump of the site database and stores it on every chosen destination.
 *
 * One backup runs per site at a time (cache lock). A failing destination never stops the others (status `partial`); an
 * upload that may have left a partial object gets a `<key>.FAILED` marker, as the server script does. The staging file is
 * always deleted. Task messages are admin i18n keys; no secret or path is written to a task, a record or an alert.
 */
class BackupRunner
{
    /** Name of the built-in destination used for safety backups when none is marked for them. */
    public const BUILTIN_LOCAL_NAME = 'Built-in local';

    private const SAFETY_REASONS = [Backup::REASON_PRE_INSTALL, Backup::REASON_PRE_IMPORT, Backup::REASON_PRE_PURGE, Backup::REASON_PRE_CORE_UPGRADE];

    /** @var Closure(array<string, mixed>): void */
    private Closure $alert;

    /**
     * @param DumperFactory|null $dumpers Dumper factory (default factory when null).
     * @param FreeSpaceGuard|null $guard Free-space guard (default guard when null).
     * @param BackgroundTaskRecorder|null $recorder Task recorder (default recorder when null).
     * @param Closure|null $alert Receives the alert payload for a failed scheduled or safety backup; defaults to Telegram.
     * @param string|null $connection Database connection to dump (null = the default connection; the records always use the default).
     */
    public function __construct(
        private readonly ?DumperFactory $dumpers = null,
        private readonly ?FreeSpaceGuard $guard = null,
        private readonly ?BackgroundTaskRecorder $recorder = null,
        ?Closure $alert = null,
        private readonly ?string $connection = null,
    ) {
        $this->alert = $alert ?? static function (array $data): void {
            \App\Services\Logger\TelegramService::error($data);
        };
    }

    /**
     * Cache lock key of this site's backup lock.
     */
    public static function lockKey(): string
    {
        return 'core-backup:' . Backup::siteSlug();
    }

    /**
     * Run one backup and return its record (status succeeded, partial or failed).
     *
     * @param string $reason One of the Backup::REASON_* values.
     * @param int|null $userId Starting user id.
     * @param array<int, int> $destinationIds Destination ids; empty = all enabled (safety reasons: those marked for safety, else the built-in local one).
     * @param BackgroundTask|null $task Task to record progress on.
     *
     * @throws RuntimeException BACKUP_ALREADY_RUNNING when another backup of this site is running
     */
    public function run(string $reason, ?int $userId, array $destinationIds = [], ?BackgroundTask $task = null): Backup
    {
        $lock = Cache::lock(self::lockKey(), (int) config('core.backup.job_timeout') + 60);
        if (! $lock->get()) {
            throw new RuntimeException('BACKUP_ALREADY_RUNNING');
        }

        $staging = null;
        $backup = null;
        try {
            $connection = $this->connection ?? (string) config('database.default');
            $driver = (string) config("database.connections.{$connection}.driver");
            $backup = Backup::query()->create([
                'reason' => $reason,
                'status' => Backup::STATUS_RUNNING,
                'db_driver' => mb_substr($driver, 0, 20),
                'db_name' => mb_substr((string) config("database.connections.{$connection}.database"), 0, 128),
                'format' => $driver === 'pgsql' ? 'pgdump' : ($driver === 'sqlite' ? 'sqlite.gz' : 'sql.gz'),
                'background_task_uuid' => $task?->uuid,
                'started_by' => $userId,
                'started_at' => now(),
            ]);

            $destinations = $this->destinations($reason, $destinationIds);
            if ($destinations === []) {
                return $this->fail($backup, $task, 'BACKUP_NO_DESTINATION');
            }

            $this->progress($task, 10, 'text.backup.task.dump_started');
            $staging = rtrim((string) config('core.backup.staging_path'), '/') . '/backup-' . Str::uuid()->toString() . '.part';
            try {
                DumpProcessRunner::ensureDir(dirname($staging));
                ($this->guard ?? new FreeSpaceGuard())->ensure($connection);
                $dump = ($this->dumpers ?? new DumperFactory())->make($connection)->dump($connection, $staging);
            } catch (Throwable $e) {
                return $this->fail($backup, $task, self::codeOf($e, 'BACKUP_DUMP_FAILED'));
            }

            $backup->forceFill([
                'format' => $dump->format,
                'tables_expected' => $dump->tablesExpected,
                'tables_dumped' => $dump->tablesDumped,
                'size_bytes' => $dump->sizeBytes,
                'sha256' => $dump->sha256,
                'verified_at' => now(),
            ])->save();
            $this->progress($task, 60, 'text.backup.task.dump_done');

            $stored = 0;
            $count = count($destinations);
            foreach ($destinations as $i => $destination) {
                $this->progress($task, 60 + (int) floor(35 * $i / $count), 'text.backup.task.uploading', ['done' => $i, 'total' => $count]);
                if ($this->upload($backup, $destination, $dump, $staging)) {
                    $stored++;
                }
            }

            $status = $stored === $count ? Backup::STATUS_SUCCEEDED : ($stored > 0 ? Backup::STATUS_PARTIAL : Backup::STATUS_FAILED);
            $backup->forceFill([
                'status' => $status,
                'finished_at' => now(),
                'error_code' => $status === Backup::STATUS_SUCCEEDED ? null : ($stored > 0 ? 'BACKUP_PARTIAL' : 'BACKUP_ALL_DESTINATIONS_FAILED'),
            ])->save();
            $this->finishTask($task, $backup, $stored, $count);
            if ($status !== Backup::STATUS_SUCCEEDED) {
                $this->alertFailure($backup);
            }

            return $backup;
        } catch (Throwable $e) {
            if ($backup === null) {
                throw $e;
            }

            return $this->fail($backup, $task, 'BACKUP_UNEXPECTED');
        } finally {
            if ($staging !== null && is_file($staging)) {
                @unlink($staging);
            }
            $lock->release();
        }
    }

    /**
     * Resolve the destinations of this run.
     *
     * @param array<int, int> $ids
     * @return array<int, BackupDestination>
     */
    private function destinations(string $reason, array $ids): array
    {
        $query = BackupDestination::query()->where('enabled', true)->orderBy('id');
        if ($ids !== []) {
            $query->whereIn('id', $ids);
        } elseif (in_array($reason, self::SAFETY_REASONS, true)) {
            $query->where('use_for_safety', true);
        }
        $found = $query->get()->all();

        if ($found === [] && $ids === [] && in_array($reason, self::SAFETY_REASONS, true)) {
            $found = [BackupDestination::query()->firstOrCreate(
                ['name' => self::BUILTIN_LOCAL_NAME],
                ['driver' => 'local', 'config' => ['root' => (string) config('core.backup.local_root')], 'retention_days' => 2, 'lock_days' => 0, 'enabled' => true]
            )];
        }

        return $found;
    }

    /**
     * Store the staged dump on one destination; returns whether it was stored.
     */
    private function upload(Backup $backup, BackupDestination $destination, DumpResult $dump, string $staging): bool
    {
        $copy = null;
        $key = null;
        $disk = null;
        $handle = null;
        try {
            $disk = DestinationDisk::for($destination);
            $config = is_array($destination->config) ? $destination->config : [];
            $prefix = isset($config['prefix']) && is_string($config['prefix']) ? $config['prefix'] : null;
            $name = $backup->db_driver === 'sqlite' ? pathinfo((string) $backup->db_name, PATHINFO_FILENAME) : (string) $backup->db_name;
            $key = ObjectKey::fresh($disk, ObjectKey::build($name, $backup->reason, $dump->format, $prefix));

            $copy = BackupCopy::query()->create([
                'backup_id' => $backup->id,
                'destination_id' => $destination->id,
                'path' => $key,
                'status' => BackupCopy::STATUS_UPLOADING,
            ]);

            $handle = fopen($staging, 'rb');
            if ($handle === false) {
                throw new RuntimeException('BACKUP_UPLOAD_FAILED');
            }
            $disk->writeStream($key, $handle);
            $disk->put($key . '.sha256', $dump->sha256 . '  ' . basename($key) . "\n");
            if ((int) $disk->size($key) !== $dump->sizeBytes) {
                throw new RuntimeException('BACKUP_UPLOAD_SIZE_MISMATCH');
            }

            $copy->forceFill(['status' => BackupCopy::STATUS_STORED, 'size_bytes' => $dump->sizeBytes, 'uploaded_at' => now()])->save();
            $destination->forceFill(['last_success_at' => now(), 'last_error_code' => null])->save();

            return true;
        } catch (Throwable $e) {
            $code = self::codeOf($e, 'BACKUP_UPLOAD_FAILED');
            if ($copy !== null) {
                $copy->forceFill(['status' => BackupCopy::STATUS_FAILED, 'error_code' => $code])->save();
            }
            if ($disk !== null && $key !== null) {
                try {
                    $disk->put($key . '.FAILED', 'failed ' . now()->utc()->format('Y-m-d\TH:i:s\Z') . "\n");
                } catch (Throwable) {
                    // The destination is unreachable; nothing more to record.
                }
            }
            $destination->forceFill(['last_failure_at' => now(), 'last_error_code' => $code])->save();

            return false;
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }
        }
    }

    /**
     * Mark the backup and its task failed, alert when needed and return the backup.
     */
    private function fail(Backup $backup, ?BackgroundTask $task, string $code): Backup
    {
        $backup->forceFill([
            'status' => Backup::STATUS_FAILED,
            'error_code' => $code,
            'message' => $code,
            'finished_at' => now(),
        ])->save();
        if ($task !== null) {
            $this->recorder()->fail($task, 'text.backup.task.failed', ['code' => $code]);
        }
        $this->alertFailure($backup);

        return $backup;
    }

    /**
     * Close the task of a finished run.
     */
    private function finishTask(?BackgroundTask $task, Backup $backup, int $stored, int $total): void
    {
        if ($task === null) {
            return;
        }
        $this->recorder()->succeed($task, [
            'backup_uuid' => $backup->uuid,
            'status' => $backup->status,
            'destinations_stored' => $stored,
            'destinations_total' => $total,
            'size_bytes' => $backup->size_bytes,
        ]);
    }

    /**
     * Send the alert for a scheduled or safety backup that did not fully succeed. The payload holds no path and no secret.
     */
    private function alertFailure(Backup $backup): void
    {
        if ($backup->reason !== Backup::REASON_SCHEDULED && ! in_array($backup->reason, self::SAFETY_REASONS, true)) {
            return;
        }
        try {
            ($this->alert)([
                'message' => sprintf('Backup %s (%s) on %s', $backup->status, $backup->reason, Backup::siteSlug()),
                'backup' => $backup->uuid,
                'error_code' => (string) $backup->error_code,
            ]);
        } catch (Throwable) {
            // An alert failure must not change the backup result.
        }
    }

    /**
     * Record progress when a task is attached.
     *
     * @param array<string, mixed> $params
     */
    private function progress(?BackgroundTask $task, int $percent, string $message, array $params = []): void
    {
        if ($task !== null) {
            $this->recorder()->progress($task, $percent, $message, $params);
        }
    }

    /**
     * The task recorder in use.
     */
    private function recorder(): BackgroundTaskRecorder
    {
        return $this->recorder ?? new BackgroundTaskRecorder();
    }

    /**
     * Machine code at the start of an exception message (`BACKUP_X: detail`), else the fallback.
     */
    private static function codeOf(Throwable $e, string $fallback): string
    {
        return preg_match('/^(BACKUP_[A-Z0-9_]+)/', $e->getMessage(), $m) === 1 ? $m[1] : $fallback;
    }
}
