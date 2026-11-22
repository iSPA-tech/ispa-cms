<?php

declare(strict_types=1);

namespace App\Support\Modules;

use App\Contracts\Modules\ModuleDisableGuard;
use Illuminate\Support\Str;

/**
 * Root registry of ModuleDisableGuard instances, bound as a singleton in
 * AppServiceProvider. Both the Core admin ModuleController and the
 * sanctioned `cms:module-disable` command query this registry before
 * changing module state; a module with zero registered guards is
 * unaffected — the caller preserves its existing behavior exactly.
 */
final class ModuleDisableGuardRegistry
{
    /** @var list<ModuleDisableGuard> */
    private array $guards = [];

    public function register(ModuleDisableGuard $guard): void
    {
        $this->guards[] = $guard;
    }

    /**
     * Whether any guard has registered for this module name (case-
     * insensitive). Distinct from `evaluate()` being empty: a module can
     * have a registered guard that currently permits disable.
     */
    public function hasGuardsFor(string $moduleName): bool
    {
        foreach ($this->guards as $guard) {
            if (Str::lower($guard->moduleName()) === Str::lower($moduleName)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string> blocking reason codes for $moduleName; an empty
     *         array means every registered guard (if any) currently permits.
     */
    public function evaluate(string $moduleName): array
    {
        $blocking = [];

        foreach ($this->guards as $guard) {
            if (Str::lower($guard->moduleName()) !== Str::lower($moduleName)) {
                continue;
            }

            $reason = $guard->evaluate();

            if ($reason !== null) {
                $blocking[] = $reason;
            }
        }

        return $blocking;
    }
}
