<?php

declare(strict_types=1);

namespace Cms\Core\Services\Workflow\Hub;

use Cms\Core\Services\Mail\ManagedEmailTemplates;
use App\Models\User;
use Cms\Core\Models\Workflows\WorkflowConnection;
use Cms\Core\Models\Workflows\WorkflowProject;
use Cms\Core\Models\Workflows\WorkflowStep;
use Cms\Core\Services\Workflow\Guard\WorkflowAudit;
use Cms\Core\Services\Workflow\Package\WorkflowPackageBindings;
use Cms\Core\Services\Workflow\Package\WorkflowPackageException;
use Cms\Core\Services\Workflow\Package\WorkflowPackageInspector;
use Cms\Core\Services\Workflow\Package\WorkflowPackageInstaller;
use Cms\Core\Services\Workflow\Versioning\WorkflowPublishPolicy;
use Cms\Core\Services\Workflow\Versioning\WorkflowVersionSnapshot;
use Cms\Core\Workflows\Upgrade\StepConfigUpgrader;
use Illuminate\Support\Facades\DB;

/**
 * Installs the latest version of a hub workflow template on this site.
 *
 * Steps: download and verify the signed bundle ({@see WorkflowHubCatalog::verifiedBundle()}), refuse when a required
 * module is off, then act on {@see WorkflowHubCatalog::plan()} for the newest install of the key:
 * - `new` / `copy` (first install, `as_copy` asked, or the newest install was modified here): hand the package to the
 *   existing {@see WorkflowPackageInstaller} (inspector checks, add-only, inactive, no schedule, audited) and record the
 *   provenance `source = {kind: hub, key, version, checksum: <bundle content_sha256>, installed_hash}`
 *   ({@see WorkflowTemplateHash}). A copy gets a free slug (`<slug>-<version>`, then `-2`, …) and the version in its
 *   name; a first install whose slug is taken by an unrelated local workflow also gets a free slug;
 * - `update`: the newest install is not modified and this version is newer:
 *   its DRAFT is updated in place ({@see updateInPlace()}), keeping the setup answers; the published version keeps
 *   running until someone publishes through the normal publish (change control included);
 * - `current`: refused with 409 {@see WorkflowHubException::UP_TO_DATE} rather than a duplicate.
 */
class WorkflowHubInstaller
{
    public const AUDIT_UPDATED = 'workflow.template_updated';

    public function __construct(
        protected WorkflowHubCatalog $catalog,
        protected WorkflowPackageInstaller $installer,
        protected WorkflowTemplateHash $hashes,
        protected WorkflowPackageInspector $inspector,
        protected WorkflowPackageBindings $bindings,
        protected WorkflowPublishPolicy $policy,
    ) {
    }

    /**
     * @return array{project_id: int, project_uuid: string, slug: string, name: string, version: int, version_label: string, is_active: bool, as_copy: bool, copy_reason: ?string, updated_in_place: bool, setup_url: string}
     *
     * @throws WorkflowHubException
     */
    public function install(string $key, User $user, bool $asCopy = false): array
    {
        ['bundle' => $bundle] = $this->catalog->verifiedBundle($key);
        $this->catalog->assertNotOlderThanAdvertised($bundle);

        $missing = $this->catalog->missingModules($bundle->requires['modules']);
        if ($missing !== []) {
            throw new WorkflowHubException(WorkflowHubException::NOT_AVAILABLE, 'turn on these modules first: ' . implode(', ', $missing), 409, ['missing_modules' => $missing]);
        }

        $plan = $this->catalog->plan($this->catalog->installsOf($bundle->key), $asCopy, $bundle->version);
        if ($plan['mode'] === WorkflowHubCatalog::PLAN_CURRENT) {
            throw self::upToDate();
        }
        if ($plan['mode'] === WorkflowHubCatalog::PLAN_UPDATE) {
            return $this->result($this->updateInPlace($plan['project'], $bundle, $user), $bundle, null, true);
        }

        $copyReason = $plan['reason'];
        // The workflow is written in the site language when the template carries it (names, notice texts).
        $package = WorkflowTemplateTranslations::localizePackage($bundle->package);
        $templateName = WorkflowTemplateTranslations::name($bundle);
        $sourceSlug = (string) ($package['workflow']['slug'] ?? '');
        $slug = $this->freeSlug($sourceSlug, $copyReason !== null ? $bundle->version : null);
        $name = $copyReason !== null ? $templateName . ' (' . WorkflowBundle::versionLabel($bundle->version) . ')' : $templateName;

        try {
            $result = $this->installer->install($package, $user, [], $slug, $name);
        } catch (WorkflowPackageException $e) {
            throw new WorkflowHubException(WorkflowHubException::NOT_INSTALLABLE, $e->getMessage(), 422, $e->report);
        }

        $project = WorkflowProject::query()->where('slug', $result['slug'])->firstOrFail();
        $project->forceFill(['source' => [
            'kind' => 'hub',
            'key' => $bundle->key,
            'version' => $bundle->version,
            'checksum' => $bundle->contentSha256,
            'installed_hash' => $this->hashes->ofDraft($project),
        ]])->save();

        return $this->result($project, $bundle, $copyReason, false);
    }

