<?php

declare(strict_types=1);

namespace Cms\Core\Services\ConfigPackage\Sections;

use Cms\Core\Models\Workflows\WorkflowConnection;
use Cms\Core\Models\Workflows\WorkflowProject;
use Cms\Core\Models\Workflows\WorkflowStep;
use Cms\Core\Services\ConfigPackage\ApplyContext;
use Cms\Core\Services\ConfigPackage\ApplyResult;
use Cms\Core\Services\ConfigPackage\DiffContext;
use Cms\Core\Services\ConfigPackage\ExportContext;
use Cms\Core\Services\ConfigPackage\SecretGuard;
use Cms\Core\Services\ConfigPackage\SectionDiff;
use Cms\Core\Services\ConfigPackage\SectionExport;
use Cms\Core\Workflows\Upgrade\StepConfigUpgrader;
use Illuminate\Support\Facades\DB;

/**
 * Workflows section of the configuration package: workflow projects with their steps and connections.
 *
 * Rows are `{ slug, name, trigger_event, target_entity_type, is_active, tags, description, steps, connections }`.
 * Projects are matched by `slug`, steps by `step_code` within the project, and connections point at step codes
 * (`from`/`to`); no numeric id ever enters a package. Workflow instances and agent schedules are never exported.
 *
 * Safety rules, re-checked on every diff and apply so a forged package cannot bypass them:
 * - a workflow whose rows contain a secret-looking key or ciphertext (SecretGuard) is skipped on export
 *   (`contains_secret`) and blocked on import;
 * - a missing or duplicate `step_code`, an unknown `step_type` (the migration enum) or a connection that points at
 *   a step code outside the workflow is blocked;
 * - a new project is always created with `is_active = false`; an imported workflow never starts by itself and
 *   `is_active` of an existing project is never changed;
 * - an existing project only gets name, description, tags, trigger_event and target_entity_type updated;
 * - its steps and connections are replaced only when the operator chose `<slug>#graph` (off by default); the old
 *   rows are soft deleted (the models use SoftDeletes), never hard deleted;
 * - `step_config` passes through StepConfigUpgrader::upgrade() before it is compared or saved;
 * - import never deletes a project.
 */
class WorkflowsSection implements SectionAdapter
{
    /** Suffix of the per-project choice key that allows replacing steps and connections (`<slug>#graph`). */
    public const GRAPH_CHOICE_SUFFIX = '#graph';

    /** Values of the `step_type` enum of the workflow_steps migration. */
    public const STEP_TYPES = [
        'event', 'job', 'call_workflow', 'decision', 'webhook', 'ai_action', 'script', 'set_variable',
        'delay', 'schedule_at', 'approval', 'loop', 'parallel',
    ];

    private const METADATA_FIELDS = ['name', 'description', 'tags', 'trigger_event', 'target_entity_type'];

    /**
     * @inheritDoc
     */
    public function key(): string
    {
        return 'workflows';
    }

    /**
     * @inheritDoc
     *
     * Every non-deleted project is exported ordered by slug. Context key patterns are not used. A project that
     * contains a secret-looking key (`contains_secret`) or whose graph cannot be expressed by step codes
     * (`invalid_graph`: missing or duplicate step code, dangling connection) is counted in `skipped`.
     */
    public function export(ExportContext $c): SectionExport
    {
        $rows = [];
        $skipped = [];
        $guard = new SecretGuard();

        $projects = WorkflowProject::query()->orderBy('slug')->orderBy('id')->get();

        foreach ($projects as $project) {
            $row = $this->exportRow($project);

            if ($row === null) {
                $skipped['invalid_graph'] = ($skipped['invalid_graph'] ?? 0) + 1;

                continue;
            }

            if ($guard->findSecrets([$row]) !== []) {
                $skipped['contains_secret'] = ($skipped['contains_secret'] ?? 0) + 1;

                continue;
            }

            $rows[] = $row;
        }

        ksort($skipped);

        return new SectionExport($rows, $skipped);
    }

    /**
     * @inheritDoc
     *
     * Items carry `key` (the slug), `action`, `reason`, `before`, `after` and `warnings`. A `create` item states
     * `after.is_active = false`. An `update` item may carry `graph`: `{ choice_key, differs, steps_before,
     * steps_after, connections_before, connections_after, would_remove_steps, would_remove_connections,
     * needs_choice: true, default_choice: false }`.
     */
    public function diff(array $rows, DiffContext $c): SectionDiff
    {
        $slugCounts = $this->slugCounts($rows);
        $items = [];

        foreach ($rows as $row) {
            $item = $this->diffRow(is_array($row) ? $row : [], $slugCounts);
            unset($item['_project_id'], $item['_prepared'], $item['_metadata_differs']);
            $items[] = $item;
        }

        return new SectionDiff($items);
    }

