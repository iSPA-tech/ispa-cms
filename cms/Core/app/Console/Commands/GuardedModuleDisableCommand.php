<?php
/*
 * Copyright (c) 2026 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Console\Commands;

use App\Support\Modules\ManagedModuleDisabler;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Nwidart\Modules\Facades\Module as ModuleFacade;

/**
 * Sanctioned managed alternative to the raw vendor `module:disable`
 * command. Delegates to the same Core/root ManagedModuleDisabler the admin
 * API's ModuleController uses (guard registry + dependent-module predicate
 * + lifecycle barrier as ONE atomic transition), so a module with a
 * registered retained-reference guard (e.g. cms/Widget) can only be
 * disabled through the managed path — never through the unmanaged vendor
 * command or a direct modules_statuses.json edit.
 *
 * With no cross-process fencing/lease adapter proven in this repository
 * (see docs/architecture/adr-widget-module-boundary.md decision 7), the
 * bound ModuleLifecycleBarrier default only proceeds for a console caller
 * during a verified drained maintenance window: either the application is
 * genuinely down for maintenance, or the operator explicitly passes
 * --force-maintenance-confirmed after confirming a drained window by
 * other means (e.g. a deploy pipeline step).
 *
 * Usage: php artisan cms:module-disable {module} [--force-maintenance-confirmed]
 */
final class GuardedModuleDisableCommand extends Command
{
    protected $signature = 'cms:module-disable
        {module : Module name or alias to disable}
        {--force-maintenance-confirmed : Confirm an operator-verified drained maintenance window when automatic down-for-maintenance detection does not apply}';

    protected $description = 'Disable a CMS module through the managed disable-guard registry and lifecycle barrier (the sanctioned alternative to the raw vendor module:disable command).';

    public function handle(ManagedModuleDisabler $disabler): int
    {
        $moduleName = Str::studly((string) $this->argument('module'));

        if (ModuleFacade::find($moduleName) === null) {
            $this->error("Module not found: {$moduleName}");

            return self::FAILURE;
        }

        if ($moduleName === 'Core') {
            $this->error('The Core module can never be disabled.');

            return self::FAILURE;
        }

        // Preserve current behavior when no guard has registered for this
        // module: no barrier/maintenance-window friction — but the
        // dependent-module predicate still applies, exactly as the admin
        // endpoint has always enforced it for every module (the raw vendor
        // module:disable command never checked dependents; this sanctioned
        // path must not repeat that gap).
        if (!$disabler->hasGuardsFor($moduleName)) {
            $dependents = $disabler->activeDependentsFor($moduleName);

            if ($dependents !== []) {
                $this->error(sprintf(
                    'Disable blocked (%s): %s is required by %s',
                    ManagedModuleDisabler::CODE_HAS_ACTIVE_DEPENDENTS,
                    $moduleName,
                    implode(', ', $dependents),
                ));

                return self::FAILURE;
            }

            ModuleFacade::disable($moduleName);
            $this->info("Module disabled: {$moduleName}");

            return self::SUCCESS;
        }

        $conflict = $disabler->disable($moduleName, (bool) $this->option('force-maintenance-confirmed'));

        if ($conflict !== null) {
            $this->error("Disable blocked ({$conflict}): {$moduleName}");

            return self::FAILURE;
        }

        $this->info("Module disabled: {$moduleName}");

        return self::SUCCESS;
    }
}
