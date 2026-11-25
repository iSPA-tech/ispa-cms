<?php

declare(strict_types=1);

namespace Cms\Core\Services\Workflow\AutoApproval;

use Cms\Core\Models\Agents\AgentApproval;
use Cms\Core\Models\Workflows\WorkflowProject;
use Cms\Core\Services\Workflow\Versioning\WorkflowAuthors;
use DateTimeInterface;
use Illuminate\Support\Collection;

/**
 * Core's calibration: the step's own approval rows. Only rows a person decided (approved / rejected) that carry a JEV
 * verdict count; automatic decisions say nothing about how well JEV agrees with people. A pass the person approved or
 * a fail the person rejected agrees, the opposite disagrees, an unsure verdict is decided but not comparable. Rows a
 * person decided without any verdict are `excluded`. Decisions of the workflow's authors (WorkflowAuthors::ofProject())
 * are not read at all (review fix 6, 2026-11-25). Thresholds: `core.workflow_nodes.human.jev.calibration_*`.
 *
 * @package Cms\Core\Services\Workflow\AutoApproval
 * @since   2026-11-24
 */
class StepApprovalCalibration implements ApprovalCalibrationProvider
{
    public const SOURCE = 'core.step_approvals';

    /** How many recent person-decided rows are read at most. */
    private const SCAN = 1000;

    public function source(): string
    {
        return self::SOURCE;
    }

    public function calibration(WorkflowProject $project, string $stepCode): array
    {
        $total = $agreed = $disagreed = $edited = $excluded = 0;
        foreach ($this->decidedByPerson($project, $stepCode, self::SCAN) as $approval) {
            $row = self::row($approval);
            if ($row['verdict'] === null) {
                $excluded++;

                continue;
            }
            $total++;
            $agreed += $row['agreement'] === true ? 1 : 0;
            $disagreed += $row['agreement'] === false ? 1 : 0;
            $edited += $approval->status === 'approved' && (array) data_get($approval->diff_data, 'workflow_node.edits', []) !== [] ? 1 : 0;
        }

        $required = WorkflowAutoApproval::requiredDecisions();
        $minimum = WorkflowAutoApproval::minimumAgreement();
        $rate = $agreed + $disagreed > 0 ? round($agreed / ($agreed + $disagreed), 4) : null;

        return [
            'decided' => $total,
            'required' => $required,
            'agreed' => $agreed,
            'disagreed' => $disagreed,
            'agreement_rate' => $rate,
            'minimum_agreement' => $minimum,
            'approved_after_edit' => $edited,
            'excluded' => $excluded,
            'ready' => $total >= $required && $rate !== null && $rate >= $minimum,
        ];
    }

    public function recentDecisions(WorkflowProject $project, string $stepCode, int $limit): array
    {
        $rows = [];
        foreach ($this->decidedByPerson($project, $stepCode, self::SCAN) as $approval) {
            $row = self::row($approval);
            if ($row['verdict'] !== null) {
                $rows[] = $row;
            }
            if (count($rows) >= $limit) {
                break;
            }
        }

        return $rows;
    }

    public function withdrawnSince(WorkflowProject $project, string $stepCode, DateTimeInterface $since): int
    {
        return 0;
    }

    /**
     * @return Collection<int, AgentApproval>
     */
    protected function decidedByPerson(WorkflowProject $project, string $stepCode, int $limit): Collection
    {
        // Review fix 6: the workflow's authors (WorkflowAuthors::ofProject(): who published any version, the draft
        // editors frozen into versions and the current draft editors) never count, nor a decision with no known person.
        $authors = WorkflowAuthors::ofProject($project);

        return WorkflowAutoApproval::stepApprovals((int) $project->getKey(), $stepCode)
            ->whereIn('status', ['approved', 'rejected'])
            ->whereNotNull('reviewed_by_user_id')
            ->when($authors !== [], static fn ($query) => $query->whereNotIn('reviewed_by_user_id', $authors))
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->filter(static fn (AgentApproval $approval): bool => data_get($approval->diff_data, 'workflow_node.decided_by') === 'human')
            ->values();
    }

    /**
     * @return array{probability: float|null, verdict: string|null, agreement: bool|null}
     */
    protected static function row(AgentApproval $approval): array
    {
        $jev = WorkflowAutoApproval::jevOf($approval);
        $verdict = $jev['verdict'] ?? null;
        $approved = $approval->status === 'approved';
        $agreement = match ($verdict) {
            'pass' => $approved,
            'fail' => !$approved,
            default => null,
        };

        return ['probability' => $jev['probability'] ?? null, 'verdict' => $verdict, 'agreement' => $agreement];
    }
}
