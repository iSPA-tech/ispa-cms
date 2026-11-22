<?php

declare(strict_types=1);

namespace App\Support\Modules;

use App\Contracts\Modules\ModuleLifecycleBarrier;
use Illuminate\Contracts\Foundation\Application;

/**
 * Bound default for ModuleLifecycleBarrier (adr-widget-module-boundary.md
 * decision 7). No cross-process fencing/lease adapter is proven in this
 * repository as of Task 5a (gap G-013), so this implementation is a
 * conservative freeze — NOT a claim that an in-process mutex is safe:
 *
 * - A caller that is not a console process is refused unconditionally,
 *   BEFORE the maintenance check. "Down for maintenance" alone does NOT
 *   prove the caller is the sanctioned drained-maintenance CLI:
 *   `php artisan down --secret=...` issues a bypass cookie that lets
 *   ordinary live HTTP requests through the maintenance middleware while
 *   the application is down, so a live admin HTTP request could otherwise
 *   reach a guarded transition during maintenance.
 * - A console caller may proceed only while the application is genuinely
 *   down for maintenance (`php artisan down`), or when the operator
 *   explicitly passes `$maintenanceConfirmed` (the sanctioned
 *   `cms:module-disable --force-maintenance-confirmed` flag) for drained
 *   maintenance windows where automatic detection is not reliable (e.g.
 *   a deploy pipeline step on a not-yet-activated release).
 *
 * DO NOT create app/Support/Modules/AtomicModuleLifecycleBarrier.php until
 * a future task records real Task-5a-grade evidence (owner token/fencing,
 * lease/renewal, timeout, backend-outage, crash behavior across
 * admin/CLI workers) — see docs/architecture/adr-widget-module-boundary.md
 * decision 7 and docs/evidence/widget-site-layout-preflight.md §7.
 */
final class MaintenanceOnlyModuleLifecycleBarrier implements ModuleLifecycleBarrier
{
    public const CODE_MAINTENANCE_REQUIRED = 'MODULE_MAINTENANCE_REQUIRED';

    public function __construct(private readonly Application $app)
    {
    }

    public function transition(string $moduleName, callable $onAcquired, bool $maintenanceConfirmed = false): ?string
    {
        // HTTP/queue-context callers are refused before the maintenance
        // check ever runs: only the sanctioned CLI under verified drained
        // maintenance may proceed, and the maintenance bypass cookie means
        // a live HTTP request can arrive while the application is down.
        if (!$this->app->runningInConsole()) {
            return self::CODE_MAINTENANCE_REQUIRED;
        }

        if (!$this->app->isDownForMaintenance() && !$maintenanceConfirmed) {
            return self::CODE_MAINTENANCE_REQUIRED;
        }

        return $onAcquired();
    }
}
