<?php

declare(strict_types=1);

namespace Cms\Core\Services\StarterPacks;

use App\Models\User;
use App\Support\Modules\ArtisanSubprocess;
use Closure;
use Cms\Core\Events\AuditLogEvent;
use Cms\Core\Jobs\ApplyStarterPackJob;
use Cms\Core\Models\BackgroundTask;
use Cms\Core\Models\Backup\Backup;
use Cms\Core\Models\ConfigPackageRun;
use Cms\Core\Models\ModuleInstall;
use Cms\Core\Models\StarterPackApplication;
use Cms\Core\Services\BackgroundTasks\BackgroundTaskRecorder;
use Cms\Core\Services\Backup\BackupGate;
use Cms\Core\Services\ConfigPackage\ConfigPackageApplier;
use Cms\Core\Services\ConfigPackage\ConfigPackageDiffer;
use Cms\Core\Services\Modules\Install\InstallPreflight;
use Cms\Core\Services\Modules\Install\ModuleInstaller;
use Cms\Core\Services\Modules\Install\PreflightResult;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Applies a starter pack to this site, step by step, as one recorded background task with a ledger entry.
 *
 * Steps, in order: preflight, backup, modules, config, roles, workflows, content, post_apply. Each step is stored on
 * the ledger ({@see StarterPackApplication::$steps}) as `{ name, status, counts, message }` with status `pending`,
 * `succeeded`, `skipped`, `failed` or `not_run`. The engine is a thin orchestrator: the safety backup is BackupGate,
 * module installs are InstallPreflight + ModuleInstaller, enabling a present module is `cms:module-status set <Name> on`
 * in a fresh process, the configuration is the configuration package dry run + apply, roles, workflows and content are the starter
 * appliers and the importers that modules register.
 *
 * Rules:
 *  - The preflight only reads (the planner's configuration dry run is rolled back). Nothing is written to the site
 *    before the backup step succeeded; a failed backup stops everything with "backup failed, nothing changed".
 *  - A failed step stops the run: the ledger and the task are marked failed and later steps are `not_run`.
 *  - Add-only: configuration items the site already has with another value are deselected,
 *    existing roles and workflows are skipped, content importers only create.
 *  - A new apply after a failed one (same pack key and version) records a step as `skipped` when the earlier run
 *    finished it and the planner shows nothing left for it; every step is safe to run twice either way.
 *  - Progress is the share of finished steps; an audit entry records the user, pack key, version and outcome.
 *
 * Module changes and the queue restart (D-2): installing or enabling a module restarts the queue workers, and a
 * process that ran the change does not know the new module's code (its content importers and configuration sections).
 * With {@see self::OPTION_YIELD} (set by ApplyStarterPackJob) the run stops after a modules step that changed
 * something and leaves the ledger `started`; the job then queues a fresh job that continues from the next step.
 * Without it the run continues in the same process; a content importer that is not loaded yet fails the content step
 * with a plain "apply again" message, and the next apply skips the finished steps.
 */
class StarterPackApplier
{
    public const STEPS = ['preflight', 'backup', 'modules', 'config', 'roles', 'workflows', 'content', 'post_apply'];

    public const STEP_PENDING = 'pending';
    public const STEP_SUCCEEDED = 'succeeded';
    public const STEP_SKIPPED = 'skipped';
    public const STEP_FAILED = 'failed';
    public const STEP_NOT_RUN = 'not_run';

    /** Message of a failed backup step (and of the task). */
    public const BACKUP_FAILED_MESSAGE = 'backup failed, nothing changed';

    /** Option: the operator confirmed modules whose migrations cannot be undone. */
    public const OPTION_CONFIRM_IRREVERSIBLE = 'confirm_irreversible';

    /** Option: the operator confirmed modules published by a partner, not by iSPA. */
    public const OPTION_CONFIRM_THIRD_PARTY = 'confirm_third_party';

    /** Option: status of the sample content the importers create (`draft` or `published`, default `draft`). */
    public const OPTION_CONTENT_STATUS = 'content_status';

    /** Option: stop after a modules step that changed something, so a fresh process continues. */
    public const OPTION_YIELD = 'yield_after_module_changes';

    /** Option: this is the last continuation; a module set that is still not settled fails the run. */
    public const OPTION_LAST_ATTEMPT = 'last_attempt';

    /** Steps that have a plan and may be skipped on a re-run. */
    private const PLANNED_STEPS = ['modules', 'config', 'roles', 'workflows', 'content'];

    /**
     * Post-apply step => artisan commands run in fresh processes. The generate writes only the public language packs:
     * on a release install the code tree (`lang/`, `cms/<Module>/lang`) is read-only for the web user, as in
     * {@see ModuleInstaller}.
     */
    private const POST_APPLY_COMMANDS = [
        'translation-sync' => [['cms:translation-sync'], ['cms:translation-generate', '--public-only']],
        'cache-clear' => [['cache:clear']],
    ];

    /** Seconds allowed for enabling one module (status, migrations, syncs). */
    private const ENABLE_TIMEOUT = 1800;

    /** Seconds allowed for each post-apply command. */
    private const COMMAND_TIMEOUT = 600;

    /** @var Closure(string, string): PreflightResult */
    private Closure $preflight;

    /** Backup taken by the current run, for the task result. */
    private ?string $backupUuid = null;

    /** Whether the modules step of the current run installed or enabled a module. */
    private bool $modulesChanged = false;

    /**
     * @param StarterPackPlanner      $planner       What applying the pack would do right now.
     * @param BackupGate              $backupGate    Safety backup.
     * @param ModuleInstaller         $installer     Runtime module install from the hub.
     * @param ArtisanSubprocess       $artisan       Fresh-process artisan runner (module enable, post-apply).
     * @param ConfigPackageDiffer     $differ        Configuration dry run.
     * @param ConfigPackageApplier    $configApplier Configuration apply.
     * @param StarterRoleApplier      $roles         Add-only role applier.
     * @param StarterWorkflowApplier  $workflows     Add-only workflow applier.
     * @param StarterContentRegistry  $registry      Content importers registered by enabled modules.
     * @param BackgroundTaskRecorder  $recorder      Background task lifecycle.
     * @param (callable(string, string): PreflightResult)|null $preflight Install preflight (default: InstallPreflight::check).
     */
    public function __construct(
        private readonly StarterPackPlanner $planner,
        private readonly BackupGate $backupGate,
        private readonly ModuleInstaller $installer,
        private readonly ArtisanSubprocess $artisan,
        private readonly ConfigPackageDiffer $differ,
        private readonly ConfigPackageApplier $configApplier,
        private readonly StarterRoleApplier $roles,
        private readonly StarterWorkflowApplier $workflows,
        private readonly StarterContentRegistry $registry,
        private readonly BackgroundTaskRecorder $recorder,
        ?callable $preflight = null,
    ) {
        $this->preflight = $preflight !== null
            ? Closure::fromCallable($preflight)
            : static fn (string $name, string $version): PreflightResult => app(InstallPreflight::class)->check($name, $version);
    }

    /**
     * Record a new application with its queued background task and dispatch ApplyStarterPackJob once.
     *
     * @param StarterPackManifest  $pack    Loaded pack.
     * @param int|null             $userId  Acting user id.
     * @param array<string, mixed> $options {@see self::OPTION_CONFIRM_IRREVERSIBLE}.
     *
     * @throws StarterPackException When another starter pack is being applied on this site.
     */
    public function queue(StarterPackManifest $pack, ?int $userId, array $options = []): StarterPackApplication
    {
        $application = Cache::lock('core:starter-pack:apply', 10)->block(5, function () use ($pack, $userId): StarterPackApplication {
            if ($this->otherRunning(null)) {
                throw StarterPackException::invalidPack($pack->key, 'Another starter pack is being applied on this site; wait until it has finished.');
            }

            return $this->record($pack, $userId);
        });

        ApplyStarterPackJob::dispatch((string) $application->uuid, $pack->packDir, array_intersect_key($options, [self::OPTION_CONFIRM_IRREVERSIBLE => true, self::OPTION_CONFIRM_THIRD_PARTY => true, self::OPTION_CONTENT_STATUS => true]));

        return $application->refresh();
    }

    /**
     * Run the pack's steps now and return the ledger entry (`succeeded`, `failed`, or `started` after a hand-over).
     *
     * @param StarterPackManifest          $pack        Loaded pack.
     * @param int|null                     $userId      Acting user id (ignored when an application is given).
     * @param array<string, mixed>         $options     {@see self::OPTION_CONFIRM_IRREVERSIBLE}, {@see self::OPTION_YIELD},
     *                                                  {@see self::OPTION_LAST_ATTEMPT}.
     * @param StarterPackApplication|null  $application Application to run or continue (default: a new one).
     */
    public function apply(StarterPackManifest $pack, ?int $userId, array $options = [], ?StarterPackApplication $application = null): StarterPackApplication
    {
        $application ??= $this->record($pack, $userId);
        if ($application->status !== StarterPackApplication::STATUS_STARTED) {
            return $application;
        }

        $userId = $application->applied_by;
        $task = $this->task($application, $userId);
        $steps = $this->steps($application);
        $continuing = $steps['backup']['status'] === self::STEP_SUCCEEDED;
        $previous = $continuing ? null : $this->previousFailure($application);
        $this->backupUuid = $continuing ? $this->storedBackupUuid($application) : null;
        $this->modulesChanged = false;
        $plan = null;

        $this->recorder->progress($task, $this->percent($steps), 'Applying the starter pack.');

        foreach (self::STEPS as $index => $name) {
            if ($continuing && $name !== 'preflight' && $this->finished($steps[$name])) {
                if ($name === 'modules' && $plan !== null && !$this->isEmpty('modules', $plan)) {
                    // A worker that started before the module change still sees the old module set: let a later job continue.
                    if (($options[self::OPTION_YIELD] ?? false) === true && ($options[self::OPTION_LAST_ATTEMPT] ?? false) !== true) {
                        $this->recorder->progress($task, $this->percent($steps), 'Waiting for the queue workers to restart after the module changes.');

                        return $application->refresh();
                    }
                    $steps[$name] = $this->failed($name, 'The modules changed while the pack was being applied; apply the pack again.');

                    return $this->fail($application, $task, $steps, $name);
                }

                continue;
            }

            if ($plan !== null && $previous !== null && $this->skippable($name, $previous, $plan)) {
                $steps[$name] = $this->result($name, self::STEP_SKIPPED, [], 'Already applied by an earlier run.');
                $this->saveProgress($application, $task, $steps);

                continue;
            }

            try {
                $result = match ($name) {
                    'preflight' => $this->preflightStep($pack, $application, $userId, $options, $plan),
                    'backup' => $this->backupStep($userId, $plan),
                    'modules' => $this->modulesStep($plan, $userId, $options),
                    'config' => $this->configStep($pack, $userId),
                    'roles' => $this->rolesStep($pack, $userId),
                    'workflows' => $this->workflowsStep($pack),
                    'content' => $this->contentStep($pack, $options),
                    'post_apply' => $this->postApplyStep($pack),
                };
            } catch (Throwable $e) {
                Log::error('Starter pack step stopped unexpectedly', ['application' => $application->uuid, 'step' => $name, 'exception' => $e::class]);
                $result = $this->failed($name, "The {$name} step stopped unexpectedly (" . class_basename($e) . ').');
            }

            $steps[$name] = $result;
            if ($result['status'] === self::STEP_FAILED) {
                return $this->fail($application, $task, $steps, $name);
            }

            if ($index === count(self::STEPS) - 1) {
                break;
            }
            $this->saveProgress($application, $task, $steps);

            if ($name === 'modules' && $this->modulesChanged && ($options[self::OPTION_YIELD] ?? false) === true) {
                $this->recorder->progress($task, $this->percent($steps), 'Waiting for the queue workers to restart after the module changes.');

                return $application->refresh();
            }
        }

        return $this->succeed($application, $task, $steps);
    }

    /**
     * Content importers the pack needs that this process does not know (for example because the process started
     * before their module was installed or enabled).
     *
     * @return list<string> Importer ids.
     */
    public function missingImporters(StarterPackManifest $pack): array
    {
        $missing = [];
        foreach ($pack->content as $entry) {
            if (!$this->registry->has($entry['importer'])) {
                $missing[] = $entry['importer'];
            }
        }

        return array_values(array_unique($missing));
    }

    /**
     * Close an application that can not finish (the job died or the pack is gone): pending steps become `not_run`,
     * the ledger and the task fail with the message.
     */
    public function stop(StarterPackApplication $application, string $message): StarterPackApplication
    {
        if ($application->status !== StarterPackApplication::STATUS_STARTED) {
            return $application;
        }

        $steps = $this->steps($application);
        foreach ($steps as $name => $step) {
            if ($step['status'] === self::STEP_PENDING) {
                $steps[$name] = $this->result($name, self::STEP_NOT_RUN, [], null);
            }
        }

        return $this->fail($application, $this->task($application, $application->applied_by), $steps, null, $message);
    }

    // ---------------------------------------------------------------- steps

    /**
     * Read-only checks: no other apply running, the plan has no blockers, irreversible modules are confirmed and the
     * configuration can be applied by this user.
     *
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    private function preflightStep(StarterPackManifest $pack, StarterPackApplication $application, ?int $userId, array $options, ?StarterPackPlan &$plan): array
    {
        if ($this->otherRunning($application)) {
            return $this->failed('preflight', 'Another starter pack is being applied on this site; wait until it has finished.');
        }

        $plan = $this->planner->plan($pack, $userId);
        if (!$plan->canApply()) {
            return $this->failed('preflight', 'The pack cannot be applied: ' . implode(' ', $plan->blockers), ['blockers' => count($plan->blockers)]);
        }

        foreach ($plan->modules as $module) {
            if ($module['state'] === StarterPackPlan::STATE_TO_INSTALL && $module['irreversible'] !== [] && ($options[self::OPTION_CONFIRM_IRREVERSIBLE] ?? false) !== true) {
                return $this->failed('preflight', "Module {$module['name']} has database changes that cannot be undone; confirm them to apply the pack.");
            }
        }

        if ($pack->configPath() !== null && !(User::query()->find((int) $userId)?->isWebmaster() ?? false)) {
            return $this->failed('preflight', 'Only a Webmaster can apply the configuration of this pack.');
        }

        $warnings = count($plan->warnings);

        return $this->result('preflight', self::STEP_SUCCEEDED, ['warnings' => $warnings], $warnings > 0 ? implode(' ', $plan->warnings) : 'Checks passed.');
    }

    /**
     * Safety backup before anything is written. Skipped only when the plan is known and has no write step at all
     * (the pack is already applied); any planned change keeps the backup, and an unknown plan always backs up.
     *
     * @return array<string, mixed>
     */
    private function backupStep(?int $userId, ?StarterPackPlan $plan): array
    {
        if ($plan !== null && $this->hasNoWriteStep($plan)) {
            return $this->result('backup', self::STEP_SKIPPED, [], 'Nothing to change, so no safety backup was taken.');
        }

        try {
            // The task is not passed on: the backup runner would close it as its own.
            $backup = $this->backupGate->ensure(Backup::REASON_PRE_INSTALL, $userId);
        } catch (Throwable $e) {
            Log::warning('Starter pack backup failed', ['exception' => $e::class]);

            return $this->failed('backup', self::BACKUP_FAILED_MESSAGE);
        }

        $this->backupUuid = (string) $backup->uuid;

        return $this->result('backup', self::STEP_SUCCEEDED, [], 'Safety backup taken.');
    }

    /**
     * Whether every planned step is empty, so applying would write nothing (configuration updates are never applied,
     * so only additions count, as in {@see self::isEmpty()}).
     */
    private function hasNoWriteStep(StarterPackPlan $plan): bool
    {
        foreach (self::PLANNED_STEPS as $name) {
            if (!$this->isEmpty($name, $plan)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Install the modules the hub provides and enable the present ones, one at a time.
     *
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    private function modulesStep(StarterPackPlan $plan, ?int $userId, array $options): array
    {
        $counts = ['installed' => 0, 'enabled' => 0, 'unchanged' => 0];

        foreach ($plan->modules as $module) {
            $name = $module['name'];
            if ($module['state'] === StarterPackPlan::STATE_TO_INSTALL) {
                $error = $this->install($name, (string) $module['version'], $userId, ($options[self::OPTION_CONFIRM_IRREVERSIBLE] ?? false) === true, ($options[self::OPTION_CONFIRM_THIRD_PARTY] ?? false) === true);
                if ($error !== null) {
                    return $this->failed('modules', $error, $counts);
                }
                $this->modulesChanged = true;
                ++$counts['installed'];
            } elseif ($module['state'] === StarterPackPlan::STATE_TO_ENABLE) {
                $result = $this->artisan->run(['cms:module-status', 'set', $name, 'on', '--reason=starter_pack'], self::ENABLE_TIMEOUT);
                if (!$result->successful()) {
                    Log::error('Starter pack could not enable a module', ['module' => $name, 'exit' => $result->exitCode()]);

                    return $this->failed('modules', "Module {$name} could not be enabled.", $counts);
                }
                $this->modulesChanged = true;
                ++$counts['enabled'];
            } else {
                ++$counts['unchanged'];
            }
        }

        return $this->result('modules', self::STEP_SUCCEEDED, $counts, $this->modulesChanged ? 'Modules installed and enabled.' : 'All modules were already enabled.');
    }

    /**
     * Publisher key of a partner module the hub names (confirmed earlier, so the preflight did not warn); null for an
     * iSPA module or when the hub can not be read. The installer still checks the signing key.
     */
    private function thirdPartyPublisherKey(string $name): ?string
    {
        // Without a hub countersign key no partner package verifies here, so there is nothing to look up.
        if (InstallPreflight::countersignKeyIds() === []) {
            return null;
        }
        try {
            return app(InstallPreflight::class)->thirdPartyPublisher($name, null)['key'] ?? null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Install one module from the hub through the module installer, inline. Returns an error message or null.
     *
     * A module published by a partner, first from its publisher on this site, needs {@see self::OPTION_CONFIRM_THIRD_PARTY}
     * (`cms:starter-pack-apply --confirm-third-party`); the confirmation is recorded on the install row.
     */
    private function install(string $name, string $version, ?int $userId, bool $confirmed, bool $confirmedThirdParty = false): ?string
    {
        if (app(\Cms\Core\Services\Modules\Install\InstallRunDispatcher::class)->hasUnfinished()) {
            return 'Another module install is running on this site; apply the pack again when it has finished.';
        }

        try {
            $check = ($this->preflight)($name, $version);
        } catch (Throwable $e) {
            Log::warning('Starter pack install preflight failed', ['module' => $name, 'exception' => $e::class]);

            return "The module catalog could not be read for module {$name}.";
        }

        if (!$check->canInstall()) {
            return "Module {$name} {$version} cannot be installed: " . (string) ($check->blocking[0]['message'] ?? 'the install checks failed.');
        }
        $irreversible = in_array(InstallPreflight::WARN_IRREVERSIBLE, $check->warningCodes(), true);
        if ($irreversible && !$confirmed) {
            return "Module {$name} has database changes that cannot be undone; confirm them to apply the pack.";
        }

        $thirdPartyWarning = null;
        foreach ($check->warnings as $warning) {
            if (($warning['code'] ?? null) === InstallPreflight::WARN_THIRD_PARTY_FIRST_INSTALL) {
                $thirdPartyWarning = $warning;
            }
        }
        if ($thirdPartyWarning !== null && !$confirmedThirdParty) {
            return "Module {$name} is published by a partner, not by iSPA, and runs with full rights on this site; confirm it (--confirm-third-party) to apply the pack.";
        }
        $publisher = $thirdPartyWarning !== null
            ? ($thirdPartyWarning['details']['publisher']['key'] ?? ModuleInstall::PUBLISHER_UNKNOWN)
            : $this->thirdPartyPublisherKey($name);
        $thirdParty = $publisher === null ? [] : [
            'third_party_publisher' => $publisher,
            'third_party_confirmed_by' => $thirdPartyWarning !== null ? $userId : null,
            'third_party_confirmed_at' => $thirdPartyWarning !== null ? now() : null,
        ];

        $install = DB::transaction(function () use ($name, $version, $userId, $check, $irreversible, $confirmed, $thirdParty): ModuleInstall {
            $task = $this->recorder->start(ModuleInstall::TASK_TYPE, 'Core', $userId);

            return ModuleInstall::query()->create($thirdParty + [
                'name' => $name,
                'version' => $version,
                'source' => ModuleInstall::SOURCE_HUB,
                'kind' => $check->kind,
                'status' => ModuleInstall::STATUS_QUEUED,
                'step_message' => 'Started by a starter pack.',
                'background_task_uuid' => $task->uuid,
                'requested_by' => $userId,
                'confirmed_irreversible' => $irreversible && $confirmed,
                'warnings' => $check->warnings,
            ]);
        });
        if ($install->third_party_confirmed_at !== null) {
            event(new AuditLogEvent('module.third_party_confirmed', [
                'name' => $name,
                'version' => $version,
                'source' => $install->source,
                'third_party_publisher' => $install->third_party_publisher,
                'starter_pack' => true,
            ], ModuleInstall::class, (int) $install->id, null, null, (int) ($userId ?? 0)));
        }

        $this->installer->run($install);
        $install->refresh();

        if ($install->status !== ModuleInstall::STATUS_SUCCEEDED) {
            Log::error('Starter pack module install failed', ['module' => $name, 'code' => $install->error_code]);

            return "Module {$name} could not be installed; see the module install list for the reason.";
        }

        return null;
    }

    /**
     * Configuration package dry run of the pack's configuration, then an apply that only adds: every item the site already holds
     * with another value is deselected.
     *
     * @return array<string, mixed>
     */
    private function configStep(StarterPackManifest $pack, ?int $userId): array
    {
        $path = $pack->configPath();
        if ($path === null) {
            return $this->result('config', self::STEP_SUCCEEDED, [], 'This pack has no configuration.');
        }

        $package = $this->readJson($path);
        if ($package === null) {
            return $this->failed('config', 'The pack configuration file could not be read.');
        }

        $dryRun = $this->differ->dryRun($package, (int) $userId);
        if ($dryRun->status !== ConfigPackageRun::STATUS_SUCCEEDED) {
            return $this->failed('config', 'The pack configuration is not valid: ' . (string) (((array) $dryRun->summary)['error'] ?? 'unknown error'));
        }

        [$choices, $notes] = $this->addOnlyChoices((array) $dryRun->plan);

        $run = ConfigPackageRun::query()->create([
            'kind' => ConfigPackageRun::KIND_APPLY,
            'status' => ConfigPackageRun::STATUS_QUEUED,
            'sections' => (array) $dryRun->sections,
            'package_checksum' => $dryRun->package_checksum,
            'parent_run_id' => (int) $dryRun->id,
            'created_by' => $userId,
            'plan' => ['choices' => $choices],
        ]);
        $run = $this->configApplier->execute($run, (int) $userId);
        $summary = (array) $run->summary;

        if ($run->status !== ConfigPackageRun::STATUS_SUCCEEDED) {
            return $this->failed('config', (string) ($summary['error'] ?? 'The configuration could not be applied.'));
        }

        $counts = ['created' => 0, 'unchanged' => 0, 'skipped' => 0, 'blocked' => 0];
        foreach ((array) ($summary['sections'] ?? []) as $section) {
            $counts['created'] += (int) ($section['updated'] ?? 0);
            $counts['unchanged'] += (int) ($section['unchanged'] ?? 0);
            $counts['skipped'] += (int) ($section['skipped'] ?? 0);
            $counts['blocked'] += (int) ($section['blocked'] ?? 0);
        }

        return $this->result('config', self::STEP_SUCCEEDED, $counts, trim('Missing configuration added; existing values were kept. ' . implode(' ', $notes)));
    }

    /**
     * Apply-run choices that keep every existing value: each `update` item is set to false. A section whose plan
     * lists only part of its items (`more` > 0) while it holds updates is skipped whole, because the unlisted updates
     * could not be deselected one by one.
     *
     * @param array<string, mixed> $plan Dry run plan.
     *
     * @return array{0: array<string, array<string, bool>>, 1: list<string>}
     */
    private function addOnlyChoices(array $plan): array
    {
        $choices = [];
        $notes = [];

        foreach ((array) ($plan['sections'] ?? []) as $name => $section) {
            $name = (string) $name;
            $updates = 0;
            foreach ((array) ($section['items'] ?? []) as $item) {
                if (($item['action'] ?? null) === 'update') {
                    $choices[$name][(string) ($item['key'] ?? '')] = false;
                    ++$updates;
                }
            }

            if ((int) ($section['more'] ?? 0) > 0 && (int) ($section['counts']['update'] ?? 0) > $updates) {
                $choices[$name] = [ConfigPackageApplier::SECTION_SKIP_KEY => false];
                $notes[] = "Section {$name} was skipped because it is too large to check item by item.";
            }
        }

        ksort($choices);

        return [$choices, $notes];
    }

    /**
     * Create the pack's roles that are missing.
     *
     * @return array<string, mixed>
     */
    private function rolesStep(StarterPackManifest $pack, ?int $userId): array
    {
        $path = $pack->rolesPath();
        if ($path === null) {
            return $this->result('roles', self::STEP_SUCCEEDED, [], 'This pack has no roles.');
        }

        $rows = $this->readJson($path);
        if ($rows === null) {
            return $this->failed('roles', 'The pack roles file could not be read.');
        }

        try {
            $result = $this->roles->apply($rows, (int) $userId);
        } catch (StarterPackException $e) {
            return $this->failed('roles', $e->getMessage());
        }

        $missing = array_sum(array_map('count', $result['missing_permissions']));

        return $this->result('roles', self::STEP_SUCCEEDED, [
            'created' => count($result['created']),
            'skipped' => count($result['skipped']),
            'missing_permissions' => $missing,
        ], $missing > 0 ? "Roles added; {$missing} permission(s) do not exist on this site." : 'Missing roles added; existing roles were kept.');
    }

    /**
     * Create the pack's workflows that are missing.
     *
     * @return array<string, mixed>
     */
    private function workflowsStep(StarterPackManifest $pack): array
    {
        $path = $pack->workflowsPath();
        if ($path === null) {
            return $this->result('workflows', self::STEP_SUCCEEDED, [], 'This pack has no workflows.');
        }

        $rows = $this->readJson($path);
        if ($rows === null) {
            return $this->failed('workflows', 'The pack workflows file could not be read.');
        }

        try {
            $result = $this->workflows->apply($rows);
        } catch (StarterPackException $e) {
            return $this->failed('workflows', $e->getMessage());
        }

        return $this->result('workflows', self::STEP_SUCCEEDED, [
            'created' => count($result['created']),
            'skipped' => count($result['skipped']),
        ], 'Missing workflows added; existing workflows were kept.');
    }

    /**
     * Import the sample content through the importers modules registered (importers only create; default status
     * draft, decision).
     *
     * @param array<string, mixed> $options {@see self::OPTION_CONTENT_STATUS} is handed to every importer as `status`.
     *
     * @return array<string, mixed>
     */
    private function contentStep(StarterPackManifest $pack, array $options = []): array
    {
        $counts = ['created' => 0, 'skipped' => 0];

        foreach ($pack->content as $entry) {
            if (!$this->registry->has($entry['importer'])) {
                return $this->failed('content', "The sample content of module {$entry['module']} is not available yet; apply the pack again in a few minutes.", $counts);
            }

            $data = $this->readJson($entry['path']);
            if ($data === null) {
                return $this->failed('content', "Content file {$entry['file']} could not be read.", $counts);
            }

            $importer = $this->registry->get($entry['importer']);
            $errors = $importer->validate($data);
            if ($errors !== []) {
                return $this->failed('content', "Content file {$entry['file']}: " . implode(' ', $errors), $counts);
            }

            $result = $importer->import($data, isset($options[self::OPTION_CONTENT_STATUS]) ? ['status' => (string) $options[self::OPTION_CONTENT_STATUS]] : []);
            $counts['created'] += array_sum(array_map('intval', (array) ($result['created'] ?? [])));
            $counts['skipped'] += array_sum(array_map('intval', (array) ($result['skipped'] ?? [])));
        }

        return $this->result('content', self::STEP_SUCCEEDED, $counts, $pack->content === [] ? 'This pack has no sample content.' : 'Missing sample content added as drafts.');
    }

    /**
     * Run the pack's post-apply commands in fresh processes.
     *
     * @return array<string, mixed>
     */
    private function postApplyStep(StarterPackManifest $pack): array
    {
        $done = 0;
        foreach ($pack->postApply as $step) {
            foreach (self::POST_APPLY_COMMANDS[$step] ?? [] as $command) {
                $result = $this->artisan->run($command, self::COMMAND_TIMEOUT);
                if (!$result->successful()) {
                    Log::error('Starter pack post-apply command failed', ['command' => $command[0], 'exit' => $result->exitCode()]);

                    return $this->failed('post_apply', "The final step {$step} failed; run it again from the command line.", ['done' => $done]);
                }
            }
            ++$done;
        }

        return $this->result('post_apply', self::STEP_SUCCEEDED, ['done' => $done], $done > 0 ? 'Final steps done.' : 'No final steps.');
    }

    // ------------------------------------------------------------- ledger

    /**
     * Create the ledger entry (all steps pending) and its queued background task.
     */
    private function record(StarterPackManifest $pack, ?int $userId): StarterPackApplication
    {
        return DB::transaction(function () use ($pack, $userId): StarterPackApplication {
            $task = $this->recorder->start(ApplyStarterPackJob::TASK_TYPE, ApplyStarterPackJob::TASK_MODULE, $userId);

            return StarterPackApplication::query()->create([
                'pack_key' => $pack->key,
                'pack_version' => $pack->version,
                'status' => StarterPackApplication::STATUS_STARTED,
                'steps' => array_map(fn (string $name): array => $this->result($name, self::STEP_PENDING, [], null), self::STEPS),
                'applied_by' => $userId,
                'background_task_uuid' => $task->uuid,
                'started_at' => now(),
            ]);
        });
    }

    /**
     * The application's background task (a new one when it is gone).
     */
    private function task(StarterPackApplication $application, ?int $userId): BackgroundTask
    {
        $task = $application->background_task_uuid !== null
            ? BackgroundTask::query()->where('uuid', $application->background_task_uuid)->first()
            : null;

        if ($task === null) {
            $task = $this->recorder->start(ApplyStarterPackJob::TASK_TYPE, ApplyStarterPackJob::TASK_MODULE, $userId);
            $application->forceFill(['background_task_uuid' => $task->uuid])->save();
        }

        return $task;
    }

    /**
     * Whether another application is running. One that has not moved for BackgroundTask::STALE_HOURS is closed.
     */
    private function otherRunning(?StarterPackApplication $self): bool
    {
        $query = StarterPackApplication::query()->where('status', StarterPackApplication::STATUS_STARTED);
        if ($self !== null) {
            $query->whereKeyNot($self->getKey());
        }

        foreach ($query->get() as $other) {
            if ($other->updated_at !== null && $other->updated_at->lt(now()->subHours(BackgroundTask::STALE_HOURS))) {
                $this->stop($other, 'The apply stopped without finishing.');

                continue;
            }

            return true;
        }

        return false;
    }

    /**
     * The latest failed application of the same pack key and version before this one.
     */
    private function previousFailure(StarterPackApplication $application): ?StarterPackApplication
    {
        return StarterPackApplication::query()
            ->where('pack_key', $application->pack_key)
            ->where('pack_version', $application->pack_version)
            ->where('status', StarterPackApplication::STATUS_FAILED)
            ->whereKeyNot($application->getKey())
            ->where('id', '<', $application->getKey())
            ->latest('id')
            ->first();
    }

    /**
     * Whether a planned step finished in the earlier failed run and the planner shows nothing left for it.
     */
    private function skippable(string $name, StarterPackApplication $previous, StarterPackPlan $plan): bool
    {
        if (!in_array($name, self::PLANNED_STEPS, true)) {
            return false;
        }

        return $this->finished($this->steps($previous)[$name] ?? null) && $this->isEmpty($name, $plan);
    }

    /**
     * Whether the plan has nothing to do for a step (configuration counts only additions: updates are never applied).
     */
    private function isEmpty(string $name, StarterPackPlan $plan): bool
    {
        return match ($name) {
            'modules' => array_filter($plan->modules, static fn (array $m): bool => in_array($m['state'], [StarterPackPlan::STATE_TO_INSTALL, StarterPackPlan::STATE_TO_ENABLE], true)) === [],
            'config' => $plan->config === null || (($plan->config['status'] ?? null) === 'succeeded'
                && array_sum(array_map(static fn (mixed $s): int => (int) (((array) ($s['counts'] ?? []))['create'] ?? 0), (array) ($plan->config['sections'] ?? []))) === 0),
            'roles' => $plan->roles === null || $plan->roles['created'] === [],
            'workflows' => $plan->workflows === null || $plan->workflows['created'] === [],
            'content' => array_filter($plan->content, static fn (array $c): bool => $c['counts'] === null || array_sum($c['counts']) > 0) === [],
            default => false,
        };
    }

    /**
     * Steps of an application keyed by name, every known step present.
     *
     * @return array<string, array<string, mixed>>
     */
    private function steps(StarterPackApplication $application): array
    {
        $stored = [];
        foreach ((array) $application->steps as $step) {
            if (is_array($step) && isset($step['name'])) {
                $stored[(string) $step['name']] = $step;
            }
        }

        $steps = [];
        foreach (self::STEPS as $name) {
            $steps[$name] = $stored[$name] ?? $this->result($name, self::STEP_PENDING, [], null);
        }

        return $steps;
    }

    /**
     * Backup uuid kept in the task result of an application that is being continued.
     */
    private function storedBackupUuid(StarterPackApplication $application): ?string
    {
        $task = BackgroundTask::query()->where('uuid', (string) $application->background_task_uuid)->first();
        $uuid = $task !== null ? (((array) $task->result)['backup_uuid'] ?? null) : null;

        return is_string($uuid) && $uuid !== '' ? $uuid : null;
    }

    /**
     * Whether a step finished (succeeded or skipped).
     *
     * @param array<string, mixed>|null $step
     */
    private function finished(?array $step): bool
    {
        return in_array($step['status'] ?? null, [self::STEP_SUCCEEDED, self::STEP_SKIPPED], true);
    }

    /**
     * Percentage of finished steps.
     *
     * @param array<string, array<string, mixed>> $steps
     */
    private function percent(array $steps): int
    {
        return intdiv(count(array_filter($steps, fn (array $s): bool => $this->finished($s))) * 100, count(self::STEPS));
    }

    /**
     * Store the steps and the task progress (the backup uuid rides in the task result so a continuation finds it).
     *
     * @param array<string, array<string, mixed>> $steps
     */
    private function saveProgress(StarterPackApplication $application, BackgroundTask $task, array $steps): void
    {
        $application->forceFill(['steps' => array_values($steps)])->save();
        if ($this->backupUuid !== null) {
            $task->forceFill(['result' => ['backup_uuid' => $this->backupUuid]])->save();
        }
        $this->recorder->progress($task, $this->percent($steps));
    }

    /**
     * Close a successful run.
     *
     * @param array<string, array<string, mixed>> $steps
     */
    private function succeed(StarterPackApplication $application, BackgroundTask $task, array $steps): StarterPackApplication
    {
        $application->forceFill([
            'status' => StarterPackApplication::STATUS_SUCCEEDED,
            'steps' => array_values($steps),
            'finished_at' => now(),
        ])->save();

        $this->recorder->succeed($task, $this->taskResult($application, $steps));
        $this->audit($application, 'starter_pack.applied', null);

        return $application->refresh();
    }

    /**
     * Close a failed run: pending steps become `not_run`, the ledger and the task fail with the step message.
     *
     * @param array<string, array<string, mixed>> $steps
     */
    private function fail(StarterPackApplication $application, BackgroundTask $task, array $steps, ?string $failedStep, ?string $message = null): StarterPackApplication
    {
        foreach ($steps as $name => $step) {
            if ($step['status'] === self::STEP_PENDING) {
                $steps[$name] = $this->result($name, self::STEP_NOT_RUN, [], null);
            }
        }
        $message ??= (string) ($steps[$failedStep]['message'] ?? 'The starter pack could not be applied.');

        $application->forceFill([
            'status' => StarterPackApplication::STATUS_FAILED,
            'steps' => array_values($steps),
            'finished_at' => now(),
        ])->save();

        $this->recorder->fail($task, $message);
        $task->forceFill(['result' => $this->taskResult($application, $steps)])->save();
        $this->audit($application, 'starter_pack.apply_failed', $failedStep);

        return $application->refresh();
    }

    /**
     * Task result: pack, backup and step statuses (no values, no paths).
     *
     * @param array<string, array<string, mixed>> $steps
     *
     * @return array<string, mixed>
     */
    private function taskResult(StarterPackApplication $application, array $steps): array
    {
        return array_filter([
            'application_uuid' => (string) $application->uuid,
            'pack_key' => $application->pack_key,
            'pack_version' => $application->pack_version,
            'backup_uuid' => $this->backupUuid,
            'steps' => array_map(static fn (array $s): string => (string) $s['status'], $steps),
        ], static fn (mixed $value): bool => $value !== null);
    }

    /**
     * Audit entry with the user, pack key, version and outcome.
     */
    private function audit(StarterPackApplication $application, string $action, ?string $failedStep): void
    {
        event(new AuditLogEvent(
            $action,
            array_filter([
                'pack_key' => $application->pack_key,
                'pack_version' => $application->pack_version,
                'application_uuid' => (string) $application->uuid,
                'step' => $failedStep,
            ], static fn (mixed $value): bool => $value !== null),
            'starter_pack_application',
            (int) $application->id,
            null,
            null,
            (int) ($application->applied_by ?? 0),
        ));
    }

    /**
     * One step record.
     *
     * @param array<string, int> $counts
     *
     * @return array{name: string, status: string, counts: array<string, int>, message: ?string}
     */
    private function result(string $name, string $status, array $counts, ?string $message): array
    {
        return ['name' => $name, 'status' => $status, 'counts' => $counts, 'message' => $message !== null ? mb_substr($message, 0, 500) : null];
    }

    /**
     * A failed step record.
     *
     * @param array<string, int> $counts
     *
     * @return array{name: string, status: string, counts: array<string, int>, message: ?string}
     */
    private function failed(string $name, string $message, array $counts = []): array
    {
        return $this->result($name, self::STEP_FAILED, $counts, $message);
    }

    /**
     * Decode a JSON file; null when it cannot be read or decoded.
     *
     * @return array<array-key, mixed>|null
     */
    private function readJson(string $path): ?array
    {
        $raw = @file_get_contents($path);
        if ($raw === false) {
            return null;
        }
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : null;
    }
}
