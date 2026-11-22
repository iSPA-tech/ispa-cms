<?php

declare(strict_types=1);

namespace Cms\Core\Services\Backup;

use Cms\Core\Models\BackgroundTask;
use Cms\Core\Models\Backup\Backup;
use Cms\Core\Models\Backup\BackupCopy;
use InvalidArgumentException;
use RuntimeException;

/**
 * Guarantees a fresh backup before an irreversible operation, or stops it.
 *
 * Callers are the install flow and the configuration package import; existing data imports are unchanged. The
 * backup runs inline (no queue job) through BackupRunner, on the destinations marked for safety or the built-in local one.
 * A `partial` backup (at least one copy stored) passes; a failed dump or no stored copy stops the caller.
 */
class BackupGate
{
    /**
     * @param BackupRunner $runner Runner that takes the backup.
     */
    public function __construct(private readonly BackupRunner $runner)
    {
    }

    /**
     * Take a safety backup now and return it, or throw.
     *
     * @param string $reason Backup::REASON_PRE_INSTALL, Backup::REASON_PRE_IMPORT, Backup::REASON_PRE_PURGE or Backup::REASON_PRE_CORE_UPGRADE.
     * @param int|null $userId Starting user id.
     * @param BackgroundTask|null $task Task to record progress on.
     *
     * @throws InvalidArgumentException when the reason is not a safety reason
     * @throws BackupRequiredException when no copy of a verified dump could be stored
     */
    public function ensure(string $reason, ?int $userId, ?BackgroundTask $task = null): Backup
    {
        if (! in_array($reason, [Backup::REASON_PRE_INSTALL, Backup::REASON_PRE_IMPORT, Backup::REASON_PRE_PURGE, Backup::REASON_PRE_CORE_UPGRADE], true)) {
            throw new InvalidArgumentException('Only safety backup reasons are allowed.');
        }

        try {
            $backup = $this->runner->run($reason, $userId, [], $task);
        } catch (RuntimeException $e) {
            throw new BackupRequiredException(str_starts_with($e->getMessage(), 'BACKUP_') ? $e->getMessage() : null, $e);
        }

        $stored = $backup->copies()->where('status', BackupCopy::STATUS_STORED)->count();
        if ($backup->status === Backup::STATUS_FAILED || $stored === 0) {
            throw new BackupRequiredException($backup->error_code);
        }

        return $backup;
    }
}
