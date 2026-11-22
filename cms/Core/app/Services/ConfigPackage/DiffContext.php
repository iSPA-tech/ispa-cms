<?php

declare(strict_types=1);

namespace Cms\Core\Services\ConfigPackage;

/**
 * Immutable input of a section diff (dry run); carries the optional acting user for auditing.
 */
final class DiffContext
{
    /**
     * @param int|null $userId Acting user id, when known.
     */
    public function __construct(public readonly ?int $userId = null)
    {
    }
}
