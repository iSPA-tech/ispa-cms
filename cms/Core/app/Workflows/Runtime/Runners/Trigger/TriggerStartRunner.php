<?php

namespace Cms\Core\Workflows\Runtime\Runners\Trigger;

use Cms\Core\Services\Ai\JsonSchemaShapeValidator;
use Cms\Core\Services\Workflow\Triggers\WorkflowTriggers;
use Cms\Core\Workflows\Runtime\Contracts\StepRunner;
use Cms\Core\Workflows\Runtime\StepDefinition;
use Cms\Core\Workflows\Runtime\StepRunResult;
use Cms\Core\Workflows\Runtime\WorkflowContext;

/**
 * Start node of every `trigger_*` type. The run service points a new run at it; it exposes what started the run as
 * its result (and in `storeAs` when set):
 * manual / sub_workflow → {input} (checked against `input_schema` when one is set); schedule → {fired_at, cron};
 * model_event → {record}; event → {event}; webhook → {body, query, headers, method, tainted: true};
 * error → {failed_instance, step, error}. Every result also carries {source, at}.
 */
class TriggerStartRunner implements StepRunner
{
    public function run(StepDefinition $step, WorkflowContext $context): StepRunResult
    {
        $trigger = $context->getTrigger()->toArray();
        $meta = is_array($trigger['meta'] ?? null) ? $trigger['meta'] : [];
        $source = (string) ($meta['source'] ?? '');
        $base = ['source' => $source, 'at' => $trigger['at'] ?? null];

        $data = match ($step->stepType) {
            WorkflowTriggers::MANUAL, WorkflowTriggers::SUB_WORKFLOW => ['input' => is_array($meta['input'] ?? null) ? $meta['input'] : []],
            WorkflowTriggers::SCHEDULE => ['fired_at' => $trigger['at'] ?? null, 'cron' => $meta['cron'] ?? null],
            WorkflowTriggers::MODEL_EVENT => ['record' => $meta['record'] ?? null],
            WorkflowTriggers::EVENT => ['event' => $meta['event'] ?? null],
            WorkflowTriggers::WEBHOOK => (is_array($meta['webhook'] ?? null) ? $meta['webhook'] : []) + ['tainted' => true],
            WorkflowTriggers::ERROR => [
                'failed_instance' => $meta['failed_instance'] ?? null,
                'step' => $meta['step'] ?? null,
                'error' => $meta['error'] ?? null,
            ],
            default => [],
        } + $base;

        $schema = $step->stepConfig['input_schema'] ?? null;
        if (isset($data['input']) && is_array($schema) && $schema !== []) {
            $errors = app(JsonSchemaShapeValidator::class)->validate($schema, $data['input']);
            if ($errors !== []) {
                return StepRunResult::failed('Trigger input does not match input_schema: ' . implode('; ', array_slice($errors, 0, 5)));
            }
        }

        $storeAs = $step->stepConfig['storeAs'] ?? null;
        if (is_string($storeAs) && str_starts_with($storeAs, 'vars.') && strlen($storeAs) > 5) {
            try {
                $context->setVarPath(substr($storeAs, 5), $data);
            } catch (\Throwable $e) {
                return StepRunResult::failed($e->getMessage());
            }
        }

        return StepRunResult::success($data, [['level' => 'info', 'message' => "Started by {$step->stepType}" . ($source !== '' ? " ({$source})" : '')]]);
    }
}
