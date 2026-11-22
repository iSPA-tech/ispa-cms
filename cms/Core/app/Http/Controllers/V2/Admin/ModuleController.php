<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Http\Controllers\V2\Admin;

use App\Support\Modules\RuntimeModuleAutoloader;
use App\Support\Modules\ManagedModuleDisabler;
use App\Support\Modules\ModuleStatusService;
use Throwable;
use Cms\Core\Services\Modules\Install\EntitlementAttentionProvider;
use Cms\Core\Services\Platform\HubCredentials;
use Cms\Core\Services\Modules\Install\ModuleCatalog;
use Cms\Core\Services\Modules\Install\ModuleVersionConstraint;
use Cms\Core\Services\Modules\Install\RevocationAttentionProvider;
use Nwidart\Modules\Module;
use Illuminate\Support\Str;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Cms\Core\Http\Controllers\AdminController;
use Nwidart\Modules\Facades\Module as ModuleFacade;
use Cms\Core\Http\Requests\Module\UpdateModuleStatusRequest;

/**
 * List and toggle CMS module status via the admin API.
 *
 * Provides endpoints to list all registered nwidart/laravel-modules and
 * enable/disable them with dependency and dependent validation. Runs
 * migration and translation sync tasks on activation. Extends AdminController.
 *
 * @see \Nwidart\Modules\Facades\Module
 * @package Cms\Core\Http\Controllers\V2\Admin
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
final class ModuleController extends AdminController
{
    /** @var array<string, array{version: string, reason: ?string, revoked_at: ?string}> Stored revocation result, read once per request. */
    private ?array $revocations = null;

    private ?ModuleCatalog $catalog = null;

    /**
     * List all registered CMS modules with dependency and status metadata.
     *
     * @return mixed  Collection of module data
     */
    public function index()
    {
        $modules = $this->allModules()
            ->map(fn(Module $module) => $this->transformModule($module) + $this->quarantineFields($module) + $this->entitlementFields($module))
            ->values();

        return ires()->data($modules);
    }

    /**
     * Quarantine fields for the list only (the status response keeps its keys).
     *
     * @return array{quarantined: bool, quarantineCode: string|null, quarantineReason: string|null}
     */
    private function quarantineFields(Module $module): array
    {
        $quarantine = RuntimeModuleAutoloader::quarantined()[$module->getName()] ?? null;

        return [
            'quarantined' => $quarantine !== null,
            'quarantineCode' => $quarantine['code'] ?? null,
            'quarantineReason' => $quarantine['reason'] ?? null,
        ];
    }

    /**
     * Stored hub entitlement state for the list only (the status response keeps its keys).
     *
     * `entitlement` is null when the hub reported none (public module, older hub). `updates_unavailable` is true when
     * the state is expired or revoked: the site offers no update, and the module keeps running.
     *
     * @return array{entitlement: array{state: string, expires_at: ?string, grace_until: ?string}|null, updates_unavailable: bool}
     */
    private function entitlementFields(Module $module): array
    {
        $entitlement = EntitlementAttentionProvider::stateFor($module->getName());

        return [
            'entitlement' => $entitlement,
            'updates_unavailable' => $entitlement !== null && in_array($entitlement['state'], EntitlementAttentionProvider::BLOCKING_STATES, true),
        ];
    }

    private function allModules(): Collection
    {
        return collect(ModuleFacade::all())
            ->sortBy(function (Module $module) {
                $coreOrder = $module->getName() === 'Core' ? 0 : 1;
                $priority = (int)$module->get('priority', 0);

                return sprintf('%d-%05d-%s', $coreOrder, $priority, strtolower($module->getName()));
            })
            ->values();
    }

    private function transformModule(?Module $module): array
    {
        if ($module === null) {
            return [];
        }

        $composer = $module->json('composer.json');
        $dependencies = $this->requiredNames($module)->values();
        $missingDependencies = $this->missingDependenciesFor($module);
        $blockingDependents = $this->activeDependentsFor($module);

        $state = ($this->catalog ??= app(ModuleCatalog::class))->installedState($module->getName());
        $this->revocations ??= RevocationAttentionProvider::revoked();
        $revokedEntry = $this->revocations === []
            ? null
            : RevocationAttentionProvider::matches($this->revocations, $module->getName(), $state['installed_version']);
        // The catalog's version is the module.json version of the copy that runs (the runtime copy for a runtime or
        // preferred handover module,); composer.json versions are stale (2026.03.0), so they are only a fallback.
        $version = $state['installed_version'] ?? $module->get('version') ?? $composer->get('version', null);

        return [
            'name' => $module->getName(),
            'alias' => $module->getLowerName(),
            'description' => $module->get('description'),
            'priority' => (int)$module->get('priority', 0),
            'path' => $module->getPath(),
            'enabled' => $module->isEnabled(),
            'version' => $version,
            'requires' => $dependencies,
            'providers' => (array)$module->get('providers', []),
            'can_disable' => $module->getName() !== 'Core',
            'switchable' => $module->getName() !== 'Core',
            'missing_dependencies' => $missingDependencies->values(),
            'blocking_dependents' => $blockingDependents->values(),
            'has_missing_dependencies' => $missingDependencies->isNotEmpty(),
            'has_blocking_dependents' => $blockingDependents->isNotEmpty(),
            'revoked' => $revokedEntry !== null,
            'revoked_reason' => $revokedEntry['reason'] ?? null,
            // Listed in the project's runtime_modules.json: a spare online copy is expected, not an error.
            'handover' => (bool) ($state['handover'] ?? false),
            'hub_configured' => $this->hubConfigured(),
        ];
    }

    /**
     * Whether this site can talk to the platform hub, mirroring PlatformHubClient's NOT_CONFIGURED rule.
     *
     * True only when the site domain, the domain secret (HubCredentials: `.env` first, then the encrypted database row)
     * and the hub URL are all non-empty. The secret value is only tested for presence and never leaves the server. The
     * Modules page reads it from the list rows to skip the hub catalog call when no hub is connected.
     */
    private function hubConfigured(): bool
    {
        return app(HubCredentials::class)->isConfigured()
            && trim((string) config('ispa-cms.platform_hub_url', '')) !== '';
    }

    /**
     * Names of the modules a module.json `requires` lists, in the list form (`["core"]`) or the object form with a
     * version constraint (`{"core": ">=2026.11.0,<2027.0.0"}`). A name that matches a present module (without case)
     * takes that module's own spelling, so `questionbank` reads `QuestionBank`; otherwise it stays studly-cased.
     */
    private function requiredNames(Module $module): Collection
    {
        return collect(array_keys(ModuleVersionConstraint::parseRequires((array) $module->get('requires', []))))
            ->map(fn (string $name): string => ModuleFacade::find($name)?->getName() ?? $name);
    }

    private function missingDependenciesFor(Module $module): Collection
    {
        return $this->requiredNames($module)
            ->reject(function ($dependency) {
                $depModule = ModuleFacade::find($dependency);

                return $depModule !== null && $depModule->isEnabled();
            })
            ->values();
    }

    /**
     * Enabled modules whose module.json requires $module, names compared without case.
     */
    private function activeDependentsFor(Module $module): Collection
    {
        $target = $module->getName();

        return collect(ModuleFacade::all())
            ->filter(fn(Module $candidate) => $candidate->isEnabled())
            ->filter(function (Module $candidate) use ($target) {
                return ModuleVersionConstraint::constraintFor((array) $candidate->get('requires', []), $target) !== null;
            })
            ->map(fn(Module $candidate) => $candidate->getName())
            ->values();
    }

    /**
     * Enable or disable a CMS module with dependency checks.
     *
     * Resolves the response builder via the ires() helper (the same
     * AppResponse-resolution convention this class's own index() method
     * already uses) rather than an injected $res parameter: the route only
     * declares a single {module} segment, so a same-named untyped $res
     * parameter can never be bound by Laravel's route-dependency resolver
     * and would always shift the real module segment out of position.
     *
     * Enable and the unguarded disable go through ModuleStatusService (audit
     * entry + runtime cache refresh, 2026-11-06); the guarded disable
     * branch keeps using ManagedModuleDisabler unchanged.
     *
     * @param  UpdateModuleStatusRequest  $request  Validated status update request
     * @param  string                     $module   Module name or alias
     * @return mixed  Updated module data or error
     */
    public function updateStatus(UpdateModuleStatusRequest $request, string $module)
    {
        $res = ires();

        $moduleInstance = $this->findModule($module);

        if ($moduleInstance === null) {
            return $res->error()->message(__('mgs.module_not_found'));
        }

        $shouldEnable = $request->boolean('enabled');
        $moduleName = $moduleInstance->getName();

        if ($moduleName === 'Core' && $shouldEnable === false) {
            return $res->error()->message(__('mgs.module_cannot_disable'));
        }

        try {
            if ($shouldEnable) {
                $missing = $this->missingDependenciesFor($moduleInstance);
                if ($missing->isNotEmpty()) {
                    return $res->error()->message(__('mgs.module_dependencies_missing', [
                        'module' => $moduleName,
                        'dependencies' => $missing->join(', '),
                    ]));
                }

                $this->statusService()->change($moduleName, true, $request->user(), 'admin');
                $this->runActivationTasks($moduleName);
                $messageKey = 'mgs.module_activated';
            } elseif ($this->disabler()->hasGuardsFor($moduleName)) {
                // Managed disable with at least one registered retained-reference
                // guard (e.g. cms/Widget): guard evaluation, the pre-existing
                // dependent-module predicate, and the status change are one
                // atomic transition under the Core/root ModuleLifecycleBarrier
                // (adr-widget-module-boundary.md decisions 5-7), shared with the
                // sanctioned cms:module-disable CLI via ManagedModuleDisabler.
                // Modules with no registered guard fall through to the untouched
                // legacy branch below, preserving current behavior exactly.
                $conflict = $this->disabler()->disable($moduleName);

                if ($conflict !== null) {
                    return $res->error()
                        ->code(409)
                        ->errorCode($conflict)
                        ->message("Module disable blocked ({$conflict}): {$moduleName}");
                }

                $messageKey = 'mgs.module_deactivated';
            } else {
                $dependents = $this->activeDependentsFor($moduleInstance);
                if ($dependents->isNotEmpty()) {
                    return $res->error()->message(__('mgs.module_has_dependents', [
                        'module' => $moduleName,
                        'dependents' => $dependents->join(', '),
                    ]));
                }

                $this->statusService()->change($moduleName, false, $request->user(), 'admin');
                $messageKey = 'mgs.module_deactivated';
            }

            $moduleInstance = $this->findModule($moduleName);
        } catch (Throwable $exception) {
            $correlationId = (string) Str::uuid();

            report($exception);
            Log::error('Module status update failed.', [
                'correlation_id' => $correlationId,
                'module' => $moduleName,
                'exception' => $exception,
            ]);

            // Never leak raw exception/SQL text to the client; only an
            // opaque correlation ID and a safe machine-readable code. Full
            // detail is retained in protected logs only (above).
            return $res->error()
                ->errorCode('MODULE_OPERATION_FAILED')
                ->message("An unexpected error occurred while updating the module status. Reference: {$correlationId}");
        }

        return $res
            ->message(__($messageKey, ['module' => $moduleName]))
            ->data($this->transformModule($moduleInstance));
    }

    private function disabler(): ManagedModuleDisabler
    {
        return app(ManagedModuleDisabler::class);
    }

    private function statusService(): ModuleStatusService
    {
        return app(ModuleStatusService::class);
    }

    private function findModule(string $name): ?Module
    {
        $candidates = Collection::make([
            $name,
            Str::studly($name),
            Str::camel($name),
            Str::lower($name),
            Str::ucfirst($name),
        ])->filter();

        foreach ($candidates as $candidate) {
            $module = ModuleFacade::find($candidate);

            if ($module !== null) {
                return $module;
            }
        }

        return null;
    }

    private function runActivationTasks(string $moduleName): void
    {
        Artisan::call('module:migrate', [
            'module' => $moduleName,
            '--force' => true,
        ]);

        Artisan::call('cms:modules-sync', [
            'module' => $moduleName,
            '--skip-translations' => true,
        ]);

        Artisan::call('cms:translation-sync', [
            'module' => $moduleName,
        ]);
    }
}
