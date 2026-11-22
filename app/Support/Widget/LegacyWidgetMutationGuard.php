<?php

declare(strict_types=1);

namespace App\Support\Widget;

use App\Contracts\Widget\DTO\WidgetMutationUsageSummary;
use App\Contracts\Widget\WidgetMutationGuard;
use App\Contracts\Widget\WidgetMutationLockGateway;

/**
 * Bound default for WidgetMutationGuard. Permits mutation of ordinary
 * unmarked legacy rows and always denies mutation of a row carrying an
 * active server-owned safe-import lock marker (checked through
 * WidgetMutationLockGateway). This protection stays active even if
 * cms/Widget is later disabled, because the gateway default is
 * independent of module state.
 *
 * TASK 16: {@see self::usageSummaryFor()} always returns null -- this
 * default has no knowledge of `cms/Widget` retained Instance Version
 * references (module off, or cms/Widget has not yet rebound
 * CompositeWidgetMutationGuard in its boot()). See the WidgetMutationGuard
 * interface docblock ("TASK 16 CONTRACT EVOLUTION") for why this
 * module-off false-negative is safe.
 */
final class LegacyWidgetMutationGuard implements WidgetMutationGuard
{
    public function __construct(private readonly WidgetMutationLockGateway $lockGateway)
    {
    }

    public function permitsMutation(string $table, int $id): bool
    {
        return !$this->lockGateway->isLocked($table, $id);
    }

    public function usageSummaryFor(string $table, int $id): ?WidgetMutationUsageSummary
    {
        return null;
    }
}
