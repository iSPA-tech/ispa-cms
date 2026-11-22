<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace App\Providers;

use App\Contracts\Achievements\AchievementReader;
use App\Contracts\Achievements\AchievementRecorder;
use App\Contracts\Modules\ModuleLifecycleBarrier;
use App\Contracts\PublicAuthority\PublicAuthorityResolver;
use App\Contracts\SignedApp\SignedAppClientProvider;
use App\Contracts\Widget\CanonicalWidgetSchemaResolver;
use App\Contracts\Widget\WidgetDataProviderRegistrar;
use App\Contracts\Widget\WidgetMutationGuard;
use App\Contracts\Widget\WidgetMutationLockGateway;
use App\Contracts\Widget\WidgetRenderer;
use App\Contracts\Widget\WidgetSlotRegistrar;
use App\Contracts\Widget\WidgetSlotRuntime;
use App\Contracts\Widget\WidgetTargetContextRegistrar;
use App\Contracts\Widget\WidgetTemplateContextRegistrar;
use App\Services\Dashboard\AttentionRegistry;
use App\Services\Import\ContactImportDefinition;
use App\Support\Achievements\NullAchievementReader;
use App\Support\Achievements\NullAchievementRecorder;
use App\Support\LocaleManager;
use App\Support\Modules\MaintenanceOnlyModuleLifecycleBarrier;
use App\Support\Modules\ManagedModuleDisabler;
use App\Support\Modules\ModuleDeploymentPreflightRegistry;
use App\Support\Modules\ModuleDisableGuardRegistry;
use App\Jobs\Modules\RefreshRuntimeCachesJob;
use App\Support\Modules\RuntimeModuleAutoloader;
use Cms\Core\Events\AuditLogEvent;
use Nwidart\Modules\Facades\Module as ModuleFacade;
use Throwable;
use App\Support\PublicAuthority\UnavailablePublicAuthorityResolver;
use App\Support\SignedApp\NullSignedAppClientProvider;
use App\Support\Widget\CanonicalWidgetSchema;
use App\Support\Widget\LegacyWidgetMutationGuard;
use App\Support\Widget\LegacyWidgetMutationLockGateway;
use App\Support\Widget\LegacyWidgetRenderer;
use App\Support\Widget\NullWidgetExtensionRegistrar;
use App\Support\Widget\NullWidgetSlotRuntime;
use Cms\Core\Responses\AppResponse;
use Cms\Core\Services\CMSSettingRuntime;
use Cms\Core\Services\Import\ImportDefinitionRegistry;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Mail\SendQueuedMailable;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\ServiceProvider;
use Laravel\Passkeys\Passkeys;
use Cms\Core\Workflows\Runtime\StepRunnerRegistry;
use Cms\Core\Workflows\Runtime\Contracts\StepRunner;
use Cms\Core\Workflows\Runtime\Runners\UnknownStepRunner;

class AppServiceProvider extends ServiceProvider
{
    /** Set once the runtime modules warning was considered in this process. */
    private static bool $runtimeModulesWarned = false;

    /** Whether broken runtime modules were already handled in this process. */
    private static bool $runtimeModulesQuarantined = false;

