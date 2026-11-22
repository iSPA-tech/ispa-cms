<?php

namespace Cms\Widget\Providers;

use App\Contracts\Modules\ModuleDeploymentPreflight;
use App\Contracts\Widget\WidgetAreaUsageCounter;
use App\Contracts\Widget\WidgetMutationGuard;
use App\Contracts\Widget\WidgetRenderer;
use App\Contracts\Widget\WidgetSlotRegistrar;
use App\Contracts\Widget\WidgetSlotRuntime;
use App\Contracts\Widget\WidgetTargetContextRegistrar;
use App\Contracts\Widget\WidgetTargetContextResolver;
use App\Contracts\Widget\WidgetTemplateContextRegistrar;
use App\Providers\Abstracts\ModuleServiceProvider;
use App\Support\Modules\ModuleDeploymentPreflightRegistry;
use App\Support\Modules\ModuleDisableGuardRegistry;
use App\Support\Widget\LegacyWidgetMutationGuard;
use Cms\Widget\Console\Commands\WidgetLegacyManifestReconciliationCommand;
use Cms\Widget\Console\Commands\WidgetModuleDisableCheckCommand;
use Cms\Widget\Console\Commands\WidgetRegistryPreflightCommand;
use Cms\Widget\Console\Commands\WidgetSiteLayoutE2EFixtureCommand;
use Cms\Widget\Operations\WidgetModuleDisableGuard;
use Cms\Widget\Security\CompositeWidgetMutationGuard;
use Cms\Widget\Security\RetainedWidgetReferenceMutationGuard;
use Cms\Widget\Rendering\Artifacts\DatabaseWidgetArtifactBlobRepository;
use Cms\Widget\Rendering\Artifacts\DatabaseWidgetArtifactManifestRepository;
use Cms\Widget\Rendering\Artifacts\FilesystemWidgetArtifactBlobStore;
use Cms\Widget\Rendering\Artifacts\WidgetArtifactBlobRepository;
use Cms\Widget\Rendering\Artifacts\WidgetArtifactManifestRepository;
use Cms\Widget\Rendering\DataProviders\DatabaseProviderUsageReader;
use Cms\Widget\Rendering\DataProviders\ProviderUsageReader;
use Cms\Widget\Rendering\DataProviders\WidgetDataProviderRegistry;
use Cms\Widget\Preview\AtomicWidgetPreviewStore;
use Cms\Widget\Preview\RedisAtomicWidgetPreviewStore;
use Cms\Widget\Preview\WidgetPreviewSessionService;
use Cms\Widget\Http\Middleware\ResolveWidgetPreviewSession;
use Cms\Widget\Rendering\WidgetRenderService;
use Cms\Widget\SiteLayout\Registry\WidgetRegistryCompatibilityChecker;
use Cms\Widget\SiteLayout\Registry\WidgetRegistryContractSnapshot;
use Cms\Widget\SiteLayout\Slots\WidgetSlotRegistry;
use Cms\Widget\SiteLayout\Slots\WidgetTemplateContextRegistry;
use Cms\Widget\SiteLayout\Targets\WidgetTargetContextRegistry;
use Cms\Widget\SiteLayout\WidgetPlacementUsageCounter;
use Cms\Widget\SiteLayout\WidgetSlotRuntimeService;
use Illuminate\Routing\Router;
use Predis\Client as PredisClient;

/**
 * Main service provider for the Widget module (Site Layout widget slots
 * bounded context).
 *
 * Root contracts (`App\Contracts\Widget\*`) are owned by Task 7; rendering
 * is owned by Task 8, rebound in `boot()` below.
 *
 * @see \App\Providers\Abstracts\ModuleServiceProvider
 * @package Cms\Widget\Providers
 */
final class WidgetServiceProvider extends ModuleServiceProvider
{
    protected function getModuleName(): string
    {
        return 'Widget';
    }

    protected function getModuleNameLower(): string
    {
        return 'widget';
    }

