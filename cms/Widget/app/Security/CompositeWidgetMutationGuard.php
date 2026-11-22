<?php

declare(strict_types=1);

namespace Cms\Widget\Security;

use App\Contracts\Widget\DTO\WidgetMutationUsageSummary;
use App\Contracts\Widget\WidgetMutationGuard;

/**
 * cms/Widget's rebind of the root {@see WidgetMutationGuard} contract
 * (plan Chunk 2 Task 16; the interface's own docblock: "cms/Widget rebinds
 * this to CompositeWidgetMutationGuard in boot()").
 *
 * Aggregates an ordered list of guards, each an independent, additive
 * denial reason over the same ($table, $id) input -- mirroring
 * `ModuleDisableGuardRegistry::evaluate()`'s established "any guard blocks"
 * aggregation shape, at Widget-module scope instead of the root registry's
 * cross-module scope. {@see RetainedWidgetReferenceMutationGuard} is
 * registered FIRST (this task's own file-list ordering instruction), ahead
 * of the pre-existing Safe-Import lock-marker guard
 * ({@see \App\Support\Widget\LegacyWidgetMutationGuard}) -- order matters
 * only for {@see self::usageSummaryFor()}, which returns the FIRST
 * non-null summary a guard produces (Safe-Import lock markers have no
 * usage-summary concept of their own, so in practice only the retained-
 * reference guard ever contributes one).
 */
final class CompositeWidgetMutationGuard implements WidgetMutationGuard
{
    /** @param list<WidgetMutationGuard> $guards */
    public function __construct(private readonly array $guards)
    {
    }

    public function permitsMutation(string $table, int $id): bool
    {
        foreach ($this->guards as $guard) {
            if (!$guard->permitsMutation($table, $id)) {
                return false;
            }
        }

        return true;
    }

    public function usageSummaryFor(string $table, int $id): ?WidgetMutationUsageSummary
    {
        foreach ($this->guards as $guard) {
            $summary = $guard->usageSummaryFor($table, $id);

            if ($summary !== null) {
                return $summary;
            }
        }

        return null;
    }
}
