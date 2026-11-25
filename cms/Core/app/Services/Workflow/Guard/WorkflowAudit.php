<?php

declare(strict_types=1);

namespace Cms\Core\Services\Workflow\Guard;

use Cms\Core\Events\AuditLogEvent;
use Cms\Core\Models\Workflows\WorkflowInstance;
use Cms\Core\Models\Workflows\WorkflowProject;
use Cms\Core\Workflows\Runtime\WorkflowContext;
use Illuminate\Database\Eloquent\Model;
use Throwable;

/**
 * Audit entries of workflow changes that the model observers do not see (roadmap B5) and the trace of every record a
 * workflow run writes (B9). Entries go through AuditLogEvent (table `audit_logs`, action stored as `action.<name>`).
 *
 * Rules: a `changed` diff holds names, field lists and plain settings only — never a credential value, webhook secret,
 * step config or field value (prompts and record values may hold personal data).
 *
 * Finding what a run wrote: `audit_logs` where `action = 'action.workflow.record_written'`, `target_type` =
 * WorkflowInstance class and `target_id` = the run id (see guidelines/workflow-recovery-runbook.md).
 *
 * @package Cms\Core\Services\Workflow\Guard
 * @since   2026-11-24
 */
final class WorkflowAudit
{
    public const CREDENTIAL_CREATED = 'workflow.credential_created';
    public const CREDENTIAL_UPDATED = 'workflow.credential_updated';
    public const CREDENTIAL_DELETED = 'workflow.credential_deleted';
    public const CREDENTIAL_TESTED = 'workflow.credential_tested';
    public const WEBHOOK_ROTATED = 'workflow.webhook_rotated';
    public const WEBHOOK_DISABLED = 'workflow.webhook_disabled';
    public const SCHEDULE_CHANGED = 'workflow.schedule_changed';
    public const ERROR_WORKFLOW_CHANGED = 'workflow.error_workflow_changed';
    public const RUN_STARTED = 'workflow.run_started_manually';
    public const GLOBAL_PAUSE_CHANGED = 'workflow.global_pause_changed';
    public const BREAKER_PAUSED = 'workflow.breaker_paused';
    public const BREAKER_RESUMED = 'workflow.breaker_resumed';
    public const RECORD_WRITTEN = 'workflow.record_written';
    // Change control (B3): sensitive versions waiting, approved, rejected, cancelled, promoted after cooling-off.
    public const VERSION_PENDING = 'workflow.version_pending';
    public const VERSION_APPROVED = 'workflow.version_approved';
    public const VERSION_REJECTED = 'workflow.version_rejected';
    public const VERSION_CANCELLED = 'workflow.version_cancelled';
    public const VERSION_PROMOTED = 'workflow.version_promoted';

    /**
     * @param array<string, mixed> $changed
     */
    public static function log(
        string $action,
        array $changed,
        string $targetType,
        int $targetId,
        string $color = 'warning',
        ?int $userId = null,
        string $icon = 'fa fa-project-diagram',
    ): void {
        try {
            event(new AuditLogEvent($action, $changed, $targetType, $targetId, $icon, $color, $userId ?? 0));
        } catch (Throwable $e) {
            // An audit failure must never stop the action it records; it is reported instead.
            report($e);
        }
    }

    /**
     * @param array<string, mixed> $changed
     */
    public static function project(string $action, WorkflowProject $project, array $changed, string $color = 'warning', ?int $userId = null): void
    {
        self::log($action, ['project' => $project->name] + $changed, WorkflowProject::class, (int) $project->getKey(), $color, $userId);
    }

    /**
     * Record that a workflow run wrote a record (B9). Field names only, never values. Does nothing outside a run
     * (no `__runtime.instance_id`, e.g. a package dry run).
     *
     * @param list<string> $fields
     */
    public static function recordWritten(
        WorkflowContext $context,
        ?string $stepCode,
        string $model,
        int|string|null $key,
        array $fields,
        string $operation,
    ): void {
        $instanceId = $context->getVarPath('__runtime.instance_id');
        if (!is_numeric($instanceId) || (int) $instanceId <= 0) {
            return;
        }

        $instance = WorkflowInstance::query()->find((int) $instanceId);
        $versionId = $instance?->getAttribute('version_id');

        self::log(self::RECORD_WRITTEN, [
            'instance_id' => (int) $instanceId,
            'project_id' => $instance?->project_id,
            'version_id' => is_numeric($versionId) ? (int) $versionId : null,
            'step_code' => $stepCode,
            'operation' => $operation,
            'model' => $model,
            'key' => $key,
            'fields' => array_values(array_unique(array_filter($fields, 'is_string'))),
        ], WorkflowInstance::class, (int) $instanceId, 'info', $instance?->delegated_by_user_id ? (int) $instance->delegated_by_user_id : null, 'fa fa-pen');
    }

    /**
     * The key a trace names for a model: its uuid when it has one, else its primary key.
     */
    public static function modelKey(Model $model): int|string|null
    {
        $uuid = $model->getAttribute('uuid');

        return is_string($uuid) && $uuid !== '' ? $uuid : $model->getKey();
    }
}
