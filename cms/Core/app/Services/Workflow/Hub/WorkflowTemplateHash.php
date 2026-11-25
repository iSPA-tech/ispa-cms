<?php

declare(strict_types=1);

namespace Cms\Core\Services\Workflow\Hub;

use Cms\Core\Models\Workflows\WorkflowProject;
use Cms\Core\Services\Workflow\Setup\WorkflowSetupService;
use Cms\Core\Models\Workflows\WorkflowVersion;
use Cms\Core\Services\Workflow\Versioning\WorkflowVersionSnapshot;

/**
 * Content hash of a workflow installed from the hub library, used to tell a locally modified copy from one that is only
 * set up (`source.installed_hash` is this hash of the draft right after the install).
 *
 * It hashes the canonical graph ({@see WorkflowVersionSnapshot}) without what does not change behaviour or what the setup
 * screen is meant to fill in: step row ids and positions, connection row ids, the schedule (cron, timezone), and the
 * step config fields that are setup questions of the project (plus `assignees` of a reviewers question). So answering
 * the setup questions or moving boxes in the designer is not a modification; any other edit of a step, connection or
 * run setting is. The left-out fields come from {@see WorkflowSetupService::answerFields()} of the hashed graph itself
 * (draft or published), the same list an update in place carries over.
 *
 * After an update in place `source.installed_hashes` keeps up to {@see self::HISTORY} earlier installed hashes, so the
 * older template version that is still published until the next publish does not count as a local change.
 */
class WorkflowTemplateHash
{
    public function __construct(protected WorkflowSetupService $setup)
    {
    }

    /** Most earlier installed hashes kept in `source.installed_hashes` after updates in place. */
    public const HISTORY = 5;

    /** Hash of the project's current draft. */
    public function ofDraft(WorkflowProject $project): string
    {
        $graph = WorkflowVersionSnapshot::fromDraft($project);

        return $this->of($graph, $this->setupFields($project, $graph));
    }

    /**
     * Whether the draft or the published version matches none of the known installed hashes; false for a project with
     * no hub provenance. Known = `source.installed_hash` plus `source.installed_hashes` (earlier template versions this
     * project was updated from in place), so a published older template version is not a local modification.
     */
    public function modified(WorkflowProject $project): bool
    {
        $known = $this->knownHashes($project);
        if ($known === []) {
            return false;
        }
        $draft = WorkflowVersionSnapshot::fromDraft($project);
        if (!in_array($this->of($draft, $this->setupFields($project, $draft)), $known, true)) {
            return true;
        }
        $published = (int) ($project->published_version_id ?? 0) > 0 ? WorkflowVersion::query()->find($project->published_version_id) : null;
        if ($published === null) {
            return false;
        }
        $graph = $published->graphData();

        return !in_array($this->of($graph, $this->setupFields($project, $graph)), $known, true);
    }

    /**
     * `source.installed_hash` first, then the earlier ones (old rows carry only the single hash).
     *
     * @return list<string>
     */
    public function knownHashes(WorkflowProject $project): array
    {
        $source = is_array($project->source) ? $project->source : [];
        $hashes = [$source['installed_hash'] ?? null, ...(is_array($source['installed_hashes'] ?? null) ? $source['installed_hashes'] : [])];

        return array_values(array_unique(array_filter($hashes, static fn (mixed $h): bool => is_string($h) && $h !== '')));
    }

    /**
     * Setup answer fields of a graph of the project (step code => fields), {@see WorkflowSetupService::answerFields()}.
     *
     * @param array<string, mixed>|null $graph defaults to the draft
     * @return array<string, list<string>>
     */
    public function setupFields(WorkflowProject $project, ?array $graph = null): array
    {
        return $this->setup->answerFields($project, $graph ?? WorkflowVersionSnapshot::fromDraft($project));
    }

    /**
     * @param array<string, mixed>        $graph
     * @param array<string, list<string>> $fields step code => config fields to leave out
     */
    protected function of(array $graph, array $fields): string
    {
        $steps = [];
        foreach ((array) ($graph['steps'] ?? []) as $step) {
            $code = (string) ($step['step_code'] ?? '');
            $config = is_array($step['step_config'] ?? null) ? $step['step_config'] : [];
            foreach ($fields[$code] ?? [] as $field) {
                unset($config[$field]);
            }
            unset($step['id'], $step['position_x'], $step['position_y']);
            $step['step_config'] = $config;
            $steps[] = $step;
        }
        $connections = [];
        foreach ((array) ($graph['connections'] ?? []) as $connection) {
            unset($connection['from_step_id'], $connection['to_step_id']);
            $connections[] = $connection;
        }
        $settings = (array) ($graph['settings'] ?? []);
        unset($settings['schedule_cron'], $settings['schedule_timezone']);

        return WorkflowVersionSnapshot::hash(['steps' => $steps, 'connections' => $connections, 'settings' => $settings]);
    }
}
