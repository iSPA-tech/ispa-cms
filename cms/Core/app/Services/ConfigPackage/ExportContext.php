<?php

declare(strict_types=1);

namespace Cms\Core\Services\ConfigPackage;

/**
 * Immutable input of a section export: which keys the section should include.
 *
 * Patterns are fnmatch-style (for example `general.*`). Until a section registry exists the caller
 * supplies the patterns directly.
 */
final class ExportContext
{
    /**
     * @param list<string> $keyPatterns fnmatch-style setting key patterns to include.
     */
    public function __construct(public readonly array $keyPatterns = [])
    {
    }

    /**
     * Tell whether a key matches at least one pattern.
     */
    public function matches(string $key): bool
    {
        foreach ($this->keyPatterns as $pattern) {
            if (fnmatch($pattern, $key)) {
                return true;
            }
        }

        return false;
    }
}
