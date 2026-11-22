<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Providers;

use Cms\Core\Agents\Runtime\AgentRunner;
use Cms\Core\Services\Ai\AiTaskRuntime;
use Cms\Core\Agents\Runtime\OpenAiReasoningEngine;
use Cms\Core\Agents\Runtime\NullReasoningEngine;
use Cms\Core\Agents\Runtime\ToolDefinitionFormatter;
use Cms\Core\Agents\Contracts\ReasoningEngineInterface;
use Cms\Core\Agents\Tools\AgentToolRegistry;
use App\Clients\OpenAIClient;
use Cms\Core\Helpers\CacheHelper;
use Cms\Core\Services\Bulk\BulkRegistry;
use Cms\Core\Services\ImageVariantService;
use Cms\Core\Console\SeedModulesCommand;
use Cms\Core\Console\ExportDatabaseTable;
use Cms\Core\Console\EncryptSettingsCommand;
use Cms\Core\Console\SetupCoreModuleCommand;
use Cms\Core\Console\SyncProvinceDataCommand;
use Cms\Core\Console\SyncEmailTemplateCommand;
use Cms\Core\Console\ScanTranslateUsageCommand;
use Cms\Core\Console\SyncModulesDatabaseCommand;
use Cms\Core\Console\SyncModulePermissionsCommand;
use App\Providers\Abstracts\ModuleServiceProvider;
use Cms\Core\Console\GenerateTemplateBladeCommand;
use Cms\Core\Console\ConvertSqlSeederToPhpCommand;
use Cms\Core\Console\SyncModuleTranslationsCommand;
use Cms\Core\Console\GenerateLanguageFilePackCommand;
use Cms\Core\Console\ExportTranslatesToModuleSeederCommand;
use Cms\Core\Console\Commands\AdminI18nAuditCommand;
use Cms\Core\Console\Commands\AdminI18nRecoverCommand;
use Cms\Core\Console\Commands\AiCallLogPruneCommand;
use Cms\Core\Console\Commands\AiCostSpikeCheckCommand;
use Cms\Core\Console\Commands\QueueWatchCommand;
use Cms\Core\Console\Commands\ImageVariantWarmCommand;
use Cms\Core\Console\Commands\MetaNormalizeCommand;
use Cms\Core\Console\Commands\GenerateImportTemplatesCommand;
use Cms\Core\Console\Commands\DeployPreflightCommand;
use Cms\Core\Console\Commands\WidgetCatalogAdoptCommand;
use Cms\Core\Console\Commands\WidgetHubAdoptCommand;
use Cms\Core\Console\Commands\WidgetCatalogVerifyCommand;
use Cms\Core\Console\Commands\WidgetRenderSnapshotCommand;
use Cms\Core\Console\Commands\StatusScanCommand;
use Cms\Core\Console\Commands\EnumUsageScanCommand;
use Cms\Core\Console\Commands\AgenticRunSchedulesCommand;
use Cms\Core\Console\Commands\AgentAnswerPreflightCommand;
use Cms\Core\Console\Commands\AgenticPreflightCommand;
use Cms\Core\Console\Commands\AgenticSyncToolsCommand;
use Cms\Core\Console\Commands\AgenticGrantProfileToolsCommand;
use Cms\Core\Agents\Contracts\AgenticAiProviderInterface;
use Cms\Core\Agents\Contracts\AgenticEmbeddingProviderInterface;
use Cms\Core\Services\Agentic\AgentApprovalService;
use Cms\Core\Services\Agentic\AgenticAiProviderFactory;
use Cms\Core\Services\Agentic\AgenticAuthorization;
use Cms\Core\Services\Agentic\AgentScheduleService;
use Cms\Core\Services\Agentic\AgentRunOperationsService;
use Cms\Core\Services\Agentic\AgenticAdhocDispatchService;
use Cms\Core\Services\Agentic\AgentArtifactApplyService;
use Cms\Core\Services\Agentic\AgentApprovalDiffBuilder;
use Cms\Core\Services\Agentic\AgenticPreflightChecker;
use Cms\Core\Services\Agentic\AgentToolProvisioner;
use Cms\Core\Services\Agentic\NullAgenticAiProvider;
use Cms\Core\Services\Agentic\NullAgenticEmbeddingProvider;
use Cms\Core\Services\Agentic\OpenAiAgenticAiProvider;
use Cms\Core\Services\Agentic\OpenAiAgenticEmbeddingProvider;
use Cms\Core\Services\Ai\AiRuntimeConfigResolver;
use Cms\Core\Services\Ai\AiStructuredExtractionService;
use Cms\Core\Services\Ai\JsonSchemaShapeValidator;
use Cms\Core\Console\Commands\AuditLegacyWidgetsCommand;
use Cms\Core\Console\Commands\AuditStorageReconciliationCommand;
use Cms\Core\Console\Commands\R2BucketListingCommand;
use Cms\Core\Console\Commands\R2EvictLocalCommand;
use Cms\Core\Console\Commands\R2FailuresCommand;
use Cms\Core\Console\Commands\R2HealthCommand;
use Cms\Core\Console\Commands\R2RetryCommand;
use Cms\Core\Console\Commands\R2VerifyCommand;
use Cms\Core\Console\Commands\SyncUploadsToR2Command;
use Cms\Core\Console\Commands\DiscoverUploadStorageObjectsCommand;
use Cms\Core\Console\Commands\ApproveR2ContentChangeCommand;
use Cms\Core\Console\Commands\RetryWidgetArtifactPublicationCommand;
use Cms\Core\Console\Commands\GuardedModuleDisableCommand;
use App\Services\Dashboard\AttentionRegistry;
use Cms\Core\Services\Agentic\AgentApprovalAttentionProvider;
use Cms\Core\Services\Backup\BackupAttentionProvider;