    /**
     * Upgrade-all (spec 2026-11-26): update the newest install of `$key` in place, as {@see install()} does for an
     * unmodified install with an older version, and never install a copy. An install that is missing or was modified
     * meanwhile is refused with {@see WorkflowHubException::CHANGED}; an install already at this version with
     * {@see WorkflowHubException::UP_TO_DATE}.
     *
     * @return array{project_id: int, project_uuid: string, slug: string, name: string, version: int, version_label: string, is_active: bool, as_copy: bool, copy_reason: ?string, updated_in_place: bool, setup_url: string}
     *
     * @throws WorkflowHubException
     */
    public function updateInPlaceOnly(string $key, User $user): array
    {
        ['bundle' => $bundle] = $this->catalog->verifiedBundle($key);
        $this->catalog->assertNotOlderThanAdvertised($bundle);

        $missing = $this->catalog->missingModules($bundle->requires['modules']);
        if ($missing !== []) {
            throw new WorkflowHubException(WorkflowHubException::NOT_AVAILABLE, 'turn on these modules first: ' . implode(', ', $missing), 409, ['missing_modules' => $missing]);
        }

        $plan = $this->catalog->plan($this->catalog->installsOf($bundle->key), false, $bundle->version);
        if ($plan['mode'] === WorkflowHubCatalog::PLAN_CURRENT) {
            throw self::upToDate();
        }
        if ($plan['mode'] !== WorkflowHubCatalog::PLAN_UPDATE) {
            throw new WorkflowHubException(WorkflowHubException::CHANGED, 'the installed workflow changed meanwhile; try again', 409);
        }

        return $this->result($this->updateInPlace($plan['project'], $bundle, $user), $bundle, null, true);
    }