    /**
     * Register any application services.
     */
    public function register(): void
    {
        Passkeys::ignoreRoutes();

        $this->app->register(CollectionMacro::class);
        $this->app->bind(AppResponse::class, fn() => new AppResponse());

        $this->app->usePublicPath(base_path((string) config('ispa-cms.public_path', 'public')));

        $folder = (string) config('ispa-cms.folder_name', 'Default');
        // The active project's own provider, Default included: the Default theme loads its `default::` translations
        // there (skipping Default left the theme copy as raw keys on a fresh install, 2026-11-19).
        if (file_exists(base_path('projects/' . $folder . '/Provider/CustomServiceProvider.php'))) {
            $this->app->register('PJ\\' . $folder . '\\Provider\\CustomServiceProvider');
        }

        $this->app->register(RepositoriesServiceProvider::class);

        // Shared "waiting for me" registry; module providers add entries in boot().
        $this->app->singleton(AttentionRegistry::class);

        // Default no-op binding so any module can safely record achievement
        // events even when the (future) Achievements module is not installed.
        // The Achievements module rebinds this to a real implementation.
        $this->app->singleton(AchievementRecorder::class, NullAchievementRecorder::class);

        // Read-side mirror of the recorder default: consumer modules can safely
        // read achievement titles/ladders even when the Achievements module is
        // absent. The Achievements module rebinds this to a real implementation.
        $this->app->singleton(AchievementReader::class, NullAchievementReader::class);

        // Signed mobile requests fail closed unless an enabled module provides
        // an active-client adapter during its boot phase.
        $this->app->singleton(SignedAppClientProvider::class, NullSignedAppClientProvider::class);
        $this->app->singleton(PublicAuthorityResolver::class, UnavailablePublicAuthorityResolver::class);

        // Widget compatibility contracts (docs/architecture/adr-widget-module-boundary.md
        // decision 3): safe/legacy defaults bound here in register(), mirroring the
        // Achievements precedent above. cms/Widget rebinds each of these in its
        // WidgetServiceProvider::boot() with a plain singleton() call — never in
        // register() — because Nwidart's module providers register() before this
        // provider's register() runs regardless of config/app.php ordering.
        $this->app->singleton(WidgetRenderer::class, LegacyWidgetRenderer::class);
        // Per-request memo of widget emergency block state, read by LegacyWidgetRenderer.
        $this->app->scoped(\App\Support\Widget\WidgetBlockState::class);
        $this->app->singleton(CanonicalWidgetSchemaResolver::class, CanonicalWidgetSchema::class);
        $this->app->singleton(WidgetSlotRuntime::class, NullWidgetSlotRuntime::class);
        $this->app->singleton(\App\Contracts\Widget\WidgetAreaUsageCounter::class, \App\Support\Widget\NullWidgetAreaUsageCounter::class);
        $this->app->singleton(WidgetMutationLockGateway::class, LegacyWidgetMutationLockGateway::class);
        $this->app->singleton(WidgetMutationGuard::class, LegacyWidgetMutationGuard::class);
        $this->app->singleton(WidgetSlotRegistrar::class, NullWidgetExtensionRegistrar::class);
        $this->app->singleton(WidgetTemplateContextRegistrar::class, NullWidgetExtensionRegistrar::class);
        $this->app->singleton(WidgetTargetContextRegistrar::class, NullWidgetExtensionRegistrar::class);
        $this->app->singleton(WidgetDataProviderRegistrar::class, NullWidgetExtensionRegistrar::class);
        // WidgetTargetContextResolver and WidgetDataProvider are per-target-type /
        // per-provider implementations resolved by FQCN through their respective
        // registries, not single container-wide singletons — no default binding here.

        // Generic module-disable/lifecycle-barrier/deployment-preflight
        // infrastructure (Core/root-owned, Widget-agnostic).
        $this->app->singleton(ModuleDisableGuardRegistry::class);
        $this->app->singleton(ModuleLifecycleBarrier::class, MaintenanceOnlyModuleLifecycleBarrier::class);
        $this->app->singleton(ManagedModuleDisabler::class);
        $this->app->singleton(ModuleDeploymentPreflightRegistry::class);

        $this->app->singleton(StepRunnerRegistry::class, function ($app) {
            $configured = (array)config('core.workflow.step_runners', []);
            $aliases = (array)config('core.workflow_step_aliases', []);
            $registry = new StepRunnerRegistry(new UnknownStepRunner($configured), $aliases);

            foreach ($configured as $type => $runnerClass) {
                if (is_string($type) && class_exists($runnerClass)) {
                    /** @var StepRunner $runner */
                    $runner = $app->make($runnerClass);
                    $registry->register($type, $runner);
                }
            }

            return $registry;
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->applyMailRuntimeSettings();
        $this->registerQueuedMailRuntimeRefresh();
        $this->warnAboutSkippedRuntimeModules();
        if (! self::$runtimeModulesQuarantined) {
            self::$runtimeModulesQuarantined = true;
            $this->quarantineRuntimeModules();
        }

        // A lazy relation load inside a listing is how the `slug` and `image`
        // accessors turned a 1,400-model page into ~5,100 queries and a 504.
        // Outside production, report every one so the next such path is found
        // while it is being written. Reported rather than thrown on purpose:
        // only part of the suite has been swept, and a hard failure would stop
        // the run at the first violation instead of listing them all.
        if (! $this->app->isProduction()) {
            Model::preventLazyLoading();
            Model::handleLazyLoadingViolationUsing(static function (Model $model, string $relation): void {
                Log::warning('Lazy relation load', [
                    'model' => $model::class,
                    'relation' => $relation,
                ]);
            });
        }

        // Load project routes in boot() so they override web.php routes
        $folder = (string) config('ispa-cms.folder_name', 'Default');
        if ($folder !== 'Default') {
            $this->loadRoutesFrom(base_path('projects/' . $folder . '/routes/index.php'));
        }

        if (!$this->app->runningInConsole()) {
            $request = request();

            if (($locale = LocaleManager::sanitize($request->cookie('locale'))) && LocaleManager::isWebLocaleEnabled($locale)) {
                app()->setLocale($locale);
            }
        }

        // Register import definitions
        $registry = $this->app->make(ImportDefinitionRegistry::class);
        $registry->register('App', 'contacts', ContactImportDefinition::class);
    }

    /**
     * Log once per process the runtime modules the autoloader skipped and the
     * runtime names that shadow a release module in cms/. Reads only the
     * autoloader's in-memory state, so it costs nothing without the folder.
     */
    private function warnAboutSkippedRuntimeModules(): void
    {
        if (self::$runtimeModulesWarned) {
            return;
        }
        self::$runtimeModulesWarned = true;

        $skipped = RuntimeModuleAutoloader::skipped();
        $collisions = RuntimeModuleAutoloader::collisions();
        if ($skipped === [] && $collisions === []) {
            return;
        }

        Log::warning('Runtime modules skipped', [
            'skipped' => $skipped,
            'release_name_collisions' => $collisions,
        ]);
    }

    /**
     * Log each quarantined runtime module (provider class or autoload folder
     * missing) and, when it is still enabled, disable it and write a
     * `module.quarantined` audit row. Only the first boot that sees the module
     * enabled writes; later boots find it disabled and only log.
     * Never throws: a failing status store must not take the site down.
     */
    public function quarantineRuntimeModules(): void
    {
        foreach (RuntimeModuleAutoloader::quarantined() as $name => $info) {
            Log::error('Runtime module quarantined', [
                'module' => $name,
                'path' => $info['path'],
                'code' => $info['code'],
                'reason' => $info['reason'],
            ]);

            // A shadowing runtime folder never switches off the release module of that name.
            if ($info['code'] === RuntimeModuleAutoloader::SHADOWS_RELEASE) {
                continue;
            }

            try {
                $module = ModuleFacade::find($name);
                if ($module === null || ! $module->isEnabled()) {
                    continue;
                }

                ModuleFacade::disable($name);
                event(new AuditLogEvent(
                    'module.quarantined',
                    ['module' => $name, 'code' => $info['code'], 'reason' => $info['reason'], 'path' => $info['path']],
                    'module_status',
                    0,
                    null,
                    null,
                    0,
                ));
                RefreshRuntimeCachesJob::dispatch();
            } catch (Throwable $e) {
                Log::error('Runtime module quarantine could not be saved', ['module' => $name, 'error' => $e->getMessage()]);
            }
        }
    }

    private function applyMailRuntimeSettings(): void
    {
        $mapping = [
            'mail.default' => 'email.MAIL_MAILER',
            'mail.mailers.smtp.host' => 'email.MAIL_HOST',
            'mail.mailers.smtp.port' => 'email.MAIL_PORT',
            'mail.mailers.smtp.encryption' => 'email.MAIL_ENCRYPTION',
            'mail.mailers.smtp.username' => 'email.MAIL_USERNAME',
            'mail.mailers.smtp.password' => 'email.MAIL_PASSWORD',
            'mail.from.address' => 'email.MAIL_FROM_ADDRESS',
            'mail.from.name' => 'email.MAIL_FROM_NAME',
            'mail.reply' => 'email.MAIL_REPLY',
        ];

        try {
            $settings = $this->app->make(CMSSettingRuntime::class);
            foreach ($mapping as $configKey => $settingKey) {
                $value = $settings->get($settingKey);
                if ($value !== null && $value !== '') {
                    config([$configKey => $value]);
                }
            }
        } catch (\Throwable $exception) {
            Log::warning('Mail runtime settings unavailable', [
                'exception' => $exception::class,
            ]);
        }
    }

    private function registerQueuedMailRuntimeRefresh(): void
    {
        Queue::before(function (JobProcessing $event): void {
            $commandName = $event->job->payload()['data']['commandName'] ?? null;

            if (
                $event->job->getQueue() !== 'emails'
                && !in_array($commandName, [SendQueuedNotifications::class, SendQueuedMailable::class], true)
            ) {
                return;
            }

            try {
                $this->app->make(CMSSettingRuntime::class)->forget();
                $this->applyMailRuntimeSettings();
                $this->app->make('mail.manager')->purge();
            } catch (\Throwable $exception) {
                Log::warning('Queued mail runtime refresh unavailable', [
                    'exception' => $exception::class,
                ]);
            }
        });
    }
}
