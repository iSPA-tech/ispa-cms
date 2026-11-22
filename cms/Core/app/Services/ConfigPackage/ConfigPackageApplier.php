<?php

declare(strict_types=1);

namespace Cms\Core\Services\ConfigPackage;

use App\Models\User;
use Cms\Core\Events\AuditLogEvent;
use Cms\Core\Jobs\ApplyConfigPackageJob;
use Cms\Core\Models\BackgroundTask;
use Cms\Core\Models\Backup\Backup;
use Cms\Core\Models\ConfigPackageRun;
use Cms\Core\Services\Backup\BackupGate;
use Cms\Core\Services\Backup\BackupRequiredException;
use Cms\Core\Services\ConfigPackage\Sections\CustomFieldsSection;
use Cms\Core\Services\ConfigPackage\Sections\MenusSection;
use Cms\Core\Services\ConfigPackage\Sections\RolesSection;
use Cms\Core\Services\ConfigPackage\Sections\SectionAdapter;
use Cms\Core\Services\ConfigPackage\Sections\SettingsSection;
use Cms\Core\Services\ConfigPackage\Sections\TablesSection;
use Cms\Core\Services\ConfigPackage\Sections\TranslationsSection;
use Cms\Core\Services\ConfigPackage\Sections\WorkflowsSection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Applies the plan of a successful dry run to this site: backup first, one transaction per section, audit, syncs.
 *
 * Run records: apply() never changes the dry run. It creates a new `apply` run (`parent_run_id` = the dry run,
 * same `package_checksum`, no payload of its own; the package is read from the dry run) and dispatches
 * ApplyConfigPackageJob, which calls execute(). The apply run's `plan` holds only the effective choices
 * (`plan.choices` = section => item key => bool); its `summary` holds per-section counts, `result`
 * (succeeded / failed / skipped section names), `sync_failed`, `backup_ref` and a safe `error`.
 *
 * Refusals (ConfigPackageApplyRefused, nothing written): the user is not a Webmaster (service-level check), the run is
 * not a successful dry run, it is older than one hour, its package is gone or its checksum does not match the run,
 * or the target changed since the dry run. Drift is detected by recomputing `base_fingerprint` with the same
 * differ code inside a transaction that is always rolled back, so the probe leaves no row behind.
 *
 * Order of execute(): checks, then the pre-import backup (BackupGate; a failure stops the apply with zero data
 * writes, no override), then each section inside its own DB transaction (a failing section rolls back only itself,
 * later sections still run), then afterApply() once for every section that wrote something, then the audit summary.
 *
 * Choices: only keys the dry run offered are honoured (top-level `plan.choices`, per-item `choices[].key` of menus and
 * the `<slug>#graph` choice of workflows); an offered key is applied only when the operator sent `true` for it.
 * Keys the dry run did not offer are ignored, except the section-level `*` = false, which skips the whole section.
 * Values never appear in audit entries, logs, messages or the run.
 */
class ConfigPackageApplier
{
    /** Maximum age of a dry run that may still be applied, in minutes. */
    public const MAX_DRY_RUN_AGE_MINUTES = 60;

    /** Choice key that excludes a whole section: `{section: {"*": false}}`. */
    public const SECTION_SKIP_KEY = '*';

    /** Maximum item keys per outcome kept in one audit entry. */
    public const AUDIT_KEY_CAP = 500;

    /** @var array<string, SectionAdapter>|null Adapters keyed by section key; null means container defaults. */
    private ?array $adapters = null;

    private readonly ConfigPackageDiffer $differ;

    /**
     * @param BackupGate                $backupGate Gate that takes the pre-import backup or throws.
     * @param list<SectionAdapter>|null $adapters   Adapters to use (keyed by key()); null resolves the defaults.
     * @param ConfigPackageDiffer|null  $differ     Differ used to recompute the fingerprint (same adapters).
     */
    public function __construct(private readonly BackupGate $backupGate, ?array $adapters = null, ?ConfigPackageDiffer $differ = null)
    {
        if ($adapters !== null) {
            $this->adapters = [];
            foreach ($adapters as $adapter) {
                $this->adapters[$adapter->key()] = $adapter;
            }
        }

        $this->differ = $differ ?? new ConfigPackageDiffer($adapters);
    }