    /**
     * Update the draft of an unmodified install to the bundle's version, in one transaction with the project row locked.
     *
     * Refused, writing nothing, when the new package fails the per-site checks of a fresh install (inspector: envelope,
     * manifest, node types and versions, the acting user's node permissions, graph rules) or the resulting draft fails
     * the checks of a designer save ({@see WorkflowPublishPolicy::draftProblems()}): 422 NOT_INSTALLABLE with the report.
     *
     * Steps are matched by `step_code`: kept codes are updated (type, name, config, schemas, start flag, position from
     * the new version; the row id stays), new codes are created, dropped codes are soft deleted, and the connections
     * are replaced, as a designer save does. Setup answers carry over for a step code present in both versions with the
     * same `step_type`: every field of {@see WorkflowSetupService::answerFields()} of the old draft (setup questions,
     * `x-secret` credentials included, plus `assignees` of a reviewers question) whose old value is not empty.
     *
     * Project row: `description`, `trigger_event` and `target_entity_type` belong to the template and take the new
     * values (run settings are frozen at publish, so the running version is not affected); tags (the manual-run tag),
     * schedule, `is_active` and the published version stay. The name stays unless it is still one of the template's own
     * names ({@see WorkflowTemplateTranslations::names()}): then it takes the name in the site language. Steps, names and
     * notice texts come in the site language when the template carries it ({@see WorkflowTemplateTranslations}). `source` gets the new version, checksum and
     * installed hash; the previous installed hashes move to `source.installed_hashes` (last
     * {@see WorkflowTemplateHash::HISTORY}). Audited as {@see self::AUDIT_UPDATED}.
     *
     * @throws WorkflowHubException
     */
    protected function updateInPlace(WorkflowProject $target, WorkflowBundle $bundle, User $user): WorkflowProject
    {
        $package = WorkflowTemplateTranslations::localizePackage($bundle->package);
        $report = $this->inspector->inspect($package, $user, [], null, null, false);
        if ($report['problems'] !== [] || $report['binding_problems'] !== []) {
            throw new WorkflowHubException(WorkflowHubException::NOT_INSTALLABLE, 'the new template version cannot be installed on this website', 422, $report);
        }
        $declared = array_values(array_filter((array) ($package['manifest']['bindings'] ?? []), 'is_array'));
        $row = $this->bindings->apply($package['workflow'], $declared, $this->bindings->resolve($declared, [])['resolved']);
        $newSteps = $this->steps($row);
        $newConnections = array_values(array_map(static fn (array $c): array => [
            'from' => (string) $c['from'], 'to' => (string) $c['to'], 'condition' => is_array($c['condition'] ?? null) ? $c['condition'] : null,
        ], array_filter((array) ($row['connections'] ?? []), 'is_array')));

        return DB::transaction(function () use ($target, $bundle, $user, $report, $row, $newSteps, $newConnections): WorkflowProject {
            /** @var WorkflowProject $project */
            $project = WorkflowProject::query()->whereKey($target->getKey())->lockForUpdate()->firstOrFail();
            $source = is_array($project->source) ? $project->source : [];
            if (($source['kind'] ?? null) !== 'hub' || ($source['key'] ?? null) !== $bundle->key || $this->hashes->modified($project)) {
                throw new WorkflowHubException(WorkflowHubException::CHANGED, 'the installed workflow changed meanwhile; try again', 409);
            }
            $fromVersion = (int) ($source['version'] ?? 0);
            if ($bundle->version <= $fromVersion) {
                throw self::upToDate();
            }

            // Carry the setup answers of the old draft over to steps whose code and type stay.
            $old = WorkflowVersionSnapshot::fromDraft($project);
            $answerFields = $this->hashes->setupFields($project, $old);
            $oldByCode = [];
            foreach ($old['steps'] as $step) {
                $oldByCode[(string) $step['step_code']] = $step;
            }
            $kept = [];
            foreach ($newSteps as $code => $step) {
                $before = $oldByCode[$code] ?? null;
                if ($before === null || $before['step_type'] !== $step['step_type']) {
                    continue;
                }
                $oldConfig = is_array($before['step_config']) ? $before['step_config'] : [];
                foreach ($answerFields[$code] ?? [] as $field) {
                    if (array_key_exists($field, $oldConfig) && !self::isEmpty($oldConfig[$field])) {
                        $newSteps[$code]['step_config'][$field] = $oldConfig[$field];
                        $kept[] = $code . '.' . $field;
                    }
                }
            }

            $problems = $this->policy->draftProblems(['steps' => array_values($newSteps), 'connections' => $newConnections], $user);
            if ($problems !== []) {
                throw new WorkflowHubException(WorkflowHubException::NOT_INSTALLABLE, 'the updated draft fails the checks of a designer save', 422,
                    ['valid' => false, 'installable' => false, 'problems' => $problems] + $report);
            }

            // Steps by code: update kept rows (ids survive), create new ones, soft delete dropped ones.
            $rows = WorkflowStep::query()->where('project_id', $project->id)->get()->keyBy('step_code');
            $ids = [];
            foreach ($newSteps as $code => $step) {
                $model = $rows[$code] ?? new WorkflowStep(['project_id' => $project->id]);
                $model->forceFill(['project_id' => $project->id] + $step)->save();
                $ids[$code] = (int) $model->id;
            }
            // Default rows of the managed e-mail templates the new version uses (never overwrites an existing key).
            $templates = app(ManagedEmailTemplates::class);
            $templates->ensureDefaults($templates->keysUsedBy($newSteps));
            $dropped = array_values(array_diff(array_keys($oldByCode), array_keys($newSteps)));
            WorkflowStep::query()->where('project_id', $project->id)->whereNotIn('step_code', array_keys($newSteps))->delete();
            WorkflowConnection::query()->where('project_id', $project->id)->delete();
            foreach ($newConnections as $connection) {
                WorkflowConnection::query()->create([
                    'project_id' => $project->id,
                    'from_step_id' => $ids[$connection['from']],
                    'to_step_id' => $ids[$connection['to']],
                    'condition' => $connection['condition'],
                ]);
            }

            $metadata = [];
            foreach (['description', 'trigger_event', 'target_entity_type'] as $field) {
                if (array_key_exists($field, $row)) {
                    $metadata[$field] = is_string($row[$field]) ? trim($row[$field]) : $row[$field];
                }
            }
            // A name nobody changed here (still a template name, in any language) follows the site language.
            if (in_array((string) $project->name, WorkflowTemplateTranslations::names($bundle), true)) {
                $metadata['name'] = WorkflowTemplateTranslations::name($bundle);
            }
            $earlier = array_slice($this->hashes->knownHashes($project), 0, WorkflowTemplateHash::HISTORY);
            $project->forceFill($metadata)->save();
            $project->forceFill(['source' => [
                'kind' => 'hub',
                'key' => $bundle->key,
                'version' => $bundle->version,
                'checksum' => $bundle->contentSha256,
                'installed_hash' => $this->hashes->ofDraft($project),
                'installed_hashes' => $earlier,
            ]])->save();

            WorkflowAudit::project(self::AUDIT_UPDATED, $project, [
                'template' => $bundle->key,
                'from_version' => WorkflowBundle::versionLabel($fromVersion),
                'to_version' => WorkflowBundle::versionLabel($bundle->version),
                'steps_added' => array_values(array_diff(array_keys($newSteps), array_keys($oldByCode))),
                'steps_removed' => $dropped,
                'answers_kept' => $kept,
            ], 'warning', (int) $user->getKey());

            return $project;
        });
    }

