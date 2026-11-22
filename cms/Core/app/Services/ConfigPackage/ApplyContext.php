<?php

declare(strict_types=1);

namespace Cms\Core\Services\ConfigPackage;

/**
 * Immutable input of a section apply: per-item choices and the optional acting user.
 *
 * Choices map an item key to a boolean; `false` excludes the item. Items without an entry are applied.
 */
final class ApplyContext
{
    /**
     * @param array<string, bool> $choices Item key => apply (true) or exclude (false).
     * @param int|null            $userId  Acting user id, when known.
     */
    public function __construct(
        public readonly array $choices = [],
        public readonly ?int $userId = null,
    ) {
    }

    /**
     * Tell whether an item is chosen for apply (default: yes).
     */
    public function isChosen(string $itemKey): bool
    {
        return ($this->choices[$itemKey] ?? true) !== false;
    }
}
