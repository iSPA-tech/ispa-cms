<?php

declare(strict_types=1);

namespace Cms\Core\Services\Workflow\Versioning;

use Cms\Core\Constants\CoreTableConstant as Tables;
use Cms\Core\Models\Workflows\WorkflowProject;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;

/**
 * The canonical graph of a workflow version: what a run executes, frozen at publish time.
 *
 * ```
 * { format: 1,
 *   steps: [{ id, step_code, name, step_type, step_config, input_schema, output_schema, is_start, position_x, position_y }],
 *   connections: [{ from, to, from_step_id, to_step_id, condition }],
 *   settings: { trigger_event, target_entity_type, schedule_cron, schedule_timezone, error_workflow_project_id,
 *               budget_max_cost_per_run, budget_max_runs_per_day } }
 * ```
 *
 * Steps are sorted by step_code (then id) and keep the draft row `id`, so step executions of a run keep their
 * foreign key to `workflow_steps`. Connections are sorted by from/to step code and condition; their row ids are not
 * kept (the designer recreates every connection on each save). Only rows that are not soft-deleted count.
 *
 * Everything is read with plain database queries (no casts, no node registry), so the migration backfill and the
 * publish service produce byte-identical JSON from the same rows: both call fromDatabase() / build().
 *
 * @package Cms\Core\Services\Workflow\Versioning
 * @since   2026-11-24
 */
final class WorkflowVersionSnapshot
{
    public const FORMAT = 1;

    /** Project columns that change how a run behaves; they are frozen with the graph. */
    public const RUN_SETTINGS = [
        'trigger_event',
        'target_entity_type',
        'schedule_cron',
        'schedule_timezone',
        'error_workflow_project_id',
        'budget_max_cost_per_run',
        'budget_max_runs_per_day',
    ];

    private const JSON_FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR;

    /**
     * Canonical graph of the project's current draft (its live steps, connections and run settings).
     *
     * @return array<string, mixed>
     */
    public static function fromDraft(WorkflowProject $project): array
    {
        return self::fromDatabase((int) $project->getKey());
    }

    /**
     * Canonical graph read straight from the database rows of a project.
     *
     * @return array<string, mixed>
     */
    public static function fromDatabase(int $projectId, ?ConnectionInterface $db = null): array
    {
        $db ??= DB::connection();

        $project = $db->table(Tables::WORKFLOW_PROJECT)->where('id', $projectId)->first();
        $steps = $db->table(Tables::WORKFLOW_STEP)->where('project_id', $projectId)->whereNull('deleted_at')->get();
        $connections = $db->table(Tables::WORKFLOW_CONNECTION)->where('project_id', $projectId)->whereNull('deleted_at')->get();

        return self::build(
            (array) $project,
            $steps->map(static fn ($row) => (array) $row)->all(),
            $connections->map(static fn ($row) => (array) $row)->all(),
        );
    }

    /**
     * Pure canonicalization of raw rows (column => raw database value).
     *
     * @param array<string, mixed>             $project
     * @param list<array<string, mixed>>       $steps
     * @param list<array<string, mixed>>       $connections
     * @return array<string, mixed>
     */
    public static function build(array $project, array $steps, array $connections): array
    {
        $canonicalSteps = [];
        $codes = [];
        foreach ($steps as $row) {
            $id = (int) ($row['id'] ?? 0);
            $code = self::stringOrNull($row['step_code'] ?? null);
            $codes[$id] = $code;
            $canonicalSteps[] = [
                'id' => $id,
                'step_code' => $code,
                'name' => self::stringOrNull($row['name'] ?? null),
                'step_type' => (string) ($row['step_type'] ?? ''),
                'step_config' => self::decode($row['step_config'] ?? null),
                'input_schema' => self::decode($row['input_schema'] ?? null),
                'output_schema' => self::decode($row['output_schema'] ?? null),
                'is_start' => (bool) ($row['is_start'] ?? false),
                'position_x' => (int) ($row['position_x'] ?? 0),
                'position_y' => (int) ($row['position_y'] ?? 0),
            ];
        }

        usort($canonicalSteps, static fn (array $a, array $b): int => [(string) $a['step_code'], $a['id']] <=> [(string) $b['step_code'], $b['id']]);

        $canonicalConnections = [];
        foreach ($connections as $row) {
            $from = (int) ($row['from_step_id'] ?? 0);
            $to = (int) ($row['to_step_id'] ?? 0);
            $canonicalConnections[] = [
                'from' => $codes[$from] ?? null,
                'to' => $codes[$to] ?? null,
                'from_step_id' => $from,
                'to_step_id' => $to,
                'condition' => self::decode($row['condition'] ?? null),
            ];
        }

        usort($canonicalConnections, static fn (array $a, array $b): int => self::connectionSortKey($a) <=> self::connectionSortKey($b));

        return [
            'format' => self::FORMAT,
            'steps' => $canonicalSteps,
            'connections' => $canonicalConnections,
            'settings' => self::settings($project),
        ];
    }

    /**
     * Canonical JSON text of a graph (the exact text stored in `workflow_versions.graph`).
     *
     * @param array<string, mixed> $graph
     */
    public static function encode(array $graph): string
    {
        return json_encode($graph, self::JSON_FLAGS);
    }

    /**
     * sha256 of the canonical JSON of a graph.
     *
     * @param array<string, mixed> $graph
     */
    public static function hash(array $graph): string
    {
        return self::hashText(self::encode($graph));
    }

    public static function hashText(string $json): string
    {
        return hash('sha256', $json);
    }

    /**
     * @param array<string, mixed> $project
     * @return array<string, mixed>
     */
    private static function settings(array $project): array
    {
        $cost = $project['budget_max_cost_per_run'] ?? null;
        $runs = $project['budget_max_runs_per_day'] ?? null;
        $errorWorkflow = $project['error_workflow_project_id'] ?? null;

        return [
            'trigger_event' => self::stringOrNull($project['trigger_event'] ?? null),
            'target_entity_type' => self::stringOrNull($project['target_entity_type'] ?? null),
            'schedule_cron' => self::stringOrNull($project['schedule_cron'] ?? null),
            'schedule_timezone' => self::stringOrNull($project['schedule_timezone'] ?? null),
            'error_workflow_project_id' => is_numeric($errorWorkflow) && (int) $errorWorkflow > 0 ? (int) $errorWorkflow : null,
            // Decimals come back as "5.0000" (MariaDB, PostgreSQL) or 5 (SQLite): a fixed 4-decimal string is the same everywhere.
            'budget_max_cost_per_run' => is_numeric($cost) ? number_format((float) $cost, 4, '.', '') : null,
            'budget_max_runs_per_day' => is_numeric($runs) ? (int) $runs : null,
        ];
    }

    /**
     * @param array<string, mixed> $connection
     * @return array<int, mixed>
     */
    private static function connectionSortKey(array $connection): array
    {
        return [
            (string) $connection['from'],
            (string) $connection['to'],
            json_encode($connection['condition'], self::JSON_FLAGS),
            $connection['from_step_id'],
            $connection['to_step_id'],
        ];
    }

    private static function decode(mixed $raw): mixed
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        if (is_array($raw)) {
            return $raw;
        }
        if (!is_string($raw)) {
            return null;
        }

        $decoded = json_decode($raw, true);

        return json_last_error() === JSON_ERROR_NONE ? $decoded : null;
    }

    private static function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : (is_scalar($value) && $value !== '' && $value !== null ? (string) $value : null);
    }
}
