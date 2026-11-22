<?php

declare(strict_types=1);

namespace Cms\Core\Services\ConfigPackage;

/**
 * Immutable outcome of a section apply: counts and per-item outcomes (no secret values).
 */
final class ApplyResult
{
    /**
     * @param int                        $updated
     * @param int                        $unchanged
     * @param int                        $skipped
     * @param int                        $blocked
     * @param list<array<string, mixed>> $items   Per item `{ key, outcome: updated|unchanged|skipped|blocked, reason }`.
     */
    public function __construct(
        public readonly int $updated,
        public readonly int $unchanged,
        public readonly int $skipped,
        public readonly int $blocked,
        public readonly array $items = [],
    ) {
    }

    /**
     * @return array{updated: int, unchanged: int, skipped: int, blocked: int, items: list<array<string, mixed>>}
     */
    public function toArray(): array
    {
        return [
            'updated' => $this->updated,
            'unchanged' => $this->unchanged,
            'skipped' => $this->skipped,
            'blocked' => $this->blocked,
            'items' => $this->items,
        ];
    }
}
