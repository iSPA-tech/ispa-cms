<?php

declare(strict_types=1);

namespace App\Support\Modules;

use App\Contracts\Modules\ModuleDeploymentPreflight;

/**
 * Root registry of ModuleDeploymentPreflight checks, bound as a singleton
 * in AppServiceProvider and consumed by `cms:deploy-preflight`
 * (DeployPreflightCommand). No module knowledge lives here; when nothing
 * is registered, `run()` returns an empty array and existing preflight
 * behavior is unchanged.
 */
final class ModuleDeploymentPreflightRegistry
{
    /** @var list<ModuleDeploymentPreflight> */
    private array $checks = [];

    public function register(ModuleDeploymentPreflight $check): void
    {
        $this->checks[] = $check;
    }

    /**
     * @return array<string, list<string>> failure descriptions keyed by
     *         checker name; an empty array means every registered check
     *         (if any) passed.
     */
    public function run(): array
    {
        $failures = [];

        foreach ($this->checks as $check) {
            $issues = $check->check();

            if ($issues !== []) {
                $failures[$check->name()] = $issues;
            }
        }

        return $failures;
    }
}
