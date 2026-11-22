<?php

declare(strict_types=1);

namespace App\Support\Modules;

use App\Contracts\Modules\ModuleLifecycleBarrier;
use Cms\Core\Services\Modules\Install\ModuleVersionConstraint;
use Nwidart\Modules\Facades\Module as ModuleFacade;
use Nwidart\Modules\Module;

/**
 * Core/root-owned managed module disable transition, shared verbatim by
 * the admin ModuleController disable path and the sanctioned
 * `cms:module-disable` command (adr-widget-module-boundary.md decisions
 * 5-6): registered disable guards, then the pre-existing dependent-module
 * predicate, then the status change — all evaluated inside ONE
 * ModuleLifecycleBarrier acquisition, never check-then-disable across two
 * acquisitions.
 *
 * The generic layer never invents module-specific conflict codes: a
 * guard-blocked disable surfaces the registered guard's own blocking code
 * (e.g. the Widget module's guard supplies WIDGET_MODULE_IN_USE when it
 * registers in Task 16), and the additive dependent-module predicate uses
 * the module-neutral MODULE_HAS_ACTIVE_DEPENDENTS.
 */
final class ManagedModuleDisabler
{
    public const CODE_HAS_ACTIVE_DEPENDENTS = 'MODULE_HAS_ACTIVE_DEPENDENTS';

    public function __construct(
        private readonly ModuleDisableGuardRegistry $guards,
        private readonly ModuleLifecycleBarrier $barrier,
    ) {
    }

    public function hasGuardsFor(string $moduleName): bool
    {
        return $this->guards->hasGuardsFor($moduleName);
    }

    /**
     * Names of enabled modules whose module.json requires $moduleName —
     * the pre-existing dependent-module predicate. ADR decision 5: the
     * retained-reference guard registry is ADDITIVE to this characterized
     * check, never a replacement for it.
     *
     * @return list<string>
     */
    public function activeDependentsFor(string $moduleName): array
    {
        return collect(ModuleFacade::all())
            ->filter(fn (Module $candidate) => $candidate->isEnabled())
            ->filter(function (Module $candidate) use ($moduleName) {
                // List form ["core"] or object form {"core": ">=2026.11.0,<2027.0.0"}; names compared without case
                // (`questionbank` requires the module `QuestionBank`,).
                return ModuleVersionConstraint::constraintFor((array) $candidate->get('requires', []), $moduleName) !== null;
            })
            ->map(fn (Module $candidate) => $candidate->getName())
            ->values()
            ->all();
    }

    /**
     * Attempt the guarded disable transition.
     *
     * @return string|null null when the module was disabled. Otherwise a
     *         stable conflict code: the barrier's own refusal code, the
     *         FIRST registered guard's blocking code, or
     *         MODULE_HAS_ACTIVE_DEPENDENTS.
     */
    public function disable(string $moduleName, bool $maintenanceConfirmed = false): ?string
    {
        return $this->barrier->transition($moduleName, function () use ($moduleName) {
            $blocking = $this->guards->evaluate($moduleName);

            if ($blocking !== []) {
                return $blocking[0];
            }

            if ($this->activeDependentsFor($moduleName) !== []) {
                return self::CODE_HAS_ACTIVE_DEPENDENTS;
            }

            ModuleFacade::disable($moduleName);

            return null;
        }, $maintenanceConfirmed);
    }
}
