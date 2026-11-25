<?php

namespace Cms\Core\Workflows\Runtime\Runners\Trigger;

use Cms\Core\Services\Workflow\Triggers\WebhookTriggerService;
use Cms\Core\Workflows\Expression\WorkflowExpressionEngine;
use Cms\Core\Workflows\Runtime\Contracts\StepRunner;
use Cms\Core\Workflows\Runtime\StepDefinition;
use Cms\Core\Workflows\Runtime\StepRunResult;
use Cms\Core\Workflows\Runtime\WorkflowContext;

/**
 * Answer of a `trigger_webhook` run whose node has `respond: wait`: `status` (200-599, default 200) and `body`
 * (expression). The waiting webhook request returns it; a run not started by a webhook just records it.
 */
class WebhookRespondRunner implements StepRunner
{
    public function run(StepDefinition $step, WorkflowContext $context): StepRunResult
    {
        $status = (int) ($step->stepConfig['status'] ?? 200);
        if ($status < 200 || $status > 599) {
            return StepRunResult::failed('webhook_respond: status must be between 200 and 599');
        }

        $body = $step->stepConfig['body'] ?? null;
        if (is_string($body) && trim($body) !== '') {
            try {
                $body = (new WorkflowExpressionEngine())->evaluate($body, $context, true);
            } catch (\Throwable $e) {
                return StepRunResult::failed('webhook_respond: ' . $e->getMessage());
            }
        }

        $response = ['status' => $status, 'body' => $body];
        $context->setVarPath(WebhookTriggerService::RESPONSE_VAR, $response);

        return StepRunResult::success($response);
    }
}
