<?php

declare(strict_types=1);

namespace Cms\Core\Workflows\Runtime\Runners\Human;

use Carbon\CarbonImmutable;
use Cms\Core\Models\Agents\AgentApproval;
use Cms\Core\Services\Agentic\AgentApprovalService;
use Cms\Core\Workflows\Expression\TemplateRenderer;
use Cms\Core\Workflows\Runtime\Contracts\StepRunner;
use Cms\Core\Workflows\Runtime\Runners\Util\NodeHelpers;
use Cms\Core\Workflows\Runtime\StepDefinition;
use Cms\Core\Workflows\Runtime\StepRunResult;
use Cms\Core\Workflows\Runtime\WorkflowContext;
use Throwable;

/**
 * `ask_and_wait` node: asks a short `question` (template) in the Agent approvals inbox and pauses until a person
 * answers (free text, or one of `options`), dismisses it, or it expires (`expires_after`). Leaves through `answered`
 * (answer in `vars.<storeAs>.answer`, default `vars.answer`), `rejected` (dismissed) or `expired`.
 *
 * Built on the approval inbox: the question is an approval of type workflow.question whose only editable field is
 * `answer`; approving it with an answer answers the question.
 *
 * @package Cms\Core\Workflows\Runtime\Runners\Human
 * @since   2026-11-24
 */
class AskAndWaitRunner implements StepRunner
{
    use NodeHelpers;

    public const OUTPUTS = ['answered', 'rejected', 'expired'];

    public function __construct(
        protected WorkflowApprovalGate $gate,
        protected HumanNotifier $notifier,
    ) {
    }

    public function run(StepDefinition $step, WorkflowContext $context): StepRunResult
    {
        $config = $step->stepConfig;
        $path = $this->storePath($config, 'vars.answer');
        if ($path === null) {
            return StepRunResult::failed('ask_and_wait: storeAs must start with vars.');
        }

        $waiting = $this->gate->waitingApproval($step, $context);
        if ($waiting !== null) {
            return $this->afterAnswer($waiting, $step, $context, $path);
        }

        try {
            $question = trim((new TemplateRenderer())->renderString((string) ($config['question'] ?? ''), $context));
            $options = $this->evalField($config, 'options', $context, []);
            $expiresAfter = WorkflowApprovalGate::parseDuration($config['expires_after'] ?? null);
        } catch (Throwable $e) {
            return StepRunResult::failed('ask_and_wait: ' . $e->getMessage());
        }
        if ($question === '') {
            return StepRunResult::failed('ask_and_wait: question is empty');
        }
        $options = is_array($options) ? array_slice(array_values(array_filter(array_map(
            static fn ($option) => is_scalar($option) ? mb_substr(trim((string) $option), 0, 120) : '',
            $options,
        ), static fn (string $option): bool => $option !== '')), 0, 10) : [];

        $expiresAt = $expiresAfter !== null ? CarbonImmutable::now()->addSeconds($expiresAfter) : null;
        $permission = is_string($config['reviewer_permission'] ?? null) && $config['reviewer_permission'] !== ''
            ? $config['reviewer_permission']
            : (string) config('core.workflow_nodes.human.reviewer_permission', 'agentic.approve');
        $question = mb_substr($question, 0, 1000);

        try {
            $approval = $this->gate->open($step, $context, [
                'artifact_type' => AgentApprovalService::WORKFLOW_QUESTION_TYPE,
                'payload' => ['summary' => $question, 'question' => $question, 'options' => $options, 'answer' => null],
            ], [
                'kind' => 'question',
                'mode' => 'human',
                'title' => $question,
                'editable_fields' => ['answer'],
                'expires_at' => $expiresAt?->toIso8601String(),
                'reviewer_permission' => $permission,
            ], $question);
        } catch (Throwable $e) {
            return StepRunResult::failed('ask_and_wait: ' . $e->getMessage());
        }

        $notified = ($config['notify'] ?? true) === false ? [] : $this->notifier->send('workflow.question.asked', [
            'approval_id' => (int) $approval->id,
            'title' => mb_substr($question, 0, 200),
        ], $permission);
        $this->gate->scheduleExpiry($approval, $expiresAt);

        return StepRunResult::paused(WorkflowApprovalGate::PAUSED_STATUS, [
            'approval_id' => (int) $approval->id,
            'notified' => count($notified),
        ])->withOutput('rejected');
    }

    protected function afterAnswer(AgentApproval $approval, StepDefinition $step, WorkflowContext $context, string $path): StepRunResult
    {
        $approval->loadMissing('artifact');
        $output = match (true) {
            $approval->status === 'approved' && $approval->apply_outcome === 'applied' => 'answered',
            $approval->status === 'rejected' => 'rejected',
            $approval->status === 'expired' => 'expired',
            default => null,
        };
        if ($output === null) {
            return StepRunResult::paused(WorkflowApprovalGate::PAUSED_STATUS, ['approval_id' => (int) $approval->id])->withOutput('rejected');
        }

        $context->setVarPath($path, [
            'status' => $output,
            'answer' => $output === 'answered' ? data_get($approval->artifact?->payload, 'answer') : null,
            'note' => $approval->reviewer_note,
            'approval_id' => (int) $approval->id,
            'reviewer_id' => $approval->reviewed_by_user_id,
        ]);

        $result = StepRunResult::success(['approval_id' => (int) $approval->id, 'output' => $output]);

        return $this->routeOutput($result, $step, $output, self::OUTPUTS, $output !== 'answered');
    }
}
