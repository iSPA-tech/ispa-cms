<?php

declare(strict_types=1);

namespace Cms\Core\Services\Backup;

use RuntimeException;
use Throwable;

/**
 * Raised by BackupGate when the guaranteed backup could not be made. The message is the stable code; the underlying
 * code (a backup error code or BACKUP_ALREADY_RUNNING) is available through reason(). It never carries a path or secret.
 */
class BackupRequiredException extends RuntimeException
{
    public const CODE = 'BACKUP_REQUIRED_FAILED';

    /**
     * @param string|null $reason Underlying machine code, when known.
     * @param Throwable|null $previous Original exception, when the runner threw.
     */
    public function __construct(private readonly ?string $reason = null, ?Throwable $previous = null)
    {
        parent::__construct(self::CODE, 0, $previous);
    }

    /**
     * Underlying machine code (for example BACKUP_ALL_DESTINATIONS_FAILED), or null when unknown.
     */
    public function reason(): ?string
    {
        return $this->reason;
    }
}
