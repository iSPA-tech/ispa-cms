<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

declare(strict_types=1);

namespace Cms\Core\Services\Backup;

use Aws\S3\Exception\S3Exception;
use Cms\Core\Models\Backup\Backup;
use Cms\Core\Models\Backup\BackupCopy;
use Cms\Core\Models\Backup\BackupDestination;
use Cms\Core\Services\Backup\Destinations\DestinationDisk;
use Closure;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Deletes expired backup copies, destination by destination, and nothing else.
 *
 * Candidates come only from `core_backup_copies` rows (the bucket or directory is never listed): status `stored`,
 * `failed` or `prune_refused`, older than the destination's `retention_days`. Age is measured from `uploaded_at`; a row
 * without it (a failed upload never sets it) falls back to `created_at`. Probes are pruned the same way. The newest
 * `stored` backup copy of a succeeded backup is always kept, whatever its age. For each candidate only the recorded path
 * and its `.sha256` / `.FAILED` sidecars are deleted, one `delete()` call each, then checked gone.
 *
 * All destinations are pruned, disabled ones included: their old copies expire just the same. A refusal (object lock,
 * or a delete that left the object in place) marks the row `prune_refused`; it stays a candidate for the next run.
 * The built-in destination's root is repointed first when it was left on another path (BackupRunner::healBuiltinRoot);
 * a destination that cannot be opened refuses its candidates and sends one alert a day.
 */
class RetentionPruner
{
    /** Copy statuses that may be pruned. */
    private const CANDIDATE_STATUSES = [BackupCopy::STATUS_STORED, BackupCopy::STATUS_FAILED, BackupCopy::STATUS_PRUNE_REFUSED];

    /** Extra days beyond `lock_days` after which a refusal means the bucket lock is longer than declared. */
    private const WARN_GRACE_DAYS = 2;

    /** @var Closure(array<string, mixed>): void */
    private Closure $alert;

    /** @var Closure(BackupDestination): Filesystem */
    private Closure $disks;

    /**
     * @param Closure|null $alert Receives the warning payload (no path, no secret); defaults to Telegram.
     * @param Closure|null $disks Resolves the filesystem of a destination; defaults to DestinationDisk::for().
     */
    public function __construct(?Closure $alert = null, ?Closure $disks = null)
    {
        $this->alert = $alert ?? static function (array $data): void {
            \App\Services\Logger\TelegramService::error($data);
        };
        $this->disks = $disks ?? static fn (BackupDestination $destination): Filesystem => DestinationDisk::for($destination);
    }

    /**
     * Prune expired copies on every destination (or only one).
     *
     * In dry-run mode nothing is deleted, no row is written and the destination is not contacted; `would_delete` lists
     * the recorded paths that would be deleted (their `.sha256` / `.FAILED` sidecars go with them).
     *
     * @return array<int, array{destination: string, uuid: string, pruned: int, refused: int, kept: int, would_delete: array<int, string>}>
     */
    public function prune(?BackupDestination $only = null, bool $dryRun = false): array
    {
        $query = BackupDestination::query()->orderBy('id');
        if ($only !== null) {
            $query->whereKey($only->id);
        }

        $results = [];
        foreach ($query->get() as $destination) {
            $results[] = $this->pruneDestination($destination, $dryRun);
        }

        return $results;
    }

    /**
     * Prune one destination.
     *
     * @return array{destination: string, uuid: string, pruned: int, refused: int, kept: int, would_delete: array<int, string>}
     */
    private function pruneDestination(BackupDestination $destination, bool $dryRun): array
    {
        $result = ['destination' => (string) $destination->name, 'uuid' => (string) $destination->uuid, 'pruned' => 0, 'refused' => 0, 'kept' => 0, 'would_delete' => []];
        $now = Carbon::now();
        $cutoff = $now->copy()->subDays(max(0, (int) $destination->retention_days));
        $protectedId = $this->lastGoodCopyId($destination);

        $live = BackupCopy::query()->where('destination_id', $destination->id)->whereIn('status', self::CANDIDATE_STATUSES);
        $candidates = (clone $live)
            ->where(static function (Builder $q) use ($cutoff): void {
                $q->where('uploaded_at', '<', $cutoff)
                    ->orWhere(static function (Builder $q) use ($cutoff): void {
                        $q->whereNull('uploaded_at')->where('created_at', '<', $cutoff);
                    });
            })
            ->when($protectedId !== null, static fn (Builder $q) => $q->whereKeyNot($protectedId))
            ->orderBy('id')
            ->get();
        $result['kept'] = (clone $live)->count() - $candidates->count();

        if ($candidates->isEmpty()) {
            return $result;
        }
        if ($dryRun) {
            $result['would_delete'] = $candidates->map(static fn (BackupCopy $c): string => (string) $c->path)->values()->all();

            return $result;
        }

        BackupRunner::healBuiltinRoot($destination);
        try {
            $disk = ($this->disks)($destination);
        } catch (Throwable $e) {
            $disk = null;
            $this->alertUnopened($destination, $e, $candidates->count());
        }

        $warnCode = null;
        foreach ($candidates as $copy) {
            $code = $disk === null ? 'BACKUP_PRUNE_FAILED' : $this->deleteCopy($disk, (string) $copy->path);
            $stamp = Carbon::now();
            if ($code === null) {
                $copy->forceFill(['status' => BackupCopy::STATUS_PRUNED, 'pruned_at' => $stamp, 'prune_attempted_at' => $stamp, 'error_code' => null])->save();
                $result['pruned']++;
                continue;
            }

            $copy->forceFill(['status' => BackupCopy::STATUS_PRUNE_REFUSED, 'prune_attempted_at' => $stamp, 'error_code' => $code])->save();
            $result['refused']++;
            $since = $copy->uploaded_at ?? $copy->created_at;
            // Only a lock refusal or an object that stayed in place points at a lock longer than declared.
            if ($warnCode === null && $since !== null && $code !== 'BACKUP_PRUNE_FAILED'
                && $since->lt($now->copy()->subDays((int) $destination->lock_days + self::WARN_GRACE_DAYS))) {
                $warnCode = $code;
            }
        }

        if ($warnCode !== null) {
            $this->warn($destination, $warnCode, $result['refused']);
        }

        return $result;
    }

