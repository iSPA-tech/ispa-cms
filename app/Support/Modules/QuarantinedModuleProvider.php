<?php

declare(strict_types=1);

namespace App\Support\Modules;

use Illuminate\Support\ServiceProvider;

/**
 * No-op stand-in for a runtime module provider whose class cannot be loaded.
 *
 * RuntimeModuleAutoloader aliases the exact missing provider class to this
 * class so nwidart can register it without a fatal error and the rest of the
 * site boots. It registers and boots nothing.
 */
final class QuarantinedModuleProvider extends ServiceProvider
{
    /**
     * Register nothing.
     */
    public function register(): void
    {
    }

    /**
     * Boot nothing.
     */
    public function boot(): void
    {
    }
}