    /**
     * Register module-specific service providers.
     */
    protected function registerModuleProviders(): void
    {
        $this->app->register(EventServiceProvider::class);
        $this->app->register(RouteServiceProvider::class);
        $this->app->register(AuthServiceProvider::class);
    }

    /**
     * Bootstrap the module.
     *
     * Rebinds the root WidgetRenderer contract to this module's
     * WidgetRenderService with a plain `singleton()` call, overriding the
     * App\Support\Widget\LegacyWidgetRenderer default bound by
     * `App\Providers\AppServiceProvider::register()`.
     *
     * This rebind is deliberately done in boot(), not register(): Laravel
     * runs register() on every provider before boot() on any provider, so a
     * boot()-time bind always wins regardless of registration order. That
     * matters here because Nwidart\Modules\LaravelModulesServiceProvider is
     * itself package-auto-discovered (see its composer.json
     * "extra.laravel.providers"), so Illuminate\Foundation\Application::
     * registerConfiguredProviders() actually registers it — and therefore
     * every enabled module provider, including this one — BEFORE
     * App\Providers\AppServiceProvider::register() runs, not after, despite
     * AppServiceProvider appearing earlier in config/app.php's providers
     * list. A rebind placed in this module's register() would be silently
     * overwritten by AppServiceProvider's later register() call. See ADR:
     * docs/architecture/adr-widget-module-boundary.md, decision 3, and the
     * verified precedent at
     * cms/Achievements/app/Providers/AchievementsServiceProvider.php:37-68.
     *
     * Task 13b additionally rebinds the four persistence-agnostic Chunk 1
     * contracts to their real Database/Filesystem adapters, now that Task
     * 13a's migrations create the underlying tables
     * (
     * lines 795: "Bind ... only after Task 13a migrations exist"). Module-off
     * consumers never reach this file at all (WidgetServiceProvider itself
     * is only registered when the module is enabled), so the Chunk 1
     * Null/InMemory defaults remain correct for the module-off state without
     * any conditional here.
     *
     * Task 15 rebinds the remaining four Chunk-1 root registrar/runtime
     * contracts named by the plan (WidgetSlotRegistrar,
     * WidgetTemplateContextRegistrar, WidgetTargetContextRegistrar,
     * WidgetSlotRuntime) to their real module registries, and wires the
     * registry-compatibility snapshot/checker/preflight chain. Every
     * concrete registry class is bound as its OWN singleton FIRST, and each
     * root interface is then bound to resolve that SAME shared instance via
     * an explicit `fn ($app) => $app->make(Concrete::class)` closure --
     * deliberately not relying on implicit container binding-chaining --
     * so a caller holding either the interface or the concrete type always
     * observes the one shared registry (registrations made through the
     * root interface must be visible to WidgetSlotRuntimeService/the
     * compatibility checker, which depend on the concrete classes).
     *
     * WidgetDataProviderRegistry itself (the concrete class, NOT the root
     * WidgetDataProviderRegistrar interface -- that interface's own rebind
     * is out of this task's scope; the plan's Task 15 checklist names only
     * the four Slot/Template/Target/Runtime contracts) is bound here as a
     * singleton because WidgetRegistryCompatibilityChecker/
     * WidgetRegistryContractSnapshot need a real, container-shared instance
     * to depend on. Production provider registration wiring (legacy
     * adapters entering this registry) remains a later task's
     * responsibility; this bind, by itself, leaves the registry empty
     * until that wiring lands -- the correct current-state behavior, not a
     * regression.
     */
    public function boot(): void
    {
        parent::boot();

        $this->app->singleton(WidgetRenderer::class, WidgetRenderService::class);

        // Widget library area counts (unified widget catalog part 2, Task 4); root default answers 0.
        $this->app->singleton(WidgetAreaUsageCounter::class, WidgetPlacementUsageCounter::class);

        // Task 16: rebind the root WidgetMutationGuard to
        // CompositeWidgetMutationGuard, with RetainedWidgetReferenceMutationGuard
        // registered FIRST ahead of the pre-existing Safe-Import
        // lock-marker default -- both are independent, additive denial
        // reasons over the same (table, id) input (see
        // CompositeWidgetMutationGuard's own docblock). Mirrors the
        // register-default/boot-rebind split already established for every
        // other root Widget contract in this method.
        $this->app->singleton(RetainedWidgetReferenceMutationGuard::class);
        $this->app->singleton(CompositeWidgetMutationGuard::class, fn ($app) => new CompositeWidgetMutationGuard([
            $app->make(RetainedWidgetReferenceMutationGuard::class),
            $app->make(LegacyWidgetMutationGuard::class),
        ]));
        $this->app->singleton(WidgetMutationGuard::class, fn ($app) => $app->make(CompositeWidgetMutationGuard::class));

        $this->app->singleton(
            FilesystemWidgetArtifactBlobStore::class,
            static fn (): FilesystemWidgetArtifactBlobStore => new FilesystemWidgetArtifactBlobStore(
                storage_path('app/widget_artifacts')
            )
        );

        $this->app->singleton(WidgetArtifactBlobRepository::class, DatabaseWidgetArtifactBlobRepository::class);
        $this->app->singleton(WidgetArtifactManifestRepository::class, DatabaseWidgetArtifactManifestRepository::class);
        $this->app->singleton(ProviderUsageReader::class, DatabaseProviderUsageReader::class);

        // --- Task 15: Slot / Template Context / Target Context registries,
        // Slot runtime, and registry-compatibility snapshot/checker/preflight.

        $this->app->singleton(WidgetSlotRegistry::class);
        $this->app->singleton(WidgetSlotRegistrar::class, fn ($app) => $app->make(WidgetSlotRegistry::class));

        $this->app->singleton(WidgetTemplateContextRegistry::class);
        $this->app->singleton(
            WidgetTemplateContextRegistrar::class,
            fn ($app) => $app->make(WidgetTemplateContextRegistry::class)
        );

        $this->app->singleton(WidgetTargetContextRegistry::class, fn ($app) => new WidgetTargetContextRegistry(
            $app->make(WidgetTemplateContextRegistry::class),
            static fn (string $fqcn): WidgetTargetContextResolver => $app->make($fqcn),
        ));
        $this->app->singleton(
            WidgetTargetContextRegistrar::class,
            fn ($app) => $app->make(WidgetTargetContextRegistry::class)
        );

        $this->app->singleton(WidgetSlotRuntime::class, fn ($app) => new WidgetSlotRuntimeService(
            $app->make(WidgetSlotRegistry::class),
        ));

        $this->app->singleton(WidgetDataProviderRegistry::class);

        $this->app->singleton(WidgetRegistryContractSnapshot::class, fn ($app) => new WidgetRegistryContractSnapshot(
            $app->make(WidgetSlotRegistry::class),
            $app->make(WidgetTemplateContextRegistry::class),
            $app->make(WidgetTargetContextRegistry::class),
            $app->make(WidgetDataProviderRegistry::class),
        ));

        $this->app->singleton(WidgetRegistryCompatibilityChecker::class, fn ($app) => new WidgetRegistryCompatibilityChecker(
            $app->make(WidgetSlotRegistry::class),
            $app->make(WidgetTemplateContextRegistry::class),
            $app->make(WidgetTargetContextRegistry::class),
            $app->make(WidgetDataProviderRegistry::class),
        ));

        $this->app->singleton(WidgetRegistryPreflightCommand::class, fn ($app) => new WidgetRegistryPreflightCommand(
            $app->make(WidgetRegistryCompatibilityChecker::class),
        ));

        // Root generic deployment-preflight registry (Task 7): register the
        // Widget registry-compatibility check so the existing
        // `cms:deploy-preflight` picks it up transitively, with zero Widget
        // knowledge in Core. See WidgetRegistryPreflightCommand's own
        // docblock for the deployment-hook evidence citation
        // (before-activation.sh) this closes the loop for.
        $this->app->make(ModuleDeploymentPreflightRegistry::class)->register(
            $this->app->make(WidgetRegistryPreflightCommand::class)
        );

        // Task 22: register the real WidgetModuleDisableGuard into the root,
        // Widget-agnostic ModuleDisableGuardRegistry (Task 7's own registry
        // -- Core never imports this module's classes to consult it). Only
        // reached while THIS provider actually boots, which nwidart does
        // ONLY when the Widget module is enabled -- the module is DISABLED
        // BY DEFAULT (WidgetModuleBoundaryTest), so this registration never
        // fires during normal app boot, cms/Core's own test suite, or any
        // other module's tests; it fires only for this module's own tests
        // (which manually construct+boot this provider, the established
        // per-test pattern already used throughout cms/Widget/tests) and a
        // real deployment with Widget explicitly enabled. This is exactly
        // why cms/Core/tests/Feature/Modules/ModuleDisableGuardTest.php's
        // "no guard registered for Widget" assertions need zero changes.
        $this->app->make(ModuleDisableGuardRegistry::class)->register(
            $this->app->make(WidgetModuleDisableGuard::class)
        );

        // --- Task 19: signed preview session atomic store + service, plus
        // the route-aware consume middleware pushed onto the EXISTING root
        // `web` middleware group (never a new group, never a hardcoded
        // Cms\Widget import in root Kernel.php -- this module owns
        // registering its own route-aware middleware, matching the module-
        // boundary precedent used everywhere else in this class).
        // `pushMiddlewareToGroup()` already dedupes internally
        // (`Router::pushMiddlewareToGroup()`), so repeated boot() calls
        // (e.g. tests that construct+boot this provider manually) never
        // register it twice.
        $this->app->singleton(AtomicWidgetPreviewStore::class, static function ($app): AtomicWidgetPreviewStore {
            // Dedicated connection array, distinct from the application's
            // own `.env`-driven `database.redis.default`/`cache`
            // connections (which are disabled/password-protected in this
            // deployment). Absent any override, this falls back to the
            // standard REDIS_* environment keys on a dedicated logical
            // database index so it never collides with the app's own
            // cache/lock keyspace.
            $appRedis = (array) config('database.redis.default', []);
            $config = config('database.redis.widget_preview', [
                'host' => (string) ($appRedis['host'] ?? '127.0.0.1'),
                'port' => (int) ($appRedis['port'] ?? 6379),
                'password' => ($appRedis['password'] ?? null) ?: null,
                'database' => 14,
            ]);

            return new RedisAtomicWidgetPreviewStore(new PredisClient($config));
        });

        $this->app->singleton(WidgetPreviewSessionService::class, fn ($app) => new WidgetPreviewSessionService(
            $app->make(WidgetSlotRegistry::class),
            $app->make(WidgetTemplateContextRegistry::class),
            $app->make(WidgetTargetContextRegistry::class),
            $app->make(AtomicWidgetPreviewStore::class),
        ));

        $this->app->make(Router::class)->pushMiddlewareToGroup('web', ResolveWidgetPreviewSession::class);
    }

    /**
     * Register commands in the format of Command::class.
     *
     * D-047 (adr-widget-provider-lifecycle.md decision 10): this method is
     * the SINGLE cumulative discovery owner for every Widget Artisan
     * command -- later tasks APPEND their Command::class entries here
     * rather than creating a parallel registration path, and never remove
     * an earlier entry. WidgetRegistryPreflightCommand (Task 15) is the
     * first entry; WidgetLegacyManifestReconciliationCommand (Task 15a) is
     * the second; WidgetModuleDisableCheckCommand (Task 22) is the third;
     * WidgetSiteLayoutE2EFixtureCommand (Task 24b, `cms:widget-site-layout-fixture`)
     * is the fourth and, for now, final entry.
     */
    protected function registerCommands(): void
    {
        $this->commands([
            WidgetRegistryPreflightCommand::class,
            WidgetLegacyManifestReconciliationCommand::class,
            WidgetModuleDisableCheckCommand::class,
            WidgetSiteLayoutE2EFixtureCommand::class,
        ]);
    }
}