/**
 * Core module service provider: wires routes, events, repositories, commands,
 * views, translations, and configuration merging/publishing.
 * @package Cms\Core\Providers
 */
final class CoreServiceProvider extends ModuleServiceProvider
{
    /**
     * Register the service provider.
     *
     * @return void
     */
    public function register(): void
    {
        $this->app->singleton(\Cms\Core\Admin\Resources\ResourceRegistry::class);
        // One importer per application, so every module's translation seeder in an install knows the rows created before it.
        $this->app->singleton(\Cms\Core\Services\TranslationModuleSeederImporter::class);
        $this->app->singleton(\Cms\Core\Services\Toolchain\ProcessRunner::class);
        $this->app->singleton(\Cms\Core\Services\CoreUpgrade\CorePackageSource::class);
        $this->app->singleton(\Cms\Core\Services\CoreUpgrade\CoreHealthCheck::class);
        $this->app->singleton(\Cms\Core\Records\RecordRegistry::class);
        $this->app->singleton(\Cms\Core\SavedViews\SavedViewRegistry::class);
        $this->app->singleton(\Cms\Core\Services\StarterPacks\StarterContentRegistry::class);
        // Registered (not auto-resolved) so Education can guard its calls with app()->bound().
        $this->app->singleton(\Cms\Core\Services\UserTagService::class);
        $this->app->register(EventServiceProvider::class);
        \Cms\Core\Services\Ai\Usage\AiCallLog::trackQueueJobs($this->app['events']);
        $this->app->register(RouteServiceProvider::class);
        $this->app->register(AuthServiceProvider::class);
        $this->app->register(CMSCacheServiceProvider::class);
        $this->app->register(CMSLoggerServiceProvider::class);
        //  Scoped (per request / per queue job): carries the R2-replication
        //  memo, which must not outlive one lifecycle or it could serve stale
        //  ledger answers inside long-running workers.
        $this->app->scoped(ImageVariantService::class);
        $this->app->scoped(\Cms\Core\Services\Forms\FormOptionSourceRegistry::class);
        $this->app->singleton(AgentToolRegistry::class, function ($app) {
            return new AgentToolRegistry($app, $app['config']);
        });
        $this->app->singleton(AgenticAiProviderInterface::class, function ($app) {
            $driver = strtolower(trim((string) $app['config']->get('core.agentic.ai_provider.driver', 'null')));

            return match ($driver) {
                'openai' => new OpenAiAgenticAiProvider($app->make(OpenAIClient::class)),
                // `agentic_ai` / `default` point back at this very binding, so they stay a Null provider here.
                '', 'null', 'agentic_ai', 'default' => new NullAgenticAiProvider(),
                // Any other provider the factory knows (anthropic/claude, gemini, deepseek, grok); unknown names
                // still end in a Null provider. The factory's own default-provider slot is never used for these.
                default => (new AgenticAiProviderFactory($app, new NullAgenticAiProvider()))->make($driver),
            };
        });
        $this->app->singleton(AgenticEmbeddingProviderInterface::class, function ($app) {
            $driver = (string) $app['config']->get('core.agentic.embedding_provider.driver', 'null');

            return match ($driver) {
                'openai' => new OpenAiAgenticEmbeddingProvider($app->make(OpenAIClient::class), $app->make(\Cms\Core\Services\Ai\Usage\AiGateway::class)),
                default => new NullAgenticEmbeddingProvider(),
            };
        });
        $this->app->singleton(ReasoningEngineInterface::class, function ($app) {
            $driver = strtolower(trim((string) $app['config']->get('core.agentic.ai_provider.driver', 'null')));
            $provider = $app->make(AgenticAiProviderInterface::class);

            if ($provider instanceof NullAgenticAiProvider) {
                return new NullReasoningEngine();
            }

            // Tool selection speaks the OpenAI tools / tool_calls dialect, so it stays on OpenAI whatever
            // AGENTIC_AI_PROVIDER_DRIVER is switched to (embeddings stay on OpenAI as well).
            return new OpenAiReasoningEngine(
                $driver === 'openai' ? $provider : new OpenAiAgenticAiProvider($app->make(OpenAIClient::class)),
                $app->make(AgentToolRegistry::class),
                new ToolDefinitionFormatter(),
                $app->make(AiTaskRuntime::class),
            );
        });
        $this->app->singleton(AgenticAiProviderFactory::class);
        $this->app->singleton(AgentRunner::class);
        $this->app->singleton(AgenticAuthorization::class);
        $this->app->singleton(AgentApprovalDiffBuilder::class);
        $this->app->singleton(AgentArtifactApplyService::class);
        $this->app->singleton(AgentApprovalService::class);
        $this->app->singleton(AgenticAdhocDispatchService::class);
        $this->app->singleton(AgentRunOperationsService::class);
        $this->app->singleton(AgentScheduleService::class);
        $this->app->singleton(AgenticPreflightChecker::class);
        $this->app->singleton(AgentToolProvisioner::class);
        // Scoped: one executor per request or queue job. The AgentToolRegistry it uses stays a singleton.
        $this->app->scoped(\Cms\Core\Services\Agentic\EphemeralAgentToolExecutor::class);
        $this->app->singleton(AiRuntimeConfigResolver::class);
        $this->app->singleton(\Cms\Core\Services\Ai\AiTaskRuntime::class, static fn ($app) => new \Cms\Core\Services\Ai\AiTaskRuntime(
            $app->make(AiRuntimeConfigResolver::class),
            $app->make(AgenticAiProviderFactory::class),
            $app->make(AgenticAiProviderInterface::class),
            $app->make(\Cms\Core\Services\Ai\Usage\AiGateway::class),
        ));
        $this->app->singleton(AiStructuredExtractionService::class, static fn ($app) => new AiStructuredExtractionService(
            $app->make(AgenticAiProviderInterface::class),
            $app->make(JsonSchemaShapeValidator::class),
            $app->make(AgenticAiProviderFactory::class),
            $app->make(AiRuntimeConfigResolver::class),
            $app->make(\Cms\Core\Services\Ai\Usage\AiGateway::class),
        ));
        $this->app->singleton(BulkRegistry::class);
        $this->app->singleton(\Cms\Core\Services\DataScope\DimensionRegistry::class, static fn ($app) => new \Cms\Core\Services\DataScope\DimensionRegistry(
            $app->make(\Nwidart\Modules\Contracts\RepositoryInterface::class)
        ));
        $this->app->scoped(\Cms\Core\Services\DataScope\ManifestScopeCatalog::class, static fn ($app) => new \Cms\Core\Services\DataScope\ManifestScopeCatalog(
            $app->make(\Nwidart\Modules\Contracts\RepositoryInterface::class)
        ));
        $this->app->scoped(\Cms\Core\Services\DataScope\ScopeResolver::class);
        $this->app->singleton(\Cms\Core\Services\DataScope\ScopedResourceRegistry::class);
        $this->app->scoped(\Cms\Core\Services\DataScope\DataScope::class);
        $this->app->singleton(\Cms\Core\Services\Webhooks\WebhookInbox::class);
        $this->app->singleton(\Cms\Core\Services\Import\ImportDefinitionRegistry::class);
        $this->app->singleton(\Cms\Core\Services\ConfigPackage\ConfigTableRegistry::class, static function () {
            $tables = new \Cms\Core\Services\ConfigPackage\ConfigTableRegistry();
            $tables->register('email_templates', ['key_name'], ['user_id'], ['language_id']);

            return $tables;
        });
        $this->app->singleton(\Cms\Core\Services\ConfigPackage\ConfigExportRegistry::class, static fn ($app) => new \Cms\Core\Services\ConfigPackage\ConfigExportRegistry(
            null,
            $app->make(\Cms\Core\Services\ConfigPackage\ConfigTableRegistry::class)
        ));
        $this->app->singleton(
            \Cms\Core\Services\Captcha\CaptchaServiceInterface::class,
            \Cms\Core\Services\Captcha\CaptchaGateway::class
        );

        $this->app->singleton('CacheHelper', function () {
            return new CacheHelper();
        });
    }

