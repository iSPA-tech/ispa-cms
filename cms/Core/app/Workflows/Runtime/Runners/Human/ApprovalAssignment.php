<?php

declare(strict_types=1);

namespace Cms\Core\Workflows\Runtime\Runners\Human;

use App\Models\User;
use Cms\Core\Models\Agents\AgentApproval;
use Cms\Core\Services\Agentic\AgentApprovalService;
use InvalidArgumentException;
use Throwable;

/**
 * Assignee and SLA of a human-reviewed approval, shared by the `approval` node and the
 * `ai_action` proposal step: both read `assignees` (user uuids, at most 10) and `sla` (seconds or `30m`, `4h`, `2d`;
 * 5 minutes..30 days) from their step config.
 *
 * Assignees are a preference, not a lock: they are notified instead of every permission holder, and anyone holding the
 * reviewer permission may still decide. AgentApprovalService::openAssignment() writes the rows and the due time; an
 * assignee without the permission is skipped, and when none is left the approval falls back to the permission holders.
 *
 * @package Cms\Core\Workflows\Runtime\Runners\Human
 * @since   2026-11-24
 */
class ApprovalAssignment
{
    public const MAX_ASSIGNEES = 10;

    public const MIN_SLA_SECONDS = 300;

    public const MAX_SLA_SECONDS = 30 * 86400;

    public const OVERDUE_TYPE = 'workflow.approval.overdue';

    public function __construct(
        protected AgentApprovalService $approvals,
        protected HumanNotifier $notifier,
    ) {
    }

    /**
     * Distinct non-empty uuid strings of an `assignees` value (anything that is not a list gives none).
     *
     * @return list<string>
     */
    public static function assigneeUuids(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $uuids = [];
        foreach ($value as $uuid) {
            if (is_string($uuid) && trim($uuid) !== '') {
                $uuids[trim($uuid)] = true;
            }
        }

        return array_keys($uuids);
    }

    /**
     * Seconds of an `sla` value (null = not measured), clamped to 5 minutes..30 days.
     *
     * @throws InvalidArgumentException When the value is not a duration.
     */
    public static function slaSeconds(mixed $value): ?int
    {
        return WorkflowApprovalGate::parseDuration($value, self::MIN_SLA_SECONDS, self::MAX_SLA_SECONDS, 'sla');
    }

    /**
     * Problems of a step's `assignees` / `sla` when the workflow is saved: unknown uuids, an unreadable duration (the
     * node schema already caps the list at 10). Same shape as NodeConfigValidator problems.
     *
     * @param array<string, mixed> $config
     * @return list<array{step_code: string, code: string, message: string}>
     */
    public static function configProblems(string $stepCode, array $config): array
    {
        $problems = [];
        $uuids = self::assigneeUuids($config['assignees'] ?? null);
        if ($uuids !== []) {
            $known = User::query()->whereIn('uuid', $uuids)->pluck('uuid')->map(static fn ($uuid): string => (string) $uuid)->all();
            $unknown = array_values(array_diff($uuids, $known));
            if ($unknown !== []) {
                $problems[] = ['step_code' => $stepCode, 'code' => 'assignee_unknown',
                    'message' => "{$stepCode}: unknown assignee " . implode(', ', $unknown) . '.'];
            }
        }

        try {
            self::slaSeconds($config['sla'] ?? null);
        } catch (InvalidArgumentException $e) {
            $problems[] = ['step_code' => $stepCode, 'code' => 'config_invalid', 'message' => "{$stepCode}: " . $e->getMessage()];
        }

        return $problems;
    }

    /**
     * Assign a pending approval that waits for a person and tell the reviewers.
     *
     * With assignees left after the permission check only they are notified; with the fallback the permission holders
     * are; with no assignees configured the permission holders are notified only when $holdersWithoutAssignees (the
     * approval node's behaviour before assignees; an ai_action proposal notified nobody).
     *
     * @param list<string>               $assigneeUuids
     * @param array<string, scalar|null> $payload Notification payload.
     * @return list<int> ids of the users notified
     */
    public function open(
        AgentApproval $approval,
        array $assigneeUuids,
        ?int $slaSeconds,
        string $permission,
        string $type,
        array $payload,
        bool $notify,
        bool $holdersWithoutAssignees,
    ): array {
        try {
            $assignment = $this->approvals->openAssignment(
                $approval,
                array_slice($assigneeUuids, 0, self::MAX_ASSIGNEES),
                $slaSeconds,
                $permission,
            );
        } catch (Throwable $e) {
            // The approval is already open: a failed assignment must not fail the step; the holders are told instead.
            report($e);
            $assignment = ['assignee_uuids' => [], 'fallback' => $assigneeUuids !== []];
        }

        if (!$notify) {
            return [];
        }
        if ($assignment['assignee_uuids'] !== []) {
            return $this->notifier->send($type, $payload, null, $assignment['assignee_uuids']);
        }
        if ($assignment['fallback'] || $holdersWithoutAssignees) {
            return $this->notifier->send($type, $payload, $permission);
        }

        return [];
    }
}