    /**
     * Id of the newest stored backup copy of a succeeded backup on this destination (never pruned), if any.
     */
    private function lastGoodCopyId(BackupDestination $destination): ?int
    {
        $id = BackupCopy::query()
            ->where('destination_id', $destination->id)
            ->where('kind', BackupCopy::KIND_BACKUP)
            ->where('status', BackupCopy::STATUS_STORED)
            ->whereHas('backup', static fn (Builder $q) => $q->where('status', Backup::STATUS_SUCCEEDED))
            ->orderByRaw('COALESCE(uploaded_at, created_at) DESC')
            ->orderByDesc('id')
            ->value('id');

        return $id !== null ? (int) $id : null;
    }

    /**
     * Delete one recorded object and its two sidecars, then confirm they are gone.
     *
     * Returns null on success, else the error code. The object is deleted first; when that fails, the sidecars are
     * left in place so a kept object never loses its checksum.
     */
    private function deleteCopy(Filesystem $disk, string $path): ?string
    {
        $paths = [$path, $path . '.sha256', $path . '.FAILED'];
        try {
            foreach ($paths as $target) {
                $disk->delete($target);
            }
        } catch (Throwable $e) {
            return self::isLockRefusal($e) ? 'BACKUP_PRUNE_LOCKED' : 'BACKUP_PRUNE_FAILED';
        }

        try {
            foreach ($paths as $target) {
                if ($disk->exists($target)) {
                    return 'BACKUP_PRUNE_NOT_DELETED';
                }
            }
        } catch (Throwable) {
            return 'BACKUP_PRUNE_FAILED';
        }

        return null;
    }

    /**
     * Send at most one warning per destination per day: copies are still refused after lock_days + 2 days.
     */
    private function warn(BackupDestination $destination, string $code, int $refused): void
    {
        $this->alertOnce('core-backup-prune-warn:' . $destination->id, [
            'message' => sprintf('Backup prune refused on %s: lock longer than declared (%d days)', Backup::siteSlug(), (int) $destination->lock_days),
            'destination' => (string) $destination->uuid,
            'refused' => $refused,
            'error_code' => $code,
        ]);
    }

    /**
     * Send at most one alert per destination per day: the destination could not be opened, so its expired copies stay.
     *
     * Only an error code (`BACKUP_*`) is passed on, never the exception text, which can carry a path.
     */
    private function alertUnopened(BackupDestination $destination, Throwable $e, int $expired): void
    {
        $this->alertOnce('core-backup-prune-open:' . $destination->id, [
            'message' => sprintf('Backup prune cannot open a destination on %s: expired copies stay on disk', Backup::siteSlug()),
            'destination' => (string) $destination->uuid,
            'expired' => $expired,
            'error_code' => preg_match('/^BACKUP_[A-Z0-9_]+$/', $e->getMessage()) === 1 ? $e->getMessage() : 'BACKUP_PRUNE_FAILED',
        ]);
    }

    /**
     * Send the alert once per key and day.
     *
     * @param array<string, mixed> $payload
     */
    private function alertOnce(string $key, array $payload): void
    {
        if (! Cache::add($key . ':' . Carbon::now()->format('Y-m-d'), true, 60 * 60 * 26)) {
            return;
        }
        try {
            ($this->alert)($payload);
        } catch (Throwable) {
            // An alert failure must not change the prune result.
        }
    }

    /**
     * Whether a delete failure came from an object-lock policy (HTTP 409 / ObjectLockedByBucketPolicy).
     *
     * Same rule as DestinationTester::isLockRefusal(), which is private there.
     */
    private static function isLockRefusal(Throwable $e): bool
    {
        for ($cur = $e; $cur !== null; $cur = $cur->getPrevious()) {
            if ($cur instanceof S3Exception && $cur->getStatusCode() === 409) {
                return true;
            }
            if (str_contains($cur->getMessage(), 'ObjectLockedByBucketPolicy')) {
                return true;
            }
        }

        return false;
    }
}
