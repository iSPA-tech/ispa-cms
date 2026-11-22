<?php

declare(strict_types=1);

namespace App\Contracts\Modules;

/**
 * Core/root-owned cross-process transition barrier (adr-widget-module-
 * boundary.md decisions 6-7). The same named barrier wraps managed
 * disable, Instance-Version creation, destructive Definition/Theme
 * mutation, and (post-Chunk-4) Safe Import store. Acquiring the disable
 * section is followed by guard evaluation and status change before
 * release — check-then-write is never split across two acquisitions.
 *
 * As of this task, no cross-process fencing/lease adapter is proven in
 * this repository (Task 5a evidence, gap G-013), so the bound default is
 * MaintenanceOnlyModuleLifecycleBarrier, never an in-process mutex.
 */
interface ModuleLifecycleBarrier
{
    /**
     * Attempt the named module transition.
     *
     * @param callable(): (string|null) $onAcquired Runs while the barrier
     *        is held; return null to signal success, or a stable conflict
     *        code (e.g. "WIDGET_MODULE_IN_USE") to signal a caller-detected
     *        conflict without letting the barrier retry.
     * @param bool $maintenanceConfirmed Operator-confirmed drained
     *        maintenance window, for callers (the sanctioned CLI) that
     *        cannot rely solely on automatic down-for-maintenance detection.
     *
     * @return string|null null on full success (the callback ran and
     *         returned null); a stable conflict code otherwise. A barrier
     *         that refuses to run $onAcquired at all returns its own
     *         conflict code (e.g. "WIDGET_MODULE_MAINTENANCE_REQUIRED")
     *         without ever invoking the callback.
     */
    public function transition(string $moduleName, callable $onAcquired, bool $maintenanceConfirmed = false): ?string;
}