    /**
     * Step rows of a package workflow keyed by step code, configs upgraded like an import
     * (the inspector already refused any shape or type problem).
     *
     * @param array<string, mixed> $row
     * @return array<string, array<string, mixed>>
     */
    protected function steps(array $row): array
    {
        $upgrader = new StepConfigUpgrader();
        $steps = [];
        foreach ((array) ($row['steps'] ?? []) as $step) {
            if (!is_array($step) || !is_string($step['step_code'] ?? null)) {
                continue;
            }
            $code = trim($step['step_code']);
            $type = (string) $step['step_type'];
            $config = is_array($step['step_config'] ?? null) ? $upgrader->upgrade($step['step_config'], $type)['config'] : [];
            $steps[$code] = [
                'step_code' => $code,
                'name' => is_string($step['name'] ?? null) ? $step['name'] : null,
                'step_type' => $type,
                'step_config' => $config,
                'input_schema' => is_array($step['input_schema'] ?? null) ? $step['input_schema'] : null,
                'output_schema' => is_array($step['output_schema'] ?? null) ? $step['output_schema'] : null,
                'position_x' => (int) ($step['position_x'] ?? 0),
                'position_y' => (int) ($step['position_y'] ?? 0),
                'is_start' => filter_var($step['is_start'] ?? false, FILTER_VALIDATE_BOOLEAN),
            ];
        }

        return $steps;
    }

    /**
     * @return array{project_id: int, project_uuid: string, slug: string, name: string, version: int, version_label: string, is_active: bool, as_copy: bool, copy_reason: ?string, updated_in_place: bool, setup_url: string}
     */
    protected function result(WorkflowProject $project, WorkflowBundle $bundle, ?string $copyReason, bool $updated): array
    {
        return [
            'project_id' => (int) $project->getKey(),
            'project_uuid' => (string) $project->uuid,
            'slug' => (string) $project->slug,
            'name' => (string) $project->name,
            'version' => $bundle->version,
            'version_label' => WorkflowBundle::versionLabel($bundle->version),
            'is_active' => (bool) $project->is_active,
            'as_copy' => $copyReason !== null,
            'copy_reason' => $copyReason,
            'updated_in_place' => $updated,
            'setup_url' => WorkflowHubCatalog::setupUrl((string) $project->slug),
        ];
    }

    protected static function upToDate(): WorkflowHubException
    {
        return new WorkflowHubException(WorkflowHubException::UP_TO_DATE, 'this version (or a newer one) is already installed and not modified', 409);
    }

    protected static function isEmpty(mixed $value): bool
    {
        return $value === null || (is_string($value) && trim($value) === '') || $value === [];
    }

    /**
     * The template slug when free (first install), else `<slug>-<version>`, then `-2`, `-3`… (deleted projects count).
     */
    protected function freeSlug(string $slug, ?int $copyVersion): string
    {
        $base = $copyVersion !== null ? $slug . '-' . $copyVersion : $slug;
        $base = substr($base, 0, 180);
        $candidate = $base;
        for ($n = 2; WorkflowProject::withTrashed()->where('slug', $candidate)->exists(); $n++) {
            $candidate = $base . '-' . $n;
        }

        return $candidate;
    }
}
