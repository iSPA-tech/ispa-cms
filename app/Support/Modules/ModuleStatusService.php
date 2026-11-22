<?php

declare(strict_types=1);

namespace App\Support\Modules;

use App\Jobs\Modules\RefreshRuntimeCachesJob;
use App\Models\User;
use App\Support\Modules\Exceptions\CoreModuleCannotBeDisabled;
use Cms\Core\Events\AuditLogEvent;
use Nwidart\Modules\Facades\Module as ModuleFacade;

/**
 * Single write path for a module status change.
 *
 * Refuses to disable Core, writes the status through nwidart (the
 * configured activator: file or database), records an audit log entry and
 * queues RefreshRuntimeCachesJob so cached config/route/event tables follow
 * the new module set and long-running workers reload.
 *
 * Dependency checks, disable guards and activation tasks stay with the
 * callers (ModuleController, cms:module-status set).
 *
 * @package App\Support\Modules
 * @author  iSPA Team
 * @since   2026-11-06
 */
class ModuleStatusService
{
    /**
     * Enable or disable a module.
     *
     * @param  string     $name     Module name as nwidart knows it (e.g. "Widget").
     * @param  bool       $enabled  True to enable, false to disable.
     * @param  User|null  $by       Acting user; null for console callers.
     * @param  string     $reason   Short reason stored in the audit entry (e.g. "admin", "cli").
     * @param  bool       $refreshCaches False when the caller refreshes the caches itself (module installer and uninstaller).
     * @return void
     *
     * @throws CoreModuleCannotBeDisabled When asked to disable Core.
     *
     * @author  iSPA Team
     * @since   2026-11-06
     */
    public function change(string $name, bool $enabled, ?User $by, string $reason, bool $refreshCaches = true): void
    {
        if (! $enabled && strcasecmp($name, 'Core') === 0) {
            throw new CoreModuleCannotBeDisabled('disable');
        }

        if ($enabled) {
            ModuleFacade::enable($name);
        } else {
            ModuleFacade::disable($name);
        }

        event(new AuditLogEvent(
            $enabled ? 'module.enabled' : 'module.disabled',
            ['module' => $name, 'reason' => $reason],
            'module_status',
            0,
            null,
            null,
            (int) ($by?->id ?? 0),
        ));

        // The module installer and uninstaller refresh the caches themselves after their own artisan steps. A queued
        // refresh would rebuild the route cache (clear, then write) while those steps boot, and a step that boots in
        // that gap fails on the missing routes file (first hub install on a dev site, 2026-11-12).
        if ($refreshCaches) {
            RefreshRuntimeCachesJob::dispatch();
        }
    }
}