    /**
     * @inheritDoc
     *
     * `created` and `updated` outcomes are both counted in ApplyResult::$updated. Items excluded with
     * `choices[<slug>] = false` are skipped. The graph of an existing project is replaced only when
     * `choices['<slug>#graph'] === true`.
     */
    public function apply(array $rows, ApplyContext $c): ApplyResult
    {
        $counts = ['updated' => 0, 'unchanged' => 0, 'skipped' => 0, 'blocked' => 0];
        $slugCounts = $this->slugCounts($rows);
        $items = [];

        foreach ($rows as $row) {
            $diff = $this->diffRow(is_array($row) ? $row : [], $slugCounts);
            $key = (string) $diff['key'];
            $reason = $diff['reason'];

            switch ($diff['action']) {
                case 'create':
                    if (!$c->isChosen($key)) {
                        $outcome = 'skipped';
                        $reason = 'not chosen';
                        ++$counts['skipped'];
                        break;
                    }

                    $this->createProject($diff['_prepared']);
                    $outcome = 'created';
                    ++$counts['updated'];
                    break;
                case 'update':
                    if (!$c->isChosen($key)) {
                        $outcome = 'skipped';
                        $reason = 'not chosen';
                        ++$counts['skipped'];
                        break;
                    }

                    $metadataChanged = (bool) $diff['_metadata_differs'];
                    $graphChosen = ($diff['graph']['differs'] ?? false) && ($c->choices[$key . self::GRAPH_CHOICE_SUFFIX] ?? null) === true;

                    if (!$metadataChanged && !$graphChosen) {
                        $outcome = 'skipped';
                        $reason = 'graph replace not chosen';
                        ++$counts['skipped'];
                        break;
                    }

                    $this->updateProject((int) $diff['_project_id'], $diff['_prepared'], $metadataChanged, $graphChosen);
                    $outcome = 'updated';
                    ++$counts['updated'];
                    break;
                case 'unchanged':
                    $outcome = 'unchanged';
                    ++$counts['unchanged'];
                    break;
                default:
                    $outcome = 'blocked';
                    ++$counts['blocked'];
            }

            $items[] = ['key' => $key, 'outcome' => $outcome, 'reason' => $reason, 'warnings' => $diff['warnings']];
        }

        return new ApplyResult($counts['updated'], $counts['unchanged'], $counts['skipped'], $counts['blocked'], $items);
    }

    /**
     * @inheritDoc
     *
     * Intentionally a no-op: workflow definitions are read from the database on every run, no cache is kept.
     */
    public function afterApply(ApplyContext $c): void
    {
    }

    /**
     * Build the export row of a project, or null when its graph cannot be expressed by step codes.
     *
     * @return array<string, mixed>|null
     */
    private function exportRow(WorkflowProject $project): ?array
    {
        $steps = WorkflowStep::query()->where('project_id', $project->id)->orderBy('id')->get();
        $codeById = [];
        $seen = [];
        $stepRows = [];

        foreach ($steps as $step) {
            $code = is_string($step->step_code) ? trim($step->step_code) : '';

            if ($code === '' || isset($seen[$code])) {
                return null;
            }

            $seen[$code] = true;
            $codeById[(int) $step->id] = $code;
            $stepRows[] = [
                'step_code' => $code,
                'name' => $step->name,
                'step_type' => $step->step_type,
                'step_config' => $this->jsonValue($step->step_config),
                'input_schema' => $this->jsonValue($step->input_schema),
                'output_schema' => $this->jsonValue($step->output_schema),
                'position_x' => (int) $step->position_x,
                'position_y' => (int) $step->position_y,
                'is_start' => (bool) $step->is_start,
            ];
        }

        $connectionRows = [];
        foreach (WorkflowConnection::query()->where('project_id', $project->id)->orderBy('id')->get() as $connection) {
            $from = $codeById[(int) $connection->from_step_id] ?? null;
            $to = $codeById[(int) $connection->to_step_id] ?? null;

            if ($from === null || $to === null) {
                return null;
            }

            $connectionRows[] = ['from' => $from, 'to' => $to, 'condition' => $this->jsonValue($connection->condition)];
        }

        return [
            'slug' => (string) $project->slug,
            'name' => (string) $project->name,
            'trigger_event' => $project->trigger_event,
            'target_entity_type' => (string) $project->target_entity_type,
            'is_active' => (bool) $project->is_active,
            'tags' => $project->tags,
            'description' => $project->description,
            'steps' => $stepRows,
            'connections' => $connectionRows,
        ];
    }

