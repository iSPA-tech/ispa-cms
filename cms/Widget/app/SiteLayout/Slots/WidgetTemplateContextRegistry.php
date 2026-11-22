<?php

declare(strict_types=1);

namespace Cms\Widget\SiteLayout\Slots;

use App\Contracts\Widget\WidgetTemplateContextRegistrar;
use InvalidArgumentException;

/**
 * Module-owned code-registered Template Context registry (plan Chunk 2 Task
 * 15, lines 848-884): which Slot keys a registered template key exposes.
 * Implements the root {@see WidgetTemplateContextRegistrar} contract frozen
 * by Task 7 -- its single method, `registerTemplateContext(string
 * $templateKey, array $slotKeys): void`, IS the full declaration shape, so
 * unlike Slot/Target Context there is no separate thin-vs-rich split and no
 * co-located declaration value object; a plain `templateKey => slotKeys`
 * map is the entire internal state.
 *
 * Deliberately holds NO dependency on {@see WidgetSlotRegistry}: the Task
 * 15 checklist names "unknown Template/Target Context keys" as the
 * rejection surface, not "unknown Slot keys" -- so this class does not
 * cross-validate its slotKeys against the Slot registry (that symmetric
 * check was considered and intentionally left out to avoid adding
 * unrequested validation surface; see WidgetTargetContextRegistry for the
 * Target-Context-references-Template-Context validation the checklist DOES
 * name). This keeps the class dependency-free and trivially proven to run
 * zero database queries.
 *
 * Duplicate/conflict rule: identical to WidgetSlotRegistry's (see that
 * class's docblock) -- a second registration of an already-used template
 * key is idempotent-OK only when its slot-key SET (order-independent) is
 * identical to the first; otherwise it throws.
 */
final class WidgetTemplateContextRegistry implements WidgetTemplateContextRegistrar
{
    /** @var array<string, list<string>> templateKey => slotKeys (as registered, insertion order) */
    private array $contexts = [];

    /**
     * @param list<string> $slotKeys
     * @throws InvalidArgumentException on a conflicting duplicate
     *         registration of the same template key (a different slot-key
     *         set than the one already registered).
     */
    public function registerTemplateContext(string $templateKey, array $slotKeys): void
    {
        $existing = $this->contexts[$templateKey] ?? null;

        if ($existing !== null) {
            if ($this->sortedUnique($existing) === $this->sortedUnique($slotKeys)) {
                return; // Identical slot-key set: idempotent-OK.
            }

            throw new InvalidArgumentException(
                "Template Context '{$templateKey}' is already registered with a conflicting slot key set."
            );
        }

        $this->contexts[$templateKey] = array_values($slotKeys);
    }

    public function isRegistered(string $templateKey): bool
    {
        return isset($this->contexts[$templateKey]);
    }

    /**
     * @return list<string>|null
     */
    public function slotKeysFor(string $templateKey): ?array
    {
        return $this->contexts[$templateKey] ?? null;
    }

    /**
     * @return list<string> every registered Template Context key.
     */
    public function keys(): array
    {
        return array_keys($this->contexts);
    }

    /**
     * @param list<string> $values
     * @return list<string>
     */
    private function sortedUnique(array $values): array
    {
        $unique = array_values(array_unique(array_map('strval', $values)));
        sort($unique);

        return $unique;
    }
}