    /**
     * Check the dry run, record a queued apply run and dispatch the background job; returns the (refreshed) apply run.
     *
     * @param ConfigPackageRun                    $dryRun  Successful dry run to apply.
     * @param array<string, array<array-key, mixed>> $choices Section => item key => bool (or a list of ticked keys).
     * @param int                                 $userId  Acting user id (must be a Webmaster).
     *
     * @throws ConfigPackageApplyRefused When the dry run may not be applied; nothing is written.
     */
    public function apply(ConfigPackageRun $dryRun, array $choices, int $userId): ConfigPackageRun
    {
        $package = $this->assertApplicable($dryRun, $userId);

        $run = ConfigPackageRun::query()->create([
            'kind' => ConfigPackageRun::KIND_APPLY,
            'status' => ConfigPackageRun::STATUS_QUEUED,
            'sections' => array_map('strval', array_keys((array) $package['sections'])),
            'package_checksum' => $dryRun->package_checksum,
            'parent_run_id' => (int) $dryRun->id,
            'created_by' => $userId > 0 ? $userId : null,
            'plan' => ['choices' => $this->effectiveChoices($dryRun, $choices)],
        ]);

        ApplyConfigPackageJob::dispatch((int) $run->id, $userId);

        return $run->refresh();
    }

    /**
     * Execute a queued apply run (called by ApplyConfigPackageJob). Never throws for section failures.
     *
     * @param ConfigPackageRun              $run       Queued apply run.
     * @param int                           $userId    Acting user id.
     * @param (callable(string): void)|null $onSection Called with the section name before each section is applied.
     * @param BackgroundTask|null           $task      Task the backup records progress on.
     */
    public function execute(ConfigPackageRun $run, int $userId, ?callable $onSection = null, ?BackgroundTask $task = null): ConfigPackageRun
    {
        $run->forceFill(['status' => ConfigPackageRun::STATUS_RUNNING])->save();

        $dryRun = $run->parent_run_id !== null ? ConfigPackageRun::query()->find($run->parent_run_id) : null;

        try {
            if ($dryRun === null) {
                throw new ConfigPackageApplyRefused('The dry run of this apply no longer exists; run the dry run again.');
            }

            $package = $this->assertApplicable($dryRun, $userId);
        } catch (ConfigPackageApplyRefused $e) {
            return $this->finish($run, $userId, [], [], $e->getMessage());
        }

        // Nothing but the run record is written before this point.
        try {
            $backup = $this->backupGate->ensure(Backup::REASON_PRE_IMPORT, $userId > 0 ? $userId : null, $task);
        } catch (Throwable $e) {
            $code = $e instanceof BackupRequiredException ? ($e->reason() ?? $e->getMessage()) : class_basename($e);
            Log::error('Config package apply stopped: backup failed', ['run_id' => $run->id, 'exception' => $e::class]);

            return $this->finish($run, $userId, [], [], "The backup before import failed ({$code}); nothing was changed.");
        }

        $run->forceFill(['backup_ref' => (string) ($backup->uuid ?? $backup->id)])->save();

        $plan = (array) $dryRun->plan;
        $choices = (array) (((array) $run->plan)['choices'] ?? []);
        $results = [];
        $touched = [];

        foreach ((array) $package['sections'] as $name => $rows) {
            $name = (string) $name;
            $adapter = $this->adapter($name);

            if (
                $adapter === null
                || ($plan['sections'][$name]['status'] ?? 'skipped') === 'skipped'
                || (((array) ($choices[$name] ?? []))[self::SECTION_SKIP_KEY] ?? true) === false
            ) {
                $results[$name] = ['status' => 'skipped'];

                continue;
            }

            if ($onSection !== null) {
                $onSection($name);
            }

            $context = new ApplyContext((array) ($choices[$name] ?? []), $userId > 0 ? $userId : null);

            try {
                /** @var ApplyResult $result */
                $result = DB::transaction(static fn (): ApplyResult => $adapter->apply(is_array($rows) ? $rows : [], $context));
            } catch (Throwable $e) {
                Log::error('Config package section apply failed', ['run_id' => $run->id, 'section' => $name, 'exception' => $e::class]);
                $results[$name] = ['status' => 'failed', 'error' => class_basename($e)];
                $this->audit("config.package.apply.{$name}", ['status' => 'failed', 'error' => class_basename($e)], $run, $userId);

                continue;
            }

            $counts = ['updated' => $result->updated, 'unchanged' => $result->unchanged, 'skipped' => $result->skipped, 'blocked' => $result->blocked];
            $results[$name] = ['status' => 'succeeded'] + $counts;
            $this->audit("config.package.apply.{$name}", ['counts' => $counts, 'items' => $this->itemKeys($result->items)], $run, $userId);

            if ($result->updated > 0) {
                $touched[$name] = [$adapter, $context];
            }
        }

        $syncFailed = [];
        foreach ($touched as $name => [$adapter, $context]) {
            try {
                $adapter->afterApply($context);
            } catch (Throwable $e) {
                Log::error('Config package post-apply sync failed', ['run_id' => $run->id, 'section' => $name, 'exception' => $e::class]);
                $syncFailed[] = $name;
            }
        }

        return $this->finish($run, $userId, $results, $syncFailed, null);
    }

