<?php

declare(strict_types=1);

namespace Cms\Core\Services\Workflow\Versioning;

use Cms\Core\Models\Workflows\WorkflowConnection;
use Cms\Core\Models\Workflows\WorkflowInstance;
use Cms\Core\Models\Workflows\WorkflowProject;
use Cms\Core\Models\Workflows\WorkflowStep;
use Cms\Core\Models\Workflows\WorkflowVersion;
use Cms\Core\Services\Workflow\Exceptions\WorkflowIntegrityException;
use Cms\Core\Services\Workflow\Exceptions\WorkflowProjectNotPublished;
use Cms\Core\Services\Workflow\Triggers\WorkflowTriggers;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

/**
 * The graph a run executes: its version's snapshot, verified, hydrated into non-persisted step/connection models.
 *
 * Hydrated steps keep the draft row ids (the snapshot stores them), so `workflow_instance_steps.step_id` keeps its
 * foreign key and the run panel keeps working. Hydrated connections have no id. Nothing here reads
 * `workflow_steps` / `workflow_connections`: a draft edit or a database write to those rows never reaches a run.
 *
 * Fail closed: a run without a version is refused (WorkflowProjectNotPublished); a version whose stored JSON does not
 * match its content hash, whose seal does not match, that is missing or belongs to another project raises
 * WorkflowIntegrityException, and so does a version that never went live (assertLive()).
 *
 * While the executor runs a step it pushes the run's graph (enter()/leave()), so runners that look at their own
 * outgoing edges (outgoing()) read the version, not the draft.
 *
 * @package Cms\Core\Services\Workflow\Versioning
 * @since   2026-11-24
 */
final class RunGraph
{
    /** @var list<array{steps: EloquentCollection, connections: EloquentCollection}> */
    private static array $stack = [];

    /**
     * @return array{steps: EloquentCollection<int, WorkflowStep>, connections: EloquentCollection<int, WorkflowConnection>, settings: array<string, mixed>, version_id: int}
     *
     * @throws WorkflowProjectNotPublished When the run has no version.
     * @throws WorkflowIntegrityException  When the version is missing, foreign or tampered with.
     */
    public static function forInstance(WorkflowInstance $instance): array
    {
        $versionId = (int) ($instance->version_id ?? 0);
        if ($versionId <= 0) {
            throw new WorkflowProjectNotPublished('This run has no workflow version, so it can not run.');
        }

        $version = WorkflowVersion::query()->find($versionId);
        if (!$version) {
            throw new WorkflowIntegrityException((int) $instance->project_id, $versionId, 'version_missing');
        }
        if ((int) $version->project_id !== (int) $instance->project_id) {
            throw new WorkflowIntegrityException((int) $instance->project_id, $versionId, 'project_mismatch');
        }

        return self::forVersion($version);
    }

    /**
     * @return array{steps: EloquentCollection<int, WorkflowStep>, connections: EloquentCollection<int, WorkflowConnection>, settings: array<string, mixed>, version_id: int}
     *
     * @throws WorkflowIntegrityException
     */
    public static function forVersion(WorkflowVersion $version): array
    {
        self::assertLive($version);
        self::verify($version);
        $graph = $version->graphData();

        return self::hydrate($graph, (int) $version->project_id) + [
            'settings' => is_array($graph['settings'] ?? null) ? $graph['settings'] : [],
            'version_id' => (int) $version->id,
        ];
    }