    protected function getModuleName(): string
    {
        return 'Core';
    }

    protected function getModuleNameLower(): string
    {
        return 'core';
    }

    /**
     * Boot the module and register its "needs attention" providers.
     *
     * @return void
     */
    public function boot(): void
    {
        // The agent answer envelope must wrap auth and throttle, which the framework priority list would otherwise
        // move ahead of it (their 401/429 would then miss the envelope and the request id).
        $httpKernel = $this->app->make(\Illuminate\Contracts\Http\Kernel::class);
        if (method_exists($httpKernel, 'prependToMiddlewarePriority')) {
            $httpKernel->prependToMiddlewarePriority(\Cms\Core\Http\Middleware\AgentAnswerEnvelope::class);
        }

        parent::boot();
        // `@moduleVite('<Module>', [...])` renders runtime-published or release assets.
        \Illuminate\Support\Facades\Blade::directive('moduleVite', static fn (string $expression): string => '<?php echo \\Cms\\Core\\Support\\ModuleAssets::tags(' . $expression . '); ?>');
        // API token audiences: Sanctum stores and loads tokens through the model with the audience and device columns.
        \Laravel\Sanctum\Sanctum::usePersonalAccessTokenModel(\Cms\Core\Models\ApiAccessToken::class);
        AgentApprovalAttentionProvider::register($this->app->make(AttentionRegistry::class));
        BackupAttentionProvider::register($this->app->make(AttentionRegistry::class));
        \Cms\Core\Services\Modules\Install\RevocationAttentionProvider::register($this->app->make(AttentionRegistry::class));
        \Cms\Core\Services\Modules\Install\EntitlementAttentionProvider::register($this->app->make(AttentionRegistry::class));
        \Cms\Core\Services\Dashboard\TokenAttentionProvider::register($this->app->make(AttentionRegistry::class));
        $this->registerTokenCleanupSchedule();
        $this->registerCoreUpgradeRunnerSchedule();
        $this->registerHubConnectPruneSchedule();
        $this->registerModuleInstallSchedule();
        $this->registerTokenRevocationTriggers();
        $this->registerDataScopeCleanup();
        $this->registerWebdavDisk();
        $this->registerGoogleDriveDisk();
        $this->registerOneDriveDisk();
        $this->registerHubSetupCodeAnnouncement();
    }