    /**
     * Check that a dry run may be applied by this user now and return its package.
     *
     * @return array<string, mixed> The decoded package of the dry run.
     *
     * @throws ConfigPackageApplyRefused With a safe, operator-facing message.
     */
    public function assertApplicable(ConfigPackageRun $dryRun, int $userId): array
    {
        $user = $userId > 0 ? User::query()->find($userId) : null;
        if ($user === null || !$user->isWebmaster()) {
            throw new ConfigPackageApplyRefused('Only a Webmaster can apply a configuration package.');
        }

        if ($dryRun->kind !== ConfigPackageRun::KIND_DRY_RUN || $dryRun->status !== ConfigPackageRun::STATUS_SUCCEEDED) {
            throw new ConfigPackageApplyRefused('Only a successful dry run can be applied.');
        }

        if ($dryRun->created_at === null || $dryRun->created_at->lt(now()->subMinutes(self::MAX_DRY_RUN_AGE_MINUTES))) {
            throw new ConfigPackageApplyRefused('The dry run is older than 1 hour; run the dry run again.');
        }

        try {
            $package = $dryRun->payload();
        } catch (RuntimeException) {
            $package = [];
        }

        if (!is_array($package['sections'] ?? null)) {
            throw new ConfigPackageApplyRefused('The dry run no longer holds its package; run the dry run again.');
        }

        $checksum = (string) ($dryRun->package_checksum ?? '');
        if ($checksum === '' || ($package['checksum'] ?? null) !== $checksum || PackageFormat::checksum($package['sections']) !== $checksum) {
            throw new ConfigPackageApplyRefused('The package checksum does not match the dry run.');
        }

        $expected = ((array) $dryRun->plan)['base_fingerprint'] ?? null;
        if (!is_string($expected) || $expected === '' || $this->liveFingerprint($package, $userId) !== $expected) {
            throw new ConfigPackageApplyRefused('This site changed since the dry run; run the dry run again.');
        }

        return $package;
    }

    /**
     * Effective per-section choices: every key the dry run offered, true only when the operator sent true for it.
     *
     * @param array<string, array<array-key, mixed>> $choices Section => item key => bool, or section => list of keys.
     *
     * @return array<string, array<string, bool>>
     */
    public function effectiveChoices(ConfigPackageRun $dryRun, array $choices): array
    {
        $ticked = [];
        foreach ($choices as $section => $keys) {
            if (!is_string($section) || !is_array($keys)) {
                continue;
            }

            foreach ($keys as $key => $value) {
                if (is_int($key) && is_string($value)) {
                    $ticked[$section][$value] = true;
                } elseif (is_string($key)) {
                    $ticked[$section][$key] = $value === true;
                }
            }
        }

        $effective = [];
        foreach ($this->offeredChoices((array) $dryRun->plan) as $section => $keys) {
            foreach ($keys as $key) {
                $effective[$section][$key] = ($ticked[$section][$key] ?? false) === true;
            }
        }

        // Skipping a whole section is expressed as `{section: {"*": false}}`; item choices cannot do it because items the
        // dry run did not offer a choice for are never excluded one by one.
        foreach (array_keys((array) (((array) $dryRun->plan)['sections'] ?? [])) as $section) {
            if (($ticked[(string) $section][self::SECTION_SKIP_KEY] ?? true) === false) {
                $effective[(string) $section][self::SECTION_SKIP_KEY] = false;
            }
        }

        ksort($effective);

        return $effective;
    }

    /**
     * Choice keys offered by a dry run plan, per section.
     *
     * @param array<string, mixed> $plan
     *
     * @return array<string, list<string>>
     */
    private function offeredChoices(array $plan): array
    {
        $offered = [];

        foreach ((array) ($plan['choices'] ?? []) as $choice) {
            if (is_array($choice) && is_string($choice['section'] ?? null) && is_string($choice['key'] ?? null)) {
                $offered[$choice['section']][$choice['key']] = true;
            }
        }

        foreach ((array) ($plan['sections'] ?? []) as $section => $sectionPlan) {
            foreach ((array) ($sectionPlan['items'] ?? []) as $item) {
                if (!is_array($item)) {
                    continue;
                }

                // Menus: optional per-item choices such as `<key>#replace_items`.
                foreach ((array) ($item['choices'] ?? []) as $choice) {
                    if (is_array($choice) && is_string($choice['key'] ?? null)) {
                        $offered[(string) $section][$choice['key']] = true;
                    }
                }

                // Workflows: replacing the graph of an existing project.
                if (($item['graph']['needs_choice'] ?? false) === true && is_string($item['key'] ?? null)) {
                    $offered[(string) $section][$item['key'] . WorkflowsSection::GRAPH_CHOICE_SUFFIX] = true;
                }
            }
        }

        return array_map(static fn (array $keys): array => array_map('strval', array_keys($keys)), $offered);
    }