    /**
     * The project's published version, verified: status `published`, named by the project's sealed live pointer, graph
     * and run seal intact. Runs already started keep their version through forInstance() (superseded allowed).
     *
     * @throws WorkflowProjectNotPublished When the project has none.
     * @throws WorkflowIntegrityException  When it is missing, foreign or tampered with.
     */
    public static function published(WorkflowProject $project): WorkflowVersion
    {
        $versionId = (int) ($project->published_version_id ?? 0);
        if ($versionId <= 0) {
            throw new WorkflowProjectNotPublished();
        }

        $version = WorkflowVersion::query()->find($versionId);
        if (!$version) {
            throw new WorkflowIntegrityException((int) $project->getKey(), $versionId, 'version_missing');
        }
        if ((int) $version->project_id !== (int) $project->getKey()) {
            throw new WorkflowIntegrityException((int) $project->getKey(), $versionId, 'project_mismatch');
        }

        // New runs (review fix 15): only the version the project's SEALED pointer names, and only while it is the
        // published one. A database write that re-points the project at an older (superseded) version, or flips a
        // status, fails closed like a tampered graph.
        if ((string) $version->status !== WorkflowVersion::STATUS_PUBLISHED) {
            throw new WorkflowIntegrityException((int) $project->getKey(), $versionId, 'version_not_published');
        }
        if (WorkflowVersionSeal::pointerSealed() && !WorkflowVersionSeal::verifyPointer(self::withPointerSeal($project))) {
            throw new WorkflowIntegrityException((int) $project->getKey(), $versionId, 'pointer_seal_mismatch');
        }
        self::verify($version);

        return $version;
    }

    /**
     * The project with its pointer seal loaded (a caller may hold a model read with a column list).
     */
    private static function withPointerSeal(WorkflowProject $project): WorkflowProject
    {
        if (array_key_exists('published_version_seal', $project->getAttributes())) {
            return $project;
        }

        return WorkflowProject::query()->whereKey($project->getKey())->first() ?? $project;
    }

    /**
     * Only a version that went live (published, or superseded by a later one) can run. A version waiting for change
     * control (or cancelled / rejected) never runs, even when a database write points a run at it (B3).
     *
     * @throws WorkflowIntegrityException `version_not_live`.
     */
    public static function assertLive(WorkflowVersion $version): void
    {
        if (!in_array((string) $version->status, WorkflowVersionSeal::LIVE_STATUSES, true)) {
            throw new WorkflowIntegrityException((int) $version->project_id, (int) $version->id, 'version_not_live');
        }
    }

    /**
     * @throws WorkflowIntegrityException `hash_mismatch` or `seal_mismatch`.
     */
    public static function verify(WorkflowVersion $version): void
    {
        $raw = $version->getAttributes()['graph'] ?? null;
        $hashOk = is_string($raw) && hash_equals((string) $version->content_hash, WorkflowVersionSnapshot::hashText($raw));
        if (!$hashOk) {
            throw new WorkflowIntegrityException((int) $version->project_id, (int) $version->id, 'hash_mismatch');
        }
        if (!WorkflowVersionSeal::verify($version)) {
            throw new WorkflowIntegrityException((int) $version->project_id, (int) $version->id, 'seal_mismatch');
        }
    }

    /**
     * Decoded graph of the project's published version WITHOUT verifying it, for routing decisions taken before a
     * run starts (trigger index, schedule, start node). The run start verifies the version and refuses a bad one.
     *
     * @return array<string, mixed>|null
     */
    public static function publishedGraph(WorkflowProject|int $project): ?array
    {
        $versionId = $project instanceof WorkflowProject
            ? (int) ($project->published_version_id ?? 0)
            : (int) (WorkflowProject::query()->whereKey($project)->value('published_version_id') ?? 0);
        if ($versionId <= 0) {
            return null;
        }

        return WorkflowVersion::query()->find($versionId)?->graphData();
    }

    /**
     * Run settings of a version (unverified decode, for routing such as the error workflow), [] when there is none.
     *
     * @return array<string, mixed>
     */
    public static function settingsOf(?int $versionId): array
    {
        if (!$versionId) {
            return [];
        }
        $settings = WorkflowVersion::query()->find($versionId)?->graphData()['settings'] ?? [];

        return is_array($settings) ? $settings : [];
    }