    /**
     * first boot: after the first `migrate` of a site that has no hub credentials, print a one-time setup code
     * to the console when it is a terminal (once per site; `cms:hub-setup-code` issues a new one). Skipped in unit tests, outside the console
     * and while `cms:install` runs (it prints the code itself).
     *
     * @return void
     */
    protected function registerHubSetupCodeAnnouncement(): void
    {
        if (! $this->app->runningInConsole() || $this->app->runningUnitTests()) {
            return;
        }

        $this->app['events']->listen(\Illuminate\Database\Events\MigrationsEnded::class, static function (\Illuminate\Database\Events\MigrationsEnded $event): void {
            // `cms:install` prints the code itself at the end of its output.
            if ($event->method !== 'up' || app()->bound('cms.installing')) {
                return;
            }
            self::announceHubSetupCode(new \Symfony\Component\Console\Output\ConsoleOutput());
        });
    }

    /**
     * After `migrate`: print the first-boot setup code, but only to an interactive terminal. A deploy runs
     * migrate in CI, where the output lands in a stored log; there no code is issued and one line says how to get it.
     *
     * @param \Symfony\Component\Console\Output\OutputInterface $output
     * @return void
     */
    public static function announceHubSetupCode(\Symfony\Component\Console\Output\OutputInterface $output): void
    {
        $credentials = app(\Cms\Core\Services\Platform\HubCredentials::class);
        if (! \Cms\Core\Services\Platform\HubCredentials::consoleIsTerminal()) {
            if ($credentials->firstSetupCodeDue()) {
                $output->writeln('');
                $output->writeln('This site is not connected to the iSPA hub yet. Run php artisan cms:hub-setup-code in a terminal on the server to get the one-time setup code.');
            }

            return;
        }

        $code = $credentials->announceSetupCodeOnce();
        if ($code !== null) {
            $output->writeln('');
            $output->writeln('iSPA hub setup code (shown once, valid 7 days): ' . $code);
            $output->writeln('Enter it with the site id and secret on the "Connect iSPA Hub" screen. New code: php artisan cms:hub-setup-code');
        }
    }

