<?php

declare(strict_types=1);

namespace App\Http\Controllers\V2\Admin\Concerns;

use App\Support\Modules\ModuleStatus;
use Illuminate\Support\Facades\Schema;

/**
 * Memoised, cached checks for module enablement and table existence.
 *
 * Mirrors the TrackController approach: each answer is memoised per request
 * and cached for one day so dashboards avoid repeated schema introspection.
 */
trait ChecksModuleAvailability
{
    /** @var array<string,bool> Per-request memo keyed by "m:<module>" / "t:<table>". */
    protected static array $availabilityMemo = [];

    /**
     * Determine whether a CMS module is enabled according to the module activator.
     *
     * @param string $module Module name, e.g. "Content".
     *
     * @return bool
     */
    protected function moduleIsEnabled(string $module): bool
    {
        return self::$availabilityMemo["m:$module"] ??= (bool) cache()->remember(
            'admin.track.schema.module.' . $module,
            86400,
            static function () use ($module): bool {
                return ModuleStatus::isEnabled($module);
            }
        );
    }

    /**
     * Determine whether a database table exists.
     *
     * @param string $table Table name.
     *
     * @return bool
     */
    protected function tableExists(string $table): bool
    {
        return self::$availabilityMemo["t:$table"] ??= (bool) cache()->remember(
            'admin.track.schema.' . $table,
            86400,
            static fn (): bool => Schema::hasTable($table)
        );
    }
}