    /**
     * Recompute the dry run fingerprint against the live database; every write of the probe is rolled back.
     *
     * @param array<string, mixed> $package
     */
    private function liveFingerprint(array $package, int $userId): ?string
    {
        DB::beginTransaction();

        try {
            $probe = new ConfigPackageRun([
                'kind' => ConfigPackageRun::KIND_DRY_RUN,
                'status' => ConfigPackageRun::STATUS_QUEUED,
                'sections' => array_map('strval', array_keys((array) $package['sections'])),
                'created_by' => $userId > 0 ? $userId : null,
            ]);
            $probe->setPayload($package);
            $probe->save();

            $probe = $this->differ->dryRunInto($probe, $userId);
            $fingerprint = ((array) $probe->plan)['base_fingerprint'] ?? null;

            return $probe->status === ConfigPackageRun::STATUS_SUCCEEDED && is_string($fingerprint) ? $fingerprint : null;
        } catch (Throwable $e) {
            Log::warning('Config package fingerprint check failed', ['exception' => $e::class]);

            return null;
        } finally {
            DB::rollBack();
        }
    }

    /**
     * Store the final status and summary of an apply run and write the summary audit event.
     *
     * @param array<string, array<string, mixed>> $results    Section => outcome.
     * @param list<string>                        $syncFailed Sections whose afterApply threw.
     */
    private function finish(ConfigPackageRun $run, int $userId, array $results, array $syncFailed, ?string $error): ConfigPackageRun
    {
        $pick = static fn (string $status): array => array_values(array_map('strval', array_keys(array_filter(
            $results,
            static fn (array $r): bool => $r['status'] === $status,
        ))));

        $result = ['succeeded' => $pick('succeeded'), 'failed' => $pick('failed'), 'skipped' => $pick('skipped')];

        if ($error === null && $result['failed'] !== []) {
            $error = 'Some sections failed: ' . implode(', ', $result['failed']) . '.';
        }

        $summary = [
            'backup_ref' => $run->backup_ref,
            'sections' => $results,
            'result' => $result,
            'sync_failed' => $syncFailed,
            'error' => $error,
        ];

        $run->forceFill([
            'status' => $error === null ? ConfigPackageRun::STATUS_SUCCEEDED : ConfigPackageRun::STATUS_FAILED,
            'summary' => $summary,
        ])->save();

        $this->audit('config.package.apply', [
            'status' => $run->status,
            'backup_ref' => $run->backup_ref,
            'result' => $result,
            'sync_failed' => $syncFailed,
        ], $run, $userId);

        return $run;
    }

    /**
     * Item keys of an apply result grouped by outcome (unchanged items are counted only), capped per outcome.
     *
     * @param list<array<string, mixed>> $items
     *
     * @return array<string, list<string>>
     */
    private function itemKeys(array $items): array
    {
        $keys = [];
        foreach ($items as $item) {
            $outcome = (string) ($item['outcome'] ?? '');
            if ($outcome === '' || $outcome === 'unchanged') {
                continue;
            }

            if (count($keys[$outcome] ?? []) < self::AUDIT_KEY_CAP) {
                $keys[$outcome][] = (string) ($item['key'] ?? '');
            }
        }

        return $keys;
    }

    /**
     * Dispatch one audit event; a failing audit listener never undoes applied data.
     *
     * @param array<string, mixed> $changed Counts, keys and section names only.
     */
    private function audit(string $action, array $changed, ConfigPackageRun $run, int $userId): void
    {
        try {
            event(new AuditLogEvent($action, $changed, ConfigPackageRun::class, (int) $run->id, null, null, $userId));
        } catch (Throwable $e) {
            Log::warning('Config package apply audit failed', ['run_id' => $run->id, 'action' => $action, 'exception' => $e::class]);
        }
    }

    /**
     * Resolve the adapter of a section (one instance per applier call chain), or null when the section is unknown.
     */
    private function adapter(string $section): ?SectionAdapter
    {
        if ($this->adapters !== null) {
            return $this->adapters[$section] ?? null;
        }

        return match ($section) {
            'settings' => app(SettingsSection::class),
            'translations' => new TranslationsSection('all'),
            'roles' => app(RolesSection::class),
            'menus' => app(MenusSection::class),
            'workflows' => app(WorkflowsSection::class),
            'custom_fields' => app(CustomFieldsSection::class),
            'tables' => app(TablesSection::class),
            default => null,
        };
    }
}

/**
 * Raised when a dry run may not be applied (not a Webmaster, stale, drifted, checksum mismatch, ...).
 *
 * The message is operator-facing and never carries a value; nothing has been written when it is thrown.
 */
final class ConfigPackageApplyRefused extends RuntimeException
{
}