    /**
     * Non-persisted models of a canonical graph.
     *
     * @param array<string, mixed> $graph
     * @return array{steps: EloquentCollection<int, WorkflowStep>, connections: EloquentCollection<int, WorkflowConnection>}
     */
    public static function hydrate(array $graph, int $projectId): array
    {
        $steps = new EloquentCollection();
        foreach ((array) ($graph['steps'] ?? []) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $step = new WorkflowStep();
            $step->forceFill([
                'id' => (int) ($row['id'] ?? 0),
                'project_id' => $projectId,
                'step_code' => $row['step_code'] ?? null,
                'name' => $row['name'] ?? null,
                'step_type' => (string) ($row['step_type'] ?? ''),
                'step_config' => $row['step_config'] ?? null,
                'input_schema' => $row['input_schema'] ?? null,
                'output_schema' => $row['output_schema'] ?? null,
                'is_start' => (bool) ($row['is_start'] ?? false),
                'position_x' => (int) ($row['position_x'] ?? 0),
                'position_y' => (int) ($row['position_y'] ?? 0),
            ]);
            $steps->push($step);
        }

        $connections = new EloquentCollection();
        foreach ((array) ($graph['connections'] ?? []) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $connection = new WorkflowConnection();
            $connection->forceFill([
                'project_id' => $projectId,
                'from_step_id' => (int) ($row['from_step_id'] ?? 0),
                'to_step_id' => (int) ($row['to_step_id'] ?? 0),
                'condition' => $row['condition'] ?? null,
            ]);
            $connections->push($connection);
        }

        return ['steps' => $steps, 'connections' => $connections];
    }

    /**
     * The trigger start node of a graph's steps: its first trigger step (`is_start` first, then lowest id) — the same
     * pick as WorkflowTriggers::startStep() on the draft.
     *
     * @param Collection<int, WorkflowStep> $steps
     */
    public static function triggerStart(Collection $steps): ?WorkflowStep
    {
        return $steps
            ->filter(static fn (WorkflowStep $step): bool => in_array($step->step_type, WorkflowTriggers::TYPES, true))
            ->sortBy(static fn (WorkflowStep $step): array => [$step->is_start ? 0 : 1, (int) $step->id])
            ->first();
    }

    /**
     * Make $graph the graph of the step running now (executor only).
     *
     * @param array{steps: EloquentCollection, connections: EloquentCollection} $graph
     */
    public static function enter(array $graph): void
    {
        self::$stack[] = $graph;
    }

    public static function leave(): void
    {
        array_pop(self::$stack);
    }

    /**
     * Outgoing edges of a step for the live readers (approval revision edge, reject routing, named outputs).
     *
     * Order: the graph of the run executing now in this process; else the version of $instance (an approval decision
     * taken outside a run); else the draft rows — reached only when a runner is called outside any run (tests,
     * previews), never by the executor, which always pushes the run's graph first.
     *
     * @return Collection<int, WorkflowConnection>
     */
    public static function edgesFrom(int $stepId, ?WorkflowInstance $instance = null): Collection
    {
        $current = self::outgoing($stepId);
        if ($current !== null) {
            return $current;
        }

        if ($instance !== null) {
            try {
                return self::forInstance($instance)['connections']
                    ->filter(static fn (WorkflowConnection $c): bool => (int) $c->from_step_id === $stepId)
                    ->values();
            } catch (\Throwable) {
                // An unverifiable version has no usable edges; the run itself is refused when it resumes.
                return collect();
            }
        }

        return WorkflowConnection::query()->where('from_step_id', $stepId)->get();
    }

    /**
     * Outgoing edges of a step in the graph of the run executing now, or null when no run is executing in this
     * process (callers outside a run pass the instance to forInstance() instead).
     *
     * @return Collection<int, WorkflowConnection>|null
     */
    public static function outgoing(int $stepId): ?Collection
    {
        $current = end(self::$stack);
        if ($current === false) {
            return null;
        }

        return $current['connections']->filter(static fn (WorkflowConnection $c): bool => (int) $c->from_step_id === $stepId)->values();
    }
}
