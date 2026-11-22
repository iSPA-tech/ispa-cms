<?php

declare(strict_types=1);

namespace App\Contracts\Modules;

/**
 * Generic, Widget-agnostic retained-reference predicate for managed module
 * disable (adr-widget-module-boundary.md decision 5). A module registers
 * one or more guard instances into ModuleDisableGuardRegistry (typically
 * from its ServiceProvider::boot()); Core never imports the registering
 * module's classes to do so — only this contract.
 */
interface ModuleDisableGuard
{
    /**
     * Name of the module this guard protects (Nwidart module name, e.g.
     * "Widget").
     */
    public function moduleName(): string;

    /**
     * Evaluate whether the module may be disabled right now.
     *
     * @return string|null null permits disable; a stable machine-readable
     *         reason code (e.g. "WIDGET_MODULE_IN_USE") blocks it.
     */
    public function evaluate(): ?string;
}