    /**
     * API token security: prune expired Sanctum tokens daily and revoke unused ones daily (the command does nothing while the
     * cleanup setting is off). Entries are only registered here; they run when the scheduler runs.
     *
     * @return void
     */
    protected function registerTokenCleanupSchedule(): void
    {
        $this->app->afterResolving(\Illuminate\Console\Scheduling\Schedule::class, static function (\Illuminate\Console\Scheduling\Schedule $schedule): void {
            $schedule->command('sanctum:prune-expired --hours=24')->dailyAt('03:10')->name('sanctum:prune-expired')->withoutOverlapping()->onOneServer();
            $schedule->command('cms:tokens-revoke-unused --force')->dailyAt('03:20')->name('cms:tokens-revoke-unused')->withoutOverlapping()->onOneServer();
        });
    }

    /**
     * One-tap hub connect (review 2026-11-22): delete connect attempts that ended more than a day ago, daily.
     *
     * @return void
     */
    protected function registerHubConnectPruneSchedule(): void
    {
        $this->app->afterResolving(\Illuminate\Console\Scheduling\Schedule::class, static function (\Illuminate\Console\Scheduling\Schedule $schedule): void {
            $schedule->call(static fn () => app(\Cms\Core\Services\Platform\HubOneTapConnect::class)->prune())
                ->dailyAt('03:30')->name('core:hub-connect-attempts-prune')->withoutOverlapping()->onOneServer();
        });
    }

    /**
     * One-click core upgrade (spec 2026-11-22): the upgrade runner picks up an upgrade or restore asked from the admin.
     * Sites whose scheduler runs as the code owner need no extra cron line; others add `php ispa upgrade --run-requested`.
     * Only the cheap pre-boot check runs inside `schedule:run`; a waiting request starts the runner as its own detached
     * process ({@see \Cms\Core\Services\CoreUpgrade\CoreRunnerLauncher}), whose lock keeps one runner at a time.
     *
     * @return void
     */
    protected function registerCoreUpgradeRunnerSchedule(): void
    {
        $this->app->afterResolving(\Illuminate\Console\Scheduling\Schedule::class, static function (\Illuminate\Console\Scheduling\Schedule $schedule): void {
            $schedule->call(static fn () => app(\Cms\Core\Services\CoreUpgrade\CoreRunnerLauncher::class)->tick())
                ->name('core-upgrade-runner')->everyMinute();
        });
    }

