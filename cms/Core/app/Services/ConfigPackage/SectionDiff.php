<?php

declare(strict_types=1);

namespace Cms\Core\Services\ConfigPackage;

/**
 * Immutable per-item comparison of a package section against the target site.
 *
 * Each item is `{ key, action: create|update|unchanged|blocked|skipped, reason, before, after }`. `before` and
 * `after` are present only for non-secret fields.
 */
final class SectionDiff
{
    public const ACTIONS = ['create', 'update', 'unchanged', 'blocked', 'skipped'];

    /**
     * @param list<array<string, mixed>> $items
     */
    public function __construct(public readonly array $items)
    {
    }

    /**
     * Count items by action (every action is present, possibly 0).
     *
     * @return array<string, int>
     */
    public function counts(): array
    {
        $counts = array_fill_keys(self::ACTIONS, 0);

        foreach ($this->items as $item) {
            $action = (string) ($item['action'] ?? '');
            if (isset($counts[$action])) {
                ++$counts[$action];
            }
        }

        return $counts;
    }

    /**
     * Find an item by key.
     *
     * @return array<string, mixed>|null
     */
    public function item(string $key): ?array
    {
        foreach ($this->items as $item) {
            if (($item['key'] ?? null) === $key) {
                return $item;
            }
        }

        return null;
    }

    /**
     * @return array{counts: array<string, int>, items: list<array<string, mixed>>}
     */
    public function toArray(): array
    {
        return ['counts' => $this->counts(), 'items' => $this->items];
    }
}
