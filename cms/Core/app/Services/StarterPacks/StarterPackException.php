<?php

namespace Cms\Core\Services\StarterPacks;

use RuntimeException;

/**
 * Raised when a starter pack folder or its pack.json manifest is invalid.
 */
class StarterPackException extends RuntimeException
{
    /**
     * Build an exception for an invalid manifest field.
     *
     * @param string $pack  Pack key or folder name.
     * @param string $field Manifest field name.
     * @param string $rule  Plain English description of what is expected.
     */
    public static function invalidField(string $pack, string $field, string $rule): self
    {
        return new self(sprintf('Starter pack "%s": field "%s" %s', $pack, $field, $rule));
    }

    /**
     * Build an exception for a pack-level problem (missing or unreadable file, bad JSON).
     *
     * @param string $pack   Pack key or folder name.
     * @param string $reason Plain English reason.
     */
    public static function invalidPack(string $pack, string $reason): self
    {
        return new self(sprintf('Starter pack "%s": %s', $pack, $reason));
    }
}