    /**
     * Compare one incoming row with the target.
     *
     * @param array<string, mixed> $row
     * @param array<string, int> $slugCounts
     *
     * @return array<string, mixed>
     */
    private function diffRow(array $row, array $slugCounts): array
    {
        $slug = is_string($row['slug'] ?? null) ? trim($row['slug']) : '';
        $item = ['key' => $slug, 'action' => 'blocked', 'reason' => null, 'before' => null, 'after' => null, 'warnings' => []];

        $prepared = $this->prepare($row, $slug, $slugCounts, $reason);
        if ($prepared === null) {
            $item['reason'] = $reason;

            return $item;
        }

        $existing = WorkflowProject::withTrashed()->where('slug', $slug)->first();

        if ($existing === null) {
            $item['action'] = 'create';
            $item['after'] = $prepared['metadata'] + ['is_active' => false];
            $item['graph'] = $this->graphSummary($prepared['graph'], ['steps' => [], 'connections' => []], false);
            $item['_prepared'] = $prepared;

            return $item;
        }

        if ($existing->trashed()) {
            $item['reason'] = 'a deleted workflow with this slug exists on this site';

            return $item;
        }

        $before = $this->metadataOf($existing);
        $currentGraph = $this->graphOf($existing);
        $graphDiffers = $this->normalize($currentGraph) !== $this->normalize($prepared['graph']);
        $metadataDiffers = $this->normalize($before) !== $this->normalize($prepared['metadata']);

        $item['before'] = $before;
        $item['after'] = $prepared['metadata'];
        $item['_project_id'] = (int) $existing->id;
        $item['_prepared'] = $prepared;
        $item['graph'] = $this->graphSummary($prepared['graph'], $currentGraph, $graphDiffers);

        if (!$metadataDiffers && !$graphDiffers) {
            $item['action'] = 'unchanged';

            return $item;
        }

        $item['_metadata_differs'] = $metadataDiffers;
        $item['action'] = 'update';

        return $item;
    }

    /**
     * Validate a row and build its normalised metadata and graph, or return null with a reason.
     *
     * @param array<string, mixed> $row
     * @param array<string, int> $slugCounts
     *
     * @return array{slug: string, metadata: array<string, mixed>, graph: array<string, mixed>}|null
     */
    private function prepare(array $row, string $slug, array $slugCounts, ?string &$reason): ?array
    {
        $reason = null;

        if ($slug === '') {
            $reason = 'workflow slug is missing';

            return null;
        }

        if (($slugCounts[$slug] ?? 0) > 1) {
            $reason = 'ambiguous: the package lists this slug more than once';

            return null;
        }

        if ((new SecretGuard())->findSecrets([$row]) !== []) {
            $reason = 'contains_secret';

            return null;
        }

        $name = $row['name'] ?? null;
        $target = $row['target_entity_type'] ?? null;
        if (!is_string($name) || trim($name) === '' || !is_string($target) || trim($target) === '') {
            $reason = 'name and target_entity_type are required';

            return null;
        }

        $trigger = $row['trigger_event'] ?? null;
        $description = $row['description'] ?? null;
        $tags = $row['tags'] ?? null;
        if (($trigger !== null && !is_string($trigger)) || ($description !== null && !is_string($description))
            || ($tags !== null && (!is_array($tags) || !array_is_list($tags)))) {
            $reason = 'trigger_event, description or tags have the wrong type';

            return null;
        }

        $steps = $row['steps'] ?? [];
        $connections = $row['connections'] ?? [];
        if (!is_array($steps) || !array_is_list($steps) || !is_array($connections) || !array_is_list($connections)) {
            $reason = 'steps and connections must be lists';

            return null;
        }

        $upgrader = new StepConfigUpgrader();
        $stepRows = [];
        foreach ($steps as $step) {
            $normalized = $this->prepareStep(is_array($step) ? $step : [], $stepRows, $upgrader, $reason);
            if ($normalized === null) {
                return null;
            }

            $stepRows[$normalized['step_code']] = $normalized;
        }

        $connectionRows = [];
        foreach ($connections as $connection) {
            $connection = is_array($connection) ? $connection : [];
            $from = $connection['from'] ?? null;
            $to = $connection['to'] ?? null;
            $condition = $connection['condition'] ?? null;

            if (!is_string($from) || !is_string($to) || !isset($stepRows[$from]) || !isset($stepRows[$to])) {
                $reason = 'a connection points at a step code that is not in this workflow';

                return null;
            }

            if ($condition !== null && !is_array($condition)) {
                $reason = 'a connection condition must be an object';

                return null;
            }

            $connectionRows[] = ['from' => $from, 'to' => $to, 'condition' => $condition];
        }

        return [
            'slug' => $slug,
            'metadata' => [
                'name' => trim($name),
                'description' => $description,
                'tags' => $tags,
                'trigger_event' => $trigger,
                'target_entity_type' => trim($target),
            ],
            'graph' => ['steps' => $stepRows, 'connections' => $connectionRows],
        ];
    }