    /**
     * Module install without a queue worker (spec 2026-11-22): a heartbeat every minute proves the scheduler runs
     * (the install preflight reads it); it is an in-process callback, so it starts no extra process. The
     * pending-install runner, which runs in its own background process, is registered only while the default queue
     * is `sync`: with a real queue a worker runs installs, so the many sites on one server do not each start a PHP
     * process every minute. The runner's overlap lock outlives the longest allowed install run
     * ({@see \Cms\Core\Services\Modules\Install\InstallRunDispatcher::lockMinutes()}).
     *
     * @return void
     */
    protected function registerModuleInstallSchedule(): void
    {
        $this->app->afterResolving(\Illuminate\Console\Scheduling\Schedule::class, static function (\Illuminate\Console\Scheduling\Schedule $schedule): void {
            $schedule->call(static fn () => app(\Cms\Core\Services\Scheduler\SchedulerHeartbeat::class)->beat())
                ->everyMinute()->name('core:scheduler-heartbeat');
            if ((string) config('queue.default', 'sync') !== 'sync') {
                return;
            }
            $schedule->command(\Cms\Core\Console\Commands\ModuleInstallRunPendingCommand::NAME)
                ->everyMinute()->name(\Cms\Core\Console\Commands\ModuleInstallRunPendingCommand::NAME)
                ->withoutOverlapping(\Cms\Core\Services\Modules\Install\InstallRunDispatcher::lockMinutes())->runInBackground();
        });
    }

    /**
     * API token security: end API tokens when the account status, type or deletion state changes or a second factor is enabled.
     *
     * @return void
     */
    protected function registerTokenRevocationTriggers(): void
    {
        $events = $this->app['events'];
        $events->listen('eloquent.updated: ' . \App\Models\User::class, static function (\App\Models\User $user): void {
            app(\Cms\Core\Listeners\RevokeTokensOnAccountChange::class)->userUpdated($user);
        });
        $events->listen('eloquent.deleted: ' . \App\Models\User::class, static function (\App\Models\User $user): void {
            app(\Cms\Core\Listeners\RevokeTokensOnAccountChange::class)->userDeleted($user);
        });
        $events->listen('eloquent.saved: ' . \Cms\Core\Models\UserMfaFactor::class, static function (\Cms\Core\Models\UserMfaFactor $factor): void {
            app(\Cms\Core\Listeners\RevokeTokensOnAccountChange::class)->factorSaved($factor);
        });
    }

    /**
     * Register the 'webdav' Storage driver (Laravel has no built-in one) used by backup destinations. The Sabre client
     * is lazy, so building the disk makes no network request.
     *
     * @return void
     */
    protected function registerWebdavDisk(): void
    {
        \Illuminate\Support\Facades\Storage::extend('webdav', static function ($app, array $config) {
            $client = new \Sabre\DAV\Client([
                'baseUri' => (string) ($config['baseUri'] ?? ''),
                'userName' => (string) ($config['userName'] ?? ''),
                'password' => (string) ($config['password'] ?? ''),
            ]);
            $adapter = new \League\Flysystem\WebDAV\WebDAVAdapter($client, (string) ($config['pathPrefix'] ?? ''));

            return new \Illuminate\Filesystem\FilesystemAdapter(new \League\Flysystem\Filesystem($adapter, $config), $adapter, $config);
        });
    }

    /**
     * Register the 'gdrive' Storage driver used by Google Drive backup destinations. The adapter is lazy and gets its
     * access tokens from the OAuth connector, so building the disk makes no network request.
     *
     * @return void
     */
    protected function registerGoogleDriveDisk(): void
    {
        \Illuminate\Support\Facades\Storage::extend('gdrive', static function ($app, array $config) {
            $destination = \Cms\Core\Models\Backup\BackupDestination::query()
                ->where('uuid', (string) ($config['destination_uuid'] ?? ''))
                ->first();
            if ($destination === null) {
                throw new \InvalidArgumentException('BACKUP_DESTINATION_NOT_FOUND');
            }
            $adapter = new \Cms\Core\Services\Backup\Destinations\GoogleDriveAdapter(
                $destination,
                $app->make(\Cms\Core\Services\Backup\OAuth\OAuthConnector::class),
            );

            return new \Illuminate\Filesystem\FilesystemAdapter(new \League\Flysystem\Filesystem($adapter, $config), $adapter, $config);
        });
    }

