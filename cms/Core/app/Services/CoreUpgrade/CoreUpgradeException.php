<?php

namespace Cms\Core\Services\CoreUpgrade;

use RuntimeException;

/**
 * A core upgrade step failed or was refused. `errorCode` is a stable machine code (CORE_*); `details` holds plain
 * values the admin may show (e.g. `modules` for CORE_MODULE_NEEDS_UPDATE).
 */
final class CoreUpgradeException extends RuntimeException
{
    /**
     * @param array<string, mixed> $details
     */
    public function __construct(public readonly string $errorCode, string $message, public readonly ?string $backupUuid = null, public readonly array $details = [])
    {
        parent::__construct($message);
    }
}