    /**
     * Validate and normalise one incoming step; step_config goes through StepConfigUpgrader::upgrade().
     *
     * @param array<string, mixed> $step
     * @param array<string, array<string, mixed>> $known Steps already accepted, keyed by step code.
     *
     * @return array<string, mixed>|null
     */
    private function prepareStep(array $step, array $known, StepConfigUpgrader $upgrader, ?string &$reason): ?array
    {
        $code = $step['step_code'] ?? null;
        if (!is_string($code) || trim($code) === '') {
            $reason = 'a step has no step_code';

            return null;
        }

        $code = trim($code);
        if (isset($known[$code])) {
            $reason = 'duplicate step_code ' . $code;

            return null;
        }

        $type = $step['step_type'] ?? null;
        if (!is_string($type) || !in_array($type, self::STEP_TYPES, true)) {
            $reason = 'step ' . $code . ' has an unknown step_type';

            return null;
        }

        $name = $step['name'] ?? null;
        $config = $step['step_config'] ?? null;
        $input = $step['input_schema'] ?? null;
        $output = $step['output_schema'] ?? null;
        $x = $this->wholeNumber($step['position_x'] ?? 0);
        $y = $this->wholeNumber($step['position_y'] ?? 0);

        if (($name !== null && !is_string($name)) || ($config !== null && !is_array($config))
            || ($input !== null && !is_array($input)) || ($output !== null && !is_array($output))
            || $x === null || $y === null) {
            $reason = 'step ' . $code . ' has a field of the wrong type';

            return null;
        }

        if (is_array($config)) {
            $config = $upgrader->upgrade($config, $type)['config'];
        }

        return [
            'step_code' => $code,
            'name' => $name,
            'step_type' => $type,
            'step_config' => $config,
            'input_schema' => $input,
            'output_schema' => $output,
            'position_x' => $x,
            'position_y' => $y,
            'is_start' => filter_var($step['is_start'] ?? false, FILTER_VALIDATE_BOOLEAN),
        ];
    }

    /**
     * Create a new inactive project with its graph.
     *
     * @param array{slug: string, metadata: array<string, mixed>, graph: array<string, mixed>} $prepared
     */
    private function createProject(array $prepared): void
    {
        DB::transaction(function () use ($prepared): void {
            $project = new WorkflowProject($prepared['metadata'] + ['slug' => $prepared['slug']]);
            // Never trust the package: an imported workflow cannot start by itself.
            $project->is_active = false;
            $project->save();

            $this->writeGraph($project, $prepared['graph']);
        });
    }

    /**
     * Update metadata and/or replace the graph of an existing project; is_active is never touched.
     *
     * @param array{slug: string, metadata: array<string, mixed>, graph: array<string, mixed>} $prepared
     */
    private function updateProject(int $projectId, array $prepared, bool $metadata, bool $graph): void
    {
        DB::transaction(function () use ($projectId, $prepared, $metadata, $graph): void {
            $project = WorkflowProject::query()->findOrFail($projectId);

            if ($metadata) {
                $project->fill(array_intersect_key($prepared['metadata'], array_flip(self::METADATA_FIELDS)));
                $project->save();
            }

            if ($graph) {
                // Soft delete: the models use SoftDeletes, so the old graph stays recoverable.
                WorkflowConnection::query()->where('project_id', $projectId)->delete();
                WorkflowStep::query()->where('project_id', $projectId)->delete();
                $this->writeGraph($project, $prepared['graph']);
            }
        });
    }

