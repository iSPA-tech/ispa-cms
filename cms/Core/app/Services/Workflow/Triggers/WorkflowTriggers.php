<?php

namespace Cms\Core\Services\Workflow\Triggers;

use Cms\Core\Models\Workflows\WorkflowProject;
use Cms\Core\Models\Workflows\WorkflowStep;

/**
 * Trigger node types and the small lookups every trigger path shares.
 *
 * @package Cms\Core\Services\Workflow\Triggers
 * @since   2026.11 (2026-11-24)
 */
final class WorkflowTriggers
{
    public const MANUAL = 'trigger_manual';
    public const SCHEDULE = 'trigger_schedule';
    public const MODEL_EVENT = 'trigger_model_event';
    public const EVENT = 'trigger_event';
    public const WEBHOOK = 'trigger_webhook';
    public const SUB_WORKFLOW = 'trigger_sub_workflow';
    public const ERROR = 'trigger_error';

    public const TYPES = [self::MANUAL, self::SCHEDULE, self::MODEL_EVENT, self::EVENT, self::WEBHOOK, self::SUB_WORKFLOW, self::ERROR];

    /** Triggers that start runs nobody clicked: they act for the project's scheduler. */
    public const UNATTENDED = [self::SCHEDULE, self::MODEL_EVENT, self::EVENT, self::WEBHOOK, self::ERROR];

    public static function isTrigger(?string $type): bool
    {
        return is_string($type) && in_array($type, self::TYPES, true);
    }

    /**
     * The DRAFT's start node: its first trigger step (`is_start` first, then lowest id), or null for a graph
     * without one (legacy graphs keep the executor's own start pick). Runtime paths use publishedStartStep().
     */
    public static function startStep(WorkflowProject|int $project): ?WorkflowStep
    {
        $projectId = $project instanceof WorkflowProject ? $project->getKey() : $project;

        return WorkflowStep::query()
            ->where('project_id', $projectId)
            ->whereIn('step_type', self::TYPES)
            ->orderByDesc('is_start')
            ->orderBy('id')
            ->first();
    }

    /**
     * The start node of the project's PUBLISHED version (what runs execute), or null when it has none or no trigger
     * step. Read without verifying the version (routing only): the run start verifies it and refuses a bad one.
     */
    public static function publishedStartStep(WorkflowProject|int $project): ?WorkflowStep
    {
        $graph = \Cms\Core\Services\Workflow\Versioning\RunGraph::publishedGraph($project);
        if ($graph === null) {
            return null;
        }
        $projectId = $project instanceof WorkflowProject ? (int) $project->getKey() : (int) $project;

        return \Cms\Core\Services\Workflow\Versioning\RunGraph::triggerStart(
            \Cms\Core\Services\Workflow\Versioning\RunGraph::hydrate($graph, $projectId)['steps']
        );
    }

    /**
     * Whether the start node of the project's published version is of $type.
     */
    public static function publishedStartsWith(WorkflowProject|int $project, string $type): bool
    {
        return self::publishedStartStep($project)?->step_type === $type;
    }

    /**
     * Whether the project's start node is of $type.
     */
    public static function startsWith(WorkflowProject|int $project, string $type): bool
    {
        return self::startStep($project)?->step_type === $type;
    }

    /**
     * A setting of the trigger node set (`core.workflow_nodes.triggers.settings.<key>`).
     */
    public static function setting(string $key, mixed $default = null): mixed
    {
        return config('core.workflow_nodes.triggers.settings.' . $key, $default);
    }

    /**
     * Cap a payload stored on a run: payloads bigger than `max_payload_bytes` keep only their keys.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public static function capPayload(array $payload): array
    {
        $max = (int) self::setting('max_payload_bytes', 65536);
        $json = json_encode($payload, JSON_PARTIAL_OUTPUT_ON_ERROR);
        if ($json !== false && strlen($json) <= $max) {
            return json_decode($json, true) ?? [];
        }

        return ['_truncated' => true, 'keys' => array_slice(array_map('strval', array_keys($payload)), 0, 100)];
    }
}
