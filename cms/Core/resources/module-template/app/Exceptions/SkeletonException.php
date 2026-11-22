<?php

declare(strict_types=1);

namespace Cms\Skeleton\Exceptions;

use RuntimeException;

/** A rule of the Skeleton module was broken; the message is safe to show to an operator. */
final class SkeletonException extends RuntimeException
{
    public static function invalidDays(int $days): self
    {
        return new self("--days must be 1 or more, got {$days}.");
    }
}