    /**
     * Insert steps then connections of a graph for a project.
     *
     * @param array<string, mixed> $graph
     */
    private function writeGraph(WorkflowProject $project, array $graph): void
    {
        $ids = [];

        foreach ($graph['steps'] as $code => $step) {
            $ids[$code] = (int) WorkflowStep::query()->create(['project_id' => $project->id] + $step)->id;
        }

        foreach ($graph['connections'] as $connection) {
            WorkflowConnection::query()->create([
                'project_id' => $project->id,
                'from_step_id' => $ids[$connection['from']],
                'to_step_id' => $ids[$connection['to']],
                'condition' => $connection['condition'],
            ]);
        }
    }

    /**
     * Metadata fields of an existing project in package shape.
     *
     * @return array<string, mixed>
     */
    private function metadataOf(WorkflowProject $project): array
    {
        return [
            'name' => (string) $project->name,
            'description' => $project->description,
            'tags' => $project->tags,
            'trigger_event' => $project->trigger_event,
            'target_entity_type' => (string) $project->target_entity_type,
        ];
    }

    /**
     * Current graph of an existing project in the same normalised shape `prepare()` builds.
     *
     * @return array<string, mixed>
     */
    private function graphOf(WorkflowProject $project): array
    {
        $steps = [];
        $codeById = [];

        foreach (WorkflowStep::query()->where('project_id', $project->id)->orderBy('id')->get() as $step) {
            $code = (string) $step->step_code;
            $codeById[(int) $step->id] = $code;
            $steps[$code] = [
                'step_code' => $code,
                'name' => $step->name,
                'step_type' => $step->step_type,
                'step_config' => $this->jsonValue($step->step_config),
                'input_schema' => $this->jsonValue($step->input_schema),
                'output_schema' => $this->jsonValue($step->output_schema),
                'position_x' => (int) $step->position_x,
                'position_y' => (int) $step->position_y,
                'is_start' => (bool) $step->is_start,
            ];
        }

        $connections = [];
        foreach (WorkflowConnection::query()->where('project_id', $project->id)->orderBy('id')->get() as $connection) {
            $connections[] = [
                'from' => $codeById[(int) $connection->from_step_id] ?? '?',
                'to' => $codeById[(int) $connection->to_step_id] ?? '?',
                'condition' => $this->jsonValue($connection->condition),
            ];
        }

        return ['steps' => $steps, 'connections' => $connections];
    }

    /**
     * Counts shown to the operator for the replace-graph choice.
     *
     * @param array<string, mixed> $incoming
     * @param array<string, mixed> $current
     *
     * @return array<string, mixed>
     */
    private function graphSummary(array $incoming, array $current, bool $differs): array
    {
        $summary = [
            'differs' => $differs,
            'steps_before' => count($current['steps']),
            'steps_after' => count($incoming['steps']),
            'connections_before' => count($current['connections']),
            'connections_after' => count($incoming['connections']),
            'would_remove_steps' => $differs ? count($current['steps']) : 0,
            'would_remove_connections' => $differs ? count($current['connections']) : 0,
        ];

        if ($differs && ($current['steps'] !== [] || $current['connections'] !== [])) {
            $summary['needs_choice'] = true;
            $summary['default_choice'] = false;
        }

        return $summary;
    }

    /**
     * Count incoming rows per slug.
     *
     * @param list<mixed> $rows
     *
     * @return array<string, int>
     */
    private function slugCounts(array $rows): array
    {
        $counts = [];
        foreach ($rows as $row) {
            $slug = is_array($row) && is_string($row['slug'] ?? null) ? trim($row['slug']) : '';
            if ($slug !== '') {
                $counts[$slug] = ($counts[$slug] ?? 0) + 1;
            }
        }

        return $counts;
    }

    /**
     * Whole number from an int or an integer string, null when invalid.
     */
    private function wholeNumber(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        return is_string($value) && preg_match('/^-?\d{1,9}$/', trim($value)) === 1 ? (int) trim($value) : null;
    }

    /**
     * JSON column value as an array (a double-encoded string is decoded), or null when empty.
     *
     * @return array<array-key, mixed>|null
     */
    private function jsonValue(mixed $value): ?array
    {
        for ($depth = 0; $depth < 2 && is_string($value); ++$depth) {
            $value = json_decode($value, true);
        }

        return is_array($value) ? $value : null;
    }

    /**
     * Recursively sort associative keys so two structures compare equal regardless of key order.
     */
    private function normalize(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }

        $value = array_map(fn ($child) => $this->normalize($child), $value);

        if (!array_is_list($value)) {
            ksort($value);
        }

        return $value === [] ? [] : $value;
    }
}