    /**
     * Register the 'onedrive' Storage driver used by OneDrive backup destinations. The adapter is lazy and gets its
     * access tokens from the OAuth connector, so building the disk makes no network request.
     *
     * @return void
     */
    protected function registerOneDriveDisk(): void
    {
        \Illuminate\Support\Facades\Storage::extend('onedrive', static function ($app, array $config) {
            $destination = \Cms\Core\Models\Backup\BackupDestination::query()
                ->where('uuid', (string) ($config['destination_uuid'] ?? ''))
                ->first();
            if ($destination === null) {
                throw new \InvalidArgumentException('BACKUP_DESTINATION_NOT_FOUND');
            }
            $adapter = new \Cms\Core\Services\Backup\Destinations\OneDriveAdapter(
                $destination,
                $app->make(\Cms\Core\Services\Backup\OAuth\OAuthConnector::class),
            );

            return new \Illuminate\Filesystem\FilesystemAdapter(new \League\Flysystem\Filesystem($adapter, $config), $adapter, $config);
        });
    }

    /**
     * Delete data-scope assignments (and role grants) together with the user or role that owns them, so a reused
     * row id can never inherit a deleted subject's scope. Runs on soft and hard deletes (fail closed).
     *
     * @return void
     */
    protected function registerDataScopeCleanup(): void
    {
        \App\Models\User::deleted(static function (\App\Models\User $user): void {
            self::purgeDataScope(\Cms\Core\Models\DataScope\DataScopeAssignment::SUBJECT_USER, (int) $user->getKey(), false);
        });
        \Cms\Core\Models\Role::deleted(static function (\Cms\Core\Models\Role $role): void {
            self::purgeDataScope(\Cms\Core\Models\DataScope\DataScopeAssignment::SUBJECT_ROLE, (int) $role->getKey(), true);
        });
    }

    /**
     * Delete the assignments of one subject, plus role grants for roles; skipped when the tables do not exist.
     *
     * @param string $subjectType Assignment subject type.
     * @param int $subjectId Subject id.
     * @param bool $withGrants Also delete the role's grants.
     * @return void
     */
    protected static function purgeDataScope(string $subjectType, int $subjectId, bool $withGrants): void
    {
        // Cache only a positive answer, so a process that started before the migration picks the tables up later. Test suites
        // swap schemas inside one process (a later test class may not create the table), so there the question is asked each time.
        static $present = false;
        if (!$present || app()->runningUnitTests()) {
            $present = \Illuminate\Support\Facades\Schema::hasTable(\Cms\Core\Constants\CoreTableConstant::DATA_SCOPE_ASSIGNMENTS);
        }

        if (!$present) {
            return;
        }

        \Cms\Core\Models\DataScope\DataScopeAssignment::query()
            ->where('subject_type', $subjectType)
            ->where('subject_id', $subjectId)
            ->delete();

        if ($withGrants) {
            \Cms\Core\Models\DataScope\DataScopeGrant::query()->where('role_id', $subjectId)->delete();
        }
    }

