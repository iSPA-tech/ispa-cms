<?php

declare(strict_types=1);

namespace App\Contracts\Widget;

use App\Contracts\Widget\DTO\WidgetMutationUsageSummary;

/**
 * Root mutation-guard contract. The bound default (App\Support\Widget\
 * LegacyWidgetMutationGuard) permits ordinary unmarked legacy rows and
 * protects Core-owned mutation-lock markers through
 * WidgetMutationLockGateway. cms/Widget rebinds this to
 * CompositeWidgetMutationGuard in boot(); the imported-graph lock
 * protection remains active even if cms/Widget is later disabled, because
 * the default keeps consulting the same lock gateway.
 *
 * TASK 16 CONTRACT EVOLUTION (disclosed, spec-review pending): adds
 * {@see self::usageSummaryFor()} so Core's WidgetController/
 * WidgetThemeController destroy/update paths can surface an ACTIONABLE 409
 * (architecture.md "Foreign keys and deletion": "Deleting or archiving a
 * Widget Definition or Theme referenced by any retained Instance Version
 * returns 409 with usage summary") without ever querying a module-owned
 * table directly -- this contract stays the ONE seam Core depends on. The
 * bound default (module off / not yet rebound) always returns null (SAFE
 * "no usage" default, Achievements precedent): managed module disable is
 * separately forbidden while any retained reference exists (Task 22's
 * future responsibility, architecture.md lines 67/839), so a module-off
 * false-negative here can never coincide with a real retained reference
 * actually existing.
 */
interface WidgetMutationGuard
{
    /**
     * Whether the legacy row identified by ($table, $id) may currently be
     * mutated (updated/deleted). Always false for a row carrying an active
     * server-owned safe-import lock marker.
     */
    public function permitsMutation(string $table, int $id): bool;

    /**
     * Safe, actionable usage summary for the legacy row identified by
     * ($table, $id), or null when nothing currently retains a reference to
     * it (including for any $table this guard does not recognize).
     */
    public function usageSummaryFor(string $table, int $id): ?WidgetMutationUsageSummary;
}
