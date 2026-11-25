<?php

declare(strict_types=1);

namespace Cms\Core\Services\Workflow\AutoApproval;

use Cms\Core\Models\Workflows\WorkflowProject;
use DateTimeInterface;

/**
 * How well JEV agrees with people for one kind of reviewed item (auto-publish after calibration, direction C).
 *
 * Modules register one per artifact type in `core.workflow_nodes.human.calibration_providers`
 * (`[artifact_type => ['provider' => class, 'source_nodes' => [node types that build that review envelope]]]`);
 * an approval step whose items have no registered provider uses Core's {@see StepApprovalCalibration}.
 *
 * @package Cms\Core\Services\Workflow\AutoApproval
 * @since   2026-11-24
 */
interface ApprovalCalibrationProvider
{
    /** Name shown as `source` by the auto-approval endpoint. */
    public function source(): string;

    /**
     * @return array{decided: int, required: int, agreed: int, disagreed: int, agreement_rate: float|null,
     *     minimum_agreement: float, approved_after_edit: int, excluded: int, ready: bool}
     */
    public function calibration(WorkflowProject $project, string $stepCode): array;

    /**
     * The latest decided items, newest first: `probability` (JEV's probability that the item may go out, null when
     * unknown), `verdict` (pass | fail | unsure | null) and `agreement` (true / false, null when not comparable).
     *
     * @return list<array{probability: float|null, verdict: string|null, agreement: bool|null}>
     */
    public function recentDecisions(WorkflowProject $project, string $stepCode, int $limit): array;

    /** Automatically published items a person withdrew since $since (0 when the provider cannot tell). */
    public function withdrawnSince(WorkflowProject $project, string $stepCode, DateTimeInterface $since): int;
}