    /**
     * Register commands in the format of Command::class
     *
     * @return void
     */
    protected function registerCommands(): void
    {
        $this->commands([
            GenerateLanguageFilePackCommand::class,
            ExportDatabaseTable::class,
            GenerateTemplateBladeCommand::class,
            ExportTranslatesToModuleSeederCommand::class,
            SyncModulePermissionsCommand::class,
            SyncModulesDatabaseCommand::class,
            SyncModuleTranslationsCommand::class,
            EncryptSettingsCommand::class,
            SetupCoreModuleCommand::class,
            ConvertSqlSeederToPhpCommand::class,
            SeedModulesCommand::class,
            SyncEmailTemplateCommand::class,
            SyncProvinceDataCommand::class,
            ScanTranslateUsageCommand::class,
            AdminI18nAuditCommand::class,
            AdminI18nRecoverCommand::class,
            GenerateImportTemplatesCommand::class,
            DeployPreflightCommand::class,
            WidgetCatalogAdoptCommand::class,
            WidgetHubAdoptCommand::class,
            WidgetCatalogVerifyCommand::class,
            WidgetRenderSnapshotCommand::class,
            StatusScanCommand::class,
            EnumUsageScanCommand::class,
            AgenticRunSchedulesCommand::class,
            AgenticPreflightCommand::class,
            AgentAnswerPreflightCommand::class,
            AgenticSyncToolsCommand::class,
            AgenticGrantProfileToolsCommand::class,
            AuditLegacyWidgetsCommand::class,
            AuditStorageReconciliationCommand::class,
            SyncUploadsToR2Command::class,
            DiscoverUploadStorageObjectsCommand::class,
            ApproveR2ContentChangeCommand::class,
            R2VerifyCommand::class,
            R2FailuresCommand::class,
            R2RetryCommand::class,
            R2BucketListingCommand::class,
            R2HealthCommand::class,
            R2EvictLocalCommand::class,
            ImageVariantWarmCommand::class,
            RetryWidgetArtifactPublicationCommand::class,
            GuardedModuleDisableCommand::class,
            \Cms\Core\Console\Commands\ModuleStatusCommand::class,
            MetaNormalizeCommand::class,
            AiCostSpikeCheckCommand::class,
            AiCallLogPruneCommand::class,
            \Cms\Core\Console\Commands\TokensForgetCachedCommand::class,
            \Cms\Core\Console\Commands\TokensReportCommand::class,
            \Cms\Core\Console\Commands\TokensRevokeUnusedCommand::class,
            \Cms\Core\Console\Commands\TokensCutoverCommand::class,
            \Cms\Core\Console\Commands\BackgroundTaskPruneCommand::class,
            \Cms\Core\Console\Commands\NotificationsPruneCommand::class,
            \Cms\Core\Console\Commands\ConfigPackageRunPruneCommand::class,
            \Cms\Core\Console\Commands\ConfigExportCommand::class,
            \Cms\Core\Console\Commands\ConfigImportCommand::class,
            \Cms\Core\Console\Commands\ManifestLintCommand::class,
            \Cms\Core\Console\Commands\MakeModuleCommand::class,
            \Cms\Core\Console\Commands\ModuleEditionReportCommand::class,
            \Cms\Core\Console\Commands\StarterPackLintCommand::class,
            \Cms\Core\Console\Commands\StarterPackExportCommand::class,
            \Cms\Core\Console\Commands\StarterPackApplyCommand::class,
            QueueWatchCommand::class,
            \Cms\Core\Console\Commands\WebhookReplayCommand::class,
            \Cms\Core\Console\Commands\WebhookPruneCommand::class,
            \Cms\Core\Console\Commands\BackupRunCommand::class,
            \Cms\Core\Console\Commands\BackupPruneCommand::class,
            \Cms\Core\Console\Commands\ModuleRevocationCheckCommand::class,
            \Cms\Core\Console\Commands\PlatformReportCommand::class,
            \Cms\Core\Console\Commands\ModuleIntegrityCommand::class,
            \Cms\Core\Console\Commands\HubSetupCodeCommand::class,
            \Cms\Core\Console\Commands\InstallCommand::class,
            \Cms\Core\Console\Commands\BackupScheduledCommand::class,
            \Cms\Core\Console\Commands\BackupVerifyCommand::class,
            \Cms\Core\Console\Commands\BackupRestoreTestCommand::class,
            \Cms\Core\Console\Commands\ModulePackageKeygenCommand::class,
            \Cms\Core\Console\Commands\ModulePackageSignCommand::class,
            \Cms\Core\Console\Commands\WidgetBundleBuildCommand::class,
            \Cms\Core\Console\Commands\WidgetBundleSignCommand::class,
            \Cms\Core\Console\Commands\WidgetBundleMarkPublishedCommand::class,
            \Cms\Core\Console\Commands\CorePackageBuildCommand::class,
            \Cms\Core\Console\Commands\CoreUpgradeCommand::class,
            \Cms\Core\Console\Commands\AdminConfigCommand::class,
            \Cms\Core\Console\Commands\BuildCommand::class,
            \Cms\Core\Console\Commands\DevCommand::class,
            \Cms\Core\Console\Commands\ModulePackageBuildCommand::class,
            \Cms\Core\Console\Commands\ModulePackageAuditCommand::class,
            \Cms\Core\Console\Commands\ModuleInstallCommandRunCommand::class,
            \Cms\Core\Console\Commands\ModuleInstallRunPendingCommand::class,
        ]);
    }

    /**
     * Register command Schedules.
     *
     * @return void
     */
    protected function registerCommandSchedules(): void
    {
        // Scheduling remains centralized in app/Console/Kernel to avoid duplicate cron registration.
    }
}
