<?php

declare(strict_types=1);

namespace App\Support\Modules;

use Nwidart\Modules\Facades\Module;
use Throwable;

/**
 * Single read path for module enabled status.
 *
 * Delegates to the configured nwidart activator (file or database) so that no
 * application code needs to read modules_statuses.json directly.
 */
final class ModuleStatus
{
    /**
     * Whether the named module exists and is enabled.
     *
     * The lookup is case-insensitive. Unknown modules and any failure while
     * resolving the status yield false.
     *
     * @param string $name Module name, e.g. "Content".
     *
     * @return bool
     */
    public static function isEnabled(string $name): bool
    {
        try {
            $module = Module::find($name);

            return $module !== null && $module->isEnabled();
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Names of all enabled modules, as nwidart reports them, sorted ascending.
     *
     * Returns an empty list when the status cannot be resolved.
     *
     * @return list<string>
     */
    public static function enabledNames(): array
    {
        try {
            $names = array_map(
                static fn ($module): string => (string) $module->getName(),
                array_values(Module::allEnabled())
            );
        } catch (Throwable) {
            return [];
        }

        sort($names);

        return $names;
    }
}
