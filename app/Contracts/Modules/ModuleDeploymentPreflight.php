<?php

declare(strict_types=1);

namespace App\Contracts\Modules;

/**
 * Generic, Widget-agnostic pre-traffic deployment check contract, run by
 * the existing `cms:deploy-preflight` command via
 * ModuleDeploymentPreflightRegistry. A module registers its own checker
 * (e.g. registry-compatibility, retained-provider-version) without Core
 * ever importing the registering module's classes.
 */
interface ModuleDeploymentPreflight
{
    public function name(): string;

    /**
     * @return list<string> human-readable failure descriptions; an empty
     *         array means this check passed.
     */
    public function check(): array;
}
